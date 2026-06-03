<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Exercises bin/upgrade against a temporary origin + clone pair, with
 * composer and curl replaced by PATH stubs that log their invocations.
 */
class UpgradeScriptTest extends TestCase
{
    private string $workspace;
    private string $origin;
    private string $seed;
    private string $site;
    private string $stubs;
    private string $composerLog;
    private string $curlLog;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/lamb-upgrade-test-' . uniqid();
        $this->origin = $this->workspace . '/origin.git';
        $this->seed = $this->workspace . '/seed';
        $this->site = $this->workspace . '/site';
        $this->stubs = $this->workspace . '/stubs';
        $this->composerLog = $this->workspace . '/composer.log';
        $this->curlLog = $this->workspace . '/curl.log';

        mkdir($this->workspace, 0777, true);
        mkdir($this->stubs, 0777, true);
        $this->writeStub('composer', $this->composerLog);
        $this->writeStub('curl', $this->curlLog);

        $this->git(['git', 'init', '--bare', '--initial-branch=main', $this->origin], $this->workspace);
        $this->git(['git', 'clone', $this->origin, $this->seed], $this->workspace);

        // Seed the repo with the working tree's upgrade script and a content file.
        mkdir($this->seed . '/bin');
        copy(codecept_root_dir('bin/upgrade'), $this->seed . '/bin/upgrade');
        chmod($this->seed . '/bin/upgrade', 0755);
        file_put_contents($this->seed . '/file.txt', "v1\n");
        $this->git(['git', 'add', '.'], $this->seed);
        $this->git(['git', 'commit', '-m', 'v1'], $this->seed);
        $this->git(['git', 'push', 'origin', 'main'], $this->seed);

        $this->git(['git', 'clone', $this->origin, $this->site], $this->workspace);

        // Advance origin past the site clone.
        file_put_contents($this->seed . '/file.txt', "v2\n");
        $this->git(['git', 'commit', '-am', 'v2'], $this->seed);
        $this->git(['git', 'push', 'origin', 'main'], $this->seed);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->workspace]))->run();
    }

    public function testUpgradeResetsToUpstreamAndInstallsWithoutDevDependencies(): void
    {
        // Local drift that a hard reset must discard.
        file_put_contents($this->site . '/file.txt', "local edit\n");

        $process = $this->runUpgrade();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $this->assertSame($this->revParse($this->seed), $this->revParse($this->site), 'site should be at origin HEAD');
        $this->assertSame("v2\n", file_get_contents($this->site . '/file.txt'), 'local edits should be discarded');

        $this->assertFileExists($this->composerLog);
        $composerArgs = file_get_contents($this->composerLog);
        $this->assertStringContainsString('install', $composerArgs);
        $this->assertStringContainsString('--no-dev', $composerArgs);
        $this->assertStringContainsString('--no-interaction', $composerArgs);
    }

    public function testUpgradeReportsOldAndNewRevisions(): void
    {
        $before = $this->revParse($this->site);
        $process = $this->runUpgrade();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $this->assertStringContainsString(substr($before, 0, 7), $process->getOutput());
        $this->assertStringContainsString(substr($this->revParse($this->seed), 0, 7), $process->getOutput());
    }

    public function testUpgradeChecksSiteHealthWhenSiteUrlIsConfigured(): void
    {
        file_put_contents($this->site . '/.env', "SITE_URL='http://example.test:8747'\n");

        $process = $this->runUpgrade();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $this->assertFileExists($this->curlLog);
        $this->assertStringContainsString('http://example.test:8747', file_get_contents($this->curlLog));
    }

    public function testUpgradeSkipsHealthCheckWithoutSiteUrl(): void
    {
        $process = $this->runUpgrade();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $this->assertFileDoesNotExist($this->curlLog);
    }

    public function testFailedHealthCheckPrintsRollbackCommandAndExitsNonZero(): void
    {
        $before = $this->revParse($this->site);
        file_put_contents($this->site . '/.env', "SITE_URL='http://example.test:8747'\n");
        $this->writeStub('curl', $this->curlLog, 22);

        $process = $this->runUpgrade();

        $this->assertNotSame(0, $process->getExitCode(), 'health check failure should be visible to cron');
        $output = $process->getOutput() . $process->getErrorOutput();
        $this->assertStringContainsString('git reset --hard ' . $before, $output, 'should print a rollback command');
    }

    private function runUpgrade(): Process
    {
        $process = new Process(
            [$this->site . '/bin/upgrade'],
            $this->site,
            ['PATH' => $this->stubs . ':' . getenv('PATH')]
        );
        $process->run();

        return $process;
    }

    private function writeStub(string $name, string $log, int $exitCode = 0): void
    {
        $script = "#!/bin/sh\necho \"$*\" >> " . escapeshellarg($log) . "\nexit {$exitCode}\n";
        file_put_contents($this->stubs . '/' . $name, $script);
        chmod($this->stubs . '/' . $name, 0755);
    }

    private function git(array $command, string $cwd): void
    {
        $process = new Process($command, $cwd, [
            'GIT_AUTHOR_NAME' => 'Test',
            'GIT_AUTHOR_EMAIL' => 'test@example.test',
            'GIT_COMMITTER_NAME' => 'Test',
            'GIT_COMMITTER_EMAIL' => 'test@example.test',
            'HOME' => $this->workspace,
        ]);
        $process->mustRun();
    }

    private function revParse(string $repo): string
    {
        $process = new Process(['git', 'rev-parse', 'HEAD'], $repo);
        $process->mustRun();

        return trim($process->getOutput());
    }
}

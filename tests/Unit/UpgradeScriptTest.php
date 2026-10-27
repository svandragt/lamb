<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Exercises bin/upgrade against a temporary origin + clone pair, with
 * viv, composer and curl replaced by PATH stubs that log their invocations.
 * PATH holds only the stubs and the few real tools the script needs, so the
 * host's own viv or composer can't leak into a test.
 */
class UpgradeScriptTest extends TestCase
{
    private string $workspace;
    private string $origin;
    private string $seed;
    private string $site;
    private string $stubs;
    private string $tools;
    private string $vivLog;
    private string $composerLog;
    private string $curlLog;
    private string $lambLog;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/lamb-upgrade-test-' . uniqid();
        $this->origin = $this->workspace . '/origin.git';
        $this->seed = $this->workspace . '/seed';
        $this->site = $this->workspace . '/site';
        $this->stubs = $this->workspace . '/stubs';
        $this->tools = $this->workspace . '/tools';
        $this->vivLog = $this->workspace . '/viv.log';
        $this->composerLog = $this->workspace . '/composer.log';
        $this->curlLog = $this->workspace . '/curl.log';
        $this->lambLog = $this->workspace . '/lamb.log';

        mkdir($this->workspace, 0777, true);
        mkdir($this->stubs, 0777, true);
        mkdir($this->tools, 0777, true);
        $finder = new ExecutableFinder();
        foreach (['bash', 'cat', 'cut', 'dirname', 'env', 'git', 'grep', 'head', 'id', 'sh', 'sort', 'stat', 'tr'] as $tool) {
            symlink($finder->find($tool), $this->tools . '/' . $tool);
        }
        $this->writeStub('viv', $this->vivLog, 0, 'viv 0.22.0');
        $this->writeStub('curl', $this->curlLog);

        $this->git(['git', 'init', '--bare', '--initial-branch=main', $this->origin], $this->workspace);
        $this->git(['git', 'clone', $this->origin, $this->seed], $this->workspace);

        // Seed the repo with the working tree's upgrade script, a stand-in
        // bin/lamb (the real one needs a full PHP app; #811 only needs bin/upgrade
        // to run *something* at that step and react to its exit code), and a
        // content file.
        mkdir($this->seed . '/bin');
        copy(codecept_root_dir('bin/upgrade'), $this->seed . '/bin/upgrade');
        chmod($this->seed . '/bin/upgrade', 0755);
        $this->writeRepoStub('bin/lamb', $this->lambLog);
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

        $this->assertFileExists($this->vivLog);
        $vivArgs = file_get_contents($this->vivLog);
        $this->assertStringContainsString('install', $vivArgs);
        $this->assertStringContainsString('--no-dev', $vivArgs);
        $this->assertStringContainsString('--no-scripts', $vivArgs);
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

    public function testFailedMigrationPrintsRollbackCommandAndExitsNonZeroBeforeTheHealthCheck(): void
    {
        $before = $this->revParse($this->site);
        file_put_contents($this->site . '/.env', "SITE_URL='http://example.test:8747'\n");

        // bin/lamb is a tracked file, reset back to whatever origin has on
        // every run — so the failing version must be committed and pushed,
        // not just written into the site checkout.
        $this->writeRepoStub('bin/lamb', $this->lambLog, 1);
        $this->git(['git', 'add', '.'], $this->seed);
        $this->git(['git', 'commit', '-m', 'fail migrate'], $this->seed);
        $this->git(['git', 'push', 'origin', 'main'], $this->seed);

        $process = $this->runUpgrade();

        $this->assertNotSame(0, $process->getExitCode(), 'migration failure should be visible to cron');
        $output = $process->getOutput() . $process->getErrorOutput();
        $this->assertStringContainsString('git reset --hard ' . $before, $output, 'should print a rollback command');
        $this->assertFileDoesNotExist($this->curlLog, 'health check must not run after a failed migration');
    }

    /**
     * #811: exit 3 from bin/lamb migrate means the #831 ownership guard
     * refused before touching the database — the normal shape of a cron
     * deploy (checkout owned by the webserver user, cron running as a
     * human), not a broken upgrade. bin/upgrade must warn and keep going
     * to the health check and a successful exit, not take the fatal path
     * testFailedMigrationPrintsRollbackCommandAndExitsNonZeroBeforeTheHealthCheck()
     * covers for every other nonzero code.
     */
    public function testMigrateOwnershipRefusalWarnsAndContinuesToTheHealthCheck(): void
    {
        file_put_contents($this->site . '/.env', "SITE_URL='http://example.test:8747'\n");

        $this->writeRepoStub('bin/lamb', $this->lambLog, 3);
        $this->git(['git', 'add', '.'], $this->seed);
        $this->git(['git', 'commit', '-m', 'lamb refuses on ownership'], $this->seed);
        $this->git(['git', 'push', 'origin', 'main'], $this->seed);

        $process = $this->runUpgrade();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $output = $process->getOutput() . $process->getErrorOutput();
        $this->assertStringContainsString('bin/lamb migrate', $output, 'should name the command to run by hand');
        $this->assertStringContainsString('sudo -u', $output, 'should tell the operator which user to run it as');
        $this->assertStringContainsString('Upgraded', $output, 'the upgrade itself still succeeded');
        $this->assertFileExists($this->curlLog, 'health check must still run after an ownership refusal');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function installerByVivVersion(): array
    {
        return [
            'exactly the minimum' => ['0.22.0', 'viv'],
            'a patch above' => ['0.22.1', 'viv'],
            'two-digit minor' => ['0.100.0', 'viv'],
            'a major above' => ['1.0.0', 'viv'],
            'a minor below' => ['0.21.9', 'composer'],
            'one digit sorts above 22 as text' => ['0.9.0', 'composer'],
            'the version before the fix' => ['0.10.0', 'composer'],
        ];
    }

    /**
     * viv before 0.22.0 writes vendor/composer/*.php as 0600, which a web
     * server running as another user can't read.
     *
     * @dataProvider installerByVivVersion
     */
    public function testInstallerIsChosenByVivVersionWhenComposerIsAlsoAvailable(string $version, string $expected): void
    {
        $this->writeStub('viv', $this->vivLog, 0, 'viv ' . $version);
        $this->writeStub('composer', $this->composerLog);

        $process = $this->runUpgrade();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $used = $expected === 'viv' ? $this->vivLog : $this->composerLog;
        $unused = $expected === 'viv' ? $this->composerLog : $this->vivLog;
        $this->assertFileExists($used);
        $this->assertStringContainsString('install --no-dev --no-scripts', file_get_contents($used));
        $this->assertFileDoesNotExist($unused);
        $this->assertStringContainsString('with ' . $expected, $process->getOutput());
    }

    public function testComposerInstallIsNonInteractive(): void
    {
        $this->removeStub('viv');
        $this->writeStub('composer', $this->composerLog);

        $process = $this->runUpgrade();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $this->assertSame("install --no-dev --no-scripts --no-interaction\n", file_get_contents($this->composerLog));
        $this->assertStringContainsString('with composer', $process->getOutput());
    }

    public function testOldVivIsNamedWhenFallingBackToComposer(): void
    {
        $this->writeStub('viv', $this->vivLog, 0, 'viv 0.10.0');
        $this->writeStub('composer', $this->composerLog);

        $process = $this->runUpgrade();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $this->assertStringContainsString('viv 0.10.0', $process->getOutput());
        $this->assertStringContainsString('0.22.0', $process->getOutput());
    }

    public function testNeitherInstallerStopsBeforeTouchingTheCheckout(): void
    {
        $before = $this->revParse($this->site);
        $this->removeStub('viv');

        $process = $this->runUpgrade();

        $this->assertSame(1, $process->getExitCode());
        $output = $process->getOutput() . $process->getErrorOutput();
        $this->assertStringContainsString('viv', $output);
        $this->assertStringContainsString('Composer', $output);
        $this->assertSame($before, $this->revParse($this->site), 'checkout must not be reset');
    }

    public function testOldVivWithoutComposerStopsBeforeTouchingTheCheckout(): void
    {
        $before = $this->revParse($this->site);
        $this->writeStub('viv', $this->vivLog, 0, 'viv 0.10.0');

        $process = $this->runUpgrade();

        $this->assertSame(1, $process->getExitCode());
        $output = $process->getOutput() . $process->getErrorOutput();
        $this->assertStringContainsString('viv 0.10.0', $output);
        $this->assertStringContainsString('0.22.0', $output);
        $this->assertStringContainsString('Composer', $output);
        $this->assertFileDoesNotExist($this->vivLog, 'old viv must not install');
        $this->assertSame($before, $this->revParse($this->site), 'checkout must not be reset');
    }

    public function testRollbackHintsNameTheChosenInstaller(): void
    {
        $before = $this->revParse($this->site);
        $this->removeStub('viv');
        $this->writeStub('composer', $this->composerLog);
        $rollback = 'git reset --hard ' . $before . ' && composer install --no-dev --no-scripts --no-interaction';

        // Migration failure.
        $this->writeRepoStub('bin/lamb', $this->lambLog, 1);
        $this->git(['git', 'add', '.'], $this->seed);
        $this->git(['git', 'commit', '-m', 'fail migrate'], $this->seed);
        $this->git(['git', 'push', 'origin', 'main'], $this->seed);
        $process = $this->runUpgrade();
        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString($rollback, $process->getOutput() . $process->getErrorOutput());

        // Health check failure, from the same starting point.
        $this->git(['git', 'reset', '--hard', $before], $this->site);
        $this->writeRepoStub('bin/lamb', $this->lambLog);
        $this->git(['git', 'add', '.'], $this->seed);
        $this->git(['git', 'commit', '-m', 'migrate ok'], $this->seed);
        $this->git(['git', 'push', 'origin', 'main'], $this->seed);
        file_put_contents($this->site . '/.env', "SITE_URL='http://example.test:8747'\n");
        $this->writeStub('curl', $this->curlLog, 22);
        $process = $this->runUpgrade();
        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString($rollback, $process->getOutput() . $process->getErrorOutput());
    }

    private function runUpgrade(): Process
    {
        $process = new Process(
            [$this->site . '/bin/upgrade'],
            $this->site,
            ['PATH' => $this->stubs . ':' . $this->tools] + self::scrubGitEnv()
        );
        $process->run();

        return $process;
    }

    /**
     * With $versionLine set, `--version` prints it without being logged, the
     * way viv answers bin/upgrade's version check.
     */
    private function writeStub(string $name, string $log, int $exitCode = 0, ?string $versionLine = null): void
    {
        $script = "#!/bin/sh\n";
        if ($versionLine !== null) {
            $script .= 'if [ "$1" = "--version" ]; then echo ' . escapeshellarg($versionLine) . "; exit 0; fi\n";
        }
        $script .= "echo \"$*\" >> " . escapeshellarg($log) . "\nexit {$exitCode}\n";
        file_put_contents($this->stubs . '/' . $name, $script);
        chmod($this->stubs . '/' . $name, 0755);
    }

    private function removeStub(string $name): void
    {
        unlink($this->stubs . '/' . $name);
    }

    /**
     * Same as writeStub(), but written into the seeded git repo rather than
     * onto PATH — for a command bin/upgrade invokes by relative path (like
     * bin/lamb), which must be committed and pushed to survive that script's
     * own `git reset --hard '@{u}'`.
     */
    private function writeRepoStub(string $relativePath, string $log, int $exitCode = 0): void
    {
        $path = $this->seed . '/' . $relativePath;
        @mkdir(dirname($path), 0755, true);
        $script = "#!/bin/sh\necho \"$*\" >> " . escapeshellarg($log) . "\nexit {$exitCode}\n";
        file_put_contents($path, $script);
        chmod($path, 0755);
    }

    private function git(array $command, string $cwd): void
    {
        $process = new Process($command, $cwd, [
            'GIT_AUTHOR_NAME' => 'Test',
            'GIT_AUTHOR_EMAIL' => 'test@example.test',
            'GIT_COMMITTER_NAME' => 'Test',
            'GIT_COMMITTER_EMAIL' => 'test@example.test',
            'HOME' => $this->workspace,
        ] + self::scrubGitEnv());
        $process->mustRun();
    }

    private function revParse(string $repo): string
    {
        $process = new Process(['git', 'rev-parse', 'HEAD'], $repo, self::scrubGitEnv());
        $process->mustRun();

        return trim($process->getOutput());
    }

    /**
     * Git hooks (e.g. pre-push) export GIT_DIR and friends pointing at the
     * real repository, which would hijack the temporary repos used here.
     *
     * @return array<string, false>
     */
    private static function scrubGitEnv(): array
    {
        return [
            'GIT_DIR' => false,
            'GIT_WORK_TREE' => false,
            'GIT_INDEX_FILE' => false,
        ];
    }
}

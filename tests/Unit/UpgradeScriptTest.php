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
        foreach (['bash', 'cat', 'cut', 'dirname', 'env', 'git', 'grep', 'date', 'head', 'id', 'sh', 'sleep', 'sort', 'stat', 'tail', 'tr'] as $tool) {
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
        $this->assertCount(3, file($this->curlLog), 'should try the health check three times before giving up');
    }

    public function testHealthCheckRetriesAfterAFailureAndPasses(): void
    {
        file_put_contents($this->site . '/.env', "SITE_URL='http://example.test:8747'\n");
        $this->writeStub('curl', $this->curlLog, 0, null, 1);

        $process = $this->runUpgrade();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringNotContainsString('Health check failed', $process->getOutput() . $process->getErrorOutput());
        $this->assertCount(2, file($this->curlLog), 'should stop retrying once curl succeeds');
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
     * Exit 3 from bin/lamb migrate means it did not run the migrations: the
     * #831 ownership guard refused (the normal shape of a cron deploy, with the
     * checkout and database owned by the webserver user and cron running as a
     * human), or another run holds the lock. That is not a broken upgrade, so
     * bin/upgrade hands the migrations to the site's own /_cron, which runs as
     * the database's owner, before it checks the site is healthy. It must not
     * take the fatal path
     * testFailedMigrationPrintsRollbackCommandAndExitsNonZeroBeforeTheHealthCheck()
     * covers for every other nonzero code.
     *
     * The HTTP status alone is not enough: /_cron answers 200 for a held lock
     * and when the migrations were not needed. bin/upgrade reads the
     * `Data migrations: <result>` line instead.
     */
    public function testMigrateRefusalRequestsCronThenChecksHealth(): void
    {
        file_put_contents($this->site . '/.env', "SITE_URL='http://example.test:8747'\n");
        $this->commitMigrateExit(3);
        $this->writeCronCurlStub([[0, 'Data migrations: migrated']]);

        $process = $this->runUpgrade();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $output = $process->getOutput() . $process->getErrorOutput();
        $this->assertStringContainsString('ran the data migrations through http://example.test:8747/_cron', $output);
        $this->assertStringNotContainsString('sudo', $output, 'nobody has to run anything by hand');
        $this->assertStringNotContainsString('WARNING', $output);
        $this->assertStringContainsString('Upgraded', $output, 'the upgrade itself still succeeded');
        $this->assertSame(
            ['http://example.test:8747/_cron', 'http://example.test:8747'],
            $this->curlUrls(),
            'the /_cron request comes before the health check'
        );
    }

    public function testMigrateRefusalAcceptsADatabaseThatIsAlreadyCurrent(): void
    {
        file_put_contents($this->site . '/.env', "SITE_URL='http://example.test:8747'\n");
        $this->commitMigrateExit(3);
        $this->writeCronCurlStub([[0, "Data migrations: already current\nToo often, try again later."]]);

        $process = $this->runUpgrade();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $this->assertStringContainsString('already migrated', $process->getOutput());
        $this->assertStringNotContainsString('ran the data migrations', $process->getOutput());
    }

    public function testMigrateRefusalWithATrailingSlashSiteUrlRequestsCronOnce(): void
    {
        file_put_contents($this->site . '/.env', "SITE_URL='http://example.test:8747/blog/'\n");
        $this->commitMigrateExit(3);
        $this->writeCronCurlStub([[0, 'Data migrations: migrated']]);

        $process = $this->runUpgrade();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $this->assertSame('http://example.test:8747/blog/_cron', $this->curlUrls()[0]);
    }

    public function testMigrateRefusalWithoutSiteUrlLeavesTheMigrationsToTheNextCronRequest(): void
    {
        $this->commitMigrateExit(3);

        $process = $this->runUpgrade();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $output = $process->getOutput() . $process->getErrorOutput();
        $this->assertStringContainsString('next scheduled /_cron request runs the data migrations', $output);
        $this->assertStringContainsString('Upgraded', $output);
        $this->assertFileDoesNotExist($this->curlLog, 'there is no site to request');
    }

    public function testFailedCronRequestAfterAMigrateRefusalPrintsRollbackCommandAndExitsNonZero(): void
    {
        $before = $this->revParse($this->site);
        file_put_contents($this->site . '/.env', "SITE_URL='http://example.test:8747'\n");
        $this->commitMigrateExit(3);
        // curl -f exits 22 on the HTTP 500 that /_cron answers when a migration fails.
        $this->writeCronCurlStub([[22, '']]);

        $process = $this->runUpgrade();

        $this->assertNotSame(0, $process->getExitCode(), 'a failed /_cron request must be visible to cron');
        $output = $process->getOutput() . $process->getErrorOutput();
        $this->assertStringContainsString('http://example.test:8747/_cron', $output);
        $this->assertStringContainsString('git reset --hard ' . $before, $output, 'should print a rollback command');
        $this->assertStringNotContainsString('ran the data migrations', $output);
        $this->assertSame(['http://example.test:8747/_cron'], $this->curlUrls(), 'the health check must not run after it');
    }

    /**
     * An old Lamb, or a proxy's error page, answers 200 without the result line.
     */
    public function testAnAnswerWithoutAResultLineIsNotTakenAsSuccess(): void
    {
        $before = $this->revParse($this->site);
        file_put_contents($this->site . '/.env', "SITE_URL='http://example.test:8747'\n");
        $this->commitMigrateExit(3);
        $this->writeCronCurlStub([[0, "Updating feeds...\nDone"]]);

        $process = $this->runUpgrade();

        $this->assertNotSame(0, $process->getExitCode());
        $output = $process->getOutput() . $process->getErrorOutput();
        $this->assertStringContainsString('did not report', $output);
        $this->assertStringContainsString('git reset --hard ' . $before, $output);
        $this->assertStringNotContainsString('ran the data migrations', $output);
    }

    public function testABusySiteIsAskedAgainAfterAShortDelay(): void
    {
        file_put_contents($this->site . '/.env', "SITE_URL='http://example.test:8747'\n");
        $this->commitMigrateExit(3);
        $this->writeCronCurlStub([
            [0, "Data migrations: busy\nAlready running, try again later."],
            [0, 'Data migrations: busy'],
            [0, 'Data migrations: migrated'],
        ]);

        $process = $this->runUpgrade();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $this->assertSame(
            array_fill(0, 3, 'http://example.test:8747/_cron'),
            array_slice($this->curlUrls(), 0, 3),
            'asked again until the site answered'
        );
        $this->assertStringContainsString('ran the data migrations', $process->getOutput());
        $this->assertSame('http://example.test:8747', $this->curlUrls()[3], 'then the health check');
    }

    /**
     * A busy answer means nothing ran and a later /_cron request will run the
     * migrations, as when there is no SITE_URL, so the upgrade is not rolled back.
     */
    public function testASiteThatStaysBusyLeavesTheMigrationsToTheNextCronRequest(): void
    {
        file_put_contents($this->site . '/.env', "SITE_URL='http://example.test:8747'\n");
        $this->commitMigrateExit(3);
        $this->writeCronCurlStub(array_fill(0, 8, [0, 'Data migrations: busy']));

        $process = $this->runUpgrade();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $output = $process->getOutput() . $process->getErrorOutput();
        $this->assertStringContainsString('busy', $output);
        $this->assertStringContainsString('next scheduled /_cron request runs the data migrations', $output);
        $this->assertStringNotContainsString('Roll back', $output);
        $this->assertStringNotContainsString('ran the data migrations', $output);
        $this->assertStringContainsString('Upgraded', $output);
        $urls = $this->curlUrls();
        $this->assertCount(6, $urls, 'five /_cron requests, then the health check');
        $this->assertSame('http://example.test:8747', $urls[5]);
    }

    /**
     * When /_cron cannot read OPcache's status it cannot tell that it is running the old code
     * right after the reset, so the script gives the cache the time the health check gives it.
     */
    public function testTheFirstCronRequestWaitsForTheCodeCacheToRefresh(): void
    {
        file_put_contents($this->site . '/.env', "SITE_URL='http://example.test:8747'\n");
        $this->commitMigrateExit(3);
        $this->writeCronCurlStub([[0, 'Data migrations: migrated']]);
        $this->writeTimedVivStub();

        $process = $this->runUpgrade(['LAMB_UPGRADE_HEALTH_RETRY_DELAY' => '1']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $installedAt = (int) trim((string) file_get_contents($this->vivLog));
        $requestedAt = (int) strtok((string) file($this->curlLog)[0], ' ');
        $this->assertGreaterThanOrEqual(900000000, $requestedAt - $installedAt, 'nanoseconds between the install and the request');
    }

    public function testASuccessfulMigrationDoesNotRequestCron(): void
    {
        file_put_contents($this->site . '/.env', "SITE_URL='http://example.test:8747'\n");

        $process = $this->runUpgrade();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $this->assertSame(['http://example.test:8747'], $this->curlUrls(), 'bin/lamb migrate did the work, so only the health check asks');
    }

    /**
     * A curl stub that answers each /_cron request from a list and logs every call as
     * "<nanoseconds> <arguments>". Any other request, the health check, succeeds silently.
     *
     * @param list<array{0: int, 1: string}> $replies Exit code and body, one pair per /_cron request, in order.
     */
    private function writeCronCurlStub(array $replies): void
    {
        foreach ($replies as $index => [$exitCode, $body]) {
            file_put_contents($this->stubs . '/cron-reply-' . ($index + 1), $exitCode . "\n" . $body . "\n");
        }
        $log = escapeshellarg($this->curlLog);
        $script = "#!/bin/sh\n"
            . "echo \"$(date +%s%N) \$*\" >> $log\n"
            . "case \"\$*\" in\n"
            . "  *_cron*)\n"
            . "    n=$(grep -c '_cron' $log)\n"
            . '    reply=' . escapeshellarg($this->stubs) . "/cron-reply-\$n\n"
            . "    [ -f \"\$reply\" ] || exit 0\n"
            . "    tail -n +2 \"\$reply\"\n"
            . "    exit \"$(head -n1 \"\$reply\")\"\n"
            . "    ;;\n"
            . "esac\n"
            . "exit 0\n";
        file_put_contents($this->stubs . '/curl', $script);
        chmod($this->stubs . '/curl', 0755);
    }

    /**
     * A viv stub that answers the version check and records when it last installed, in nanoseconds.
     */
    private function writeTimedVivStub(): void
    {
        $script = "#!/bin/sh\n"
            . 'if [ "$1" = "--version" ]; then echo ' . escapeshellarg('viv 0.22.0') . "; exit 0; fi\n"
            . 'date +%s%N > ' . escapeshellarg($this->vivLog) . "\n";
        file_put_contents($this->stubs . '/viv', $script);
        chmod($this->stubs . '/viv', 0755);
    }

    /**
     * Commits a bin/lamb that exits with $code to the origin: bin/lamb is a
     * tracked file, reset to whatever origin has on every run, so a stand-in
     * written only into the site checkout would not survive.
     */
    private function commitMigrateExit(int $code): void
    {
        $this->writeRepoStub('bin/lamb', $this->lambLog, $code);
        $this->git(['git', 'add', '.'], $this->seed);
        $this->git(['git', 'commit', '-m', "bin/lamb exits {$code}"], $this->seed);
        $this->git(['git', 'push', 'origin', 'main'], $this->seed);
    }

    /**
     * The last argument of each curl call, in order.
     *
     * @return list<string>
     */
    private function curlUrls(): array
    {
        $urls = [];
        foreach (file($this->curlLog, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $urls[] = substr($line, (int) strrpos($line, ' ') + 1);
        }

        return $urls;
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

    /**
     * @param array<string, string> $env Environment that replaces the defaults.
     */
    private function runUpgrade(array $env = []): Process
    {
        $process = new Process(
            [$this->site . '/bin/upgrade'],
            $this->site,
            $env + ['PATH' => $this->stubs . ':' . $this->tools, 'LAMB_UPGRADE_HEALTH_RETRY_DELAY' => '0'] + self::scrubGitEnv()
        );
        $process->run();

        return $process;
    }

    /**
     * With $versionLine set, `--version` prints it without being logged, the
     * way viv answers bin/upgrade's version check.
     */
    private function writeStub(string $name, string $log, int $exitCode = 0, ?string $versionLine = null, int $failFirst = 0): void
    {
        $script = "#!/bin/sh\n";
        if ($versionLine !== null) {
            $script .= 'if [ "$1" = "--version" ]; then echo ' . escapeshellarg($versionLine) . "; exit 0; fi\n";
        }
        $script .= "echo \"$*\" >> " . escapeshellarg($log) . "\n";
        if ($failFirst > 0) {
            $script .= 'if [ "$(grep -c \'\' ' . escapeshellarg($log) . ")\" -le {$failFirst} ]; then exit 22; fi\n";
        }
        $script .= "exit {$exitCode}\n";
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

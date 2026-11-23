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
        foreach (['bash', 'cat', 'cut', 'dirname', 'env', 'git', 'grep', 'date', 'head', 'id', 'mktemp', 'rm', 'sed', 'sh', 'sleep', 'sort', 'stat', 'tail', 'tee', 'tr'] as $tool) {
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
        $this->writeRepoStub('bin/lamb', $this->lambLog, 0, 'Data migrations: already current');
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

    /**
     * The migrations only move forward, so once one ran, old code could meet data migrated for
     * the new code. The script then prints no rollback command and points to the error log.
     */
    public function testFailedHealthCheckAfterTheCliMigratedSaysRollingBackIsNotSafe(): void
    {
        file_put_contents($this->site . '/.env', "SITE_URL='http://example.test:8747'\n");
        $this->commitMigrateExit(0, 'Data migrations: migrated');
        $this->writeStub('curl', $this->curlLog, 22);

        $process = $this->runUpgrade();

        $this->assertSame(1, $process->getExitCode(), 'health check failure should be visible to cron');
        $output = $process->getOutput() . $process->getErrorOutput();
        $this->assertStringContainsString('Data migrations: migrated', $output, 'the output of bin/lamb migrate is still shown');
        $this->assertStringContainsString('Health check failed', $output);
        $this->assertStringNotContainsString('Roll back', $output);
        $this->assertStringNotContainsString('git reset --hard', $output);
        $this->assertStringContainsString('rolling back is not safe', $output);
        $this->assertStringContainsString('error log', $output);
    }

    /**
     * @return array<string, array{list<array{0: int, 1: int, 2: string}>}>
     */
    public static function cronRepliesAfterWhichTheSiteMayHaveMigrated(): array
    {
        return [
            'the site migrated' => [[[0, 200, 'Data migrations: migrated']]],
            'the site stayed busy' => [array_fill(0, 5, [0, 200, 'Data migrations: busy'])],
            'a timeout' => [[[28, 0, '']]],
        ];
    }

    /**
     * @dataProvider cronRepliesAfterWhichTheSiteMayHaveMigrated
     * @param list<array{0: int, 1: int, 2: string}> $replies
     */
    public function testFailedHealthCheckAfterTheSiteMayHaveMigratedSaysRollingBackIsNotSafe(array $replies): void
    {
        file_put_contents($this->site . '/.env', "SITE_URL='http://example.test:8747'\n");
        $this->commitMigrateExit(3);
        $this->writeCronCurlStub($replies, 22);

        $process = $this->runUpgrade();

        $this->assertSame(1, $process->getExitCode());
        $output = $process->getOutput() . $process->getErrorOutput();
        $this->assertStringContainsString('Health check failed', $output);
        $this->assertStringNotContainsString('Roll back', $output);
        $this->assertStringNotContainsString('git reset --hard', $output);
        $this->assertStringContainsString('rolling back is not safe', $output);
        $this->assertStringContainsString('error log', $output);
    }

    public function testFailedHealthCheckAfterTheSiteFoundTheDataCurrentStillPrintsTheRollbackCommand(): void
    {
        $before = $this->revParse($this->site);
        file_put_contents($this->site . '/.env', "SITE_URL='http://example.test:8747'\n");
        $this->commitMigrateExit(3);
        $this->writeCronCurlStub([[0, 200, 'Data migrations: already current']], 22);

        $process = $this->runUpgrade();

        $this->assertSame(1, $process->getExitCode());
        $output = $process->getOutput() . $process->getErrorOutput();
        $this->assertStringContainsString('git reset --hard ' . $before, $output, 'nothing migrated, so rolling back is safe');
        $this->assertStringNotContainsString('rolling back is not safe', $output);
    }

    /**
     * @return array<string, array{?string, string}> The marker the data was migrated for before the
     *     upgrade (null: it could not be read) and who said the data was current afterwards.
     */
    public static function upgradesThatMayHaveMigrated(): array
    {
        return [
            'a scheduled /_cron migrated during the upgrade, then bin/lamb said current' => ['code:old', 'cli'],
            'a scheduled /_cron migrated during the upgrade, then /_cron said current' => ['code:old', 'cron'],
            'the marker before the upgrade is unknown, bin/lamb said current' => [null, 'cli'],
            'the marker before the upgrade is unknown, /_cron said current' => [null, 'cron'],
        ];
    }

    /**
     * "Already current" does not prove that no migration ran during the upgrade: a scheduled
     * /_cron can migrate during `composer install`. Only a marker read before the reset that
     * already equals the new code's marker proves it.
     *
     * @dataProvider upgradesThatMayHaveMigrated
     */
    public function testFailedHealthCheckAfterAnAlreadyCurrentAnswerNeedsTheMarkerFromBeforeTheUpgrade(?string $storedBefore, string $via): void
    {
        file_put_contents($this->site . '/.env', "SITE_URL='http://example.test:8747'\n");
        $this->writeSiteStub($storedBefore);
        if ($via === 'cli') {
            $this->commitMigrateExit(0, 'Data migrations: already current', 'code:new');
            $this->writeStub('curl', $this->curlLog, 22);
        } else {
            $this->commitMigrateExit(3, null, 'code:new');
            $this->writeCronCurlStub([[0, 200, 'Data migrations: already current']], 22);
        }

        $process = $this->runUpgrade();

        $this->assertSame(1, $process->getExitCode());
        $output = $process->getOutput() . $process->getErrorOutput();
        $this->assertStringContainsString('Health check failed', $output);
        $this->assertStringNotContainsString('Roll back', $output);
        $this->assertStringNotContainsString('git reset --hard', $output);
        $this->assertStringContainsString('rolling back is not safe', $output);
        $this->assertStringContainsString('error log', $output);
    }

    public function testFailedHealthCheckStillPrintsTheRollbackCommandWhenTheDataWasCurrentForTheNewCodeBeforeTheUpgrade(): void
    {
        $before = $this->revParse($this->site);
        file_put_contents($this->site . '/.env', "SITE_URL='http://example.test:8747'\n");
        $this->writeSiteStub('code:new');
        $this->commitMigrateExit(0, 'Data migrations: already current', 'code:new');
        $this->writeStub('curl', $this->curlLog, 22);

        $process = $this->runUpgrade();

        $this->assertSame(1, $process->getExitCode());
        $output = $process->getOutput() . $process->getErrorOutput();
        $this->assertStringContainsString('git reset --hard ' . $before, $output);
        $this->assertStringNotContainsString('rolling back is not safe', $output);
    }

    /**
     * A site behind a redirect (http to https, a missing trailing slash) answers the first
     * request with a 301 that carries no result line.
     */
    public function testTheMigrationsRequestFollowsRedirects(): void
    {
        file_put_contents($this->site . '/.env', "SITE_URL='http://example.test:8747'\n");
        $this->commitMigrateExit(3);
        $this->writeCronCurlStub([[0, 200, 'Data migrations: migrated']]);

        $process = $this->runUpgrade();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $firstRequest = (string) file($this->curlLog)[0];
        $this->assertMatchesRegularExpression('/ -L /', $firstRequest);
        $this->assertDoesNotMatchRegularExpression('/ -\w*f/', $firstRequest, 'no -f, because a failed migration answers 500 with the result line');
    }

    /**
     * A long migration must show its progress, and its errors, as it goes, not after it ends.
     */
    public function testTheMigrationOutputAppearsWhileTheMigrationIsStillRunning(): void
    {
        $release = $this->workspace . '/release-migration';
        $script = "#!/bin/sh\n"
            . "if [ \"\$1\" = marker ]; then echo code:same; exit 0; fi\n"
            . "echo 'migration progress'\n"
            . 'i=0; while [ ! -f ' . escapeshellarg($release) . " ] && [ \$i -lt 300 ]; do sleep 0.1; i=\$((i + 1)); done\n"
            . "echo 'Data migrations: migrated'\n"
            . "exit 0\n";
        file_put_contents($this->seed . '/bin/lamb', $script);
        $this->git(['git', 'add', '.'], $this->seed);
        $this->git(['git', 'commit', '-m', 'slow migrate'], $this->seed);
        $this->git(['git', 'push', 'origin', 'main'], $this->seed);

        $process = $this->upgradeProcess();
        $process->setTimeout(60);
        $process->start();
        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline && !str_contains($process->getOutput(), 'migration progress')) {
            usleep(50000);
        }
        $seenWhileRunning = str_contains($process->getOutput(), 'migration progress');
        touch($release);
        $process->wait();

        $this->assertTrue($seenWhileRunning, 'the output of bin/lamb migrate must not wait for it to finish');
        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertStringContainsString('Data migrations: migrated', $process->getOutput());
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

    /**
     * Migrations only move forward, so a failed one leaves data that old code may not read: the
     * script prints no rollback command. It says the data may be partly migrated and how to
     * finish the job.
     */
    public function testFailedMigrationStopsWithoutARollbackHintBeforeTheHealthCheck(): void
    {
        file_put_contents($this->site . '/.env', "SITE_URL='http://example.test:8747'\n");
        $this->commitMigrateExit(1);

        $process = $this->runUpgrade();

        $this->assertSame(1, $process->getExitCode(), 'migration failure should be visible to cron');
        $output = $process->getOutput() . $process->getErrorOutput();
        $this->assertStringNotContainsString('Roll back', $output);
        $this->assertStringNotContainsString('git reset --hard', $output, 'old code on partly migrated data is worse than the failure');
        $this->assertStringContainsString('partly migrated', $output);
        $this->assertStringContainsString('output above', $output);
        $this->assertStringContainsString('run bin/lamb migrate again as the user that owns data/lamb.db', $output);
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
     * testFailedMigrationStopsWithoutARollbackHintBeforeTheHealthCheck()
     * covers for every other nonzero code.
     *
     * Migrations only move forward, so from here bin/upgrade never prints the
     * rollback hint: old code on migrated data is worse than the problem. It
     * reads the `Data migrations: <result>` line, not the HTTP status, because
     * /_cron answers 200 when busy and when nothing needed migrating, and a
     * timeout on a large archive says nothing about whether the migration ran.
     */
    public function testMigrateRefusalRequestsCronThenChecksHealth(): void
    {
        file_put_contents($this->site . '/.env', "SITE_URL='http://example.test:8747'\n");
        $this->commitMigrateExit(3);
        $this->writeCronCurlStub([[0, 200, 'Data migrations: migrated']]);

        $process = $this->runUpgrade();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $output = $process->getOutput() . $process->getErrorOutput();
        $this->assertStringContainsString('ran the data migrations through http://example.test:8747/_cron?migrations', $output);
        $this->assertStringNotContainsString('sudo', $output, 'nobody has to run anything by hand');
        $this->assertStringNotContainsString('WARNING', $output);
        $this->assertStringContainsString('Upgraded', $output, 'the upgrade itself still succeeded');
        $this->assertSame(
            ['http://example.test:8747/_cron?migrations', 'http://example.test:8747'],
            $this->curlUrls(),
            'the /_cron request comes before the health check'
        );
    }

    public function testMigrateRefusalAcceptsADatabaseThatIsAlreadyCurrent(): void
    {
        file_put_contents($this->site . '/.env', "SITE_URL='http://example.test:8747'\n");
        $this->commitMigrateExit(3);
        $this->writeCronCurlStub([[0, 200, "Data migrations: already current\nToo often, try again later."]]);

        $process = $this->runUpgrade();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $this->assertStringContainsString('already migrated', $process->getOutput());
        $this->assertStringNotContainsString('ran the data migrations', $process->getOutput());
    }

    public function testMigrateRefusalWithATrailingSlashSiteUrlRequestsCronOnce(): void
    {
        file_put_contents($this->site . '/.env', "SITE_URL='http://example.test:8747/blog/'\n");
        $this->commitMigrateExit(3);
        $this->writeCronCurlStub([[0, 200, 'Data migrations: migrated']]);

        $process = $this->runUpgrade();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $this->assertSame('http://example.test:8747/blog/_cron?migrations', $this->curlUrls()[0]);
    }

    public function testMigrateRefusalWithoutSiteUrlGivesTheManualCommandAndLeavesTheRestToCron(): void
    {
        mkdir($this->site . '/data');
        touch($this->site . '/data/lamb.db');
        $this->commitMigrateExit(3);

        $process = $this->runUpgrade();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $output = $process->getOutput() . $process->getErrorOutput();
        $owner = trim((string) shell_exec('id -un 2>/dev/null'));
        if ($owner === '' || ctype_digit($owner)) {
            $owner = "'#" . trim((string) shell_exec('id -u')) . "'";
        }
        $this->assertStringContainsString("sudo -u {$owner} bin/lamb migrate", $output, 'a hint for the operator, never run by the script');
        $this->assertStringContainsString('a scheduled /_cron request also runs the data migrations', $output);
        $this->assertStringContainsString('Upgraded', $output);
        $this->assertFileDoesNotExist($this->curlLog, 'there is no site to request');
    }

    public function testMigrateRefusalHintUsesTheNumericUidWhenTheOwnerHasNoName(): void
    {
        mkdir($this->site . '/data');
        touch($this->site . '/data/lamb.db');
        $script = "#!/bin/sh\ncase \"\$2\" in\n    %U) echo UNKNOWN ;;\n    %u) echo 1234 ;;\nesac\n";
        file_put_contents($this->stubs . '/stat', $script);
        chmod($this->stubs . '/stat', 0755);
        $this->commitMigrateExit(3);

        $process = $this->runUpgrade();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $output = $process->getOutput() . $process->getErrorOutput();
        $this->assertStringContainsString("sudo -u '#1234' bin/lamb migrate", $output, 'sudo takes a numeric uid as #uid');
        $this->assertStringNotContainsString('UNKNOWN', $output);
    }

    public function testMigrateRefusalWithoutCurlGivesTheManualCommandToo(): void
    {
        file_put_contents($this->site . '/.env', "SITE_URL='http://example.test:8747'\n");
        $this->commitMigrateExit(3);
        $this->removeStub('curl');

        $process = $this->runUpgrade();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $output = $process->getOutput() . $process->getErrorOutput();
        $this->assertStringContainsString('sudo -u <web server user> bin/lamb migrate', $output, 'no data/lamb.db to read an owner from');
        $this->assertStringContainsString('a scheduled /_cron request also runs the data migrations', $output);
    }

    /**
     * /_cron answers 500 with "Data migrations: failed" when a step threw. The status alone
     * would hide that line, so curl must not use -f.
     */
    public function testAFailedMigrationStopsTheRunPointingToTheErrorLogWithoutARollbackHint(): void
    {
        file_put_contents($this->site . '/.env', "SITE_URL='http://example.test:8747'\n");
        $this->commitMigrateExit(3);
        $this->writeCronCurlStub([[0, 500, "Data migrations: failed\nData migration failed, see the error log."]]);

        $process = $this->runUpgrade();

        $this->assertSame(1, $process->getExitCode(), 'a failed migration must be visible to cron');
        $output = $process->getOutput() . $process->getErrorOutput();
        $this->assertStringContainsString('error log', $output);
        $this->assertStringContainsString('next /_cron request retries', $output);
        $this->assertStringNotContainsString('Roll back', $output, 'old code on migrated data is worse than the failure');
        $this->assertStringNotContainsString('ran the data migrations', $output);
        $this->assertSame(['http://example.test:8747/_cron?migrations'], $this->curlUrls(), 'the health check does not run after it');
    }

    /**
     * @return array<string, array{int, int, string}>
     */
    public static function unconfirmedCronReplies(): array
    {
        return [
            'a timeout' => [28, 0, ''],
            'a connection error' => [7, 0, ''],
            'a 504 from the proxy' => [0, 504, '<html>Gateway Time-out</html>'],
            'a 502 from the proxy' => [0, 502, '<html>Bad Gateway</html>'],
            'a 200 without the result line' => [0, 200, "Updating feeds...\nDone"],
        ];
    }

    /**
     * A timeout, a gateway error or a reply from an older Lamb says nothing about whether the
     * migration ran, and it may still be running. Rolling the code back could put old code on
     * migrated data, so the script warns, leaves the retry to /_cron and carries on.
     *
     * @dataProvider unconfirmedCronReplies
     */
    public function testAnUnconfirmedCronRequestWarnsAndContinuesWithoutARollbackHint(int $curlExit, int $status, string $body): void
    {
        file_put_contents($this->site . '/.env', "SITE_URL='http://example.test:8747'\n");
        $this->commitMigrateExit(3);
        $this->writeCronCurlStub([[$curlExit, $status, $body]]);

        $process = $this->runUpgrade();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $output = $process->getOutput() . $process->getErrorOutput();
        $this->assertStringContainsString('may still be running or may have failed', $output);
        $this->assertStringContainsString('next /_cron request retries', $output);
        $this->assertStringContainsString('error log', $output);
        $this->assertStringNotContainsString('Roll back', $output);
        $this->assertStringNotContainsString('ran the data migrations', $output);
        $this->assertStringContainsString('Upgraded', $output);
        $this->assertSame('http://example.test:8747', $this->curlUrls()[1], 'the health check still runs');
    }

    public function testABusySiteIsAskedAgainAfterAShortDelay(): void
    {
        file_put_contents($this->site . '/.env', "SITE_URL='http://example.test:8747'\n");
        $this->commitMigrateExit(3);
        $this->writeCronCurlStub([
            [0, 200, 'Data migrations: busy'],
            [0, 200, 'Data migrations: busy'],
            [0, 200, 'Data migrations: migrated'],
        ]);

        $process = $this->runUpgrade();

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput() . $process->getOutput());
        $this->assertSame(
            array_fill(0, 3, 'http://example.test:8747/_cron?migrations'),
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
        $this->writeCronCurlStub(array_fill(0, 8, [0, 200, 'Data migrations: busy']));

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
     * OPcache can serve the old code for a moment after the reset, so the script gives it the
     * time the health check gives it before the first request.
     */
    public function testTheFirstCronRequestWaitsForTheCodeCacheToRefresh(): void
    {
        file_put_contents($this->site . '/.env', "SITE_URL='http://example.test:8747'\n");
        $this->commitMigrateExit(3);
        $this->writeCronCurlStub([[0, 200, 'Data migrations: migrated']]);
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
     * A curl stub that answers each /_cron request from a list, the way
     * `curl -sS -w '\n%{http_code}'` does: the body, a newline and the status. It logs every
     * call as "<nanoseconds> <arguments>". Any other request, the health check, succeeds silently.
     *
     * @param list<array{0: int, 1: int, 2: string}> $replies Curl's exit code, the HTTP status and
     *     the body, one triple per /_cron request, in order.
     * @param int $healthExit Curl's exit code for every other request.
     */
    private function writeCronCurlStub(array $replies, int $healthExit = 0): void
    {
        foreach ($replies as $index => [$exitCode, $status, $body]) {
            file_put_contents($this->stubs . '/cron-reply-' . ($index + 1), $exitCode . "\n" . sprintf('%03d', $status) . "\n" . $body . "\n");
        }
        $log = escapeshellarg($this->curlLog);
        $script = "#!/bin/sh\n"
            . "printf '%s %s\\n' \"$(date +%s%N)\" \"\$*\" >> $log\n"
            . "case \"\$*\" in\n"
            . "  *_cron*)\n"
            . "    n=$(grep -c '_cron' $log)\n"
            . '    reply=' . escapeshellarg($this->stubs) . "/cron-reply-\$n\n"
            . "    [ -f \"\$reply\" ] || exit 0\n"
            . "    tail -n +3 \"\$reply\"\n"
            . "    printf '%s' \"$(sed -n 2p \"\$reply\")\"\n"
            . "    exit \"$(head -n1 \"\$reply\")\"\n"
            . "    ;;\n"
            . "esac\n"
            . "exit {$healthExit}\n";
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
    private function commitMigrateExit(int $code, ?string $output = null, ?string $codeMarker = 'code:same'): void
    {
        $this->writeRepoStub('bin/lamb', $this->lambLog, $code, $output, 'code:same', $codeMarker);
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

    public function testTheRollbackHintNamesTheChosenInstaller(): void
    {
        $before = $this->revParse($this->site);
        $this->removeStub('viv');
        $this->writeStub('composer', $this->composerLog);
        file_put_contents($this->site . '/.env', "SITE_URL='http://example.test:8747'\n");
        $this->writeStub('curl', $this->curlLog, 22);

        $process = $this->runUpgrade();

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString(
            'git reset --hard ' . $before . ' && composer install --no-dev --no-scripts --no-interaction',
            $process->getOutput() . $process->getErrorOutput()
        );
    }

    /**
     * @param array<string, string> $env Environment that replaces the defaults.
     */
    private function runUpgrade(array $env = []): Process
    {
        $process = $this->upgradeProcess($env);
        $process->run();

        return $process;
    }

    /**
     * @param array<string, string> $env Environment that replaces the defaults.
     */
    private function upgradeProcess(array $env = []): Process
    {
        return new Process(
            [$this->site . '/bin/upgrade'],
            $this->site,
            $env + ['PATH' => $this->stubs . ':' . $this->tools, 'LAMB_UPGRADE_HEALTH_RETRY_DELAY' => '0'] + self::scrubGitEnv()
        );
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
    private function writeRepoStub(
        string $relativePath,
        string $log,
        int $exitCode = 0,
        ?string $output = null,
        ?string $storedMarker = 'code:same',
        ?string $codeMarker = 'code:same'
    ): void {
        $path = $this->seed . '/' . $relativePath;
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, self::lambStub($log, $exitCode, $output, $storedMarker, $codeMarker));
        chmod($path, 0755);
    }

    /**
     * A stand-in bin/lamb. `marker stored` and `marker code` answer with the given marker, or fail
     * like a release that has no such command (or like a refusal for a database of another
     * user) when it is null. Every other call is logged and exits with $exitCode.
     */
    private static function lambStub(string $log, int $exitCode, ?string $output, ?string $storedMarker, ?string $codeMarker): string
    {
        $answer = static fn (?string $marker): string => $marker === null ? 'exit 1' : 'echo ' . escapeshellarg($marker) . '; exit 0';

        return "#!/bin/sh\n"
            . "if [ \"\$1\" = marker ]; then\n"
            . "  case \"\$2\" in\n"
            . '    stored) ' . $answer($storedMarker) . " ;;\n"
            . '    code) ' . $answer($codeMarker) . " ;;\n"
            . "  esac\n"
            . "  exit 1\n"
            . "fi\n"
            . 'echo "$*" >> ' . escapeshellarg($log) . "\n"
            . ($output !== null ? 'echo ' . escapeshellarg($output) . "\n" : '')
            . "exit {$exitCode}\n";
    }

    /**
     * Replaces bin/lamb in the site checkout, which is what bin/upgrade runs before it resets
     * the checkout, with one whose stored marker is $storedMarker.
     */
    private function writeSiteStub(?string $storedMarker): void
    {
        file_put_contents($this->site . '/bin/lamb', self::lambStub($this->lambLog, 0, 'Data migrations: already current', $storedMarker, 'code:same'));
        chmod($this->site . '/bin/lamb', 0755);
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

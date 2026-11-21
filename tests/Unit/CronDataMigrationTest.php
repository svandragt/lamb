<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\CronProbe;
use Tests\Support\Filesystem;

use function Lamb\Bootstrap\code_marker;
use function Lamb\Network\acquire_cron_lock;
use function Lamb\Network\cron_lock_path;

/**
 * /_cron runs as the web server user, which owns the database, so it is where the data
 * migrations run after an upgrade by a user who does not own lamb.db (bin/lamb migrate
 * refuses to run for such a user). Each test sends GET /_cron through the front controller.
 *
 * A code change is simulated by storing a marker that differs from the running code's,
 * which is the state a database is in after the code under it was replaced.
 *
 * Every reply carries one `Data migrations: <result>` line, because bin/upgrade acts on it:
 * `migrated` and `already current` mean the data is current, `busy` means try again, and
 * `failed` comes with HTTP 500.
 */
class CronDataMigrationTest extends TestCase
{
    private const MARKER_OPTION = 'data_migrated_for';

    private const FAILED_OPTION = 'data_migration_failed';

    private const OLD_MARKER = 'code:the-previous-release';

    private string $dataDir;

    protected function setUp(): void
    {
        $this->dataDir = sys_get_temp_dir() . '/lamb-cron-migration-' . bin2hex(random_bytes(6));
        mkdir($this->dataDir, 0750, true);
    }

    protected function tearDown(): void
    {
        Filesystem::removeDirectory($this->dataDir);
    }

    public function testACronRequestAfterACodeChangeRunsTheMigrationsAndRecordsTheMarker(): void
    {
        $id = $this->seedStalePost();
        $this->storeOption(self::MARKER_OPTION, self::OLD_MARKER);

        $response = CronProbe::run($this->dataDir);

        $this->assertSame(0, $response['status'], $response['stderr']);
        $this->assertSame(200, $response['http']);
        $this->assertStringContainsString("Data migrations: migrated\n", $response['output']);
        $this->assertStringContainsString('upgrade_all_posts: upgraded 1 post(s)', $response['output']);
        $this->assertSame(POST_VERSION, $this->postVersion($id));
        $this->assertSame(code_marker(), $this->storedOption(self::MARKER_OPTION));
    }

    public function testACronRequestOnADatabaseThatHasNoMarkerYetRunsTheMigrations(): void
    {
        $id = $this->seedStalePost();

        $response = CronProbe::run($this->dataDir);

        $this->assertStringContainsString("Data migrations: migrated\n", $response['output']);
        $this->assertSame(POST_VERSION, $this->postVersion($id));
        $this->assertSame(code_marker(), $this->storedOption(self::MARKER_OPTION));
    }

    public function testACronRequestWithTheCurrentMarkerRunsNoMigration(): void
    {
        $id = $this->seedStalePost();
        $this->storeOption(self::MARKER_OPTION, code_marker());

        $response = CronProbe::run($this->dataDir);

        $this->assertStringContainsString("Data migrations: already current\n", $response['output']);
        $this->assertStringNotContainsString('upgrade_all_posts', $response['output']);
        $this->assertSame(1, $this->postVersion($id), 'a current marker means the migrations already ran');
        $this->assertSame(code_marker(), $this->storedOption(self::MARKER_OPTION));
    }

    public function testTheMigrationsRunBeforeTheFeedTasksAreDue(): void
    {
        CronProbe::run($this->dataDir);
        $id = $this->seedStalePost();
        $this->storeOption(self::MARKER_OPTION, self::OLD_MARKER);

        $response = CronProbe::run($this->dataDir);

        $this->assertStringContainsString('Too often', $response['output'], 'the feed tasks must not be due for this test');
        $this->assertStringContainsString('upgrade_all_posts: upgraded 1 post(s)', $response['output']);
        $this->assertSame(POST_VERSION, $this->postVersion($id));
        $this->assertSame(code_marker(), $this->storedOption(self::MARKER_OPTION));
    }

    public function testAHeldLockSkipsTheMigrationsAndReportsBusy(): void
    {
        $id = $this->seedStalePost();
        $this->storeOption(self::MARKER_OPTION, self::OLD_MARKER);
        $lock = acquire_cron_lock(cron_lock_path($this->dataDir));
        $this->assertIsResource($lock);

        $response = CronProbe::run($this->dataDir);
        fclose($lock);

        $this->assertStringContainsString("Data migrations: busy\n", $response['output']);
        $this->assertStringContainsString('Already running', $response['output']);
        $this->assertSame(200, $response['http']);
        $this->assertSame(1, $this->postVersion($id));
        $this->assertSame(self::OLD_MARKER, $this->storedOption(self::MARKER_OPTION));

        $after = CronProbe::run($this->dataDir);

        $this->assertStringContainsString('upgrade_all_posts: upgraded 1 post(s)', $after['output'], 'the next request runs them once the lock is free');
        $this->assertSame(code_marker(), $this->storedOption(self::MARKER_OPTION));
    }

    public function testAFailedMigrationAnswers500LogsLeavesTheMarkerAndStopsTheRun(): void
    {
        $this->seedUnmigratablePost();
        $this->storeOption(self::MARKER_OPTION, self::OLD_MARKER);

        $failed = CronProbe::run($this->dataDir);

        $this->assertSame(0, $failed['status'], $failed['stderr']);
        $this->assertSame(500, $failed['http'], 'a client such as bin/upgrade must see the failure');
        $this->assertStringContainsString("Data migrations: failed\n", $failed['output']);
        $this->assertStringNotContainsString('refused by the test', $failed['output'], 'the unauthenticated response must not carry the error');
        $this->assertStringContainsString('Data migration failed: ', $failed['stderr'], 'the failure is logged');
        $this->assertStringContainsString('refused by the test', $failed['stderr']);
        $this->assertStringNotContainsString('Updating feeds', $failed['output'], 'the feed cycle must not run on half-migrated data');
        $this->assertSame(self::OLD_MARKER, $this->storedOption(self::MARKER_OPTION), 'a failed run leaves the marker for a later request');
    }

    /**
     * /_cron is unauthenticated, and the migration check sits before the rate-limit gate, so
     * without a throttle every request after a failure would run the whole migration again.
     */
    public function testAFailedMigrationIsNotRetriedBeforeTheCronIntervalHasPassed(): void
    {
        $id = $this->seedUnmigratablePost();
        $this->storeOption(self::MARKER_OPTION, self::OLD_MARKER);
        CronProbe::run($this->dataDir);
        [$failedAt, $failedMarker] = explode(' ', $this->storedOption(self::FAILED_OPTION), 2);
        $this->assertEqualsWithDelta(time(), (int) $failedAt, 120, 'the time of the failure is recorded');
        $this->assertSame(code_marker(), $failedMarker, 'with the marker that failed');
        $this->inDatabase('\RedBeanPHP\R::exec("DROP TRIGGER refuse_updates");');

        $again = CronProbe::run($this->dataDir);

        $this->assertStringContainsString("Data migrations: failed\n", $again['output']);
        $this->assertSame(500, $again['http']);
        $this->assertNull($this->postVersionOrNull($id), 'the throttled request did not run the migration');
        $this->assertSame(self::OLD_MARKER, $this->storedOption(self::MARKER_OPTION));
    }

    public function testAFailedMigrationIsRetriedOnceTheCronIntervalHasPassed(): void
    {
        $id = $this->seedUnmigratablePost();
        $this->storeOption(self::MARKER_OPTION, self::OLD_MARKER);
        CronProbe::run($this->dataDir);
        $this->inDatabase('\RedBeanPHP\R::exec("DROP TRIGGER refuse_updates");');
        $this->storeOption(self::FAILED_OPTION, (time() - 61) . ' ' . code_marker());

        $retried = CronProbe::run($this->dataDir);

        $this->assertStringContainsString("Data migrations: migrated\n", $retried['output']);
        $this->assertStringContainsString('backfill_post_version: stamped version=1 on 1 post(s)', $retried['output']);
        $this->assertSame(POST_VERSION, $this->postVersionOrNull($id));
        $this->assertSame(code_marker(), $this->storedOption(self::MARKER_OPTION));
        $this->assertSame('', $this->storedOption(self::FAILED_OPTION), 'a success clears the failure record');
    }

    public function testACodeChangeMigratesAtOnceEvenRightAfterAFailureOfEarlierCode(): void
    {
        $id = $this->seedStalePost();
        $this->storeOption(self::MARKER_OPTION, self::OLD_MARKER);
        $this->storeOption(self::FAILED_OPTION, time() . ' code:an-earlier-release');

        $response = CronProbe::run($this->dataDir);

        $this->assertStringContainsString("Data migrations: migrated\n", $response['output']);
        $this->assertSame(POST_VERSION, $this->postVersion($id));
    }

    /**
     * A 30-minute feed run must not hold the client of a request that came for the migrations.
     */
    public function testTheResponseEndsAfterTheMigrationStepBeforeTheFeedTasks(): void
    {
        $this->seedStalePost();
        $this->storeOption(self::MARKER_OPTION, self::OLD_MARKER);

        $response = CronProbe::run($this->dataDir, fastcgi: true);

        $this->assertStringContainsString("Data migrations: migrated\n", $response['output']);
        $this->assertStringNotContainsString('Updating feeds', $response['output'], 'the client must not wait for the feed cycle');
        $this->assertStringContainsString('Updating feeds', $response['unsent'], 'the feed cycle still runs');
    }

    public function testAFailureStopsTheRunBeforeTheFeedTasksToo(): void
    {
        $this->seedUnmigratablePost();
        $this->storeOption(self::MARKER_OPTION, self::OLD_MARKER);

        $response = CronProbe::run($this->dataDir, fastcgi: true);

        $this->assertSame(500, $response['http']);
        $this->assertStringContainsString("Data migrations: failed\n", $response['output']);
        $this->assertStringNotContainsString('Updating feeds', $response['output'] . $response['unsent']);
    }

    /**
     * Only a request that migrated has a reason to end early. An ordinary caller keeps the
     * feed output ("Done", "Too often", errors) it has always received.
     */
    public function testARequestThatMigratedNothingKeepsTheFeedOutput(): void
    {
        $this->storeOption(self::MARKER_OPTION, code_marker());

        $response = CronProbe::run($this->dataDir, fastcgi: true);

        $this->assertStringContainsString("Data migrations: already current\n", $response['output']);
        $this->assertStringContainsString('Updating feeds', $response['output'], 'the feed output still reaches the caller');
        $this->assertStringContainsString('Done', $response['output']);
        $this->assertSame('', $response['unsent'], 'the response was not ended early');
    }

    /**
     * @return array<string, array{string, string, int}> The database state, the result line and
     *     the HTTP status.
     */
    public static function migrationStates(): array
    {
        return [
            'the data is current' => ['current', 'already current', 200],
            'a migration runs' => ['stale', 'migrated', 200],
            'a migration fails' => ['failed', 'failed', 500],
        ];
    }

    /**
     * bin/upgrade asks for `/_cron?migrations`: the migration step under the lock, the result
     * line, and the end of the request. A feed cycle can run 30 minutes, and a client that came
     * for the migrations must not wait for it whatever the result. This holds without
     * fastcgi_finish_request() too, which only some servers have.
     *
     * @dataProvider migrationStates
     */
    public function testAMigrationsOnlyRequestEndsAfterTheResultLine(string $state, string $line, int $http): void
    {
        if ($state === 'current') {
            $this->storeOption(self::MARKER_OPTION, code_marker());
        } else {
            if ($state === 'failed') {
                $this->seedUnmigratablePost();
            } else {
                $this->seedStalePost();
            }
            $this->storeOption(self::MARKER_OPTION, self::OLD_MARKER);
        }

        $response = CronProbe::run($this->dataDir, query: 'migrations');

        $this->assertSame(0, $response['status'], $response['stderr']);
        $this->assertSame($http, $response['http']);
        $this->assertStringContainsString("Data migrations: {$line}\n", $response['output']);
        $this->assertStringNotContainsString('Updating feeds', $response['output'], 'no feed cycle');
        $this->assertStringNotContainsString('Done', $response['output'], 'no run to finish');
        $this->assertStringNotContainsString('Too often', $response['output']);
        $this->assertSame('', $response['unsent']);
    }

    public function testAMigrationsOnlyRequestStillRunsTheMigrationsAndRecordsTheMarker(): void
    {
        $id = $this->seedStalePost();
        $this->storeOption(self::MARKER_OPTION, self::OLD_MARKER);

        CronProbe::run($this->dataDir, query: 'migrations');

        $this->assertSame(POST_VERSION, $this->postVersion($id));
        $this->assertSame(code_marker(), $this->storedOption(self::MARKER_OPTION));
    }

    public function testAMigrationsOnlyRequestAnswersBusyWhileTheLockIsHeld(): void
    {
        $lock = acquire_cron_lock(cron_lock_path($this->dataDir));
        $this->assertIsResource($lock);

        $response = CronProbe::run($this->dataDir, query: 'migrations');
        fclose($lock);

        $this->assertStringContainsString("Data migrations: busy\n", $response['output']);
        $this->assertSame(200, $response['http']);
    }

    /**
     * A busy answer means the running code is older than the files, so a feed cycle would run
     * old code over data nobody migrated. It stops the run, as a failure does.
     */
    public function testAStaleCodeCacheAnswersBusyAndStopsTheRunBeforeTheFeedTasks(): void
    {
        $id = $this->seedStalePost();
        $this->storeOption(self::MARKER_OPTION, self::OLD_MARKER);

        $response = CronProbe::run($this->dataDir, fastcgi: true, staleCode: true);

        $this->assertSame(0, $response['status'], $response['stderr']);
        $this->assertSame(200, $response['http']);
        $this->assertStringContainsString("Data migrations: busy\n", $response['output']);
        $this->assertStringNotContainsString('Updating feeds', $response['output'] . $response['unsent']);
        $this->assertStringNotContainsString('Done', $response['output'] . $response['unsent']);
        $this->assertSame(1, $this->postVersion($id), 'nothing migrated');
        $this->assertSame(self::OLD_MARKER, $this->storedOption(self::MARKER_OPTION));
    }

    /**
     * Seeds a post stored before the current POST_VERSION, in the data directory's own
     * database. The child process owns the connection, as the probe's requests do.
     */
    private function seedStalePost(): int
    {
        return (int) $this->inDatabase(
            '$bean = \RedBeanPHP\R::dispense("post"); $bean->body = "plain body"; $bean->version = 1; '
            . 'echo \RedBeanPHP\R::store($bean);'
        );
    }

    /**
     * Seeds a post with no version, which backfill_post_version() has to UPDATE, and a trigger
     * that refuses every UPDATE of `post`.
     */
    private function seedUnmigratablePost(): int
    {
        return (int) $this->inDatabase(
            '\RedBeanPHP\R::exec("INSERT INTO post (body) VALUES (\'unversioned\')"); '
            . '$id = \RedBeanPHP\R::getCell("SELECT last_insert_rowid()"); '
            . '\RedBeanPHP\R::exec("CREATE TRIGGER refuse_updates BEFORE UPDATE ON post BEGIN SELECT RAISE(ABORT, \'refused by the test\'); END"); '
            . 'echo $id;'
        );
    }

    private function postVersion(int $id): int
    {
        return (int) $this->postVersionOrNull($id);
    }

    private function postVersionOrNull(int $id): ?int
    {
        $version = $this->inDatabase('echo \RedBeanPHP\R::getCell("SELECT version FROM post WHERE id = ?", [' . $id . ']);');

        return $version === '' ? null : (int) $version;
    }

    private function storeOption(string $name, string $value): void
    {
        $this->inDatabase(
            '$bean = \RedBeanPHP\R::findOneOrDispense("option", " name = ? ", [getenv("SEED_NAME")]); '
            . '$bean->name = getenv("SEED_NAME"); $bean->value = getenv("SEED_VALUE"); \RedBeanPHP\R::store($bean);',
            ['SEED_NAME' => $name, 'SEED_VALUE' => $value]
        );
    }

    private function storedOption(string $name): string
    {
        return $this->inDatabase(
            'echo \RedBeanPHP\R::getCell("SELECT value FROM option WHERE name = ?", [getenv("SEED_NAME")]);',
            ['SEED_NAME' => $name]
        );
    }

    /**
     * Runs PHP against the data directory's database in its own process, so this process never
     * holds a connection that a request would have to wait for.
     *
     * @param string $code PHP to run once the database is bootstrapped; it may echo a result.
     * @param array<string, string> $env Extra environment for the process.
     */
    private function inDatabase(string $code, array $env = []): string
    {
        $root = rtrim(codecept_root_dir(), '/');
        $process = new Process(
            [
                PHP_BINARY,
                '-r',
                "define('ROOT_DIR', '$root/src'); require '$root/vendor/autoload.php'; "
                . "\\Lamb\\Bootstrap\\bootstrap_db(getenv('LAMB_DATA_DIR')); " . $code,
            ],
            $root,
            ['LAMB_DATA_DIR' => $this->dataDir] + $env + getenv(),
        );
        $process->mustRun();

        return $process->getOutput();
    }
}

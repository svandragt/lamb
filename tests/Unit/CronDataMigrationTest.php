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
 */
class CronDataMigrationTest extends TestCase
{
    private const MARKER_OPTION = 'data_migrated_for';

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
        $this->storeMarker('code:the-previous-release');

        $response = CronProbe::run($this->dataDir);

        $this->assertSame(0, $response['status'], $response['stderr']);
        $this->assertStringContainsString('upgrade_all_posts: upgraded 1 post(s)', $response['output']);
        $this->assertSame(POST_VERSION, $this->postVersion($id));
        $this->assertSame(code_marker(), $this->storedMarker());
    }

    public function testACronRequestOnADatabaseThatHasNoMarkerYetRunsTheMigrations(): void
    {
        $id = $this->seedStalePost();

        $response = CronProbe::run($this->dataDir);

        $this->assertStringContainsString('upgrade_all_posts: upgraded 1 post(s)', $response['output']);
        $this->assertSame(POST_VERSION, $this->postVersion($id));
        $this->assertSame(code_marker(), $this->storedMarker());
    }

    public function testACronRequestWithTheCurrentMarkerRunsNoMigration(): void
    {
        $id = $this->seedStalePost();
        $this->storeMarker(code_marker());

        $response = CronProbe::run($this->dataDir);

        $this->assertStringNotContainsString('upgrade_all_posts', $response['output']);
        $this->assertSame(1, $this->postVersion($id), 'a current marker means the migrations already ran');
        $this->assertSame(code_marker(), $this->storedMarker());
    }

    public function testTheMigrationsRunBeforeTheFeedTasksAreDue(): void
    {
        CronProbe::run($this->dataDir);
        $id = $this->seedStalePost();
        $this->storeMarker('code:the-previous-release');

        $response = CronProbe::run($this->dataDir);

        $this->assertStringContainsString('Too often', $response['output'], 'the feed tasks must not be due for this test');
        $this->assertStringContainsString('upgrade_all_posts: upgraded 1 post(s)', $response['output']);
        $this->assertSame(POST_VERSION, $this->postVersion($id));
        $this->assertSame(code_marker(), $this->storedMarker());
    }

    public function testAHeldLockSkipsTheMigrations(): void
    {
        $id = $this->seedStalePost();
        $this->storeMarker('code:the-previous-release');
        $lock = acquire_cron_lock(cron_lock_path($this->dataDir));
        $this->assertIsResource($lock);

        $response = CronProbe::run($this->dataDir);
        fclose($lock);

        $this->assertStringContainsString('Already running', $response['output']);
        $this->assertSame(1, $this->postVersion($id));
        $this->assertSame('code:the-previous-release', $this->storedMarker());

        $after = CronProbe::run($this->dataDir);

        $this->assertStringContainsString('upgrade_all_posts: upgraded 1 post(s)', $after['output'], 'the next request runs them once the lock is free');
        $this->assertSame(code_marker(), $this->storedMarker());
    }

    public function testAFailedMigrationIsLoggedLeavesTheMarkerAndIsRetriedByTheNextRequest(): void
    {
        // A post with no version makes backfill_post_version() UPDATE; the trigger refuses it.
        $this->inDatabase('\RedBeanPHP\R::exec("INSERT INTO post (body) VALUES (\'unversioned\')"); '
            . '\RedBeanPHP\R::exec("CREATE TRIGGER refuse_updates BEFORE UPDATE ON post BEGIN SELECT RAISE(ABORT, \'refused by the test\'); END");');
        $this->storeMarker('code:the-previous-release');

        $failed = CronProbe::run($this->dataDir);

        $this->assertSame(0, $failed['status'], $failed['stderr']);
        $this->assertStringContainsString('Data migration failed', $failed['output']);
        $this->assertStringNotContainsString('refused by the test', $failed['output'], 'the unauthenticated response must not carry the error');
        $this->assertStringContainsString('Data migration failed: ', $failed['stderr'], 'the failure is logged');
        $this->assertStringContainsString('refused by the test', $failed['stderr']);
        $this->assertStringContainsString('Updating feeds', $failed['output'], 'the other cron tasks still run');
        $this->assertSame('code:the-previous-release', $this->storedMarker(), 'a failed run leaves the marker for the next request');

        $this->inDatabase('\RedBeanPHP\R::exec("DROP TRIGGER refuse_updates");');
        $retried = CronProbe::run($this->dataDir);

        $this->assertStringContainsString('backfill_post_version: stamped version=1 on 1 post(s)', $retried['output']);
        $this->assertSame(code_marker(), $this->storedMarker());
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

    private function postVersion(int $id): int
    {
        return (int) $this->inDatabase('echo (int) \RedBeanPHP\R::load("post", ' . $id . ')->version;');
    }

    private function storeMarker(string $marker): void
    {
        $this->inDatabase(
            '$bean = \RedBeanPHP\R::findOneOrDispense("option", " name = ? ", ["' . self::MARKER_OPTION . '"]); '
            . '$bean->name = "' . self::MARKER_OPTION . '"; $bean->value = getenv("SEED_MARKER"); \RedBeanPHP\R::store($bean);',
            ['SEED_MARKER' => $marker]
        );
    }

    private function storedMarker(): string
    {
        return $this->inDatabase(
            'echo \RedBeanPHP\R::getCell("SELECT value FROM option WHERE name = ?", ["' . self::MARKER_OPTION . '"]);'
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

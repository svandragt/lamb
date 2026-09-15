<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;
use Symfony\Component\Process\Process;

use function Lamb\Bootstrap\ensure_schema;

use const Lamb\Bootstrap\SCHEMA;

/**
 * Covers the write-lock gates added for issue #831: a database whose schema
 * (and journal mode) are already current must boot as a pure reader, because
 * SQLite's WAL mode fails writes when the -shm sidecar is not writable by the
 * current user, and a boot that always takes a write lock always hits that.
 *
 * ensure_post_indexes() and the backfill_*() probes already establish the
 * pattern (read sqlite_master/PRAGMA first, only issue DDL when it would
 * actually change something); these tests cover the two boot-path spots that
 * still wrote unconditionally: ensure_schema()'s CREATE TABLE, and
 * bootstrap_db()'s PRAGMA journal_mode=WAL.
 */
class BootWriteGateTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::nuke();
    }

    /**
     * The DDL a callable issues, captured via RedBeanPHP's array logger.
     *
     * @return list<string>
     */
    private function ddlDuring(callable $fn): array
    {
        R::debug(true, \RedBeanPHP\Logger\RDefault::C_LOGGER_ARRAY);
        try {
            $fn();
            $logs = R::getDatabaseAdapter()->getDatabase()->getLogger()->getLogs();
        } finally {
            R::debug(false);
        }

        return array_values(array_filter(
            $logs,
            fn ($line) => is_string($line) && preg_match('/^\s*(CREATE|ALTER)\b/i', $line) === 1
        ));
    }

    public function testEnsureSchemaIssuesNoDdlOnASecondCallAgainstACurrentDatabase(): void
    {
        ensure_schema();

        $this->assertSame([], $this->ddlDuring(fn () => ensure_schema()));
    }

    public function testEnsureSchemaStillCreatesATableThatIsGenuinelyMissing(): void
    {
        ensure_schema();
        R::exec('DROP TABLE redirect');

        $ddl = $this->ddlDuring(fn () => ensure_schema());

        $this->assertNotSame([], $ddl);
        $this->assertNotNull(R::getCell("SELECT name FROM sqlite_master WHERE type='table' AND name='redirect'"));
    }

    public function testEnsureSchemaCreatesEveryDeclaredTableFromScratch(): void
    {
        ensure_schema();

        foreach (array_keys(SCHEMA) as $table) {
            $this->assertNotNull(
                R::getCell("SELECT name FROM sqlite_master WHERE type='table' AND name=?", [$table]),
                "Missing table: $table"
            );
        }
    }

    /**
     * ensure_wal_journal_mode() needs a real file-backed connection (SQLite's
     * :memory: databases always report journal_mode "memory", never "wal",
     * so the shared in-process connection the rest of this file uses can't
     * exercise it) and R::setup() throws if called twice in one process, so
     * this runs in a subprocess that sets up its own file-backed connection
     * (mirrors SchemaFreezeTest / LoginThrottleTest's WAL check).
     */
    public function testEnsureWalJournalModeSetsItOnceAndLeavesItWalOnASecondCall(): void
    {
        $dir = sys_get_temp_dir() . '/lamb_boot_write_gate_test_' . uniqid();
        mkdir($dir, 0750, true);

        $script = <<<'PHP'
        use RedBeanPHP\R;

        require "vendor/autoload.php";

        R::setup('sqlite:' . $argv[1] . '/lamb.db');

        R::debug(true, \RedBeanPHP\Logger\RDefault::C_LOGGER_ARRAY);
        \Lamb\Bootstrap\ensure_wal_journal_mode();
        $first_logs = R::getDatabaseAdapter()->getDatabase()->getLogger()->getLogs();
        R::debug(false);

        // The read-only probe ("PRAGMA journal_mode") runs on every call;
        // only the assignment form ("PRAGMA journal_mode=WAL") is the write
        // a second, already-current call must not repeat.
        $is_pragma_write = fn ($line) => is_string($line) && stripos($line, 'journal_mode=') !== false;

        if (array_filter($first_logs, $is_pragma_write) === []) {
            fwrite(STDERR, "FIRST CALL DID NOT SET THE PRAGMA\n");
            exit(1);
        }

        R::debug(true, \RedBeanPHP\Logger\RDefault::C_LOGGER_ARRAY);
        \Lamb\Bootstrap\ensure_wal_journal_mode();
        $second_logs = R::getDatabaseAdapter()->getDatabase()->getLogger()->getLogs();
        R::debug(false);

        if (array_filter($second_logs, $is_pragma_write) !== []) {
            fwrite(STDERR, "SECOND CALL RE-ISSUED THE PRAGMA\n");
            exit(1);
        }

        $mode = (string) R::getCell('PRAGMA journal_mode');
        if (strtolower($mode) !== 'wal') {
            fwrite(STDERR, "MODE WAS $mode, NOT wal\n");
            exit(1);
        }

        echo "OK\n";
        PHP;

        $boot = new Process(['php', '-r', $script, $dir]);
        $boot->run();

        @unlink($dir . '/lamb.db');
        @unlink($dir . '/lamb.db-wal');
        @unlink($dir . '/lamb.db-shm');
        @rmdir($dir);

        $this->assertSame(
            "OK\n",
            $boot->getOutput(),
            "stdout:\n" . $boot->getOutput() . "\nstderr:\n" . $boot->getErrorOutput()
        );
    }
}

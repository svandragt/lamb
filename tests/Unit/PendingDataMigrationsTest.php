<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Bootstrap\run_pending_data_migrations;

/**
 * run_pending_data_migrations() answers anyone through /_cron, so a database error while it
 * reads the marker or the option must come back as a `failed` result, not as an uncaught
 * exception that ends the request with a stack trace.
 */
class PendingDataMigrationsTest extends TestCase
{
    private string|false $previousErrorLog;

    private string $errorLog;

    protected function setUp(): void
    {
        $this->previousErrorLog = ini_get('error_log');
        $this->errorLog = sys_get_temp_dir() . '/lamb-pending-migrations-' . bin2hex(random_bytes(6)) . '.log';
        ini_set('error_log', $this->errorLog);
    }

    protected function tearDown(): void
    {
        R::freeze(false);
        ini_set('error_log', (string) $this->previousErrorLog);
        @unlink($this->errorLog);
    }

    public function testADatabaseErrorWhileReadingTheOptionIsAFailedResult(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::nuke();
        R::freeze(true); // A missing table is then an error, not an empty result.

        $reply = run_pending_data_migrations();

        $this->assertSame('failed', $reply['result']);
        $this->assertSame(['Data migration failed, see the error log.'], $reply['lines']);
        $this->assertStringContainsString('Data migration failed: ', (string) file_get_contents($this->errorLog));
    }
}

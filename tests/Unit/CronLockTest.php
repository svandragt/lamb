<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Bootstrap\data_dir;
use function Lamb\Network\acquire_cron_lock;
use function Lamb\Network\cron_lock_path;

/**
 * The /_cron lock has to live in the install's own data directory. It used to
 * be hardcoded to `../data`, so an install with LAMB_DATA_DIR set had no such
 * directory, the lock file could never be opened, and every run was reported as
 * "Already running" — feeds, the webmention queue and the purge silently never
 * ran again.
 */
class CronLockTest extends TestCase
{
    private string $tmp;
    private string|false $previous_env;

    protected function setUp(): void
    {
        $this->previous_env = getenv('LAMB_DATA_DIR');
        $this->tmp = sys_get_temp_dir() . '/lamb-cron-lock-' . bin2hex(random_bytes(6));
        mkdir($this->tmp, 0755, true);
    }

    protected function tearDown(): void
    {
        if ($this->previous_env === false) {
            putenv('LAMB_DATA_DIR');
        } else {
            putenv('LAMB_DATA_DIR=' . $this->previous_env);
        }
        array_map('unlink', glob($this->tmp . '/*') ?: []);
        @rmdir($this->tmp);
    }

    public function testDataDirDefaultsToTheWebRootSibling(): void
    {
        putenv('LAMB_DATA_DIR');
        $this->assertSame('../data', data_dir());
    }

    public function testDataDirDefaultsToACliBaseSiblingForCliEntryPoints(): void
    {
        // The two defaults must stay distinct: the web default is relative to
        // src/, a CLI script's is relative to the repo root it passes in.
        putenv('LAMB_DATA_DIR');
        $this->assertSame('/opt/lamb/data', data_dir('/opt/lamb'));
    }

    public function testDataDirHonoursTheEnvironmentOverride(): void
    {
        putenv('LAMB_DATA_DIR=' . $this->tmp);
        $this->assertSame($this->tmp, data_dir());
    }

    public function testDataDirEnvironmentOverrideWinsOverTheCliBase(): void
    {
        putenv('LAMB_DATA_DIR=' . $this->tmp);
        $this->assertSame($this->tmp, data_dir('/opt/lamb'));
    }

    public function testCronLockLivesInTheConfiguredDataDir(): void
    {
        putenv('LAMB_DATA_DIR=' . $this->tmp);
        $this->assertSame($this->tmp . '/cron.lock', cron_lock_path());
    }

    public function testLockIsAcquiredThenContended(): void
    {
        putenv('LAMB_DATA_DIR=' . $this->tmp);

        $first = acquire_cron_lock();
        $this->assertIsResource($first);
        $this->assertFileExists(cron_lock_path());

        // A second run while the first still holds it: contention, not failure.
        $this->assertNull(acquire_cron_lock());

        fclose($first);
        $second = acquire_cron_lock();
        $this->assertIsResource($second);
        fclose($second);
    }

    public function testUnopenableLockFileIsNotReportedAsContention(): void
    {
        $missing = $this->tmp . '/no-such-directory/cron.lock';

        $this->assertFalse(acquire_cron_lock($missing));
    }
}

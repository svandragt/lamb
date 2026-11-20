<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\Filesystem;

/**
 * Several requests can reach a brand-new data directory at the same moment.
 * Each one boots it with bootstrap_db(), and every one of them must succeed:
 * switching the file to WAL needs a lock another booting request may hold.
 * Each request is a child process, released together by a barrier file.
 */
class ConcurrentFirstBootTest extends TestCase
{
    private const REQUESTS = 4;
    private const ROUNDS = 12;

    private const SCRIPT = <<<'PHP'
    require $argv[1];
    touch($argv[3] . '.ready' . $argv[4]);
    while (!file_exists($argv[3])) {
        usleep(1000);
    }
    \Lamb\Bootstrap\bootstrap_db($argv[2]);
    echo \RedBeanPHP\R::getCell('PRAGMA journal_mode');
    PHP;

    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            is_dir($path) ? Filesystem::removeDirectory($path) : @unlink($path);
        }
    }

    public function testRequestsBootingANewDataDirectoryTogetherAllSucceedInWalMode(): void
    {
        $failures = [];
        for ($round = 0; $round < self::ROUNDS; $round++) {
            $dir = sys_get_temp_dir() . '/lamb_concurrent_boot_test_' . uniqid();
            $barrier = $dir . '.go';
            $this->paths[] = $dir;
            mkdir($dir . '/data', 0750, true);
            $this->paths[] = $barrier;

            $children = [];
            for ($i = 0; $i < self::REQUESTS; $i++) {
                $child = new Process([PHP_BINARY, '-d', 'display_errors=stderr', '-r', self::SCRIPT, dirname(__DIR__, 2) . '/vendor/autoload.php', $dir . '/data', $barrier, (string) $i]);
                $this->paths[] = $barrier . '.ready' . $i;
                $child->start();
                $children[] = $child;
            }
            $deadline = microtime(true) + 30;
            while (count(glob($barrier . '.ready*')) < self::REQUESTS) {
                $this->assertLessThan($deadline, microtime(true), 'timed out waiting for the child processes');
                usleep(2000);
            }
            touch($barrier);

            foreach ($children as $child) {
                $child->wait();
                if ($child->getExitCode() !== 0 || $child->getOutput() !== 'wal') {
                    $failures[] = "round $round exit " . $child->getExitCode() . ': ' . trim($child->getErrorOutput() . $child->getOutput());
                }
            }
        }

        $this->assertSame([], $failures, count($failures) . ' of ' . (self::ROUNDS * self::REQUESTS) . ' boots failed');
    }

    public function testADataDirectoryThatCannotBeCreatedThrows(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('root ignores directory permissions');
        }
        $parent = sys_get_temp_dir() . '/lamb_readonly_parent_' . uniqid();
        mkdir($parent, 0550);
        $this->paths[] = $parent;

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('was not created');
            \Lamb\Bootstrap\bootstrap_db($parent . '/data');
        } finally {
            chmod($parent, 0750);
        }
    }
}

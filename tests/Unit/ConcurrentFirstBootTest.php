<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Several requests can reach a brand-new data directory at the same moment.
 * Each one boots it with bootstrap_db(), and every one of them must succeed:
 * switching the file to WAL needs a lock another booting request may hold.
 * Each request is a child process, released together by a barrier file.
 */
class ConcurrentFirstBootTest extends TestCase
{
    private const REQUESTS = 4;
    private const ROUNDS = 25;

    private const SCRIPT = <<<'PHP'
    require $argv[1];
    while (!file_exists($argv[3])) {
        usleep(100);
    }
    \Lamb\Bootstrap\bootstrap_db($argv[2]);
    echo \RedBeanPHP\R::getCell('PRAGMA journal_mode');
    PHP;

    /** @var list<string> */
    private array $paths = [];

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            (new Process(['rm', '-rf', $path]))->run();
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
                $child = new Process(['php', '-d', 'display_errors=stderr', '-r', self::SCRIPT, dirname(__DIR__, 2) . '/vendor/autoload.php', $dir . '/data', $barrier]);
                $child->start();
                $children[] = $child;
            }
            usleep(400000);
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
}

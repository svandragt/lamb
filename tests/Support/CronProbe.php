<?php

namespace Tests\Support;

use Symfony\Component\Process\Process;

/**
 * Sends one GET /_cron request through the real front controller, src/index.php, in its own
 * `php` process, and returns what the request printed.
 *
 * /_cron ends with exit(), which PHPUnit cannot survive in-process, and index.php defines
 * constants that a second request in the same process would redefine. The child runs from the
 * CLI over the given data directory, so the database, the lock file and the stored options
 * persist from one call to the next. No network or port is involved: a probe site has no
 * feeds, so the run makes no outbound request.
 */
final class CronProbe
{
    private const TIMEOUT_SECONDS = 60;

    /**
     * Runs one request.
     *
     * @param string $dataDir The LAMB_DATA_DIR for the request.
     * @return array{output: string, stderr: string, status: int|null} The response body, what PHP
     *     and error_log() wrote to stderr, and the exit code.
     */
    public static function run(string $dataDir): array
    {
        $script = sys_get_temp_dir() . '/lamb-cron-probe-' . uniqid() . '.php';
        file_put_contents($script, self::script());

        $process = new Process(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'xdebug.mode=off', $script],
            dirname(__DIR__, 2) . '/src',
            ['LAMB_DATA_DIR' => $dataDir, 'LAMB_LOGIN_PASSWORD' => false, 'LAMB_SITE_URL' => false]
        );
        $process->setTimeout(self::TIMEOUT_SECONDS);
        $process->run();
        unlink($script);

        return [
            'output' => $process->getOutput(),
            'stderr' => $process->getErrorOutput(),
            'status' => $process->getExitCode(),
        ];
    }

    /**
     * The PHP source of the child process.
     *
     * @return string
     */
    private static function script(): string
    {
        return strtr(self::TEMPLATE, ['__INDEX__' => var_export(dirname(__DIR__, 2) . '/src/index.php', true)]);
    }

    private const TEMPLATE = <<<'PHP'
<?php
$_SERVER = array_merge($_SERVER, [
    'REQUEST_METHOD'  => 'GET',
    'REQUEST_URI'     => '/_cron',
    'SERVER_PROTOCOL' => 'HTTP/1.1',
    'REMOTE_ADDR'     => '127.0.0.1',
    'HTTP_HOST'       => 'example.test',
]);

require __INDEX__;
PHP;
}

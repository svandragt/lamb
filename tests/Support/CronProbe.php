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
 *
 * The CLI has no fastcgi_finish_request(), so under PHP-FPM or FrankenPHP the client stops
 * receiving output at the call. With `$fastcgi` the child defines that function and prints a
 * marker where it is called, which splits the output into what the client received and what
 * it did not.
 */
final class CronProbe
{
    private const TIMEOUT_SECONDS = 60;

    private const META_MARKER = "\n===META===\n";

    private const FINISHED_MARKER = "\n===FINISHED===\n";

    /**
     * Runs one request.
     *
     * @param string $dataDir The LAMB_DATA_DIR for the request.
     * @param bool $fastcgi Whether the child has a fastcgi_finish_request().
     * @return array{output: string, unsent: string, stderr: string, status: int|null, http: int} What
     *     the client received, what the request printed after fastcgi_finish_request() (always ''
     *     without `$fastcgi`), what PHP and error_log() wrote to stderr, the exit code, and the HTTP
     *     response code.
     */
    public static function run(string $dataDir, bool $fastcgi = false): array
    {
        $script = sys_get_temp_dir() . '/lamb-cron-probe-' . uniqid() . '.php';
        file_put_contents($script, self::script($fastcgi));

        $process = new Process(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'xdebug.mode=off', $script],
            dirname(__DIR__, 2) . '/src',
            ['LAMB_DATA_DIR' => $dataDir, 'LAMB_LOGIN_PASSWORD' => false, 'LAMB_SITE_URL' => false]
        );
        $process->setTimeout(self::TIMEOUT_SECONDS);
        $process->run();
        unlink($script);

        [$printed, $metaJson] = array_pad(explode(self::META_MARKER, $process->getOutput(), 2), 2, '{}');
        [$output, $unsent] = array_pad(explode(self::FINISHED_MARKER, $printed, 2), 2, '');
        $meta = json_decode(trim($metaJson), true) ?: [];

        return [
            'output' => $output,
            'unsent' => $unsent,
            'stderr' => $process->getErrorOutput(),
            'status' => $process->getExitCode(),
            'http'   => (int) ($meta['http'] ?? 0),
        ];
    }

    /**
     * The PHP source of the child process.
     *
     * @param bool $fastcgi Whether to define fastcgi_finish_request().
     * @return string
     */
    private static function script(bool $fastcgi): string
    {
        return strtr(self::TEMPLATE, [
            '__INDEX__'    => var_export(dirname(__DIR__, 2) . '/src/index.php', true),
            '__FASTCGI__'  => var_export($fastcgi, true),
            '__FINISHED__' => var_export(self::FINISHED_MARKER, true),
            '__META__'     => var_export(self::META_MARKER, true),
        ]);
    }

    private const TEMPLATE = <<<'PHP'
<?php
if (__FASTCGI__) {
    function fastcgi_finish_request(): bool
    {
        echo __FINISHED__;
        return true;
    }
}

register_shutdown_function(function () {
    echo __META__ . json_encode(['http' => http_response_code() ?: 200]);
});

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

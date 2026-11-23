<?php

namespace Tests\Support;

use Symfony\Component\Process\Process;

/**
 * Sends one request, by default GET /_cron, through the real front controller, src/index.php,
 * in its own `php` process, and returns what the request printed.
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
 *
 * `$query` is the request's query string, which PHP would put into $_GET.
 *
 * The CLI ignores header(), so the child records the headers that code in `Lamb\Network` sets,
 * each with the number of bytes the request had printed by then.
 *
 * The CLI also keeps no response cookies. The child therefore defines Lamb\Response\setcookie(),
 * which PHP resolves before the built-in one for calls inside that namespace, and records the
 * cookies Lamb sets itself. PHP's session module writes the LAMBSESSID header in C, which
 * nothing can intercept, so the child reports session_get_cookie_params() instead: the module
 * takes the header's attributes from it.
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
     * @param string $query The query string, without the question mark.
     * @param array{
     *     method?: string,
     *     uri?: string,
     *     host?: string,
     *     https?: string,
     *     post?: array<string, string>,
     *     cookies?: array<string, string>,
     *     site_url?: string,
     *     settings?: string,
     *     login_hash?: string
     * } $request Defaults to GET /_cron on host example.test. `host` is the Host header and `https`
     *     the HTTPS server variable. `site_url` is LAMB_SITE_URL, `settings` the INI text saved
     *     before the request, and `login_hash` LAMB_LOGIN_PASSWORD.
     * @return array{
     *     output: string,
     *     unsent: string,
     *     stderr: string,
     *     status: int|null,
     *     http: int,
     *     headers: list<array{string, int}>,
     *     cookies: array<string, array{value: string, secure: bool}>,
     *     session: array{started: bool, secure: bool},
     *     root_url: string|null
     * } What the client received, what the request printed after fastcgi_finish_request() (always
     *     '' without `$fastcgi`), what PHP and error_log() wrote to stderr, the exit code, the HTTP
     *     response code, the headers set from `Lamb\Network` with the bytes printed before each, the
     *     cookies Lamb set, the session (whether the request started one, and the Secure attribute
     *     its cookie gets) and the ROOT_URL that absolute links are built from.
     */
    public static function run(string $dataDir, bool $fastcgi = false, string $query = '', array $request = []): array
    {
        if (isset($request['settings'])) {
            self::seedSettings($dataDir, $request['settings']);
        }

        $script = sys_get_temp_dir() . '/lamb-cron-probe-' . uniqid() . '.php';
        file_put_contents($script, self::script($fastcgi, $query, $request));

        $process = new Process(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'xdebug.mode=off', $script],
            dirname(__DIR__, 2) . '/src',
            [
                'LAMB_DATA_DIR' => $dataDir,
                'LAMB_LOGIN_PASSWORD' => $request['login_hash'] ?? false,
                'LAMB_SITE_URL' => $request['site_url'] ?? false,
            ]
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
            'headers' => $meta['headers'] ?? [],
            'cookies' => $meta['cookies'] ?? [],
            'session' => $meta['session'] ?? ['started' => false, 'secure' => false],
            'root_url' => $meta['root_url'] ?? null,
        ];
    }

    /**
     * Saves INI text as the site's settings, as the /settings form would.
     *
     * @param string $dataDir The data directory holding the database.
     * @param string $ini     The INI text.
     * @return void
     */
    private static function seedSettings(string $dataDir, string $ini): void
    {
        $process = new Process([
            PHP_BINARY,
            '-r',
            'require $argv[1]; \Lamb\Bootstrap\bootstrap_db($argv[2]); \Lamb\Config\save_ini_text($argv[3]);',
            dirname(__DIR__, 2) . '/vendor/autoload.php',
            $dataDir,
            $ini,
        ]);
        $process->mustRun();
    }

    /**
     * The PHP source of the child process.
     *
     * @param bool $fastcgi Whether to define fastcgi_finish_request().
     * @param string $query The query string, without the question mark.
     * @param array<string, mixed> $request The request, as for run().
     * @return string
     */
    private static function script(bool $fastcgi, string $query, array $request): string
    {
        $server = [
            'REQUEST_METHOD'  => $request['method'] ?? 'GET',
            'REQUEST_URI'     => $request['uri'] ?? '/_cron' . ($query === '' ? '' : '?' . $query),
            'QUERY_STRING'    => $query,
            'SERVER_PROTOCOL' => 'HTTP/1.1',
            'REMOTE_ADDR'     => '127.0.0.1',
            'HTTP_HOST'       => $request['host'] ?? 'example.test',
        ];
        if (isset($request['https'])) {
            $server['HTTPS'] = $request['https'];
        }

        return strtr(self::TEMPLATE, [
            '__INDEX__'    => var_export(dirname(__DIR__, 2) . '/src/index.php', true),
            '__FASTCGI__'  => var_export($fastcgi, true),
            '__QUERY__'    => var_export($query, true),
            '__SERVER__'   => var_export($server, true),
            '__POST__'     => var_export($request['post'] ?? [], true),
            '__COOKIES__'  => var_export($request['cookies'] ?? [], true),
            '__FINISHED__' => var_export(self::FINISHED_MARKER, true),
            '__META__'     => var_export(self::META_MARKER, true),
        ]);
    }

    private const TEMPLATE = <<<'PHP'
<?php
namespace Lamb\Response {
    function setcookie(string $name, string $value = '', array|int $options = 0): bool
    {
        $GLOBALS['lamb_probe_cookies'][$name] = [
            'value'  => $value,
            'secure' => is_array($options) && !empty($options['secure']),
        ];
        return true;
    }
}

namespace {
    if (__FASTCGI__) {
        function fastcgi_finish_request(): bool
        {
            echo __FINISHED__;
            return true;
        }
    }

    $GLOBALS['lamb_probe_printed'] = 0;
    $GLOBALS['lamb_probe_headers'] = [];
    $GLOBALS['lamb_probe_cookies'] = [];
    ob_start(function (string $chunk): string {
        $GLOBALS['lamb_probe_printed'] += strlen($chunk);

        return $chunk;
    }, 1);
    eval('namespace Lamb\Network; function header(string $header, bool $replace = true, int $response_code = 0): void { '
        . '$GLOBALS["lamb_probe_headers"][] = [$header, $GLOBALS["lamb_probe_printed"]]; \header($header, $replace, $response_code); }');

    register_shutdown_function(function () {
        echo __META__ . json_encode([
            'http' => http_response_code() ?: 200,
            'headers' => $GLOBALS['lamb_probe_headers'],
            'cookies' => $GLOBALS['lamb_probe_cookies'],
            'session' => [
                'started' => session_status() === PHP_SESSION_ACTIVE,
                'secure'  => (bool) session_get_cookie_params()['secure'],
            ],
            'root_url' => defined('ROOT_URL') ? ROOT_URL : null,
        ]);
    });

    $_SERVER = array_merge($_SERVER, __SERVER__);
    $_POST = __POST__;
    $_COOKIE = __COOKIES__;

    parse_str(__QUERY__, $_GET);

    require __INDEX__;
}
PHP;
}

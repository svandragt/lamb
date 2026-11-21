<?php

namespace Tests\Support;

use Symfony\Component\Process\Process;

/**
 * Runs one request through the real front controller, src/index.php, in its own `php` process.
 *
 * index.php defines constants, starts the session and ends some requests with die(), so PHPUnit
 * cannot include it in the test process. The child includes it from the CLI, over a temporary
 * data directory, with $_SERVER, $_POST and $_COOKIE set from the request. No network or port is
 * involved.
 *
 * The CLI SAPI keeps no response headers: headers_list() stays empty after header() and
 * setcookie(). The child therefore defines Lamb\Response\setcookie(), which PHP resolves before
 * the built-in one for calls inside that namespace, and records the cookies Lamb sets itself.
 * PHP's session module writes the LAMBSESSID header in C, which nothing can intercept, so the
 * child reports session_get_cookie_params() instead: the module takes the header's attributes
 * from it.
 */
final class FrontControllerProbe
{
    private const META_MARKER = "\n===META===\n";

    private const TIMEOUT_SECONDS = 60;

    /**
     * Runs a request and reports the cookies it sets.
     *
     * @param string $dataDir The LAMB_DATA_DIR for the request; the database persists in it between calls.
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
     * } $request `host` is the Host header and `https` the HTTPS server variable. `site_url` is
     *     LAMB_SITE_URL, `settings` the INI text saved before the request, and `login_hash`
     *     LAMB_LOGIN_PASSWORD.
     * @return array{
     *     cookies: array<string, array{value: string, secure: bool}>,
     *     session: array{started: bool, secure: bool},
     *     root_url: string|null,
     *     stderr: string
     * } The cookies Lamb set, the session (whether the request started one, and the Secure
     *     attribute its cookie gets) and the ROOT_URL that absolute links are built from.
     */
    public static function run(string $dataDir, array $request = []): array
    {
        if (isset($request['settings'])) {
            self::seedSettings($dataDir, $request['settings']);
        }

        $script = $dataDir . '/probe-' . uniqid() . '.php';
        file_put_contents($script, self::script($request));

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

        [, $metaJson] = array_pad(explode(self::META_MARKER, $process->getOutput(), 2), 2, '');
        $meta = json_decode(trim($metaJson), true);
        if (!is_array($meta)) {
            throw new \RuntimeException(
                'The probe produced no result. stdout: ' . $process->getOutput() . ' stderr: ' . $process->getErrorOutput()
            );
        }

        return [
            'cookies' => $meta['cookies'],
            'session' => $meta['session'],
            'root_url' => $meta['root_url'],
            'stderr'  => $process->getErrorOutput(),
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
     * @param array<string, mixed> $request The request, as for run().
     * @return string
     */
    private static function script(array $request): string
    {
        $server = ['REQUEST_METHOD' => $request['method'] ?? 'GET', 'REQUEST_URI' => $request['uri'] ?? '/',
            'SERVER_PROTOCOL' => 'HTTP/1.1', 'REMOTE_ADDR' => '127.0.0.1'];
        if (isset($request['host'])) {
            $server['HTTP_HOST'] = $request['host'];
        }
        if (isset($request['https'])) {
            $server['HTTPS'] = $request['https'];
        }

        return strtr(self::TEMPLATE, [
            '__INDEX__'   => var_export(dirname(__DIR__, 2) . '/src/index.php', true),
            '__SERVER__'  => var_export($server, true),
            '__POST__'    => var_export($request['post'] ?? [], true),
            '__COOKIES__' => var_export($request['cookies'] ?? [], true),
            '__MARKER__'  => var_export(self::META_MARKER, true),
        ]);
    }

    private const TEMPLATE = <<<'PHP'
<?php
namespace Lamb\Response {
    function setcookie(string $name, string $value = '', array|int $options = 0): bool
    {
        $GLOBALS['probe']['cookies'][$name] = [
            'value'  => $value,
            'secure' => is_array($options) && !empty($options['secure']),
        ];
        return true;
    }
}

namespace {
    // Keeps the page out of stdout and headers_sent() false, so session_start() runs as under a web server.
    ob_start();
    $probe = ['cookies' => []];

    register_shutdown_function(function () {
        global $probe;
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        fwrite(STDOUT, __MARKER__ . json_encode([
            'cookies' => $probe['cookies'],
            'session' => [
                'started' => session_status() === PHP_SESSION_ACTIVE,
                'secure'  => (bool) session_get_cookie_params()['secure'],
            ],
            'root_url' => defined('ROOT_URL') ? ROOT_URL : null,
        ]));
    });

    $_SERVER = array_merge($_SERVER, __SERVER__);
    $_POST = __POST__;
    $_COOKIE = __COOKIES__;

    require __INDEX__;
}
PHP;
}

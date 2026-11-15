<?php

namespace Tests\Support;

/**
 * Runs a Micropub request handler in its own `php` process and reports what it sent.
 *
 * respond_micropub() and respond_micropub_media() (src/micropub.php) read
 * php://input, send the response with header() and http_response_code(), and
 * then exit(). PHPUnit cannot observe that in-process: exit() ends the test run,
 * and the CLI SAPI keeps no headers for headers_list(). So the handler runs in a
 * child process, and the child defines Lamb\Micropub\header(),
 * http_response_code() and file_get_contents() before it starts. PHP resolves an
 * unqualified call inside a namespace to the namespaced function first, so those
 * three capture the response and supply the request body without Xdebug.
 *
 * The child has an in-memory database and a fake IndieAuth token endpoint: when
 * `token` is given, the parent answers the handler's token introspection request
 * with that JSON.
 */
final class EndpointProbe
{
    private const META_MARKER = "\n===META===\n";

    /**
     * Runs a handler call against one request and returns the response it sent.
     *
     * @param string $call PHP statement that invokes the handler under test.
     * @param array{
     *     server?: array<string, string>,
     *     post?: array<string, mixed>,
     *     input?: string,
     *     files?: array<string, list<array{0: string, 1: string, 2?: int}>>,
     *     seed?: array<string, mixed>,
     *     seed_deleted?: bool,
     *     token?: string,
     *     debug?: bool,
     *     accept_uploads?: bool
     * } $request What the client sent, plus the post to seed before it:
     *     `server` is $_SERVER, `post` is $_POST, `input` is the raw body.
     *     `files` maps a field name to [client filename, bytes, error code] uploads;
     *     a name ending in `[]` gives PHP's array-of-uploads shape.
     *     `seed` is Micropub data for a post created first (soft-deleted when
     *     `seed_deleted` is set); every `%%%` in the request is replaced with its
     *     URL (percent-encoded inside the request URI), as micropub.rocks does.
     *     `token` is the JSON the token endpoint answers with; leave it out when
     *     the request never reaches introspection. `debug` turns Micropub
     *     diagnostic logging on. `accept_uploads` lets move_uploaded_file() move the
     *     fixtures: PHP only moves a file a real HTTP upload delivered, and a
     *     CLI child never receives one.
     * @return array{
     *     status: int,
     *     headers: array<string, list<string>>,
     *     body: string,
     *     posts: list<array{body: string, deleted: mixed}>,
     *     assets: list<string>,
     *     log: string,
     *     stderr: string
     * } Header names are lower-case.
     */
    public static function run(string $call, array $request = []): array
    {
        $rootDir = sys_get_temp_dir() . '/lamb_probe_' . uniqid();
        mkdir($rootDir, 0777, true);

        $listener = null;
        $tokenEndpoint = 'https://tokens.invalid/token';
        if (isset($request['token'])) {
            $listener = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
            if ($listener === false) {
                throw new \RuntimeException("Could not open the fake token endpoint: $errstr");
            }
            $name = (string) stream_socket_get_name($listener, false);
            $tokenEndpoint = 'http://127.0.0.1:' . substr($name, (int) strrpos($name, ':') + 1) . '/token';
        }

        try {
            $script = $rootDir . '/probe.php';
            file_put_contents($script, self::script($call, $request, $rootDir, $tokenEndpoint));

            $env = getenv();
            unset($env['LAMB_MICROPUB_DEBUG']);
            if (!empty($request['debug'])) {
                $env['LAMB_MICROPUB_DEBUG'] = '1';
            }

            $process = proc_open(
                [PHP_BINARY, $script],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                $env
            );
            if (!is_resource($process)) {
                throw new \RuntimeException('Failed to spawn the probe subprocess.');
            }

            fclose($pipes[0]);
            if ($listener !== null) {
                self::answerTokenIntrospection($listener, $request['token'] ?? '');
            }
            $stdout = (string) stream_get_contents($pipes[1]);
            $stderr = (string) stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        } finally {
            self::removeDirectory($rootDir);
        }

        [$body, $metaJson] = array_pad(explode(self::META_MARKER, $stdout, 2), 2, '{}');
        $meta = json_decode(trim($metaJson), true) ?: [];

        return [
            'status'  => $meta['status'] ?? 0,
            'headers' => $meta['headers'] ?? [],
            'body'    => trim($body),
            'posts'   => $meta['posts'] ?? [],
            'assets'  => $meta['assets'] ?? [],
            'log'     => $meta['log'] ?? '',
            'stderr'  => $stderr,
        ];
    }

    /**
     * Answers the handler's one token introspection request.
     *
     * @param resource $listener The listening socket named in the child's token_endpoint.
     * @param string   $json     The JSON body to answer with.
     * @return void
     */
    private static function answerTokenIntrospection($listener, string $json): void
    {
        $connection = @stream_socket_accept($listener, 10);
        if ($connection === false) {
            return;
        }

        fread($connection, 8192);
        fwrite(
            $connection,
            "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($json)
            . "\r\nConnection: close\r\n\r\n" . $json
        );
        fclose($connection);
    }

    /**
     * The PHP source of the child process.
     *
     * @param string               $call          The handler call.
     * @param array<string, mixed> $request       The request, as for run().
     * @param string               $rootDir       The child's ROOT_DIR, also holding its upload fixtures.
     * @param string               $tokenEndpoint The child's token_endpoint setting.
     * @return string
     */
    private static function script(string $call, array $request, string $rootDir, string $tokenEndpoint): string
    {
        $server = array_merge(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/micropub'], $request['server'] ?? []);

        return strtr(self::TEMPLATE, [
            '__AUTOLOAD__' => var_export(__DIR__ . '/../../vendor/autoload.php', true),
            '__ROOT_DIR__' => var_export($rootDir, true),
            '__CONFIG__'   => var_export(['site_url' => 'https://example.com', 'token_endpoint' => $tokenEndpoint], true),
            '__SERVER__'   => var_export($server, true),
            '__POST__'     => var_export($request['post'] ?? [], true),
            '__INPUT__'    => var_export($request['input'] ?? '', true),
            '__FILES__'    => var_export(self::filesGlobal($rootDir, $request['files'] ?? []), true),
            '__SEED__'     => var_export($request['seed'] ?? null, true),
            '__SEED_DELETED__' => var_export(!empty($request['seed_deleted']), true),
            '__ACCEPT_UPLOADS__' => var_export(!empty($request['accept_uploads']), true),
            '__CALL__'     => $call,
        ]);
    }

    /**
     * Writes the upload fixtures to disk and returns the $_FILES array PHP would build.
     *
     * @param string $dir The directory the fixtures are written to.
     * @param array<string, list<array{0: string, 1: string, 2?: int}>> $files As for run().
     * @return array<string, array<string, mixed>>
     */
    private static function filesGlobal(string $dir, array $files): array
    {
        $global = [];
        $count = 0;
        foreach ($files as $field => $uploads) {
            $entry = ['name' => [], 'type' => [], 'tmp_name' => [], 'error' => [], 'size' => []];
            foreach ($uploads as $upload) {
                $tmp = $dir . '/upload_' . $count++;
                file_put_contents($tmp, $upload[1]);
                $entry['name'][] = $upload[0];
                $entry['type'][] = 'application/octet-stream';
                $entry['tmp_name'][] = $tmp;
                $entry['error'][] = $upload[2] ?? UPLOAD_ERR_OK;
                $entry['size'][] = strlen($upload[1]);
            }

            if (str_ends_with($field, '[]')) {
                $global[substr($field, 0, -2)] = $entry;
            } else {
                $global[$field] = array_map(fn(array $values) => $values[0], $entry);
            }
        }

        return $global;
    }

    /**
     * Removes a directory and everything in it.
     *
     * @param string $path The directory.
     * @return void
     */
    private static function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = "$path/$entry";
            is_dir($full) ? self::removeDirectory($full) : unlink($full);
        }
        rmdir($path);
    }

    private const TEMPLATE = <<<'PHP'
<?php
namespace Lamb\Micropub {
    function header(string $header, bool $replace = true, int $response_code = 0): void
    {
        [$name, $value] = array_pad(explode(':', $header, 2), 2, '');
        $name = strtolower(trim($name));
        if ($replace) {
            unset($GLOBALS['probe']['headers'][$name]);
        }
        $GLOBALS['probe']['headers'][$name][] = trim($value);
        if ($response_code > 0) {
            $GLOBALS['probe']['status'] = $response_code;
        }
    }

    function http_response_code(int $response_code = 0): int|bool
    {
        if ($response_code > 0) {
            $GLOBALS['probe']['status'] = $response_code;
            return true;
        }
        return $GLOBALS['probe']['status'];
    }

    function file_get_contents(string $filename, mixed ...$args): string|false
    {
        return $filename === 'php://input' ? $GLOBALS['probe']['input'] : \file_get_contents($filename, ...$args);
    }
}

namespace Lamb\Response {
    if (__ACCEPT_UPLOADS__) {
        function move_uploaded_file(string $from, string $to): bool
        {
            return rename($from, $to);
        }
    }
}

namespace {
    require __AUTOLOAD__;

    define('ROOT_DIR', __ROOT_DIR__);
    define('ROOT_URL', 'https://example.com');
    $config = __CONFIG__;
    $probe = ['status' => 200, 'headers' => [], 'input' => __INPUT__];
    $logPath = ROOT_DIR . '/micropub.log';
    $GLOBALS['lamb_mp_log_path'] = $logPath;

    \RedBeanPHP\R::setup('sqlite::memory:');
    \RedBeanPHP\R::freeze(false);

    register_shutdown_function(function () use ($logPath) {
        global $probe;
        $posts = [];
        try {
            // SELECT *, not named columns: `deleted` only exists once a post has been deleted.
            foreach (\RedBeanPHP\R::getAll('SELECT * FROM post ORDER BY id') as $row) {
                $posts[] = ['body' => $row['body'], 'deleted' => $row['deleted'] ?? null];
            }
        } catch (\Throwable) {
            // No post was ever stored, so the table does not exist.
        }
        $assets = [];
        if (is_dir(ROOT_DIR . '/assets')) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(ROOT_DIR . '/assets', \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                $assets[] = substr($file->getPathname(), strlen(ROOT_DIR));
            }
        }
        fwrite(STDOUT, "\n===META===\n" . json_encode([
            'status'  => $probe['status'],
            'headers' => $probe['headers'],
            'posts'   => $posts,
            'assets'  => $assets,
            'log'     => is_file($logPath) ? file_get_contents($logPath) : '',
        ], JSON_INVALID_UTF8_SUBSTITUTE));
    });

    $_SERVER = array_merge($_SERVER, __SERVER__);
    $_POST = __POST__;
    $_FILES = __FILES__;

    $seed = __SEED__;
    if ($seed !== null) {
        $adapter = new \Lamb\Micropub\LambMicropubAdapter();
        $url = $adapter->createCallback($seed);
        if (__SEED_DELETED__) {
            $adapter->deleteCallback($url);
        }
        $probe['input'] = str_replace('%%%', $url, $probe['input']);
        array_walk_recursive($_POST, function (&$value) use ($url) {
            $value = is_string($value) ? str_replace('%%%', $url, $value) : $value;
        });
        $_SERVER['REQUEST_URI'] = str_replace('%%%', rawurlencode($url), $_SERVER['REQUEST_URI']);
    }

    __CALL__
}
PHP;
}

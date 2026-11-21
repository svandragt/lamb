<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;
use Tests\Support\Filesystem;

use function Lamb\Http\is_https;

/**
 * Behind a TLS-terminating proxy Lamb sees plain HTTP, so the connection says
 * nothing about the scheme visitors use (issue #995). The site URL the author
 * configured does: an `https://` site URL means the site is served over HTTPS.
 *
 * The first tests pin the decision. The last ones boot src/index.php under PHP's
 * built-in server, the way a proxy reaches it, and read the Set-Cookie headers
 * the browser would receive.
 */
class SecureCookieSiteUrlTest extends TestCase
{
    private const PASSWORD = 'correct horse battery staple';

    /** @var list<string> */
    private array $directories = [];

    /** @var list<Process> */
    private array $servers = [];

    protected function tearDown(): void
    {
        foreach ($this->servers as $server) {
            $server->stop();
        }
        foreach ($this->directories as $directory) {
            Filesystem::removeDirectory($directory);
        }
    }

    public function testHttpsSiteUrlMakesAPlainHttpRequestHttps(): void
    {
        $this->assertTrue(is_https([], 'https://example.com'));
        $this->assertTrue(is_https(['HTTPS' => 'off'], 'https://example.com:8443'));
    }

    public function testWithoutASiteUrlTheConnectionDecides(): void
    {
        $this->assertTrue(is_https(['HTTPS' => 'on'], null));
        $this->assertFalse(is_https([], null));
        $this->assertFalse(is_https(['HTTPS' => 'off'], null));
    }

    public function testHttpSiteUrlLeavesAPlainHttpRequestHttp(): void
    {
        $this->assertFalse(is_https([], 'http://example.com'));
    }

    public function testHttpSiteUrlDoesNotDowngradeARequestThatArrivedOverTls(): void
    {
        $this->assertTrue(is_https(['HTTPS' => 'on'], 'http://example.com'));
    }

    public function testForwardedProtoIsIgnored(): void
    {
        $this->assertFalse(is_https(['HTTP_X_FORWARDED_PROTO' => 'https'], null));
        $this->assertFalse(is_https(['HTTP_X_FORWARDED_PROTO' => 'https'], 'http://example.com'));
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: string, 2: bool}>
     */
    public static function siteUrls(): array
    {
        return [
            'https site URL in the environment' => [['LAMB_SITE_URL' => 'https://example.com'], '', true],
            'https site URL in the settings'    => [[], 'https://example.com', true],
            'http site URL'                     => [['LAMB_SITE_URL' => 'http://example.com'], '', false],
            'no site URL'                       => [[], '', false],
        ];
    }

    /**
     * The login form's CSRF cookie, the session cookie and the login marker are
     * each set through a different code path; all three must follow the site URL.
     *
     * @dataProvider siteUrls
     *
     * @param array<string, string> $env      Environment for the server.
     * @param string                $setting  The `site_url` saved in the settings, or '' for none.
     * @param bool                  $secure   Whether every cookie must carry Secure.
     */
    public function testLoginCookiesCarrySecureWhenTheSiteUrlIsHttps(array $env, string $setting, bool $secure): void
    {
        $base = $this->startApp($env, $setting);

        [$headers, $body] = $this->request($base . '/login');
        $csrf = $this->cookies($headers);
        $this->assertArrayHasKey('lamb_login_csrf', $csrf, 'The login form sets its CSRF cookie');
        $this->assertSame($secure, $csrf['lamb_login_csrf']['secure'], 'lamb_login_csrf Secure flag');

        preg_match('/name="csrf"\s+value="([^"]+)"|value="([^"]+)"\s+name="csrf"/', $body, $m);
        $token = ($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '');
        $this->assertNotSame('', $token, 'The login form carries a CSRF token');

        [$headers] = $this->request($base . '/login', [
            'Cookie: lamb_login_csrf=' . $csrf['lamb_login_csrf']['value'],
        ], http_build_query([
            'submit' => 'Log in',
            'password' => self::PASSWORD,
            'csrf' => $token,
        ]));
        $logged_in = $this->cookies($headers);

        $this->assertArrayHasKey('LAMBSESSID', $logged_in, 'A successful login starts a session');
        $this->assertArrayHasKey('lamb_logged_in', $logged_in, 'A successful login sets the marker');
        $this->assertSame($secure, $logged_in['LAMBSESSID']['secure'], 'LAMBSESSID Secure flag');
        $this->assertSame($secure, $logged_in['lamb_logged_in']['secure'], 'lamb_logged_in Secure flag');
    }

    public function testAbsoluteLinksUseTheSiteUrlScheme(): void
    {
        $base = $this->startApp(['LAMB_SITE_URL' => 'https://example.com']);

        [$headers] = $this->request($base . '/');

        $this->assertStringContainsString('<https://example.com/micropub>; rel="micropub"', implode("\n", $headers));
    }

    /**
     * Boots the real front controller and returns the server's base URL.
     *
     * @param array<string, string> $env     Extra environment, such as LAMB_SITE_URL.
     * @param string                $setting The `site_url` to save in the settings first, or '' for none.
     * @return string The base URL, without a trailing slash.
     */
    private function startApp(array $env, string $setting = ''): string
    {
        $data = sys_get_temp_dir() . '/lamb_secure_cookie_test_' . uniqid();
        mkdir($data, 0750, true);
        $this->directories[] = $data;
        if ($setting !== '') {
            $seed = new Process([
                PHP_BINARY,
                '-r',
                'require $argv[1]; \\Lamb\\Bootstrap\\bootstrap_db($argv[2]); '
                    . '\\Lamb\\Config\\save_ini_text("site_url = " . $argv[3] . "\\n");',
                dirname(__DIR__, 2) . '/vendor/autoload.php',
                $data,
                $setting,
            ]);
            $seed->mustRun();
        }

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $this->assertNotFalse($socket, (string) $error);
        $port = (int) parse_url('tcp://' . stream_socket_get_name($socket, false), PHP_URL_PORT);
        fclose($socket);

        $server = new Process(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', dirname(__DIR__, 2) . '/src'],
            null,
            $env + [
                'LAMB_DATA_DIR' => $data,
                'LAMB_LOGIN_PASSWORD' => base64_encode(password_hash(self::PASSWORD, PASSWORD_DEFAULT)),
                'LAMB_SITE_URL' => '',
            ]
        );
        $server->start();
        $this->servers[] = $server;

        $deadline = microtime(true) + 10;
        while (@fsockopen('127.0.0.1', $port) === false) {
            $this->assertLessThan(
                $deadline,
                microtime(true),
                'timed out waiting for php -S: ' . $server->getErrorOutput()
            );
            usleep(20000);
        }

        return 'http://127.0.0.1:' . $port;
    }

    /**
     * Sends one request without following redirects.
     *
     * @param string       $url     Absolute URL.
     * @param list<string> $headers Request headers.
     * @param string|null  $body    Form body; sends a POST when given.
     * @return array{0: list<string>, 1: string} Response headers and body.
     */
    private function request(string $url, array $headers = [], ?string $body = null): array
    {
        $http = ['ignore_errors' => true, 'follow_location' => 0, 'timeout' => 20, 'header' => $headers];
        if ($body !== null) {
            $http['method'] = 'POST';
            $http['header'][] = 'Content-Type: application/x-www-form-urlencoded';
            $http['content'] = $body;
        }
        $response = file_get_contents($url, false, stream_context_create(['http' => $http]));
        $this->assertNotFalse($response, 'The request to ' . $url . ' failed');

        return [$http_response_header ?? [], $response];
    }

    /**
     * Reads the Set-Cookie headers into name => value and Secure flag.
     *
     * @param list<string> $headers Response headers.
     * @return array<string, array{value: string, secure: bool}>
     */
    private function cookies(array $headers): array
    {
        $cookies = [];
        foreach ($headers as $header) {
            if (stripos($header, 'Set-Cookie:') !== 0) {
                continue;
            }
            $parts = array_map('trim', explode(';', trim(substr($header, strlen('Set-Cookie:')))));
            [$name, $value] = explode('=', array_shift($parts), 2);
            $cookies[$name] = [
                'value' => $value,
                'secure' => in_array('secure', array_map('strtolower', $parts), true),
            ];
        }

        return $cookies;
    }
}

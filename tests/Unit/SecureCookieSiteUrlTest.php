<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\CronProbe;
use Tests\Support\Filesystem;

use function Lamb\Bootstrap\sign_login_marker;
use function Lamb\Http\is_https;
use function Lamb\Response\login_csrf_secret;

/**
 * Behind a TLS-terminating proxy Lamb sees plain HTTP, so the connection says
 * nothing about the scheme visitors use (issue #995). The site URL the author
 * configured does, but only for requests to that site: an https:// site URL must
 * not mark cookies Secure on a plain-HTTP request to another host, because the
 * browser would drop them and the owner could not log in to fix the setting.
 *
 * The first tests pin the decision. The rest send a login through the real
 * front controller and read the cookies it sets (see CronProbe).
 */
class SecureCookieSiteUrlTest extends TestCase
{
    private static string $secret;

    private static string $secretHash;

    /** @var list<string> */
    private array $directories = [];

    public static function setUpBeforeClass(): void
    {
        self::$secret = bin2hex(random_bytes(16));
        self::$secretHash = base64_encode(password_hash(self::$secret, PASSWORD_DEFAULT));
    }

    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            Filesystem::removeDirectory($directory);
        }
    }

    public function testHttpsSiteUrlMakesARequestToItsHostHttps(): void
    {
        $this->assertTrue(is_https(['HTTP_HOST' => 'example.com'], 'https://example.com'));
    }

    public function testHostIsComparedWithoutPortAndCase(): void
    {
        $this->assertTrue(is_https(['HTTP_HOST' => 'Example.COM:8080'], 'https://example.com'));
        $this->assertTrue(is_https(['HTTP_HOST' => 'example.com'], 'https://EXAMPLE.com:8443'));
        $this->assertTrue(is_https(['HTTP_HOST' => '[::1]:8080'], 'https://[::1]'));
    }

    public function testAnEmptyPortOnTheHostIsIgnored(): void
    {
        $this->assertTrue(is_https(['HTTP_HOST' => 'example.com:'], 'https://example.com'));
    }

    public function testATrailingDotOnEitherHostIsIgnored(): void
    {
        $this->assertTrue(is_https(['HTTP_HOST' => 'example.com.'], 'https://example.com'));
        $this->assertTrue(is_https(['HTTP_HOST' => 'example.com.:8080'], 'https://example.com'));
        $this->assertTrue(is_https(['HTTP_HOST' => 'example.com'], 'https://example.com.'));
        $this->assertFalse(is_https(['HTTP_HOST' => 'example.com..'], 'https://example.com'));
    }

    public function testHttpsSiteUrlDoesNotMakeAPlainHttpRequestToAnotherHostHttps(): void
    {
        $this->assertFalse(is_https(['HTTP_HOST' => '192.168.1.20'], 'https://example.com'));
        $this->assertFalse(is_https(['HTTP_HOST' => 'staging.example.com'], 'https://example.com'));
        $this->assertFalse(is_https(['HTTP_HOST' => 'example.com.evil.test'], 'https://example.com'));
        $this->assertFalse(is_https([], 'https://example.com'));
        $this->assertFalse(is_https(['HTTP_HOST' => ['example.com']], 'https://example.com'));
    }

    public function testARequestOverTlsIsAlwaysHttps(): void
    {
        $this->assertTrue(is_https(['HTTPS' => 'on'], null));
        $this->assertTrue(is_https(['HTTPS' => 'on', 'HTTP_HOST' => '192.168.1.20'], 'https://example.com'));
        $this->assertTrue(is_https(['HTTPS' => 'on', 'HTTP_HOST' => 'example.com'], 'http://example.com'));
    }

    /**
     * PHP documents HTTPS as set to a non-empty value for TLS requests, and some
     * servers set it to "off" or "0" for plain HTTP.
     */
    public function testHttpsServerVariableIsOffWhenEmptyOffOrZero(): void
    {
        $this->assertTrue(is_https(['HTTPS' => 'ON'], null));
        $this->assertTrue(is_https(['HTTPS' => '1'], null));
        $this->assertFalse(is_https(['HTTPS' => 'off'], null));
        $this->assertFalse(is_https(['HTTPS' => 'OFF'], null));
        $this->assertFalse(is_https(['HTTPS' => '0'], null));
        $this->assertFalse(is_https(['HTTPS' => ''], null));
        $this->assertFalse(is_https([], null));
    }

    public function testWithoutAnHttpsSiteUrlAPlainHttpRequestIsHttp(): void
    {
        $this->assertFalse(is_https(['HTTP_HOST' => 'example.com'], null));
        $this->assertFalse(is_https(['HTTP_HOST' => 'example.com'], 'http://example.com'));
    }

    public function testForwardedProtoIsIgnored(): void
    {
        $server = ['HTTP_HOST' => 'example.com', 'HTTP_X_FORWARDED_PROTO' => 'https'];
        $this->assertFalse(is_https($server, null));
        $this->assertFalse(is_https($server, 'http://example.com'));
    }

    /**
     * Each row is a request and what its login cookies must be.
     *
     * @return array<string, array{0: array<string, string>, 1: bool}>
     */
    public static function logins(): array
    {
        $https_env = ['site_url' => 'https://example.com'];

        return [
            'https site URL in the environment, request to its host'  => [$https_env + ['host' => 'example.com'], true],
            'https site URL in the settings, request to its host'     => [
                ['settings' => "site_url = https://example.com\n", 'host' => 'example.com'],
                true,
            ],
            'https site URL, host differs in case and port'           => [$https_env + ['host' => 'EXAMPLE.com:8080'], true],
            'https site URL, plain HTTP request to another host'      => [$https_env + ['host' => '192.168.1.20'], false],
            'https site URL in the settings, request to another host' => [
                ['settings' => "site_url = https://example.com\n", 'host' => 'localhost:8747'],
                false,
            ],
            'https site URL, request over TLS to another host'        => [
                $https_env + ['host' => '192.168.1.20', 'https' => 'on'],
                true,
            ],
            'http site URL, plain HTTP request'                       => [
                ['site_url' => 'http://example.com', 'host' => 'example.com'],
                false,
            ],
            'no site URL, plain HTTP request'                         => [['host' => 'example.com'], false],
            'no site URL, request over TLS'                           => [['host' => 'example.com', 'https' => 'on'], true],
        ];
    }

    /**
     * A successful login sets the login marker, starts the session and clears the
     * form's CSRF cookie. Different code does each; all three must follow the one
     * decision.
     *
     * @dataProvider logins
     *
     * @param array<string, string> $request As for CronProbe::run().
     * @param bool                  $secure  Whether every cookie must carry Secure.
     */
    public function testLoginCookiesCarrySecureOnlyWhenTheRequestIsHttps(array $request, bool $secure): void
    {
        $token = sign_login_marker(bin2hex(random_bytes(8)), login_csrf_secret(self::$secretHash));

        $login = CronProbe::run($this->dataDir(), request: [
            'method' => 'POST',
            'uri' => '/login',
            'cookies' => ['lamb_login_csrf' => $token],
            'post' => $this->loginForm($token),
            'login_hash' => self::$secretHash,
        ] + $request);

        $this->assertArrayHasKey('lamb_logged_in', $login['cookies'], 'A successful login sets the marker');
        $this->assertTrue($login['session']['started'], 'A successful login starts a session');
        $this->assertSame($secure, $login['cookies']['lamb_logged_in']['secure'], 'lamb_logged_in Secure flag');
        $this->assertSame($secure, $login['session']['secure'], 'LAMBSESSID Secure flag');
        $this->assertSame($secure, $login['cookies']['lamb_login_csrf']['secure'], 'lamb_login_csrf Secure flag');
    }

    public function testLoginFormCsrfCookieFollowsTheSameDecision(): void
    {
        $site = ['site_url' => 'https://example.com', 'login_hash' => self::$secretHash, 'uri' => '/login'];

        $own_host = CronProbe::run($this->dataDir(), request: $site + ['host' => 'example.com']);
        $other_host = CronProbe::run($this->dataDir(), request: $site + ['host' => '192.168.1.20']);

        $this->assertTrue($own_host['cookies']['lamb_login_csrf']['secure']);
        $this->assertFalse($other_host['cookies']['lamb_login_csrf']['secure']);
    }

    public function testAbsoluteLinksUseTheSiteUrlScheme(): void
    {
        $result = CronProbe::run(
            $this->dataDir(),
            request: ['host' => '192.168.1.20', 'site_url' => 'https://example.com']
        );

        $this->assertSame('https://example.com', $result['root_url']);
    }

    /**
     * Fills in the login form's fields. The theme gives the field that takes the
     * secret no constant, so its name is read from the form's markup.
     *
     * @param string $token The CSRF token for the hidden field.
     * @return array<string, string>
     */
    private function loginForm(string $token): array
    {
        $markup = (string) file_get_contents(dirname(__DIR__, 2) . '/src/themes/base/parts/login.php');
        preg_match('/type="password" name="([^"]+)"/', $markup, $match);
        $this->assertArrayHasKey(1, $match, 'The login form has a field of type password');

        return [$match[1] => self::$secret, 'submit' => SUBMIT_LOGIN, HIDDEN_CSRF_NAME => $token];
    }

    private function dataDir(): string
    {
        $dir = sys_get_temp_dir() . '/lamb_secure_cookie_test_' . uniqid();
        mkdir($dir, 0750, true);
        $this->directories[] = $dir;

        return $dir;
    }
}

<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Response\local_redirect_target;
use function Lamb\Response\log_failed_login;
use function Lamb\Response\redirect_login;

class ResponseAuthTest extends TestCase
{
    /** @var string|false */
    private $previousErrorLog;

    protected function setUp(): void
    {
        $_SESSION = [];
        $_POST    = [];
        $_COOKIE  = [];
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
        $_SERVER['REQUEST_URI']     = '/';

        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'http://localhost');
        }

        $this->previousErrorLog = ini_get('error_log');
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST    = [];
        $_COOKIE  = [];
        unset($_SERVER['REMOTE_ADDR']);
        ini_set('error_log', $this->previousErrorLog === false ? '' : $this->previousErrorLog);
    }

    /**
     * Routes error_log() to a temp file, runs the callback, returns the captured log.
     */
    private function captureErrorLog(callable $fn): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'lamb-log');
        ini_set('error_log', $tmp);
        try {
            $fn();
            return file_get_contents($tmp) ?: '';
        } finally {
            @unlink($tmp);
        }
    }

    // log_failed_login — audit trail for failed admin login attempts (issue #444)

    public function testLogFailedLoginWritesMarkerAndClientIp(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $log = $this->captureErrorLog(static fn() => log_failed_login());

        $this->assertStringContainsString('failed admin login', $log);
        $this->assertStringContainsString('203.0.113.7', $log);
    }

    public function testLogFailedLoginFallsBackWhenIpMissing(): void
    {
        unset($_SERVER['REMOTE_ADDR']);
        $log = $this->captureErrorLog(static fn() => log_failed_login());

        $this->assertStringContainsString('failed admin login', $log);
        $this->assertStringContainsString('unknown', $log);
    }

    public function testLogFailedLoginNeverIncludesSubmittedPassword(): void
    {
        $_SERVER['REMOTE_ADDR'] = '203.0.113.7';
        $_POST['password']      = 'hunter2-secret';
        $log = $this->captureErrorLog(static fn() => log_failed_login());

        $this->assertStringNotContainsString('hunter2-secret', $log);
    }

    // redirect_login — paths that return without calling die()
    //
    // The login page is now stateless for anonymous visitors (issue #462): no
    // session is started, and the form's CSRF token rides in a signed cookie +
    // hidden field instead of the session. So "show the login page" no longer
    // means an empty array — it means an array carrying the double-submit token
    // (login_csrf) and no authenticated session.

    public function testRedirectLoginShowsFormWhenNoPostData(): void
    {
        // No POST at all — login form should be rendered
        $result = redirect_login();
        $this->assertIsArray($result);
        $this->assertArrayHasKey('login_csrf', $result);
        $this->assertArrayNotHasKey('login_error', $result);
        $this->assertArrayNotHasKey(SESSION_LOGIN, $_SESSION);
    }

    public function testRedirectLoginShowsFormWhenSubmitKeyAbsent(): void
    {
        $_POST['other_field'] = 'value';
        $result = redirect_login();
        $this->assertIsArray($result);
        $this->assertArrayHasKey('login_csrf', $result);
        $this->assertArrayNotHasKey('login_error', $result);
    }

    public function testRedirectLoginShowsFormWhenSubmitValueIsNotLogin(): void
    {
        $_POST['submit'] = 'some other action';
        $result = redirect_login();
        $this->assertIsArray($result);
        $this->assertArrayHasKey('login_csrf', $result);
    }

    public function testRedirectLoginShowsFormWhenSubmitValueIsEmpty(): void
    {
        $_POST['submit'] = '';
        $result = redirect_login();
        $this->assertIsArray($result);
        $this->assertArrayHasKey('login_csrf', $result);
    }

    /**
     * Wrong password re-renders the login page in place with the error rather
     * than redirecting through a session flash (issue #462): the flash carrier
     * is gone now that /login is sessionless, so the message must travel in the
     * returned data. The visitor must remain anonymous (no session started).
     */
    public function testRedirectLoginWrongPasswordRendersErrorWithoutSession(): void
    {
        $token = \Lamb\Response\issue_login_csrf();
        $_POST['submit']          = SUBMIT_LOGIN;
        $_POST[HIDDEN_CSRF_NAME]  = $token;
        $_POST['password']        = 'definitely-the-wrong-password-xyz';

        $result = redirect_login();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('login_error', $result);
        $this->assertSame('Password is incorrect, please try again.', $result['login_error']);
        $this->assertArrayHasKey('login_csrf', $result);
        $this->assertArrayNotHasKey(SESSION_LOGIN, $_SESSION);
    }

    // local_redirect_target — the post-login redirect must stay on this site

    public function testLocalRedirectTargetAllowsLocalPath(): void
    {
        $this->assertSame('/settings', local_redirect_target('/settings'));
    }

    public function testLocalRedirectTargetPreservesQueryString(): void
    {
        $this->assertSame('/search/foo?page=2', local_redirect_target('/search/foo?page=2'));
    }

    public function testLocalRedirectTargetRejectsAbsoluteUrl(): void
    {
        $this->assertSame('/', local_redirect_target('https://evil.example/phish'));
    }

    public function testLocalRedirectTargetRejectsProtocolRelativeUrl(): void
    {
        $this->assertSame('/', local_redirect_target('//evil.example/phish'));
    }

    public function testLocalRedirectTargetRejectsBackslashTrick(): void
    {
        $this->assertSame('/', local_redirect_target('/\\evil.example'));
    }

    public function testLocalRedirectTargetDefaultsToRootForEmpty(): void
    {
        $this->assertSame('/', local_redirect_target(''));
        $this->assertSame('/', local_redirect_target(null));
    }
}

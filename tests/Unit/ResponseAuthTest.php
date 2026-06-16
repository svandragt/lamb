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

    public function testRedirectLoginReturnsEmptyArrayWhenNoPostData(): void
    {
        // No POST at all — login form should be rendered
        $result = redirect_login();
        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    public function testRedirectLoginReturnsEmptyArrayWhenSubmitKeyAbsent(): void
    {
        $_POST['other_field'] = 'value';
        $result = redirect_login();
        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    public function testRedirectLoginReturnsEmptyArrayWhenSubmitValueIsNotLogin(): void
    {
        $_POST['submit'] = 'some other action';
        $result = redirect_login();
        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    public function testRedirectLoginReturnsEmptyArrayWhenSubmitValueIsEmpty(): void
    {
        $_POST['submit'] = '';
        $result = redirect_login();
        $this->assertIsArray($result);
        $this->assertCount(0, $result);
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

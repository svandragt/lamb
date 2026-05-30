<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Response\local_redirect_target;
use function Lamb\Response\redirect_login;

class ResponseAuthTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        $_POST    = [];
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
        $_SERVER['REQUEST_URI']     = '/';

        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'http://localhost');
        }
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST    = [];
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

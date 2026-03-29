<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

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
}

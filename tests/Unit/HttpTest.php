<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Http\get_request_uri;

class HttpTest extends TestCase
{
    private string $originalRequestUri;

    protected function setUp(): void
    {
        $this->originalRequestUri = $_SERVER['REQUEST_URI'] ?? '';
    }

    protected function tearDown(): void
    {
        $_SERVER['REQUEST_URI'] = $this->originalRequestUri;
    }

    public function testGetRequestUriReturnsHomeForRootPath(): void
    {
        $_SERVER['REQUEST_URI'] = '/';
        $this->assertSame('/home', get_request_uri());
    }

    public function testGetRequestUriReturnsPathAsIsForNonRoot(): void
    {
        $_SERVER['REQUEST_URI'] = '/about';
        $this->assertSame('/about', get_request_uri());
    }

    public function testGetRequestUriStripsQueryString(): void
    {
        $_SERVER['REQUEST_URI'] = '/about?page=2&foo=bar';
        $this->assertSame('/about', get_request_uri());
    }

    public function testGetRequestUriStripsQueryStringFromRoot(): void
    {
        $_SERVER['REQUEST_URI'] = '/?redirect_to=/home';
        $this->assertSame('/home', get_request_uri());
    }

    public function testGetRequestUriReturnsDeepPath(): void
    {
        $_SERVER['REQUEST_URI'] = '/tag/php';
        $this->assertSame('/tag/php', get_request_uri());
    }
}

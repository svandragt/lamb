<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Http\build_http_context_options;
use function Lamb\Http\fetch;

class HttpFetchTest extends TestCase
{
    // build_http_context_options -------------------------------------------

    public function testDefaultOptionsAreGetWithDefaultUserAgent(): void
    {
        $opts = build_http_context_options([]);

        $this->assertSame('GET', $opts['method']);
        $this->assertSame(1, $opts['follow_location']);
        $this->assertSame(5, $opts['max_redirects']);
        $this->assertTrue($opts['ignore_errors']);
        $this->assertStringContainsString('User-Agent: Lamb-Webmention', $opts['header']);
    }

    public function testCustomHeadersAreAssembledInOrder(): void
    {
        $opts = build_http_context_options([
            'headers' => ['Accept: text/html, */*', 'User-Agent: Lamb-Webmention'],
        ]);

        $this->assertSame("Accept: text/html, */*\r\nUser-Agent: Lamb-Webmention", $opts['header']);
    }

    public function testTimeoutIsPassedThrough(): void
    {
        $opts = build_http_context_options(['timeout' => 5]);
        $this->assertSame(5, $opts['timeout']);
    }

    public function testRedirectOptionsCanBeOmitted(): void
    {
        // introspectToken historically set neither follow_location nor max_redirects.
        $opts = build_http_context_options([
            'follow_location' => null,
            'max_redirects' => null,
        ]);

        $this->assertArrayNotHasKey('follow_location', $opts);
        $this->assertArrayNotHasKey('max_redirects', $opts);
    }

    public function testPostMethodWithBody(): void
    {
        $opts = build_http_context_options([
            'method' => 'POST',
            'content' => 'source=a&target=b',
        ]);

        $this->assertSame('POST', $opts['method']);
        $this->assertSame('source=a&target=b', $opts['content']);
    }

    // fetch -----------------------------------------------------------------

    public function testFetchReturnsNullOnTransportFailure(): void
    {
        // A scheme/host that cannot be opened yields file_get_contents === false.
        $result = @fetch('file:///nonexistent/path/that/does/not/exist/at/all');
        $this->assertNull($result);
    }

    public function testFetchReadsBodyFromDataUrl(): void
    {
        $result = @fetch('data://text/plain,hello-world');

        $this->assertNotNull($result);
        $this->assertSame('hello-world', $result['body']);
        $this->assertIsArray($result['headers']);
        $this->assertIsInt($result['status']);
    }
}

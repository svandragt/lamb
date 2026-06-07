<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Http\build_http_context_options;
use function Lamb\Http\fetch;
use function Lamb\Http\parse_status_line;
use function Lamb\Http\post_form;

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

    // parse_status_line ------------------------------------------------------

    public function testParseStatusLineWithReasonPhrase(): void
    {
        $this->assertSame(200, parse_status_line('HTTP/1.1 200 OK'));
    }

    public function testParseStatusLineWithoutReasonPhrase(): void
    {
        // An empty reason phrase is valid; some servers omit it entirely.
        $this->assertSame(200, parse_status_line('HTTP/1.1 200'));
    }

    public function testParseStatusLineReturnsZeroForGarbage(): void
    {
        $this->assertSame(0, parse_status_line(''));
        $this->assertSame(0, parse_status_line('not a status line'));
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

    // post_form ---------------------------------------------------------------

    public function testPostFormReturnsZeroOnTransportFailure(): void
    {
        $status = @post_form('file:///nonexistent/path/at/all', ['a' => 'b'], 1, 'Lamb-Test');
        $this->assertSame(0, $status);
    }
}

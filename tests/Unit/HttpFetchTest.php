<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Http\build_http_context_options;
use function Lamb\Http\fetch;
use function Lamb\Http\fetch_guarded;
use function Lamb\Http\is_private_ip;
use function Lamb\Http\is_public_http_url;
use function Lamb\Http\is_valid_http_url;
use function Lamb\Http\parse_status_line;
use function Lamb\Http\post_form;
use function Lamb\Http\resolve_redirect_location;
use function Lamb\Http\resolve_validated_ip;

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

    // fetch() with 'pin' (curl / CURLOPT_RESOLVE) ------------------------------

    /**
     * Starts a one-shot local HTTP responder in a subprocess bound to
     * 127.0.0.1, serving exactly one connection with the given raw response
     * bytes. Returns [process resource, port] — caller must proc_close().
     *
     * @return array{0: resource, 1: int}
     */
    private function startLocalResponder(string $rawResponse): array
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        $this->assertNotFalse($socket, "failed to bind local test listener: $errstr");
        $name = stream_socket_get_name($socket, false);
        $port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($socket);

        // The subprocess prints "READY\n" the instant it's bound and listening,
        // so the parent can wait on that instead of probing with a real
        // connection — a probe would consume the one accept() meant for the
        // test's actual request.
        $script = sprintf(
            '$s = stream_socket_server("tcp://127.0.0.1:%d", $e, $s2); '
            . 'echo "READY\n"; '
            . '$c = stream_socket_accept($s, 5); '
            . 'if ($c) { fread($c, 8192); fwrite($c, %s); fclose($c); }',
            $port,
            var_export($rawResponse, true)
        );

        $process = proc_open(
            ['php', '-r', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process);
        $this->assertSame("READY\n", fgets($pipes[1]));

        return [$process, $port];
    }

    public function testFetchPinnedConnectsToPinnedIpRegardlessOfHostname(): void
    {
        [$process, $port] = $this->startLocalResponder(
            "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\n\r\nhello-from-pinned-ip"
        );

        try {
            // The hostname does not resolve in real DNS at all — only
            // CURLOPT_RESOLVE (driven by the 'pin' opt) can make this connect.
            $result = fetch("http://nonexistent.invalid:$port/", [
                'pin' => ['host' => 'nonexistent.invalid', 'ip' => '127.0.0.1'],
            ]);
        } finally {
            proc_close($process);
        }

        $this->assertNotNull($result);
        $this->assertSame(200, $result['status']);
        $this->assertSame('hello-from-pinned-ip', $result['body']);
    }

    public function testFetchPinnedEnforcesMaxBytes(): void
    {
        [$process, $port] = $this->startLocalResponder(
            "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\n\r\n" . str_repeat('x', 100)
        );

        try {
            $result = fetch("http://nonexistent.invalid:$port/", [
                'pin' => ['host' => 'nonexistent.invalid', 'ip' => '127.0.0.1'],
                'max_bytes' => 10,
            ]);
        } finally {
            proc_close($process);
        }

        $this->assertNull($result);
    }

    // is_valid_http_url -------------------------------------------------------

    public function testIsValidHttpUrlAcceptsHttpAndHttps(): void
    {
        $this->assertTrue(is_valid_http_url('http://example.com/'));
        $this->assertTrue(is_valid_http_url('https://example.com/path'));
        // Scheme casing is normalised before the check.
        $this->assertTrue(is_valid_http_url('HTTPS://EXAMPLE.com/'));
    }

    public function testIsValidHttpUrlRejectsNonHttpAndHostless(): void
    {
        $this->assertFalse(is_valid_http_url('mailto:someone@example.com'));
        $this->assertFalse(is_valid_http_url('ftp://example.com/'));
        $this->assertFalse(is_valid_http_url('/relative/path'));
        $this->assertFalse(is_valid_http_url('not a url'));
        $this->assertFalse(is_valid_http_url(''));
    }

    // post_form ---------------------------------------------------------------

    public function testPostFormReturnsZeroOnTransportFailure(): void
    {
        $status = @post_form('file:///nonexistent/path/at/all', ['a' => 'b'], 1, 'Lamb-Test');
        $this->assertSame(0, $status);
    }

    // is_private_ip -----------------------------------------------------------

    public function testIsPrivateIpDetectsLoopbackAndPrivateRanges(): void
    {
        $this->assertTrue(is_private_ip('127.0.0.1'));
        $this->assertTrue(is_private_ip('10.0.0.1'));
        $this->assertTrue(is_private_ip('172.16.0.1'));
        $this->assertTrue(is_private_ip('192.168.1.1'));
        // Link-local, including the common cloud metadata address.
        $this->assertTrue(is_private_ip('169.254.169.254'));
        $this->assertTrue(is_private_ip('::1'));
    }

    public function testIsPrivateIpFalseForPublicAddress(): void
    {
        $this->assertFalse(is_private_ip('93.184.216.34'));
    }

    // is_public_http_url --------------------------------------------------------

    public function testIsPublicHttpUrlRejectsLiteralPrivateIp(): void
    {
        $this->assertFalse(is_public_http_url('http://127.0.0.1/'));
        $this->assertFalse(is_public_http_url('http://169.254.169.254/latest/meta-data/'));
        $this->assertFalse(is_public_http_url('http://[::1]/'));
    }

    public function testIsPublicHttpUrlAcceptsLiteralPublicIp(): void
    {
        $this->assertTrue(is_public_http_url('http://93.184.216.34/'));
    }

    public function testIsPublicHttpUrlRejectsMalformedUrl(): void
    {
        $this->assertFalse(is_public_http_url('not a url'));
        $this->assertFalse(is_public_http_url('ftp://example.com/'));
    }

    public function testIsPublicHttpUrlUsesInjectedResolverForHostnames(): void
    {
        $resolver = fn (string $host) => $host === 'evil.example' ? ['127.0.0.1'] : ['93.184.216.34'];

        $this->assertFalse(is_public_http_url('http://evil.example/', $resolver));
        $this->assertTrue(is_public_http_url('http://good.example/', $resolver));
    }

    public function testIsPublicHttpUrlRejectsWhenResolutionFails(): void
    {
        $resolver = fn (string $host) => [];
        $this->assertFalse(is_public_http_url('http://unresolvable.example/', $resolver));
    }

    public function testIsPublicHttpUrlRejectsWhenAnyResolvedIpIsPrivate(): void
    {
        // DNS rebinding / multi-record tricks: reject if *any* resolved address is private.
        $resolver = fn (string $host) => ['93.184.216.34', '127.0.0.1'];
        $this->assertFalse(is_public_http_url('http://mixed.example/', $resolver));
    }

    // resolve_validated_ip ------------------------------------------------------

    public function testResolveValidatedIpAcceptsLiteralPublicIp(): void
    {
        $this->assertSame('93.184.216.34', resolve_validated_ip('93.184.216.34'));
    }

    public function testResolveValidatedIpRejectsLiteralPrivateIp(): void
    {
        $this->assertFalse(resolve_validated_ip('127.0.0.1'));
    }

    public function testResolveValidatedIpUsesInjectedResolverForHostnames(): void
    {
        $resolver = fn (string $host) => $host === 'evil.example' ? ['127.0.0.1'] : ['93.184.216.34'];

        $this->assertFalse(resolve_validated_ip('evil.example', $resolver));
        $this->assertSame('93.184.216.34', resolve_validated_ip('good.example', $resolver));
    }

    public function testResolveValidatedIpRejectsWhenResolutionFails(): void
    {
        $resolver = fn (string $host) => [];
        $this->assertFalse(resolve_validated_ip('unresolvable.example', $resolver));
    }

    public function testResolveValidatedIpRejectsWhenAnyResolvedIpIsPrivate(): void
    {
        $resolver = fn (string $host) => ['93.184.216.34', '127.0.0.1'];
        $this->assertFalse(resolve_validated_ip('mixed.example', $resolver));
    }

    public function testResolveValidatedIpReturnsFirstResolvedPublicIp(): void
    {
        $resolver = fn (string $host) => ['93.184.216.34', '203.0.113.5'];
        $this->assertSame('93.184.216.34', resolve_validated_ip('good.example', $resolver));
    }

    // resolve_redirect_location -------------------------------------------------

    public function testResolveRedirectLocationPassesThroughAbsoluteUrl(): void
    {
        $this->assertSame(
            'https://other.example/x',
            resolve_redirect_location('https://example.com/a', 'https://other.example/x')
        );
    }

    public function testResolveRedirectLocationResolvesRootRelativePath(): void
    {
        $this->assertSame(
            'https://example.com/new',
            resolve_redirect_location('https://example.com/a/b', '/new')
        );
    }

    public function testResolveRedirectLocationResolvesProtocolRelative(): void
    {
        $this->assertSame(
            'https://other.example/x',
            resolve_redirect_location('https://example.com/a', '//other.example/x')
        );
    }

    public function testResolveRedirectLocationResolvesRelativePathAgainstDirectory(): void
    {
        $this->assertSame(
            'https://example.com/a/next',
            resolve_redirect_location('https://example.com/a/b', 'next')
        );
    }

    // fetch_guarded -------------------------------------------------------------

    public function testFetchGuardedRejectsPrivateDestination(): void
    {
        $this->assertNull(fetch_guarded('http://127.0.0.1/secret'));
    }

    public function testFetchGuardedRejectsWhenResolvedHostIsPrivate(): void
    {
        $resolver = fn (string $host) => ['127.0.0.1'];
        $this->assertNull(fetch_guarded('http://evil.example/', [], 5, $resolver));
    }

    public function testFetchGuardedRejectsMalformedUrl(): void
    {
        $this->assertNull(fetch_guarded('not a url'));
    }

    public function testFetchGuardedPinsTheResolvedIpOnTheFetcherCall(): void
    {
        $resolver = fn (string $host) => ['93.184.216.34'];
        $calls = [];
        $fetcher = function (string $url, array $opts) use (&$calls) {
            $calls[] = [$url, $opts];
            return ['status' => 200, 'headers' => ['HTTP/1.1 200 OK'], 'body' => 'ok'];
        };

        $result = fetch_guarded('http://good.example/path', [], 5, $resolver, $fetcher);

        $this->assertSame('ok', $result['body']);
        $this->assertCount(1, $calls);
        $this->assertSame(
            ['host' => 'good.example', 'ip' => '93.184.216.34'],
            $calls[0][1]['pin']
        );
    }

    public function testFetchGuardedResolvesAndPinsEachRedirectHopIndependently(): void
    {
        $resolver = fn (string $host) => match ($host) {
            'first.example' => ['93.184.216.34'],
            'second.example' => ['203.0.113.5'],
            default => [],
        };
        $calls = [];
        $fetcher = function (string $url, array $opts) use (&$calls) {
            $calls[] = [$url, $opts];
            if ($url === 'http://first.example/') {
                return [
                    'status' => 302,
                    'headers' => ['HTTP/1.1 302 Found', 'Location: http://second.example/'],
                    'body' => '',
                ];
            }
            return ['status' => 200, 'headers' => ['HTTP/1.1 200 OK'], 'body' => 'final'];
        };

        $result = fetch_guarded('http://first.example/', [], 5, $resolver, $fetcher);

        $this->assertSame('final', $result['body']);
        $this->assertCount(2, $calls);
        $this->assertSame(['host' => 'first.example', 'ip' => '93.184.216.34'], $calls[0][1]['pin']);
        $this->assertSame(['host' => 'second.example', 'ip' => '203.0.113.5'], $calls[1][1]['pin']);
    }

    public function testFetchGuardedRejectsRedirectToPrivateHostOnLaterHop(): void
    {
        $resolver = fn (string $host) => match ($host) {
            'first.example' => ['93.184.216.34'],
            'evil.example' => ['127.0.0.1'],
            default => [],
        };
        $fetcher = function (string $url, array $opts) {
            if ($url === 'http://first.example/') {
                return [
                    'status' => 302,
                    'headers' => ['HTTP/1.1 302 Found', 'Location: http://evil.example/'],
                    'body' => '',
                ];
            }
            return ['status' => 200, 'headers' => [], 'body' => 'should never be reached'];
        };

        $result = fetch_guarded('http://first.example/', [], 5, $resolver, $fetcher);

        $this->assertNull($result);
    }
}

<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Http\extract_page_segment;
use function Lamb\Http\get_request_uri;
use function Lamb\Http\resolve_host_ips;
use function Lamb\Http\page_path;
use function Lamb\Http\request_string;
use function Lamb\Http\requested_path;

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

    // requested_path — the visitor-facing reading of the request path, as
    // opposed to get_request_uri()'s router-facing one (which keeps the leading
    // slash and rewrites `/` to `/home`).

    public function testRequestedPathStripsSurroundingSlashes(): void
    {
        $_SERVER['REQUEST_URI'] = '/no-such-page/';
        $this->assertSame('no-such-page', requested_path());
    }

    public function testRequestedPathStripsQueryString(): void
    {
        $_SERVER['REQUEST_URI'] = '/tag/php?utm=1';
        $this->assertSame('tag/php', requested_path());
    }

    public function testRequestedPathIsEmptyForTheRoot(): void
    {
        $_SERVER['REQUEST_URI'] = '/';
        $this->assertSame('', requested_path());
    }

    public function testRequestedPathIsEmptyWhenRequestUriIsAbsent(): void
    {
        unset($_SERVER['REQUEST_URI']);
        $this->assertSame('', requested_path());
    }

    public function testRequestedPathDoesNotRewriteTheRootToHome(): void
    {
        // get_request_uri() maps `/` to `/home` for routing; showing "home" back
        // to a visitor as the thing they asked for would be wrong.
        $_SERVER['REQUEST_URI'] = '/';
        $this->assertNotSame('home', requested_path());
    }

    public function testExtractPageSegmentFromRootPageMapsToHome(): void
    {
        $this->assertSame(['/home', 2], extract_page_segment('/page/2'));
    }

    public function testExtractPageSegmentFromTagPath(): void
    {
        $this->assertSame(['/tag/foo', 3], extract_page_segment('/tag/foo/page/3'));
    }

    public function testExtractPageSegmentFromSearchPath(): void
    {
        $this->assertSame(['/search/foo', 5], extract_page_segment('/search/foo/page/5'));
    }

    public function testExtractPageSegmentWithoutPageReturnsUriUnchanged(): void
    {
        $this->assertSame(['/tag/foo', null], extract_page_segment('/tag/foo'));
    }

    public function testExtractPageSegmentLeavesNonNumericPageAlone(): void
    {
        $this->assertSame(['/page/abc', null], extract_page_segment('/page/abc'));
    }

    public function testExtractPageSegmentToleratesTrailingSlash(): void
    {
        $this->assertSame(['/tag/foo', 2], extract_page_segment('/tag/foo/page/2/'));
    }

    public function testExtractPageSegmentClampsToAtLeastOne(): void
    {
        $this->assertSame(['/home', 1], extract_page_segment('/page/0'));
    }

    public function testPagePathAppendsSegmentForLaterPages(): void
    {
        $this->assertSame('/tag/foo/page/2', page_path('/tag/foo', 2));
    }

    public function testPagePathReturnsBaseForFirstPage(): void
    {
        $this->assertSame('/tag/foo', page_path('/tag/foo', 1));
    }

    public function testPagePathHomeFirstPageIsRoot(): void
    {
        $this->assertSame('/', page_path('/', 1));
    }

    public function testPagePathHomeLaterPageHasNoBase(): void
    {
        $this->assertSame('/page/3', page_path('/', 3));
    }

    public function testPagePathStripsExistingPageSegment(): void
    {
        $this->assertSame('/tag/foo/page/4', page_path('/tag/foo/page/2', 4));
    }
    // request_string — a request value can always arrive as an array, and PHP 8
    // fatals at the first string-typed sink rather than coercing it.

    public function testRequestStringPassesStringsThrough(): void
    {
        $this->assertSame('needle', request_string('needle'));
        $this->assertSame('', request_string(''));
    }

    public function testRequestStringReportsAnArrayAsAbsent(): void
    {
        $this->assertNull(request_string(['x']));
        $this->assertNull(request_string(['a' => 'b']));
        $this->assertNull(request_string([]));
    }

    public function testRequestStringReportsMissingAndNonTextValuesAsAbsent(): void
    {
        $this->assertNull(request_string(null));
        $this->assertNull(request_string(true));
        $this->assertNull(request_string(new \stdClass()));
    }

    public function testRequestStringRendersNumbersAsText(): void
    {
        $this->assertSame('42', request_string(42));
        $this->assertSame('1.5', request_string(1.5));
    }

    // resolve_host_ips() runs before curl connects, and neither dns_get_record()
    // nor gethostbyname() takes a timeout — so it caps the resolver via
    // RES_OPTIONS, keeping a black-holed nameserver from holding the /_cron flock
    // unbounded (#707). '.invalid' never resolves, so this needs no live network.
    public function testResolveHostIpsBoundsTheResolver(): void
    {
        resolve_host_ips('nonexistent.invalid');

        $this->assertStringContainsString('timeout:5 attempts:2', (string) getenv('RES_OPTIONS'));
    }
}

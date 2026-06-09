<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Http\extract_page_segment;
use function Lamb\Http\get_request_uri;
use function Lamb\Http\page_path;

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
}

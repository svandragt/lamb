<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Http\extract_page_segment;
use function Lamb\Http\get_request_uri;
use function Lamb\Http\page_path;
use function Lamb\Http\requested_path;
use function Lamb\Http\app_path;
use function Lamb\Http\strip_base_path;

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

    // Subdirectory installs (issue #580). An install served under
    // https://example.com/blog still receives `/blog/...` in REQUEST_URI, so the
    // base has to come off before the router segments the path — otherwise every
    // request routes on the action "blog".

    public function testStripBasePathLeavesTheUriAloneWhenThereIsNoBase(): void
    {
        $this->assertSame('/about', strip_base_path('/about', ''));
        $this->assertSame('/', strip_base_path('/', ''));
    }

    public function testStripBasePathRemovesTheBase(): void
    {
        $this->assertSame('/about', strip_base_path('/blog/about', '/blog'));
        $this->assertSame('/tag/php', strip_base_path('/blog/tag/php', '/blog'));
        $this->assertSame('/about', strip_base_path('/a/b/about', '/a/b'));
    }

    public function testStripBasePathMapsTheBaseItselfToTheRoot(): void
    {
        $this->assertSame('/', strip_base_path('/blog', '/blog'));
        $this->assertSame('/', strip_base_path('/blog/', '/blog'));
    }

    public function testStripBasePathOnlyMatchesWholeSegments(): void
    {
        // `/blogger` starts with `/blog` as a string but is not inside it. Cutting
        // on the raw prefix would route it as the action "ger".
        $this->assertSame('/blogger', strip_base_path('/blogger', '/blog'));
        $this->assertSame('/blogging/x', strip_base_path('/blogging/x', '/blog'));
    }

    public function testStripBasePathIgnoresAPathOutsideTheBase(): void
    {
        // Nothing should reach the router under a base it does not sit in; leaving
        // it whole lets the normal 404 handle it rather than inventing a route.
        $this->assertSame('/elsewhere', strip_base_path('/elsewhere', '/blog'));
    }

    public function testStripBasePathIsCaseSensitive(): void
    {
        // Paths are case-sensitive, and the base came from the author's configured
        // site_url verbatim.
        $this->assertSame('/Blog/about', strip_base_path('/Blog/about', '/blog'));
    }

    public function testAppPathPrefixesTheBase(): void
    {
        // The counterpart to strip_base_path(): links and redirects are written
        // as the app's own root-relative paths, and this is where the base goes
        // back on so they point inside the install rather than at the domain root.
        $this->assertSame('/blog/login', app_path('/login', '/blog'));
        $this->assertSame('/blog/tag/php', app_path('/tag/php', '/blog'));
        $this->assertSame('/blog/', app_path('/', '/blog'));
    }

    public function testAppPathIsIdentityAtTheDomainRoot(): void
    {
        $this->assertSame('/login', app_path('/login', ''));
        $this->assertSame('/', app_path('/', ''));
    }

    public function testAppPathAndStripBasePathRoundTrip(): void
    {
        foreach (['/login', '/tag/php', '/search/a%20b'] as $path) {
            $this->assertSame($path, strip_base_path(app_path($path, '/blog'), '/blog'), $path);
        }
    }

    public function testGetRequestUriStripsTheBasePath(): void
    {
        $_SERVER['REQUEST_URI'] = '/blog/about?utm=1';
        $this->assertSame('/about', get_request_uri('/blog'));
    }

    public function testGetRequestUriMapsTheBaseRootToHome(): void
    {
        $_SERVER['REQUEST_URI'] = '/blog';
        $this->assertSame('/home', get_request_uri('/blog'));

        $_SERVER['REQUEST_URI'] = '/blog/';
        $this->assertSame('/home', get_request_uri('/blog'));
    }

    public function testRequestedPathStripsTheBasePath(): void
    {
        // The visitor asked for `/blog/no-such-page`; the thing that was not found
        // is `no-such-page`, so a 404 must not offer to search for "blog".
        $_SERVER['REQUEST_URI'] = '/blog/no-such-page';
        $this->assertSame('no-such-page', requested_path('/blog'));
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
}

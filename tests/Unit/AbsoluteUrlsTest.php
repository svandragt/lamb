<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\absolute_urls;

/**
 * absolute_urls() rewrites root-relative src/href attributes to absolute URLs.
 *
 * Posts store image links as root-relative URLs ("/assets/Y/m/hash.webp") via
 * asset_url() so they survive a domain change. That works on-site, but feed
 * readers and syndication services (micro.blog, etc.) that re-host the content
 * resolve "/assets/..." against their own host, leaving a broken image. Feed
 * templates absolutise the content so syndicated images load.
 */
class AbsoluteUrlsTest extends TestCase
{
    public function testRewritesRootRelativeImageSrc(): void
    {
        $html = '<p><img src="/assets/2026/06/abc.webp" alt=""></p>';
        $this->assertSame(
            '<p><img src="https://example.com/assets/2026/06/abc.webp" alt=""></p>',
            absolute_urls($html, 'https://example.com')
        );
    }

    public function testRewritesRootRelativeAnchorHref(): void
    {
        $html = '<a href="/tag/php">#php</a>';
        $this->assertSame(
            '<a href="https://example.com/tag/php">#php</a>',
            absolute_urls($html, 'https://example.com')
        );
    }

    public function testStripsTrailingSlashFromBase(): void
    {
        $html = '<img src="/x.webp">';
        $this->assertSame(
            '<img src="https://example.com/x.webp">',
            absolute_urls($html, 'https://example.com/')
        );
    }

    public function testLeavesAbsoluteUrlsUntouched(): void
    {
        $html = '<img src="https://cdn.example.org/cat.jpg">';
        $this->assertSame($html, absolute_urls($html, 'https://example.com'));
    }

    public function testLeavesProtocolRelativeUrlsUntouched(): void
    {
        $html = '<img src="//cdn.example.org/cat.jpg">';
        $this->assertSame($html, absolute_urls($html, 'https://example.com'));
    }

    public function testLeavesContentWithoutLinksUntouched(): void
    {
        $html = '<p>Just some text, no links.</p>';
        $this->assertSame($html, absolute_urls($html, 'https://example.com'));
    }

    public function testRewritesMultipleAttributesInOnePass(): void
    {
        $html = '<a href="/status/1"><img src="/assets/a.webp"></a>';
        $this->assertSame(
            '<a href="https://example.com/status/1"><img src="https://example.com/assets/a.webp"></a>',
            absolute_urls($html, 'https://example.com')
        );
    }
}

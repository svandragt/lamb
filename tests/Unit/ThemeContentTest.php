<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Theme\the_content;

/**
 * the_content() is the single seam every theme renders a post body through.
 *
 * Post bodies store asset links root-relative (`/assets/…`) on purpose, so the
 * content survives a domain change. That resolves against the domain root, which
 * is the wrong place on an install served from a subdirectory (issue #580), so
 * the install's base goes on here — at render time, leaving what is stored
 * portable.
 */
class ThemeContentTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
    }

    private function post(string $transformed): \RedBeanPHP\OODBBean
    {
        $bean = R::dispense('post');
        $bean->transformed = $transformed;

        return $bean;
    }

    public function testPrefixesTheBaseOntoStoredAssetUrls(): void
    {
        $html = the_content($this->post('<p><img src="/assets/2026/08/x.webp" alt=""></p>'), null, '/blog');

        $this->assertStringContainsString('src="/blog/assets/2026/08/x.webp"', $html);
    }

    public function testLeavesContentAloneAtTheDomainRoot(): void
    {
        $stored = '<p><img src="/assets/2026/08/x.webp" alt=""></p>';

        $this->assertStringContainsString('src="/assets/2026/08/x.webp"', the_content($this->post($stored), null, ''));
    }

    public function testLeavesAbsoluteAndProtocolRelativeUrlsAlone(): void
    {
        $stored = '<a href="https://other.example/x">a</a><img src="//cdn.example/y.png" alt="">';
        $html = the_content($this->post($stored), null, '/blog');

        $this->assertStringContainsString('href="https://other.example/x"', $html);
        $this->assertStringContainsString('src="//cdn.example/y.png"', $html);
    }

    public function testAppliesHeadingAnchorsWhenALevelIsGiven(): void
    {
        $html = the_content($this->post('<h2>A heading</h2>'), 3, '');

        // anchor_headings() shifts the level and adds the anchor id.
        $this->assertStringContainsString('<h3', $html);
    }

    public function testSkipsHeadingAnchorsWithoutALevel(): void
    {
        $html = the_content($this->post('<h2>A heading</h2>'), null, '');

        $this->assertStringContainsString('<h2>A heading</h2>', $html);
    }

    public function testAPathAlreadyCarryingTheBaseIsPrefixedAgain(): void
    {
        // Known and deliberate. A stored path is written as the app sees it —
        // `/assets/…`, no base — which is what the editor and uploader produce.
        // A hand-pasted `/blog/assets/…` gets the base a second time.
        //
        // It cannot be detected away: a post slugged `blog` on a `/blog` install
        // is stored as `/blog` and must become `/blog/blog`, so "starts with the
        // base" does not separate an already-prefixed path from a genuine one.
        // Anything that skips the second prefix breaks that post instead.
        $html = the_content($this->post('<img src="/blog/assets/x.webp" alt="">'), null, '/blog');
        $this->assertStringContainsString('src="/blog/blog/assets/x.webp"', $html);

        $slugged = the_content($this->post('<a href="/blog">the blog post</a>'), null, '/blog');
        $this->assertStringContainsString('href="/blog/blog"', $slugged);
    }

    public function testEmptyContentStaysEmpty(): void
    {
        $this->assertSame('', the_content($this->post(''), null, '/blog'));
    }
}

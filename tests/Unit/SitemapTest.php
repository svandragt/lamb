<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Response\render_sitemap;
use function Lamb\Response\sitemap_urls;

/**
 * The sitemap lists every publicly visible URL for crawlers. It must reuse the
 * canonical visible_clause() so it includes the home page and published posts
 * (including menu/standalone pages) while omitting drafts, deleted posts, and
 * posts scheduled for the future.
 */
class SitemapTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::nuke();

        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'https://example.com');
        }
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function makePost(array $fields): int
    {
        $post = R::dispense('post');
        $post->body = $fields['body'] ?? 'Body';
        $post->slug = $fields['slug'] ?? null;
        $post->draft = $fields['draft'] ?? 0;
        $post->deleted = $fields['deleted'] ?? 0;
        $post->created = $fields['created'] ?? '2026-01-01 12:00:00';
        $post->updated = $fields['updated'] ?? '2026-01-01 12:00:00';
        return (int) R::store($post);
    }

    private function locs(): array
    {
        return array_column(sitemap_urls(), 'loc');
    }

    public function testIncludesHomePageFirst(): void
    {
        $urls = sitemap_urls();
        $this->assertNotEmpty($urls);
        $this->assertSame(ROOT_URL . '/', $urls[0]['loc']);
    }

    public function testListsPublishedStatusPost(): void
    {
        $id = $this->makePost([]);
        $this->assertContains(ROOT_URL . "/status/$id", $this->locs());
    }

    public function testListsPublishedSluggedPage(): void
    {
        $this->makePost(['slug' => 'about']);
        $this->assertContains(ROOT_URL . '/about', $this->locs());
    }

    public function testOmitsDraft(): void
    {
        $id = $this->makePost(['draft' => 1]);
        $this->assertNotContains(ROOT_URL . "/status/$id", $this->locs());
    }

    public function testOmitsDeleted(): void
    {
        $id = $this->makePost(['deleted' => 1]);
        $this->assertNotContains(ROOT_URL . "/status/$id", $this->locs());
    }

    public function testOmitsFutureScheduledPost(): void
    {
        $id = $this->makePost(['created' => '2099-01-01 00:00:00']);
        $this->assertNotContains(ROOT_URL . "/status/$id", $this->locs());
    }

    public function testDeduplicatesPostsSharingASlug(): void
    {
        // Two distinct posts can end up with the same slug; the sitemap must
        // still list that canonical URL only once (duplicate <loc>s are invalid).
        $this->makePost(['slug' => 'dup', 'updated' => '2026-06-01 09:00:00']);
        $this->makePost(['slug' => 'dup', 'updated' => '2026-06-02 09:00:00']);

        $locs = $this->locs();
        $matches = array_filter($locs, static fn ($loc) => $loc === ROOT_URL . '/dup');
        $this->assertCount(1, $matches);
    }

    public function testDeduplicatedEntryKeepsNewestLastmod(): void
    {
        // Posts are ordered newest-first, so the surviving entry keeps the
        // freshest post's lastmod.
        $this->makePost(['slug' => 'dup', 'updated' => '2026-06-01 09:00:00']);
        $this->makePost(['slug' => 'dup', 'updated' => '2026-06-02 09:00:00']);

        $entry = null;
        foreach (sitemap_urls() as $url) {
            if ($url['loc'] === ROOT_URL . '/dup') {
                $entry = $url;
                break;
            }
        }
        $this->assertNotNull($entry);
        $this->assertSame(date('c', strtotime('2026-06-02 09:00:00')), $entry['lastmod']);
    }

    public function testEntryCarriesIso8601Lastmod(): void
    {
        $this->makePost(['updated' => '2026-06-01 09:30:00']);
        $urls = sitemap_urls();
        $entry = end($urls);
        $this->assertSame(date('c', strtotime('2026-06-01 09:30:00')), $entry['lastmod']);
    }

    public function testRenderSitemapWrapsUrlsInUrlset(): void
    {
        $xml = render_sitemap([
            ['loc' => 'https://example.com/', 'lastmod' => '2026-06-01T09:30:00+00:00'],
        ]);
        $this->assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        $this->assertStringContainsString('http://www.sitemaps.org/schemas/sitemap/0.9', $xml);
        $this->assertStringContainsString('<loc>https://example.com/</loc>', $xml);
        $this->assertStringContainsString('<lastmod>2026-06-01T09:30:00+00:00</lastmod>', $xml);
    }

    public function testRenderSitemapEscapesAmpersandsInLoc(): void
    {
        $xml = render_sitemap([['loc' => 'https://example.com/a?b=1&c=2', 'lastmod' => null]]);
        $this->assertStringContainsString('https://example.com/a?b=1&amp;c=2', $xml);
        $this->assertStringNotContainsString('&c=2', $xml);
    }

    public function testRenderSitemapKeepsLocWithMalformedUtf8(): void
    {
        // A slug may carry a malformed UTF-8 byte. Without ENT_SUBSTITUTE,
        // htmlspecialchars() returns '' for the whole string, emitting an empty
        // <loc></loc> — invalid, since <loc> is required. The bad byte must
        // degrade to U+FFFD instead, leaving the rest of the URL intact.
        $xml = render_sitemap([['loc' => "https://example.com/b\xC3\x28d", 'lastmod' => null]]);
        $this->assertStringNotContainsString('<loc></loc>', $xml);
        $this->assertStringContainsString('https://example.com/b', $xml);
    }

    public function testRenderSitemapOmitsEmptyLastmod(): void
    {
        $xml = render_sitemap([['loc' => 'https://example.com/', 'lastmod' => null]]);
        $this->assertStringNotContainsString('<lastmod>', $xml);
    }
}

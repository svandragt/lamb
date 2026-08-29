<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Response\count_visible_posts;
use function Lamb\Response\emit_sitemap;
use function Lamb\Response\newest_visible_update;
use function Lamb\Response\render_sitemap;
use function Lamb\Response\render_sitemap_index;
use function Lamb\Response\sitemap_cache_key;
use function Lamb\Response\sitemap_cache_path;
use function Lamb\Response\sitemap_page_count;
use function Lamb\Response\sitemap_urls;
use function Lamb\Response\store_sitemap_cache;

use const Lamb\Response\SITEMAP_ROOT;

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

    /**
     * /sitemap.xml is anonymous and unpaginated — the feeds cap at 20 rows, this
     * lists every visible post — so reading whole beans put every body and every
     * rendered `transformed` blob in memory at once. Measured: ~14 MB at 2,000
     * posts, and at 20,000 a fatal "Allowed memory size of 134217728 bytes
     * exhausted", which is a blank 500 for every crawler on the images' default
     * 128M limit. The URL entries need three columns; nothing must widen that
     * back to the whole row.
     */
    public function testSitemapNeverReadsAPostBody(): void
    {
        $this->makePost(['slug' => 'hello-world', 'body' => 'Body text']);
        $this->makePost(['slug' => null]);

        R::debug(true, \RedBeanPHP\Logger\RDefault::C_LOGGER_ARRAY);
        try {
            sitemap_urls();
            $logs = R::getDatabaseAdapter()->getDatabase()->getLogger()->getLogs();
        } finally {
            R::debug(false);
        }

        // RedBean quotes the table name in the SQL it builds but not in SQL it is
        // handed, so the backticks come off before matching either form.
        $logs = array_map(static fn(string $sql): string => str_replace('`', '', $sql), $logs);
        $selects = array_values(array_filter(
            $logs,
            static fn(string $sql): bool => stripos($sql, 'SELECT') !== false
                && stripos($sql, 'FROM post') !== false
        ));

        $this->assertNotSame([], $selects, 'the sitemap should have queried the post table');
        foreach ($selects as $sql) {
            // The column list, not the whole statement: a wildcard here is
            // `post`.* as much as it is *, and either one drags the bodies in.
            $columns = (string) preg_replace('/^.*?SELECT(.*?)FROM.*$/is', '$1', $sql);
            $this->assertStringNotContainsString(
                '*',
                $columns,
                'the sitemap must name its columns rather than select them all: ' . $sql
            );
            $this->assertStringNotContainsStringIgnoringCase(
                'body',
                $columns,
                'the sitemap must not read post bodies: ' . $sql
            );
        }
    }

    /**
     * respond_sitemap() answers a conditional GET from this one row instead of
     * building all 25,000 URLs to read the date off the first one. It therefore
     * has to be the same date, or a crawler is handed a validator that does not
     * match the document it would have been sent.
     */
    public function testNewestVisibleUpdateMatchesTheSitemapsOwnLastmod(): void
    {
        $this->makePost(['slug' => 'old', 'updated' => '2026-06-01 09:00:00']);
        $this->makePost(['slug' => 'new', 'updated' => '2026-06-03 09:00:00']);
        $this->makePost(['slug' => 'mid', 'updated' => '2026-06-02 09:00:00']);

        $urls = sitemap_urls();

        $this->assertSame($urls[0]['lastmod'], date('c', (int) strtotime((string) newest_visible_update())));
    }

    public function testNewestVisibleUpdateIgnoresPostsTheSitemapOmits(): void
    {
        $this->makePost(['slug' => 'live', 'updated' => '2026-06-01 09:00:00']);
        // Each of these is newer, and each is excluded from the sitemap — so
        // none of them may become the validator either.
        $this->makePost(['slug' => 'draft', 'draft' => 1, 'updated' => '2026-07-01 09:00:00']);
        $this->makePost(['slug' => 'trash', 'deleted' => 1, 'updated' => '2026-07-02 09:00:00']);
        $this->makePost(['slug' => 'future', 'created' => '2099-01-01 00:00:00', 'updated' => '2026-07-03 09:00:00']);

        $this->assertSame('2026-06-01 09:00:00', newest_visible_update());
    }

    public function testNewestVisibleUpdateIsNullWithNoVisiblePosts(): void
    {
        $this->makePost(['slug' => 'draft', 'draft' => 1]);

        $this->assertNull(newest_visible_update());
    }

    private function cacheDir(): string
    {
        $dir = sys_get_temp_dir() . '/lamb-sitemap-cache-' . getmypid();
        if (!is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        foreach (glob($dir . '/*') ?: [] as $f) {
            unlink($f);
        }
        return $dir;
    }

    private function emit(string $path): string
    {
        ob_start();
        emit_sitemap($path);
        return (string) ob_get_clean();
    }

    // The cache key turns over exactly when the ETag does, so a cached copy is
    // never served under a validator describing something else.

    public function testCacheKeyIsStableForTheSameInputs(): void
    {
        $this->assertSame(
            sitemap_cache_key('2026-06-01 09:00:00', 1000),
            sitemap_cache_key('2026-06-01 09:00:00', 1000)
        );
    }

    public function testCacheKeyChangesWithEachInput(): void
    {
        $base = sitemap_cache_key('2026-06-01 09:00:00', 1000);

        $this->assertNotSame($base, sitemap_cache_key('2026-06-02 09:00:00', 1000));
        $this->assertNotSame($base, sitemap_cache_key('2026-06-01 09:00:00', 2000));
    }

    public function testCacheKeyIsFilenameSafe(): void
    {
        $key = sitemap_cache_key('2026-06-01 09:00:00', 1000);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $key);
        $this->assertStringContainsString($key, sitemap_cache_path($key));
    }

    /**
     * The cached document must carry no host at all. With no `site_url`
     * configured ROOT_URL comes from the request's own Host header, so a cache
     * holding a rendered host would serve whatever host one visitor claimed to
     * every later visitor — and keying the cache per host instead would let
     * junk Host headers evict the honest entry. Storing a placeholder and
     * substituting on the way out removes both problems.
     */
    public function testTheCachedCopyHoldsNoHost(): void
    {
        $this->makePost(['slug' => 'hello']);
        $path = $this->cacheDir() . '/sitemap-nohost.xml';

        $this->emit($path);
        $cached = (string) file_get_contents($path);

        $this->assertStringContainsString('<loc>' . SITEMAP_ROOT . '/hello</loc>', $cached);
        $this->assertStringNotContainsString(ROOT_URL, $cached);
    }

    public function testAMissRendersTheCurrentHostAndWritesTheCache(): void
    {
        $this->makePost(['slug' => 'hello']);
        $path = $this->cacheDir() . '/sitemap-miss.xml';

        $body = $this->emit($path);

        // Byte-for-byte what a direct render produces, so the cache can never
        // change the document — only where it comes from.
        $this->assertSame(render_sitemap(sitemap_urls()), $body);
        $this->assertFileExists($path);
    }

    public function testAHitSubstitutesTheHostIntoTheCachedTemplate(): void
    {
        $this->makePost(['slug' => 'hello']);
        $path = $this->cacheDir() . '/sitemap-hit.xml';
        $this->emit($path);

        // Prove it came from the file, not the database: the row is gone.
        R::exec('DELETE FROM post');

        $body = $this->emit($path);

        $this->assertStringContainsString('<loc>' . ROOT_URL . '/hello</loc>', $body);
        $this->assertStringNotContainsString(SITEMAP_ROOT, $body);
    }

    // The host substituted on the way out is escaped through the one XML
    // escaper (Theme\escape_xml()), not an inline copy of its flags — so both
    // <loc> call sites in this file stay converged (#752).
    public function testSubstitutedHostIsEscapedViaEscapeXml(): void
    {
        $this->makePost(['slug' => 'hello']);
        $path = $this->cacheDir() . '/sitemap-escape.xml';

        $body = $this->emit($path);

        $this->assertStringContainsString(
            '<loc>' . \Lamb\Theme\escape_xml(ROOT_URL) . '/hello</loc>',
            $body
        );
    }

    public function testStoringACopyRemovesTheOlderOnes(): void
    {
        $dir = $this->cacheDir();
        $stale = $dir . '/sitemap-oldkey.xml';
        file_put_contents($stale, 'old');
        $current = $dir . '/sitemap-newkey.xml';

        store_sitemap_cache($current, 'new');

        $this->assertFileExists($current);
        $this->assertFileDoesNotExist($stale);
        $this->assertSame([], glob($dir . '/*.tmp') ?: []);
    }

    public function testAnUnwritableCacheDirectoryStillServesTheSitemap(): void
    {
        $this->makePost(['slug' => 'hello']);
        // A path under a file, so neither mkdir() nor the write can succeed.
        $blocker = $this->cacheDir() . '/not-a-dir';
        file_put_contents($blocker, 'x');

        $body = $this->emit($blocker . '/sitemap-x.xml');

        $this->assertStringContainsString('<loc>' . ROOT_URL . '/hello</loc>', $body);
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

    // Sitemap index: sitemaps.org caps a single document at 50,000 URLs, so a
    // site past that splits into an index of /sitemap.xml/page/N children.
    // Seeding 50,000 posts is impractical here, so the cap is injectable
    // throughout — sitemap_page_count() and sitemap_urls() take it as an
    // optional param instead of always reading SITEMAP_MAX_URLS.

    public function testCountVisiblePostsMatchesTheVisibleClause(): void
    {
        $this->makePost([]);
        $this->makePost(['slug' => 'about']);
        $this->makePost(['draft' => 1]);
        $this->makePost(['deleted' => 1]);
        $this->makePost(['created' => '2099-01-01 00:00:00']);

        $this->assertSame(2, count_visible_posts());
    }

    public function testSitemapPageCountIsOneUnderTheCap(): void
    {
        $this->assertSame(1, sitemap_page_count(1, 2));
        $this->assertSame(1, sitemap_page_count(2, 2));
    }

    public function testSitemapPageCountRoundsUpPastTheCap(): void
    {
        $this->assertSame(2, sitemap_page_count(3, 2));
        $this->assertSame(2, sitemap_page_count(4, 2));
        $this->assertSame(3, sitemap_page_count(5, 2));
    }

    public function testSitemapUrlsPageOneIncludesHomeAndTheFirstSlice(): void
    {
        // Cap of 2: page 1 holds the home entry plus one post (entry 0 is the
        // home page, so page 1's post budget is cap - 1).
        $this->makePost(['slug' => 'a', 'updated' => '2026-06-03 09:00:00']);
        $this->makePost(['slug' => 'b', 'updated' => '2026-06-02 09:00:00']);
        $this->makePost(['slug' => 'c', 'updated' => '2026-06-01 09:00:00']);

        $locs = array_column(sitemap_urls(ROOT_URL, 1, 2), 'loc');

        $this->assertSame([ROOT_URL . '/', ROOT_URL . '/a'], $locs);
    }

    public function testSitemapUrlsPageTwoOffsetsPastTheFirstSlice(): void
    {
        $this->makePost(['slug' => 'a', 'updated' => '2026-06-03 09:00:00']);
        $this->makePost(['slug' => 'b', 'updated' => '2026-06-02 09:00:00']);
        $this->makePost(['slug' => 'c', 'updated' => '2026-06-01 09:00:00']);

        $locs = array_column(sitemap_urls(ROOT_URL, 2, 2), 'loc');

        $this->assertSame([ROOT_URL . '/b', ROOT_URL . '/c'], $locs);
    }

    public function testSitemapUrlsLastPageReturnsTheRemainder(): void
    {
        $this->makePost(['slug' => 'a', 'updated' => '2026-06-04 09:00:00']);
        $this->makePost(['slug' => 'b', 'updated' => '2026-06-03 09:00:00']);
        $this->makePost(['slug' => 'c', 'updated' => '2026-06-02 09:00:00']);
        $this->makePost(['slug' => 'd', 'updated' => '2026-06-01 09:00:00']);

        // Total entries = home + 4 posts = 5; cap 2 → 3 pages (2, 2, 1), so page
        // 3 gets only the one leftover post instead of a full slice of two.
        $locs = array_column(sitemap_urls(ROOT_URL, 3, 2), 'loc');

        $this->assertSame([ROOT_URL . '/d'], $locs);
    }

    public function testSitemapUrlsPageBeyondRangeIsEmpty(): void
    {
        $this->makePost(['slug' => 'a']);

        $this->assertSame([], sitemap_urls(ROOT_URL, 5, 2));
    }

    public function testRenderSitemapIndexWrapsChildrenInSitemapindex(): void
    {
        $xml = render_sitemap_index(ROOT_URL, 2, '2026-06-01T09:00:00+00:00');

        $this->assertStringContainsString('<?xml version="1.0" encoding="UTF-8"?>', $xml);
        $this->assertStringContainsString(
            '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">',
            $xml
        );
        $this->assertStringContainsString('</sitemapindex>', $xml);
    }

    public function testRenderSitemapIndexListsEachChildPageLoc(): void
    {
        $xml = render_sitemap_index(ROOT_URL, 2, null);

        $this->assertStringContainsString('<loc>' . ROOT_URL . '/sitemap.xml/page/1</loc>', $xml);
        $this->assertStringContainsString('<loc>' . ROOT_URL . '/sitemap.xml/page/2</loc>', $xml);
    }

    public function testRenderSitemapIndexAppliesTheSameLastmodToEveryChild(): void
    {
        $xml = render_sitemap_index(ROOT_URL, 2, '2026-06-01T09:00:00+00:00');

        $this->assertSame(2, substr_count($xml, '<lastmod>2026-06-01T09:00:00+00:00</lastmod>'));
    }

    public function testRenderSitemapIndexOmitsLastmodWhenNull(): void
    {
        $xml = render_sitemap_index(ROOT_URL, 1, null);

        $this->assertStringNotContainsString('<lastmod>', $xml);
    }

    public function testRenderSitemapIndexEscapesChildLocs(): void
    {
        $xml = render_sitemap_index('https://example.com/a?b=1&c=2', 1, null);

        $this->assertStringContainsString('https://example.com/a?b=1&amp;c=2/sitemap.xml/page/1', $xml);
        $this->assertStringNotContainsString('&c=2', $xml);
    }

    public function testCacheKeyIsSharedButPathsDifferPerPage(): void
    {
        $key = sitemap_cache_key('2026-06-01 09:00:00', 1000);

        $this->assertNotSame(sitemap_cache_path($key, 1), sitemap_cache_path($key, 2));
    }

    public function testStoringASiblingPageDoesNotEvictAnotherPageOfTheSameGeneration(): void
    {
        $dir = $this->cacheDir();
        $key = sitemap_cache_key('2026-06-01 09:00:00', 1000);
        $page1 = sitemap_cache_path($key, 1);
        $page2 = sitemap_cache_path($key, 2);

        // Both cache files live in the temp cacheDir(), not data_dir()'s real
        // cache path, so exercise the same prune logic store_sitemap_cache()
        // uses directly rather than through it (which is pinned to data_dir()).
        file_put_contents($dir . '/' . basename($page1), 'one');
        store_sitemap_cache($dir . '/' . basename($page2), 'two');

        $this->assertFileExists($dir . '/' . basename($page1));
        $this->assertFileExists($dir . '/' . basename($page2));
    }

    public function testStoringANewGenerationEvictsAnOlderGenerationsPages(): void
    {
        $dir = $this->cacheDir();
        $oldKey = sitemap_cache_key('2026-06-01 09:00:00', 1000);
        $newKey = sitemap_cache_key('2026-06-02 09:00:00', 1000);
        $oldPage = sitemap_cache_path($oldKey, 2);
        $newPage = sitemap_cache_path($newKey, 1);

        file_put_contents($dir . '/' . basename($oldPage), 'old');
        store_sitemap_cache($dir . '/' . basename($newPage), 'new');

        $this->assertFileDoesNotExist($dir . '/' . basename($oldPage));
        $this->assertFileExists($dir . '/' . basename($newPage));
    }
}

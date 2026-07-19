<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;
use SimpleXMLElement;

use function Lamb\Import\html_to_markdown;
use function Lamb\Import\parse_rss_string;
use function Lamb\Import\prepare_imported_html;
use function Lamb\Known\extract_items;
use function Lamb\Known\import_item;
use function Lamb\Known\known_uuid;
use function Lamb\Known\normalize_known_html_in_dom;
use function Lamb\Known\should_import;
use function Lamb\Known\skip_reason;
use function Lamb\Known\strip_structural_hashtags;
use function Lamb\Post\split_frontmatter;

/**
 * Covers parsing, Known-specific DOM normalisation, body assembly and
 * idempotent import for the Known CMS importer. Outbound webmentions/WebSub
 * pings are NOT triggered because import_item() uses the low-level pipeline
 * (populate_bean → finalize_and_store_post) which never calls
 * notify_post_subscribers().
 *
 * The fixture below is minimised from the real ~445-item /home/sander/Downloads/export.rss
 * export: a photo post (data-gallery anchor + img + duplicate enclosure), a
 * bookmark (offsite link + unfurl-block + inline p-category anchor matching a
 * <category>), a synthetic-title status update (both the `...`-title and
 * `p-name` detection signals at once), a titled article whose inline hashtag
 * duplicates its <category> tag in a different case, and one non-published
 * item to exercise the skip path.
 */
class KnownImportTest extends TestCase
{
    private const SAMPLE_RSS = <<<'XML'
<?xml version="1.0"?>
<rss version="2.0" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
    <title>Example Notes</title>
    <link>https://example.test/</link>
    <item>
        <title>Turn that frown upside down</title>
        <link>https://example.test/2020/turn-that-frown-upside-down</link>
        <guid>https://known.example/view/aaaa1111</guid>
        <pubDate>Tue, 26 May 2020 12:48:16 +0000</pubDate>
        <wp:post_type>post</wp:post_type>
        <wp:status>publish</wp:status>
        <dc:creator>Author</dc:creator>
        <description><![CDATA[
<div class="e-content entry-content" data-num-pics="1">
    <div class="photo-view">
        <a href="https://example.test/2020/turn-that-frown-upside-down" data-gallery="Turn that frown upside down"><img
            src="https://example.test/file/aaaa1111/photo.png" class="u-photo"
            alt="Turn that frown upside down"/></a>
    </div>
    <p>People recognise faces.</p>
</div>
]]></description>
        <enclosure url="https://example.test/file/aaaa1111/photo.png" type="image/png" length="123"/>
    </item>
    <item>
        <title>Fancy Zoom Calls - Example.ca</title>
        <link>http://external.example/archives/fancy-zoom-calls</link>
        <guid>https://known.example/view/bbbb2222</guid>
        <pubDate>Wed, 06 May 2020 07:04:42 +0000</pubDate>
        <wp:post_type>post</wp:post_type>
        <wp:status>publish</wp:status>
        <dc:creator>Author</dc:creator>
        <description><![CDATA[<div class="known-bookmark">
    <div class="e-content"><p>I love reading about these <a href="https://example.test/tag/remoteworking" class="p-category" rel="tag">#remoteworking</a> setups</p></div></div>
<div class="unfurl-block" data-parent-object="bbbb2222">
    <div class="unfurl col-md-12" style="display:none;" data-url="http://external.example/archives/fancy-zoom-calls"></div>
    <div class="unfurl-edit pull-right small" style="display:none;">
        <a href="#" class="refresh" title="Refresh preview">refresh</a>
        <a href="#" class="delete" title="Remove preview">delete</a>
    </div>
</div>
]]></description>
        <category>#remoteworking</category>
    </item>
    <item>
        <title>If you send email using `noreply@` then ...</title>
        <link>https://example.test/2020/if-you-send-email-using-noreply-then</link>
        <guid>https://known.example/view/cccc3333</guid>
        <pubDate>Thu, 21 May 2020 08:35:53 +0000</pubDate>
        <wp:post_type>post</wp:post_type>
        <wp:status>publish</wp:status>
        <dc:creator>Author</dc:creator>
        <description><![CDATA[<p class="p-name e-content entry-content">If you send email using `noreply@` then think about why you're not having a human contact.&lt;p&gt;#status <a href="https://example.test/tag/status" class="p-category" rel="tag">#status</a>&lt;/p&gt;</p>
]]></description>
        <category>#status</category>
        <category>#Uncategorized</category>
    </item>
    <item>
        <title>Cuttlefish are amazing</title>
        <link>https://example.test/2019/cuttlefish-are-amazing</link>
        <guid>https://known.example/view/dddd4444</guid>
        <pubDate>Sun, 04 Aug 2019 11:33:55 +0000</pubDate>
        <wp:post_type>post</wp:post_type>
        <wp:status>publish</wp:status>
        <dc:creator>Author</dc:creator>
        <description><![CDATA[
<div class="e-content entry-content">
<p>Look at this <a href="https://example.test/tag/Cuttlefish" class="p-category" rel="tag">#cuttlefish</a> go.</p>
</div>
]]></description>
        <category>#Cuttlefish</category>
        <category>#cuttlefish</category>
    </item>
    <item>
        <title>Draft thought</title>
        <link>https://example.test/2021/draft-thought</link>
        <guid>https://known.example/view/eeee5555</guid>
        <pubDate>Fri, 01 Jan 2021 00:00:00 +0000</pubDate>
        <wp:post_type>post</wp:post_type>
        <wp:status>draft</wp:status>
        <dc:creator>Author</dc:creator>
        <description><![CDATA[<p>Not published yet.</p>]]></description>
    </item>
</channel>
</rss>
XML;

    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::nuke();

        global $config;
        $config = $config ?? [];

        // pubDate carries an explicit +0000 offset, so pinning to UTC makes
        // the resulting local timestamps deterministic regardless of host TZ.
        date_default_timezone_set('UTC');
    }

    private function sampleItems(): array
    {
        return extract_items(parse_rss_string(self::SAMPLE_RSS));
    }

    public function testKnownUuidIsStableForSameGuid(): void
    {
        $guid = 'https://known.example/view/aaaa1111';
        $this->assertSame(md5('known-' . $guid), known_uuid($guid));
    }

    public function testParseRssStringReturnsRssElement(): void
    {
        $rss = parse_rss_string(self::SAMPLE_RSS);
        $this->assertInstanceOf(SimpleXMLElement::class, $rss);
        $this->assertSame('rss', $rss->getName());
    }

    public function testExtractItemsReturnsAllChannelItems(): void
    {
        $items = $this->sampleItems();
        $this->assertCount(5, $items);
        $this->assertSame('Turn that frown upside down', $items[0]['title']);
        $this->assertSame('post', $items[0]['post_type']);
        $this->assertSame('publish', $items[0]['status']);
    }

    public function testExtractItemsSetsCreatedEqualToUpdatedFromPubDate(): void
    {
        $items = $this->sampleItems();
        $this->assertSame('2020-05-26 12:48:16', $items[0]['created']);
        $this->assertSame($items[0]['created'], $items[0]['updated']);
    }

    public function testExtractItemsStripsHashLowercasesAndDedupesTags(): void
    {
        $items = $this->sampleItems();
        // Two <category> tags differing only in case ("#Cuttlefish",
        // "#cuttlefish") must collapse into a single lowercase 'cuttlefish'.
        $this->assertSame(['cuttlefish'], $items[3]['tags']);
    }

    public function testExtractItemsDropsStructuralTags(): void
    {
        // 'status' marks a titleless status update (Lamb models that as a
        // post shape, not a tag) and 'uncategorized' means no category —
        // neither carries meaning worth importing, in any case variant.
        $items = $this->sampleItems();
        $this->assertSame([], $items[2]['tags']);
    }

    public function testExtractItemsReadsSlugFromOnHostLinkLeaf(): void
    {
        $items = $this->sampleItems();
        $this->assertSame('turn-that-frown-upside-down', $items[0]['slug']);
        $this->assertSame('cuttlefish-are-amazing', $items[3]['slug']);
    }

    public function testExtractItemsLeavesSlugEmptyAndSetsBookmarkUrlForOffsiteLink(): void
    {
        $items = $this->sampleItems();
        $this->assertSame('', $items[1]['slug']);
        $this->assertSame('http://external.example/archives/fancy-zoom-calls', $items[1]['bookmark_url']);
    }

    public function testExtractItemsLeavesBookmarkUrlEmptyForOnHostLinks(): void
    {
        $items = $this->sampleItems();
        $this->assertSame('', $items[0]['bookmark_url']);
        $this->assertSame('', $items[3]['bookmark_url']);
    }

    public function testExtractItemsDetectsSyntheticTitleFromEllipsisAndPName(): void
    {
        $items = $this->sampleItems();
        $this->assertTrue($items[2]['title_is_synthetic']);
    }

    public function testExtractItemsDoesNotFlagRealTitlesAsSynthetic(): void
    {
        $items = $this->sampleItems();
        $this->assertFalse($items[0]['title_is_synthetic']);
        $this->assertFalse($items[3]['title_is_synthetic']);
    }

    public function testExtractItemsDetectsSyntheticTitleFromEllipsisOnly(): void
    {
        $xml = <<<'XML'
<?xml version="1.0"?>
<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
    <link>https://example.test/</link>
    <item>
        <title>Tweet: so tempted to start a truth correction ac...</title>
        <link>https://example.test/2015/tweet-so-tempted</link>
        <guid>https://known.example/view/ffff6666</guid>
        <pubDate>Sun, 25 Jan 2015 13:11:09 +0000</pubDate>
        <wp:post_type>post</wp:post_type>
        <wp:status>publish</wp:status>
        <description><![CDATA[<div class="e-content entry-content"><p>So tempted to start a truth correction account.</p></div>]]></description>
    </item>
</channel>
</rss>
XML;
        $items = extract_items(parse_rss_string($xml));
        $this->assertTrue($items[0]['title_is_synthetic']);
    }

    public function testExtractItemsDetectsSyntheticTitleFromPNameOnly(): void
    {
        $xml = <<<'XML'
<?xml version="1.0"?>
<rss version="2.0" xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
    <link>https://example.test/</link>
    <item>
        <title>Thinking whether to renew http://example.org</title>
        <link>https://example.test/2020/thinking-whether-to-renew</link>
        <guid>https://known.example/view/gggg7777</guid>
        <pubDate>Thu, 16 Jan 2020 11:40:00 +0000</pubDate>
        <wp:post_type>post</wp:post_type>
        <wp:status>publish</wp:status>
        <description><![CDATA[<p class="p-name e-content entry-content">Thinking whether to renew <a href="http://example.org">http://example.org</a></p>]]></description>
    </item>
</channel>
</rss>
XML;
        $items = extract_items(parse_rss_string($xml));
        $this->assertTrue($items[0]['title_is_synthetic']);
    }

    public function testShouldImportRejectsNonPublishedStatus(): void
    {
        $items = $this->sampleItems();
        $this->assertTrue(should_import($items[0]));
        $this->assertFalse(should_import($items[4]));
        $this->assertStringContainsString("non-published status 'draft'", (string) skip_reason($items[4]));
    }

    public function testNormalizeKnownHtmlRemovesUnfurlBlockEntirely(): void
    {
        $items = $this->sampleItems();
        $downloader = fn(): ?string => null;
        $prepared = prepare_imported_html(
            $items[1]['content'],
            $items[1]['created'],
            $downloader,
            normalize_known_html_in_dom(...),
        );
        $markdown = html_to_markdown($prepared);

        $this->assertStringNotContainsString('unfurl', $markdown);
        $this->assertStringNotContainsString('refresh', $markdown);
        $this->assertStringNotContainsString('<div', $markdown);
    }

    public function testNormalizeKnownHtmlReplacesCategoryAnchorWithPlainTagText(): void
    {
        $items = $this->sampleItems();
        $downloader = fn(): ?string => null;
        $prepared = prepare_imported_html(
            $items[1]['content'],
            $items[1]['created'],
            $downloader,
            normalize_known_html_in_dom(...),
        );
        $markdown = html_to_markdown($prepared);

        $this->assertStringContainsString('#remoteworking', $markdown);
        $this->assertStringNotContainsString('example.test/tag', $markdown);
        $this->assertStringNotContainsString('[#remoteworking]', $markdown);
    }

    public function testNormalizeKnownHtmlUnwrapsGalleryAnchorAndImageIsDownloaded(): void
    {
        $items = $this->sampleItems();
        $downloaded = [];
        $downloader = function (string $url) use (&$downloaded): ?string {
            $downloaded[] = $url;
            return 'photo123.png';
        };
        $prepared = prepare_imported_html(
            $items[0]['content'],
            $items[0]['created'],
            $downloader,
            normalize_known_html_in_dom(...),
        );
        $markdown = html_to_markdown($prepared);

        $this->assertSame(['https://example.test/file/aaaa1111/photo.png'], $downloaded);
        $this->assertStringContainsString('/assets/2020/05/photo123.png', $markdown);
        $this->assertStringNotContainsString('example.test', $markdown);
        $this->assertStringNotContainsString('[![', $markdown); // not still wrapped in a link
        $this->assertStringNotContainsString('<div', $markdown);
    }

    public function testNormalizeKnownHtmlRemovesStructuralTagAnchorsAndText(): void
    {
        // The fixture mirrors Known's malformed trailing tag line: an escaped
        // `&lt;p&gt;` wrapping the tag as plain '#status' text plus its
        // p-category anchor. Neither form may survive — Lamb's tag index
        // scans the body, so leftover text alone would re-import the tag
        // through the back door — and the escaped paragraph itself must not
        // decode into literal '<p>' text.
        $items = $this->sampleItems();
        $downloader = fn(): ?string => null;
        $prepared = prepare_imported_html(
            $items[2]['content'],
            $items[2]['created'],
            $downloader,
            normalize_known_html_in_dom(...),
        );
        $markdown = strip_structural_hashtags(html_to_markdown($prepared));

        $this->assertStringNotContainsString('#status', $markdown);
        $this->assertStringNotContainsString('<p>', $markdown);
        $this->assertStringContainsString('human contact', $markdown);
    }

    public function testImportItemBodyCarriesNoStructuralTags(): void
    {
        $items = $this->sampleItems();
        $downloader = fn(): ?string => null;

        $bean = import_item($items[2], $downloader, true);

        $this->assertNotNull($bean);
        $body = strtolower((string) $bean->body);
        $this->assertStringNotContainsString('#status', $body);
        $this->assertStringNotContainsString('#uncategorized', $body);
    }

    public function testNormalizeKnownHtmlUnwrapsLegacyAuthoredDivs(): void
    {
        // Posts migrated into Known from earlier platforms carry authored
        // wrapper divs (bare <div>, Windows Live Writer markup) that Known's
        // own class list doesn't cover. Any div surviving conversion renders
        // as visibly escaped HTML in Lamb's safe renderer, so the DOM pass
        // unwraps every remaining div.
        $html = '<div class="e-content entry-content">'
            . '<div class="wlWriterSmartContent" style="margin:0px;"><div>'
            . '<p>Legacy paragraph.</p>'
            . '</div></div></div>';
        $downloader = fn(): ?string => null;
        $prepared = prepare_imported_html(
            $html,
            '2009-01-01 00:00:00',
            $downloader,
            normalize_known_html_in_dom(...),
        );
        $markdown = html_to_markdown($prepared);

        $this->assertStringContainsString('Legacy paragraph.', $markdown);
        $this->assertStringNotContainsString('<div', $markdown);
        $this->assertStringNotContainsString('wlWriterSmartContent', $markdown);
    }

    public function testImportItemDedupesExtractedTagAgainstInlineHashtagCaseInsensitively(): void
    {
        $items = $this->sampleItems();
        $downloader = fn(): ?string => null;

        $bean = import_item($items[3], $downloader, true);

        $this->assertNotNull($bean);
        // The extracted '#Cuttlefish'/'#cuttlefish' category tags must not be
        // appended a second time — the inline "#cuttlefish" already covers it.
        $this->assertSame(1, substr_count(strtolower((string) $bean->body), '#cuttlefish'));
    }

    public function testImportItemCreatesPostWithKnownUuidFeedNameAndDates(): void
    {
        $items = $this->sampleItems();
        $downloader = fn(): ?string => null;

        $bean = import_item($items[0], $downloader, false);

        $this->assertNotNull($bean);
        $this->assertNotEmpty($bean->id);
        $this->assertSame(known_uuid('https://known.example/view/aaaa1111'), $bean->feeditem_uuid);
        $this->assertSame('known', $bean->feed_name);
        $this->assertSame('2020-05-26 12:48:16', $bean->created);
    }

    public function testImportItemPinsTitleAndSlugForTitledPost(): void
    {
        $items = $this->sampleItems();
        $downloader = fn(): ?string => null;

        $bean = import_item($items[0], $downloader, false);

        $this->assertNotNull($bean);
        $this->assertSame('turn-that-frown-upside-down', (string) $bean->slug);
        $this->assertStringContainsString("title: 'Turn that frown upside down'", (string) $bean->body);
    }

    public function testImportItemLeavesTitleAndSlugEmptyForSyntheticStatusPost(): void
    {
        $items = $this->sampleItems();
        $downloader = fn(): ?string => null;

        $bean = import_item($items[2], $downloader, false);

        $this->assertNotNull($bean);
        $this->assertSame('', (string) $bean->slug);
        $this->assertStringNotContainsString('title:', (string) $bean->body);
        $this->assertStringNotContainsString('slug:', (string) $bean->body);
    }

    public function testImportItemPrependsBookmarkLinkLineToBody(): void
    {
        $items = $this->sampleItems();
        $downloader = fn(): ?string => null;

        $bean = import_item($items[1], $downloader, false);

        $this->assertNotNull($bean);
        // Bookmarks keep their title in front matter.
        $this->assertStringContainsString("title: 'Fancy Zoom Calls - Example.ca'", (string) $bean->body);
        [, $content] = split_frontmatter((string) $bean->body);
        $this->assertStringStartsWith(
            '[Fancy Zoom Calls - Example.ca](http://external.example/archives/fancy-zoom-calls)',
            ltrim($content)
        );
    }

    public function testImportItemStoresRedirectsForBothLinkPathAndGuidPath(): void
    {
        $items = $this->sampleItems();
        $downloader = fn(): ?string => null;

        $bean = import_item($items[0], $downloader, false);
        $this->assertNotNull($bean);

        $linkRedirect = R::findOne('redirect', ' from_slug = ? ', ['2020/turn-that-frown-upside-down']);
        $guidRedirect = R::findOne('redirect', ' from_slug = ? ', ['view/aaaa1111']);

        $this->assertNotNull($linkRedirect);
        $this->assertNotNull($guidRedirect);
        $this->assertSame('/' . $bean->slug, (string) $linkRedirect->to_url);
        $this->assertSame('/' . $bean->slug, (string) $guidRedirect->to_url);
    }

    public function testImportItemIsIdempotentByUuid(): void
    {
        $items = $this->sampleItems();
        $downloader = fn(): ?string => null;

        $first = import_item($items[0], $downloader, false);
        $second = import_item($items[0], $downloader, false);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->id);
        $this->assertCount(1, R::findAll('post'));
    }

    public function testImportItemDryRunStoresNothing(): void
    {
        $items = $this->sampleItems();
        $downloader = fn(): ?string => null;

        $bean = import_item($items[0], $downloader, true);

        $this->assertNotNull($bean);
        $this->assertEmpty($bean->id);
        $this->assertCount(0, R::findAll('post'));
        $this->assertCount(0, R::findAll('redirect'));
    }

    public function testImportItemSkipsNonPublishedItems(): void
    {
        $items = $this->sampleItems();
        $downloader = fn(): ?string => null;

        $bean = import_item($items[4], $downloader, false);

        $this->assertNull($bean);
        $this->assertCount(0, R::findAll('post'));
    }

    public function testAuthenticLocalExportDryRunsWhenPresent(): void
    {
        if (!class_exists(\League\HTMLToMarkdown\HtmlConverter::class)) {
            $this->markTestSkipped('league/html-to-markdown is not installed');
        }

        $paths = glob(dirname(__DIR__, 2) . '/tmp/*.rss') ?: [];
        $path = $paths[0] ?? null;
        if ($path === null || !is_readable($path)) {
            $this->markTestSkipped('Local authentic Known export is not present.');
        }

        $rss = parse_rss_string((string) file_get_contents($path));
        $items = extract_items($rss);

        $importable = array_values(array_filter($items, should_import(...)));
        $this->assertCount(445, $items);
        $this->assertCount(445, $importable);

        $downloader = fn(): ?string => null;
        foreach ($importable as $item) {
            $this->assertNotSame('', trim((string) $item['guid']));
            $this->assertNotSame('', trim((string) $item['created']));
            $bean = import_item($item, $downloader, true);
            $this->assertNotNull($bean);
            $this->assertEmpty($bean->id);
            $this->assertNotSame('', trim((string) $bean->body));
        }

        $this->assertCount(0, R::findAll('post'));
    }
}

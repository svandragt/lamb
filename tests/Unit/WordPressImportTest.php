<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;
use SimpleXMLElement;

use function Lamb\Response\persist_image_bytes;
use function Lamb\WordPress\asset_dir_for_date;
use function Lamb\WordPress\build_post_body;
use function Lamb\WordPress\extract_items;
use function Lamb\WordPress\html_to_markdown;
use function Lamb\WordPress\import_item;
use function Lamb\WordPress\parse_wxr_file;
use function Lamb\WordPress\parse_wxr_string;
use function Lamb\WordPress\response_is_image;
use function Lamb\WordPress\rewrite_image_links;
use function Lamb\WordPress\sanitize_html;
use function Lamb\WordPress\should_import;
use function Lamb\WordPress\wordpress_uuid;

/**
 * Covers parsing, sanitisation, body assembly, image rewriting and
 * idempotent import. Outbound webmentions/WebSub pings are NOT triggered
 * because import_item() uses the low-level pipeline (populate_bean →
 * finalize_and_store_post) which never calls notify_post_subscribers().
 */
class WordPressImportTest extends TestCase
{
    private const SAMPLE_WXR = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0"
    xmlns:excerpt="http://wordpress.org/export/1.2/excerpt/"
    xmlns:content="http://purl.org/rss/1.0/modules/content/"
    xmlns:wfw="http://wellformedweb.org/CommentAPI/"
    xmlns:dc="http://purl.org/dc/elements/1.1/"
    xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
    <wp:base_blog_url>https://oldsite.example/blog</wp:base_blog_url>
    <wp:base_site_url>https://oldsite.example</wp:base_site_url>
    <item>
        <title>Hello World</title>
        <link>https://oldsite.example/blog/hello-world/</link>
        <guid isPermaLink="false">https://oldsite.example/?p=42</guid>
        <pubDate>Mon, 03 Mar 2024 10:00:00 +0000</pubDate>
        <dc:creator><![CDATA[author]]></dc:creator>
        <content:encoded><![CDATA[<p>Hello <strong>world</strong>.</p><p><img src="https://oldsite.example/wp-content/uploads/2024/03/photo.jpg" alt="photo"/></p><script>alert(1)</script>]]></content:encoded>
        <wp:post_date>2024-03-03 11:00:00</wp:post_date>
        <wp:post_date_gmt>2024-03-03 10:00:00</wp:post_date_gmt>
        <wp:post_modified>2024-03-05 12:30:00</wp:post_modified>
        <wp:post_modified_gmt>2024-03-05 11:30:00</wp:post_modified_gmt>
        <wp:post_id>42</wp:post_id>
        <wp:post_name>hello-world</wp:post_name>
        <wp:status>publish</wp:status>
        <wp:post_type>post</wp:post_type>
        <category domain="category" nicename="news"><![CDATA[News]]></category>
        <category domain="post_tag" nicename="welcome"><![CDATA[Welcome]]></category>
    </item>
    <item>
        <title>About</title>
        <link>https://oldsite.example/about/</link>
        <guid isPermaLink="false">https://oldsite.example/?page_id=2</guid>
        <pubDate>Mon, 01 Jan 2024 09:00:00 +0000</pubDate>
        <content:encoded><![CDATA[<p>About page body.</p>]]></content:encoded>
        <wp:post_date>2024-01-01 09:00:00</wp:post_date>
        <wp:post_id>2</wp:post_id>
        <wp:post_name>about-us</wp:post_name>
        <wp:status>publish</wp:status>
        <wp:post_type>page</wp:post_type>
    </item>
    <item>
        <title>Draft</title>
        <guid isPermaLink="false">https://oldsite.example/?p=99</guid>
        <content:encoded><![CDATA[<p>Unfinished.</p>]]></content:encoded>
        <wp:post_date>2024-02-01 09:00:00</wp:post_date>
        <wp:post_id>99</wp:post_id>
        <wp:status>draft</wp:status>
        <wp:post_type>post</wp:post_type>
    </item>
    <item>
        <title>Cool product</title>
        <guid isPermaLink="false">https://oldsite.example/?product=7</guid>
        <content:encoded><![CDATA[<p>Custom post type body.</p>]]></content:encoded>
        <wp:post_date>2024-02-15 09:00:00</wp:post_date>
        <wp:post_id>7</wp:post_id>
        <wp:status>publish</wp:status>
        <wp:post_type>product</wp:post_type>
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

        // The WXR `_gmt` fields are UTC; pin the test process to UTC so the
        // resulting local timestamps are deterministic regardless of host TZ.
        date_default_timezone_set('UTC');
    }

    public function testWordpressUuidIsStableForSameGuid(): void
    {
        $guid = 'https://oldsite.example/?p=42';
        $this->assertSame(md5('wordpress-' . $guid), wordpress_uuid($guid));
    }

    public function testSanitizeHtmlStripsScriptTags(): void
    {
        $html = '<p>ok</p><script>alert(1)</script>';
        $this->assertStringNotContainsString('<script', sanitize_html($html));
        $this->assertStringNotContainsString('alert', sanitize_html($html));
    }

    public function testSanitizeHtmlStripsStyleTags(): void
    {
        $html = '<p>ok</p><style>body{color:red}</style>';
        $this->assertStringNotContainsString('<style', sanitize_html($html));
        $this->assertStringNotContainsString('color:red', sanitize_html($html));
    }

    public function testSanitizeHtmlStripsIframeTags(): void
    {
        $html = '<p>ok</p><iframe src="https://evil"></iframe>';
        $this->assertStringNotContainsString('<iframe', sanitize_html($html));
    }

    public function testSanitizeHtmlStripsOnEventAttributes(): void
    {
        $html = '<a href="x" onclick="alert(1)" ONLOAD="x">link</a>';
        $clean = sanitize_html($html);
        $this->assertStringNotContainsString('onclick', strtolower($clean));
        $this->assertStringNotContainsString('onload', strtolower($clean));
        $this->assertStringContainsString('href="x"', $clean);
    }

    /**
     * Regression test: an earlier load/dump implementation relied on
     * LIBXML_HTML_NOIMPLIED behaviour that changed between libxml 2.9 and 2.12.
     * On 2.12 the round-trip collapsed to just a stray <meta> hint, losing the
     * actual content. Pin the round-trip explicitly so it can't drift again.
     */
    public function testSanitizeHtmlPreservesContentAcrossRoundTrip(): void
    {
        $clean = sanitize_html('<p>Hello <em>world</em>.</p>');
        $this->assertStringNotContainsString('<meta', $clean);
        $this->assertStringContainsString('<p>', $clean);
        $this->assertStringContainsString('<em>world</em>', $clean);
    }

    public function testSanitizeHtmlPreservesUtf8(): void
    {
        $clean = sanitize_html('<p>Cliché — naïve façade. 日本語</p>');
        $this->assertStringContainsString('Cliché', $clean);
        $this->assertStringContainsString('日本語', $clean);
    }

    public function testHtmlToMarkdownConvertsBasicTags(): void
    {
        if (!class_exists(\League\HTMLToMarkdown\HtmlConverter::class)) {
            $this->markTestSkipped('league/html-to-markdown is not installed');
        }
        $md = html_to_markdown('<p>Hello <strong>world</strong>.</p>');
        $this->assertStringContainsString('**world**', $md);
        $this->assertStringContainsString('Hello', $md);
    }

    public function testHtmlToMarkdownDecodesTextEntities(): void
    {
        if (!class_exists(\League\HTMLToMarkdown\HtmlConverter::class)) {
            $this->markTestSkipped('league/html-to-markdown is not installed');
        }

        $md = html_to_markdown('<p><strong>Settings &gt; Music &gt; Library</strong></p>');

        $this->assertSame('**Settings > Music > Library**', $md);
    }

    public function testHtmlToMarkdownUnwrapsWordpressBlockWrappers(): void
    {
        if (!class_exists(\League\HTMLToMarkdown\HtmlConverter::class)) {
            $this->markTestSkipped('league/html-to-markdown is not installed');
        }

        $html = '<div class="wp-block-buttons"><div class="wp-block-button">'
            . '<a href="https://example.test/source">View Source</a>'
            . '</div></div>';

        $md = html_to_markdown($html);

        $this->assertSame('[View Source](https://example.test/source)', $md);
    }

    public function testHtmlToMarkdownUnwrapsWordpressImageBlockFigure(): void
    {
        if (!class_exists(\League\HTMLToMarkdown\HtmlConverter::class)) {
            $this->markTestSkipped('league/html-to-markdown is not installed');
        }

        $html = '<figure class="wp-block-image size-large">'
            . '<img src="https://example.test/image.jpg" alt="Example image">'
            . '</figure>';

        $md = html_to_markdown($html);

        $this->assertSame('![Example image](https://example.test/image.jpg)', $md);
    }

    public function testHtmlToMarkdownKeepsFigureImageSeparateFromFollowingText(): void
    {
        if (!class_exists(\League\HTMLToMarkdown\HtmlConverter::class)) {
            $this->markTestSkipped('league/html-to-markdown is not installed');
        }

        $html = '<figure class="wp-block-image">'
            . '<img src="https://example.test/image.jpg" alt="Example image">'
            . '</figure>Following text.';

        $md = html_to_markdown($html);

        $this->assertSame("![Example image](https://example.test/image.jpg)\n\nFollowing text.", $md);
    }

    public function testHtmlToMarkdownUnwrapsFigureCaptionAndInlinePresentationTags(): void
    {
        if (!class_exists(\League\HTMLToMarkdown\HtmlConverter::class)) {
            $this->markTestSkipped('league/html-to-markdown is not installed');
        }

        $html = '<figure class="aligncenter size-large">'
            . '<img src="https://example.test/image.jpg" alt="Example image">'
            . '<figcaption><span class="has-inline-color">Example caption</span></figcaption>'
            . '</figure>';

        $md = html_to_markdown($html);

        $this->assertStringContainsString('![Example image](https://example.test/image.jpg)', $md);
        $this->assertStringContainsString('Example caption', $md);
        $this->assertStringNotContainsString('<figure', $md);
        $this->assertStringNotContainsString('<figcaption', $md);
        $this->assertStringNotContainsString('<span', $md);
    }

    public function testHtmlToMarkdownConvertsSimpleTables(): void
    {
        if (!class_exists(\League\HTMLToMarkdown\HtmlConverter::class)) {
            $this->markTestSkipped('league/html-to-markdown is not installed');
        }

        $html = '<table><thead><tr><th>Project</th><th>Description</th></tr></thead>'
            . '<tbody><tr><td><a href="https://example.test">Tool</a></td><td>Does work.</td></tr></tbody></table>';

        $md = html_to_markdown($html);

        $this->assertStringContainsString('| Project | Description |', $md);
        $this->assertStringContainsString('| --- | --- |', $md);
        $this->assertStringContainsString('| [Tool](https://example.test) | Does work. |', $md);
        $this->assertStringNotContainsString('<table', $md);
    }

    public function testHtmlToMarkdownConvertsVideoToLink(): void
    {
        if (!class_exists(\League\HTMLToMarkdown\HtmlConverter::class)) {
            $this->markTestSkipped('league/html-to-markdown is not installed');
        }

        $md = html_to_markdown('<video controls src="https://example.test/demo.mp4"></video>');

        $this->assertSame('<https://example.test/demo.mp4>', $md);
    }

    public function testBuildPostBodyEmitsFrontMatterAndHashtags(): void
    {
        $body = build_post_body('My Title', "Body text.", ['news', 'welcome']);
        $this->assertStringStartsWith("---\n", $body);
        $this->assertStringContainsString("title: 'My Title'", $body);
        $this->assertStringContainsString('Body text.', $body);
        $this->assertStringContainsString('#news', $body);
        $this->assertStringContainsString('#welcome', $body);
    }

    public function testBuildPostBodyHandlesEmptyTagsAndTitle(): void
    {
        $body = build_post_body('', 'Just content.', []);
        $this->assertStringNotContainsString('title:', $body);
        $this->assertStringNotContainsString('#', $body);
        $this->assertStringContainsString('Just content.', $body);
    }

    public function testBuildPostBodyEscapesYamlSpecialCharsInTitle(): void
    {
        $body = build_post_body("It's \"complicated\": yes", 'Hi', []);
        $this->assertStringContainsString('title:', $body);
        // Round-trip: parse_matter should recover the original title.
        $matter = \Lamb\Post\parse_matter($body);
        $this->assertSame("It's \"complicated\": yes", $matter['title']);
    }

    public function testAssetDirForDateUsesPostCreatedMonth(): void
    {
        $this->assertSame('2024/03', asset_dir_for_date('2024-03-15 12:00:00'));
        $this->assertSame('2019/12', asset_dir_for_date('2019-12-01 00:00:00'));
    }

    public function testParseWxrStringReturnsRssElement(): void
    {
        $rss = parse_wxr_string(self::SAMPLE_WXR);
        $this->assertInstanceOf(SimpleXMLElement::class, $rss);
        $this->assertSame('rss', $rss->getName());
    }

    public function testAuthenticLocalExportDryRunsWhenPresent(): void
    {
        if (!class_exists(\League\HTMLToMarkdown\HtmlConverter::class)) {
            $this->markTestSkipped('league/html-to-markdown is not installed');
        }

        $paths = glob(dirname(__DIR__, 2) . '/tmp/*.WordPress.*.xml') ?: [];
        $path = $paths[0] ?? null;
        if ($path === null || !is_readable($path)) {
            $this->markTestSkipped('Local authentic WordPress export is not present.');
        }

        $rss = parse_wxr_file($path);
        $items = extract_items($rss);

        $importable = array_values(array_filter($items, should_import(...)));
        $this->assertCount(175, $items);
        $this->assertCount(124, $importable);
        $this->assertCount(51, array_filter($items, static fn(array $item): bool => !should_import($item)));

        foreach ($importable as $item) {
            $this->assertNotSame('', trim((string) $item['guid']));
            $this->assertNotSame('', trim((string) $item['created']));
            $bean = import_item($item, fn(): ?string => null, true);
            $this->assertNotNull($bean);
            $this->assertEmpty($bean->id);
            $this->assertNotSame('', trim((string) $bean->body));
        }

        $this->assertCount(0, R::findAll('post'));
    }

    public function testExtractItemsReturnsAllChannelItems(): void
    {
        $rss = parse_wxr_string(self::SAMPLE_WXR);
        $items = extract_items($rss);
        $this->assertCount(4, $items);
        $this->assertSame('Hello World', $items[0]['title']);
        $this->assertSame('post', $items[0]['post_type']);
        $this->assertSame('publish', $items[0]['status']);
        $this->assertSame(['news', 'welcome'], $items[0]['tags']);
        $this->assertSame('https://oldsite.example/?p=42', $items[0]['guid']);
        $this->assertSame('2024-03-03 10:00:00', $items[0]['created']);
    }

    public function testExtractItemsPrefersPostDateGmtOverLocalPostDate(): void
    {
        // The fixture has post_date=11:00:00 and post_date_gmt=10:00:00.
        // Running under UTC, the GMT value should win — the local field
        // is ambiguous (no offset emitted by WP) and would silently mis-stamp
        // when the WP site's timezone differs from the importer's.
        $rss = parse_wxr_string(self::SAMPLE_WXR);
        $items = extract_items($rss);
        $this->assertSame('2024-03-03 10:00:00', $items[0]['created']);
    }

    public function testExtractItemsUsesPostModifiedGmtForUpdated(): void
    {
        $rss = parse_wxr_string(self::SAMPLE_WXR);
        $items = extract_items($rss);
        $this->assertSame('2024-03-05 11:30:00', $items[0]['updated']);
    }

    public function testExtractItemsFallsBackUpdatedToCreatedWhenModifiedMissing(): void
    {
        // The About item has no post_modified*; updated should mirror created.
        $rss = parse_wxr_string(self::SAMPLE_WXR);
        $items = extract_items($rss);
        $this->assertSame($items[1]['created'], $items[1]['updated']);
    }

    public function testExtractItemsSkipsZeroSentinelDate(): void
    {
        // Posts saved as drafts in WP emit `0000-00-00 00:00:00` for the GMT
        // field; the importer must fall through to the local field or pubDate
        // rather than treating the sentinel as a real timestamp.
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/" xmlns:wp="http://wordpress.org/export/1.2/">
<channel>
    <item>
        <title>Zero sentinel</title>
        <guid>https://x/?p=1</guid>
        <pubDate>Mon, 03 Mar 2024 10:00:00 +0000</pubDate>
        <content:encoded><![CDATA[<p>x</p>]]></content:encoded>
        <wp:post_date>2024-03-03 11:00:00</wp:post_date>
        <wp:post_date_gmt>0000-00-00 00:00:00</wp:post_date_gmt>
        <wp:post_id>1</wp:post_id>
        <wp:status>publish</wp:status>
        <wp:post_type>post</wp:post_type>
    </item>
</channel>
</rss>
XML;
        $items = extract_items(parse_wxr_string($xml));
        // Falls through to the local field (treated as-is).
        $this->assertSame('2024-03-03 11:00:00', $items[0]['created']);
    }

    public function testExtractItemsReadsWordpressPostNameAsSlug(): void
    {
        $rss = parse_wxr_string(self::SAMPLE_WXR);
        $items = extract_items($rss);
        $this->assertSame('hello-world', $items[0]['slug']);
        $this->assertSame('about-us', $items[1]['slug']);
    }

    public function testBuildPostBodyEmitsSlugFrontMatterWhenProvided(): void
    {
        $body = build_post_body('My Title', 'Body.', [], 'custom-permalink');
        $this->assertStringContainsString('slug: custom-permalink', $body);
        $matter = \Lamb\Post\parse_matter($body);
        $this->assertSame('custom-permalink', $matter['slug']);
    }

    public function testImportItemPreservesWordpressSlug(): void
    {
        if (!class_exists(\League\HTMLToMarkdown\HtmlConverter::class)) {
            $this->markTestSkipped('league/html-to-markdown is not installed');
        }
        // The title would slugify to "about", but the WP permalink leaf is
        // "about-us". The imported post must keep the WP slug.
        $item = [
            'title' => 'About',
            'guid' => 'https://oldsite.example/?page_id=2',
            'created' => '2024-01-01 09:00:00',
            'updated' => '2024-01-01 09:00:00',
            'content' => '<p>About page body.</p>',
            'tags' => [],
            'status' => 'publish',
            'post_type' => 'page',
            'slug' => 'about-us',
        ];

        $bean = import_item($item, fn() => null, false);

        $this->assertNotNull($bean);
        $this->assertSame('about-us', (string) $bean->slug);
    }

    public function testShouldImportPublishedPostsAndPages(): void
    {
        $rss = parse_wxr_string(self::SAMPLE_WXR);
        $items = extract_items($rss);
        $this->assertTrue(should_import($items[0])); // published post
        $this->assertTrue(should_import($items[1])); // published page
        $this->assertFalse(should_import($items[2])); // draft
        $this->assertFalse(should_import($items[3])); // custom post type
    }

    public function testRewriteImageLinksDownloadsImagesAndReplacesUrl(): void
    {
        $downloaded = [];
        $downloader = function (string $url, string $dest_dir) use (&$downloaded): ?string {
            $downloaded[] = $url;
            // Pretend we wrote a file; return the saved filename.
            return 'abc123.jpg';
        };

        $html = '<p><img src="https://oldsite.example/wp-content/uploads/2024/03/photo.jpg" alt="x"/></p>';
        $out = rewrite_image_links($html, '2024-03-03 10:00:00', $downloader);

        $this->assertCount(1, $downloaded);
        $this->assertStringContainsString('assets/2024/03/abc123.jpg', $out);
        $this->assertStringNotContainsString('oldsite.example', $out);
    }

    public function testRewriteImageLinksDownloadsOffSiteImages(): void
    {
        // WordPress exports often reference images on a different host than
        // <wp:base_blog_url> (CDN, migrated domain, multisite uploads). The
        // importer must pull those in too — the downloader's content-type /
        // extension guards are what keep this safe, not a host allowlist.
        $downloaded = [];
        $downloader = function (string $url) use (&$downloaded): ?string {
            $downloaded[] = $url;
            return 'abc123.jpg';
        };

        $html = '<p><img src="https://other.example/img.jpg"/></p>';
        $out = rewrite_image_links($html, '2024-03-03 10:00:00', $downloader);

        $this->assertSame(['https://other.example/img.jpg'], $downloaded);
        $this->assertStringContainsString('assets/2024/03/abc123.jpg', $out);
        $this->assertStringNotContainsString('other.example', $out);
    }

    public function testRewriteImageLinksLeavesUrlWhenDownloadFails(): void
    {
        $downloader = fn(): ?string => null;

        $html = '<p><img src="https://oldsite.example/wp-content/uploads/2024/03/photo.jpg"/></p>';
        $out = rewrite_image_links($html, '2024-03-03 10:00:00', $downloader);

        $this->assertStringContainsString('oldsite.example/wp-content/uploads/2024/03/photo.jpg', $out);
    }

    public function testRewriteImageLinksSkipsDataAndRelativeSrc(): void
    {
        $downloaded = [];
        $downloader = function (string $url) use (&$downloaded): ?string {
            $downloaded[] = $url;
            return 'abc123.jpg';
        };

        $html = '<p>'
            . '<img src="data:image/png;base64,iVBORw0KG"/>'
            . '<img src="/relative/path.jpg"/>'
            . '</p>';
        $out = rewrite_image_links($html, '2024-03-03 10:00:00', $downloader);

        $this->assertSame([], $downloaded);
        $this->assertStringContainsString('data:image/png', $out);
        $this->assertStringContainsString('/relative/path.jpg', $out);
    }

    public function testRewriteImageLinksPrefersDataFullUrlOverDownscaledSrc(): void
    {
        // WordPress gallery blocks put the full-resolution URL in data-full-url
        // and a downscaled variant (e.g. -473x1024.jpg) in src. The importer
        // must hand the *full* URL to the downloader so convert_to_webp's
        // 1600px resize starts from a higher-quality source.
        $downloaded = [];
        $downloader = function (string $url) use (&$downloaded): ?string {
            $downloaded[] = $url;
            return 'abc.jpg';
        };

        $html = '<p><img'
            . ' src="https://oldsite.example/photo-473x1024.jpg"'
            . ' data-full-url="https://oldsite.example/photo.jpg"'
            . ' data-id="346"'
            . '/></p>';
        $out = rewrite_image_links($html, '2024-03-03 10:00:00', $downloader);

        $this->assertSame(['https://oldsite.example/photo.jpg'], $downloaded);
        $this->assertStringContainsString('assets/2024/03/abc.jpg', $out);
        $this->assertStringNotContainsString('data-full-url', $out);
        $this->assertStringNotContainsString('oldsite.example', $out);
    }

    public function testRewriteImageLinksUsesDataSrcWhenSrcMissing(): void
    {
        // Lazy-loading plugins emit `<img data-src="…">` (often with a 1px
        // placeholder src, or no src at all). Without this fallback the
        // image would silently fail to import.
        $downloaded = [];
        $downloader = function (string $url) use (&$downloaded): ?string {
            $downloaded[] = $url;
            return 'abc.jpg';
        };

        $html = '<p><img data-src="https://oldsite.example/lazy.jpg" alt="x"/></p>';
        $out = rewrite_image_links($html, '2024-03-03 10:00:00', $downloader);

        $this->assertSame(['https://oldsite.example/lazy.jpg'], $downloaded);
        $this->assertStringContainsString('assets/2024/03/abc.jpg', $out);
        $this->assertStringNotContainsString('data-src', $out);
    }

    public function testRewriteImageLinksPromotesProtocolRelativeUrls(): void
    {
        // Some WP installs (and many old multisite networks) emit `//host/...`
        // for images. parse_url() returns no scheme; treat these as https.
        $downloaded = [];
        $downloader = function (string $url) use (&$downloaded): ?string {
            $downloaded[] = $url;
            return 'abc.jpg';
        };

        $html = '<p><img src="//oldsite.example/img.jpg"/></p>';
        $out = rewrite_image_links($html, '2024-03-03 10:00:00', $downloader);

        $this->assertSame(['https://oldsite.example/img.jpg'], $downloaded);
        $this->assertStringContainsString('assets/2024/03/abc.jpg', $out);
    }

    public function testRewriteImageLinksRewritesAnchorHrefsToImages(): void
    {
        // Thumbnail-linking-to-full-size is a common WP pattern. Leaving the
        // anchor href pointed at the source domain breaks "view full size"
        // once the original WP site is decommissioned.
        $downloaded = [];
        $downloader = function (string $url) use (&$downloaded): ?string {
            $downloaded[] = $url;
            return 'full.jpg';
        };

        $html = '<p><a href="https://oldsite.example/full.jpg">'
            . '<img src="https://oldsite.example/thumb.jpg" alt="t"/></a></p>';
        $out = rewrite_image_links($html, '2024-03-03 10:00:00', $downloader);

        $this->assertSame(
            ['https://oldsite.example/thumb.jpg', 'https://oldsite.example/full.jpg'],
            $downloaded
        );
        $this->assertStringContainsString('href="assets/2024/03/full.jpg"', $out);
        $this->assertStringContainsString('src="assets/2024/03/full.jpg"', $out);
        $this->assertStringNotContainsString('oldsite.example', $out);
    }

    public function testRewriteImageLinksLeavesNonImageAnchorsAlone(): void
    {
        // Anchors that point at HTML pages or other non-image resources must
        // not be touched — otherwise we'd 404 every external link.
        $downloaded = [];
        $downloader = function (string $url) use (&$downloaded): ?string {
            $downloaded[] = $url;
            return 'x.jpg';
        };

        $html = '<p><a href="https://oldsite.example/article">read more</a></p>';
        $out = rewrite_image_links($html, '2024-03-03 10:00:00', $downloader);

        $this->assertSame([], $downloaded);
        $this->assertStringContainsString('href="https://oldsite.example/article"', $out);
    }

    public function testRewriteImageLinksUnwrapsPictureToImgFallback(): void
    {
        // <picture> with WebP/AVIF sources + a JPEG <img> fallback. The
        // converter doesn't know <picture>, so unwrapping to the <img> is the
        // only way to keep the body free of raw HTML and still pull the image.
        $downloaded = [];
        $downloader = function (string $url) use (&$downloaded): ?string {
            $downloaded[] = $url;
            return 'pic.jpg';
        };

        $html = '<p><picture>'
            . '<source srcset="https://oldsite.example/img.webp" type="image/webp">'
            . '<source srcset="https://oldsite.example/img.avif" type="image/avif">'
            . '<img src="https://oldsite.example/img.jpg" alt="x">'
            . '</picture></p>';
        $out = rewrite_image_links($html, '2024-03-03 10:00:00', $downloader);

        $this->assertSame(['https://oldsite.example/img.jpg'], $downloaded);
        $this->assertStringContainsString('src="assets/2024/03/pic.jpg"', $out);
        $this->assertStringNotContainsString('<picture', $out);
        $this->assertStringNotContainsString('<source', $out);
        $this->assertStringNotContainsString('oldsite.example', $out);
    }

    public function testRewriteImageLinksSynthesisesImgFromSourceWhenFallbackMissing(): void
    {
        // Real-world <picture> always has an <img> fallback, but defensive:
        // if a stripped/odd snippet only carries <source srcset>, pick the
        // first srcset URL so the image still gets imported.
        $downloaded = [];
        $downloader = function (string $url) use (&$downloaded): ?string {
            $downloaded[] = $url;
            return 'src.jpg';
        };

        $html = '<p><picture>'
            . '<source srcset="https://oldsite.example/big.jpg 1024w, https://oldsite.example/big-2x.jpg 2048w">'
            . '</picture></p>';
        $out = rewrite_image_links($html, '2024-03-03 10:00:00', $downloader);

        $this->assertSame(['https://oldsite.example/big.jpg'], $downloaded);
        $this->assertStringContainsString('src="assets/2024/03/src.jpg"', $out);
        $this->assertStringNotContainsString('<picture', $out);
        $this->assertStringNotContainsString('<source', $out);
    }

    public function testRewriteImageLinksStripsSrcsetAndSizesOnSuccess(): void
    {
        // WordPress responsive markup keeps absolute multi-resolution URLs in
        // srcset. Once we have a single canonical local WebP, leaving srcset
        // in place would silently re-leak the old host back into the post.
        $downloader = fn(): ?string => 'abc123.jpg';

        $html = '<p><img src="https://oldsite.example/photo.jpg"'
            . ' srcset="https://oldsite.example/photo-300.jpg 300w, https://oldsite.example/photo-600.jpg 600w"'
            . ' sizes="(max-width: 600px) 100vw, 600px"/></p>';
        $out = rewrite_image_links($html, '2024-03-03 10:00:00', $downloader);

        $this->assertStringContainsString('assets/2024/03/abc123.jpg', $out);
        $this->assertStringNotContainsString('srcset', $out);
        $this->assertStringNotContainsString('sizes', $out);
        $this->assertStringNotContainsString('oldsite.example', $out);
    }

    public function testResponseIsImageAcceptsImageContentType(): void
    {
        $this->assertTrue(response_is_image([
            'HTTP/1.1 200 OK',
            'Content-Type: image/jpeg',
        ]));
        $this->assertTrue(response_is_image([
            'HTTP/1.1 200 OK',
            'content-type:image/webp; charset=binary',
        ]));
    }

    public function testResponseIsImageRejectsHtmlAndMissingHeader(): void
    {
        // HTML error page returned with 200 — the URL extension said .jpg but
        // the server actually handed us HTML. This is exactly the case the
        // Content-Type sniff exists to catch.
        $this->assertFalse(response_is_image([
            'HTTP/1.1 200 OK',
            'Content-Type: text/html; charset=utf-8',
        ]));
        // No Content-Type at all → unknown payload, reject defensively.
        $this->assertFalse(response_is_image(['HTTP/1.1 200 OK']));
    }

    public function testImportItemCreatesPostWithWordpressUuid(): void
    {
        if (!class_exists(\League\HTMLToMarkdown\HtmlConverter::class)) {
            $this->markTestSkipped('league/html-to-markdown is not installed');
        }
        $item = [
            'title' => 'Hello World',
            'guid' => 'https://oldsite.example/?p=42',
            'created' => '2024-03-03 10:00:00',
            'updated' => '2024-03-03 10:00:00',
            'content' => '<p>Hello <strong>world</strong>.</p>',
            'tags' => ['news'],
            'status' => 'publish',
            'post_type' => 'post',
        ];

        $bean = import_item($item, fn() => null, false);

        $this->assertNotNull($bean);
        $this->assertNotEmpty($bean->id);
        $expected = md5('wordpress-https://oldsite.example/?p=42');
        $this->assertSame($expected, $bean->feeditem_uuid);
        $this->assertSame('2024-03-03 10:00:00', $bean->created);
        $this->assertStringContainsString('**world**', (string) $bean->body);
        $this->assertStringContainsString('#news', (string) $bean->body);
    }

    public function testImportItemDoesNotPinNumericSlugForWordpressStatusUrl(): void
    {
        if (!class_exists(\League\HTMLToMarkdown\HtmlConverter::class)) {
            $this->markTestSkipped('league/html-to-markdown is not installed');
        }
        $item = [
            'title' => '',
            'guid' => 'https://oldsite.example/?p=59',
            'link' => 'https://oldsite.example/status/59/',
            'created' => '2024-03-03 10:00:00',
            'updated' => '2024-03-03 10:00:00',
            'content' => '<p>Status body.</p>',
            'tags' => ['status'],
            'status' => 'publish',
            'post_type' => 'post',
            'slug' => '59',
        ];

        $bean = import_item($item, fn() => null, false);

        $this->assertNotNull($bean);
        $this->assertSame('', (string) $bean->slug);
        $this->assertStringNotContainsString('slug:', (string) $bean->body);
    }

    public function testImportItemStoresRedirectFromWordpressStatusUrlToLocalStatusUrl(): void
    {
        if (!class_exists(\League\HTMLToMarkdown\HtmlConverter::class)) {
            $this->markTestSkipped('league/html-to-markdown is not installed');
        }
        $item = [
            'title' => '',
            'guid' => 'https://oldsite.example/?p=59',
            'link' => 'https://oldsite.example/status/59/',
            'created' => '2024-03-03 10:00:00',
            'updated' => '2024-03-03 10:00:00',
            'content' => '<p>Status body.</p>',
            'tags' => ['status'],
            'status' => 'publish',
            'post_type' => 'post',
            'slug' => '59',
        ];

        $bean = import_item($item, fn() => null, false);
        $redirect = R::findOne('redirect', ' from_slug = ? ', ['status/59']);

        $this->assertNotNull($bean);
        $this->assertNotNull($redirect);
        $this->assertSame('/status/' . $bean->id, (string) $redirect->to_url);
    }

    public function testImportItemDropsNumericSlugAndRedirectsFromNonStatusPath(): void
    {
        if (!class_exists(\League\HTMLToMarkdown\HtmlConverter::class)) {
            $this->markTestSkipped('league/html-to-markdown is not installed');
        }
        $item = [
            'title' => '',
            'guid' => 'https://oldsite.example/?p=26',
            'link' => 'https://oldsite.example/bugs/26/',
            'created' => '2024-03-03 10:00:00',
            'updated' => '2024-03-03 10:00:00',
            'content' => '<p>Bug body.</p>',
            'tags' => [],
            'status' => 'publish',
            'post_type' => 'post',
            'slug' => '26',
        ];

        $bean = import_item($item, fn() => null, false);
        $redirect = R::findOne('redirect', ' from_slug = ? ', ['bugs/26']);

        $this->assertNotNull($bean);
        $this->assertSame('', (string) $bean->slug);
        $this->assertNotNull($redirect);
        $this->assertSame('/status/' . $bean->id, (string) $redirect->to_url);
    }

    public function testImportItemIsIdempotentByUuid(): void
    {
        if (!class_exists(\League\HTMLToMarkdown\HtmlConverter::class)) {
            $this->markTestSkipped('league/html-to-markdown is not installed');
        }
        $item = [
            'title' => 'Once',
            'guid' => 'https://oldsite.example/?p=1',
            'created' => '2024-01-01 00:00:00',
            'updated' => '2024-01-01 00:00:00',
            'content' => '<p>Hi.</p>',
            'tags' => [],
            'status' => 'publish',
            'post_type' => 'post',
        ];

        $first = import_item($item, fn() => null, false);
        $second = import_item($item, fn() => null, false);

        $this->assertNotNull($first);
        $this->assertNotNull($second);
        $this->assertSame($first->id, $second->id);
        $this->assertCount(1, R::findAll('post'));
    }

    public function testImportItemDryRunDoesNotStore(): void
    {
        if (!class_exists(\League\HTMLToMarkdown\HtmlConverter::class)) {
            $this->markTestSkipped('league/html-to-markdown is not installed');
        }
        $item = [
            'title' => 'Dry',
            'guid' => 'https://oldsite.example/?p=5',
            'created' => '2024-01-01 00:00:00',
            'updated' => '2024-01-01 00:00:00',
            'content' => '<p>Hi.</p>',
            'tags' => [],
            'status' => 'publish',
            'post_type' => 'post',
        ];

        $bean = import_item($item, fn() => null, true);

        $this->assertNotNull($bean);
        $this->assertEmpty($bean->id);
        $this->assertCount(0, R::findAll('post'));
    }

    public function testPersistImageBytesWritesFallbackForNonConvertibleExtension(): void
    {
        // .gif is in the upload allowlist but should_convert_to_webp() rejects
        // it (animation frames would be flattened by GD), so persist_image_bytes
        // must fall back to writing the raw bytes under "$seed.gif".
        $dir = sys_get_temp_dir() . '/lamb_persist_' . uniqid('', true);
        mkdir($dir, 0777, true);
        try {
            $bytes = 'GIF89a' . str_repeat("\0", 32);
            $seed = 'deadbeef';
            $filename = persist_image_bytes($bytes, 'gif', $dir, $seed);

            $this->assertSame("$seed.gif", $filename);
            $this->assertFileExists("$dir/$filename");
            $this->assertSame($bytes, file_get_contents("$dir/$filename"));
            // The tempfile used internally lives under $dir (so the final
            // rename never crosses filesystems); after a successful write the
            // temp prefix should have been renamed away to "$seed.gif".
            $this->assertCount(
                0,
                glob("$dir/lamb_img_*") ?: [],
                'expected zero leftover temp files'
            );
        } finally {
            array_map('unlink', glob("$dir/*") ?: []);
            rmdir($dir);
        }
    }

    public function testPersistImageBytesReturnsNullWhenDirMissing(): void
    {
        $filename = persist_image_bytes('xx', 'gif', '/does/not/exist', 'seed');
        $this->assertNull($filename);
    }

    public function testPersistImageBytesReturnsNullForEmptyBody(): void
    {
        $dir = sys_get_temp_dir() . '/lamb_persist_' . uniqid('', true);
        mkdir($dir, 0777, true);
        try {
            $this->assertNull(persist_image_bytes('', 'gif', $dir, 'seed'));
        } finally {
            rmdir($dir);
        }
    }
}

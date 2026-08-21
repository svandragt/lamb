<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Theme\asset_loader;
use function Lamb\Theme\get_posts_by_tags;
use function Lamb\Theme\link_source;
use function Lamb\Theme\related_posts;

class ThemeExtendedTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        // Ensure visibility columns exist (RedBeanPHP fluid mode only creates columns on first store).
        // Without deleted/created present, the visibility SQL errors and fluid mode silently returns
        // an empty result set, masking the behaviour under test.
        $seed = R::dispense('post');
        $seed->draft = 0;
        $seed->deleted = 0;
        $seed->created = date('Y-m-d H:i:s');
        R::store($seed);
        R::exec("DELETE FROM post");

        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'http://localhost');
        }

        global $config;
        $config['feeds'] = [
            'ExampleBlog' => 'https://example.com/feed',
            'AnotherFeed' => 'https://another.com/rss',
        ];

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    // link_source

    public function testLinkSourceReturnsEmptyWhenNoFeedName(): void
    {
        $bean = R::dispense('post');
        R::store($bean);

        $result = link_source($bean);
        $this->assertSame('', $result);
    }

    public function testLinkSourceReturnsViaLinkWhenFeedNameSet(): void
    {
        $bean = R::dispense('post');
        $bean->feed_name = 'ExampleBlog';
        R::store($bean);

        $result = link_source($bean);
        $this->assertStringContainsString('Via', $result);
        $this->assertStringContainsString('<a href=', $result);
    }

    public function testLinkSourceIncludesFeedUrl(): void
    {
        $bean = R::dispense('post');
        $bean->feed_name = 'ExampleBlog';
        R::store($bean);

        $result = link_source($bean);
        $this->assertStringContainsString('https://example.com/feed', $result);
    }

    public function testLinkSourceIncludesFeedName(): void
    {
        $bean = R::dispense('post');
        $bean->feed_name = 'ExampleBlog';
        R::store($bean);

        $result = link_source($bean);
        $this->assertStringContainsString('ExampleBlog', $result);
    }

    public function testLinkSourceWorksForDifferentFeeds(): void
    {
        $bean = R::dispense('post');
        $bean->feed_name = 'AnotherFeed';
        R::store($bean);

        $result = link_source($bean);
        $this->assertStringContainsString('https://another.com/rss', $result);
        $this->assertStringContainsString('AnotherFeed', $result);
    }

    // asset_loader

    public function testAssetLoaderYieldsPublicAsset(): void
    {
        $assets = ['' => ['styles.css']];
        $results = iterator_to_array(asset_loader($assets, 'themes/base/styles'));

        $this->assertCount(1, $results);
        $href = array_values($results)[0];
        $this->assertStringContainsString('styles.css', $href);
    }

    public function testAssetLoaderKeyIsMd5OfHref(): void
    {
        $assets = ['' => ['styles.css']];
        $results = iterator_to_array(asset_loader($assets, 'themes/base/styles'));

        foreach ($results as $key => $href) {
            $this->assertSame(md5($href), $key);
        }
    }

    public function testAssetLoaderSkipsAdminScriptWhenNotLoggedIn(): void
    {
        unset($_SESSION[SESSION_LOGIN]);
        $assets = [SESSION_LOGIN => ['admin.js']];
        $results = iterator_to_array(asset_loader($assets, 'scripts'));

        $this->assertCount(0, $results);
    }

    public function testAssetLoaderIncludesAdminScriptWhenLoggedIn(): void
    {
        $_SESSION[SESSION_LOGIN] = true;
        $assets = [SESSION_LOGIN => ['admin.js']];
        $results = iterator_to_array(asset_loader($assets, 'scripts'));

        $this->assertCount(1, $results);
        $href = array_values($results)[0];
        $this->assertStringContainsString('admin.js', $href);
    }

    public function testAssetLoaderYieldsMultiplePublicAssets(): void
    {
        $assets = ['' => ['app.js', 'extra.js']];
        $results = iterator_to_array(asset_loader($assets, 'scripts'));

        $this->assertCount(2, $results);
    }

    public function testAssetLoaderHrefStartsWithRootUrl(): void
    {
        $assets = ['' => ['styles.css']];
        $results = iterator_to_array(asset_loader($assets, 'styles'));

        $href = array_values($results)[0];
        $this->assertStringStartsWith(ROOT_URL, $href);
    }

    // get_posts_by_tags

    public function testGetPostsByTagsReturnsEmptyArrayWhenNoMatchingPosts(): void
    {
        $result = get_posts_by_tags(['nonexistenttag']);
        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    public function testGetPostsByTagsReturnsEmptyArrayForEmptyTagList(): void
    {
        $result = get_posts_by_tags([]);
        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    public function testGetPostsByTagsFindsPostWithMatchingTag(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'Hello #mytag end';
        $bean->version = 1;
        $bean->created = date('Y-m-d H:i:s');
        R::store($bean);

        $result = get_posts_by_tags(['mytag']);
        $this->assertCount(1, $result);
    }

    public function testGetPostsByTagsDoesNotReturnPostWithDifferentTag(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'Hello #othertag end';
        $bean->version = 1;
        $bean->created = date('Y-m-d H:i:s');
        R::store($bean);

        $result = get_posts_by_tags(['mytag']);
        $this->assertCount(0, $result);
    }

    public function testGetPostsByTagsReturnsUniquePostsWhenMatchedByMultipleTags(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'Hello #alpha and #beta end';
        $bean->version = 1;
        $bean->created = date('Y-m-d H:i:s');
        R::store($bean);

        $result = get_posts_by_tags(['alpha', 'beta']);
        // The same post should only appear once despite matching two tags
        $this->assertCount(1, $result);
    }

    /**
     * The block shows ten links; it used to read every row the LIKE matched to
     * find them. On a common tag that is the whole archive, on the most-visited
     * anonymous page there is: 58 MB for one post page at 8,000 posts sharing a
     * tag, and a fatal "Allowed memory size of 134217728 bytes exhausted" at
     * 20,000 against the images' default 128M limit.
     */
    public function testGetPostsByTagsReadsInBoundedPages(): void
    {
        // More than one page, and interleaved with rows the LIKE matches but
        // body_has_tag() rejects — the reason a plain LIMIT will not do.
        for ($i = 1; $i <= 120; $i++) {
            $this->taggedPost("Post $i about #photography", $i * 2);
            $this->taggedPost("Post $i about #photographylover", $i * 2 + 1);
        }

        R::debug(true, \RedBeanPHP\Logger\RDefault::C_LOGGER_ARRAY);
        try {
            $result = get_posts_by_tags(['photography']);
            $logs = R::getDatabaseAdapter()->getDatabase()->getLogger()->getLogs();
        } finally {
            R::debug(false);
        }

        // The decoys must not stop it filling the block.
        $this->assertCount(10, $result);

        $selects = array_filter(
            array_map(static fn(string $sql): string => str_replace('`', '', $sql), $logs),
            static fn(string $sql): bool => stripos($sql, 'SELECT') !== false
                && stripos($sql, 'FROM post') !== false
        );
        $this->assertNotSame([], $selects, 'the lookup should have queried the post table');
        foreach ($selects as $sql) {
            $this->assertStringContainsStringIgnoringCase(
                'LIMIT',
                $sql,
                'a related-posts query must be bounded: ' . $sql
            );
        }
    }

    private function taggedPost(string $body, int $minutesOld): void
    {
        $bean = R::dispense('post');
        $bean->body = $body;
        $bean->version = 1;
        $bean->created = date('Y-m-d H:i:s', time() - $minutesOld * 60);
        $bean->updated = $bean->created;
        R::store($bean);
    }

    // related_posts

    public function testRelatedPostsReturnsEmptyPostsArrayWhenBodyHasNoTags(): void
    {
        $result = related_posts('<p>No hashtags here.</p>');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('posts', $result);
        $this->assertCount(0, $result['posts']);
    }

    public function testRelatedPostsFindsPostsMatchingTagsInBody(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'Related post about #lamb end';
        $bean->version = 1;
        $bean->created = date('Y-m-d H:i:s');
        R::store($bean);

        // related_posts extracts tags from the body HTML and finds matching posts
        $result = related_posts('<p>Some post about #lamb</p>');
        $this->assertArrayHasKey('posts', $result);
        $this->assertCount(1, $result['posts']);
    }

    public function testRelatedPostsReturnsArrayWithPostsKey(): void
    {
        $result = related_posts('<p>Hello #world</p>');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('posts', $result);
    }

    public function testRelatedPostsExcludesCurrentPost(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'My post about #lamb end';
        $bean->version = 1;
        $bean->created = date('Y-m-d H:i:s');
        R::store($bean);

        $result = related_posts($bean->body, (int) $bean->id);
        $this->assertArrayHasKey('posts', $result);
        $this->assertCount(0, $result['posts']);
    }

    // _related.php title truncation ------------------------------------------

    /**
     * Renders the base theme's related-posts partial for one seeded post.
     *
     * The 2024 theme has no _related.php of its own and falls back to this one,
     * so it renders identically; the 2026 theme overrides it.
     */
    private function renderRelated(string $relatedTitle): string
    {
        $related = R::dispense('post');
        $related->title = $relatedTitle;
        $related->body = 'Related post about #lamb end';
        $related->transformed = '<p>Related post about #lamb end</p>';
        $related->version = 1;
        $related->created = date('Y-m-d H:i:s');
        R::store($related);

        $current = R::dispense('post');
        $current->body = 'Current post about #lamb end';
        $current->transformed = '<p>Current post about #lamb end</p>';
        $current->version = 1;
        $current->created = date('Y-m-d H:i:s');
        R::store($current);

        global $data, $template;
        $data = ['posts' => [$current]];
        $template = 'status';

        ob_start();
        include dirname(__DIR__, 2) . '/src/themes/base/parts/_related.php';
        return (string) ob_get_clean();
    }

    public function testRelatedTitleIsNotCutMidCharacter(): void
    {
        // substr() cuts on bytes, so a title in any script whose characters are
        // multi-byte was truncated mid-sequence; escape()'s ENT_SUBSTITUTE then
        // rendered the broken byte as U+FFFD.
        $html = $this->renderRelated('Заголовок статьи на русском языке для проверки длины');

        $this->assertStringNotContainsString("\u{FFFD}", $html, 'title must not be cut mid-character');
        $this->assertTrue(mb_check_encoding($html, 'UTF-8'), 'rendered markup must be valid UTF-8');
    }

    public function testRelatedTitleKeepsTheSameLengthAcrossScripts(): void
    {
        // Byte truncation gave a Cyrillic title half as many characters as a
        // Latin one from the same limit.
        $latin = $this->renderRelated(str_repeat('a', 80));
        $cyrillic = $this->renderRelated(str_repeat('б', 80));

        $this->assertSame(
            mb_strlen((string) $this->relatedSpan($latin)),
            mb_strlen((string) $this->relatedSpan($cyrillic)),
            'the limit should count characters, not bytes'
        );
    }

    public function testRelatedTitleThatFitsGetsNoEllipsis(): void
    {
        // The literal &hellip; sat outside the trim, so every related title got
        // an ellipsis whether it had been shortened or not.
        $html = $this->renderRelated('Short title');

        $this->assertStringContainsString('Short title', $html);
        $this->assertStringNotContainsString('…', $this->relatedSpan($html) ?? '');
        $this->assertStringNotContainsString('&hellip;', $html);
    }

    public function testRelatedTitleThatIsTooLongIsTrimmedWithAnEllipsis(): void
    {
        $html = $this->renderRelated(str_repeat('a', 200));
        $span = (string) $this->relatedSpan($html);

        $this->assertStringContainsString('…', $span);
        $this->assertLessThanOrEqual(42, mb_strwidth($span));
    }

    /**
     * The text of the related item's title span, or null when none was rendered.
     */
    private function relatedSpan(string $html): ?string
    {
        if (preg_match('#<span>(.*?)</span>#s', $html, $m) !== 1) {
            return null;
        }
        return html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5);
    }

    public function testGetPostsByTagsExcludesGivenId(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'Post about #exclude end';
        $bean->version = 1;
        $bean->created = date('Y-m-d H:i:s');
        R::store($bean);

        $result = get_posts_by_tags(['exclude'], (int) $bean->id);
        $this->assertCount(0, $result);
    }

    public function testGetPostsByTagsLimitsResults(): void
    {
        for ($i = 0; $i < 11; $i++) {
            $bean = R::dispense('post');
            $bean->body = "Post $i about #limitme end";
            $bean->version = 1;
            $bean->created = date('Y-m-d H:i:s');
            R::store($bean);
        }

        $result = get_posts_by_tags(['limitme']);
        $this->assertCount(10, $result);
    }

    public function testGetPostsByTagsExcludesDrafts(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'Draft post about #draftme end';
        $bean->version = 1;
        $bean->draft = 1;
        $bean->created = date('Y-m-d H:i:s');
        R::store($bean);

        $result = get_posts_by_tags(['draftme']);
        $this->assertCount(0, $result);
    }

    public function testGetPostsByTagsExcludesTrashedPosts(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'Trashed post about #trashme end';
        $bean->version = 1;
        $bean->deleted = 1;
        $bean->created = date('Y-m-d H:i:s');
        R::store($bean);

        $result = get_posts_by_tags(['trashme']);
        $this->assertCount(0, $result);
    }

    public function testGetPostsByTagsExcludesScheduledPosts(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'Scheduled post about #scheduleme end';
        $bean->version = 1;
        $bean->draft = 0;
        $bean->created = date('Y-m-d H:i:s', time() + 86400);
        R::store($bean);

        $result = get_posts_by_tags(['scheduleme']);
        $this->assertCount(0, $result);
    }
}

<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimplePie\Item as SimplePieItem;

use RedBeanPHP\R;

use function Lamb\Network\attributed_content;
use function Lamb\Network\get_structured_content;
use function Lamb\Network\purge_deleted_posts;

class NetworkTest extends TestCase
{
    private function makeItem(string $title = '', string $description = '', string $permalink = ''): SimplePieItem
    {
        $item = $this->createMock(SimplePieItem::class);
        $item->method('get_title')->willReturn($title);
        $item->method('get_description')->willReturn($description);
        $item->method('get_permalink')->willReturn($permalink);
        return $item;
    }

    // attributed_content

    public function testAttributedContentIncludesFeedName(): void
    {
        $item = $this->makeItem('', 'Hello world', 'https://example.com/post');
        $result = attributed_content($item, 'ExampleBlog');
        $this->assertStringContainsString('ExampleBlog', $result);
    }

    public function testAttributedContentIncludesPermalink(): void
    {
        $item = $this->makeItem('', 'Content', 'https://example.com/post');
        $result = attributed_content($item, 'Blog');
        $this->assertStringContainsString('https://example.com/post', $result);
    }

    public function testAttributedContentStripsHtmlTags(): void
    {
        $item = $this->makeItem('', '<p>Hello <b>world</b></p>', 'https://example.com');
        $result = attributed_content($item, 'Blog');
        $this->assertStringNotContainsString('<p>', $result);
        $this->assertStringNotContainsString('<b>', $result);
    }

    public function testAttributedContentQuotesEachLine(): void
    {
        $item = $this->makeItem('', "Line one\nLine two", 'https://example.com');
        $result = attributed_content($item, 'Blog');
        $this->assertStringContainsString('> Line one', $result);
        $this->assertStringContainsString('> Line two', $result);
    }

    public function testAttributedContentLimitsToFiveLines(): void
    {
        $description = implode("\n", range(1, 10));
        $item = $this->makeItem('', $description, 'https://example.com');
        $result = attributed_content($item, 'Blog');
        // Lines 6-10 should not appear as quoted lines
        $this->assertStringNotContainsString('> 6', $result);
        $this->assertStringNotContainsString('> 10', $result);
    }

    public function testAttributedContentEmptyDescriptionReturnsAttribution(): void
    {
        $item = $this->makeItem('', '', 'https://example.com');
        $result = attributed_content($item, 'Blog');
        $this->assertStringContainsString('Originally written on', $result);
    }

    // get_structured_content

    public function testGetStructuredContentWithTitleAddsFrontMatter(): void
    {
        $item = $this->makeItem('My Post Title', 'Some content', 'https://example.com');
        $result = get_structured_content($item, 'Blog');
        $this->assertStringContainsString('---', $result);
        $this->assertStringContainsString('title: My Post Title', $result);
    }

    public function testGetStructuredContentWithoutTitleHasNoFrontMatter(): void
    {
        $item = $this->makeItem('', 'Some content', 'https://example.com');
        $result = get_structured_content($item, 'Blog');
        $this->assertStringNotContainsString('---', $result);
        $this->assertStringNotContainsString('title:', $result);
    }

    public function testGetStructuredContentIncludesAttributedBody(): void
    {
        $item = $this->makeItem('', 'Hello world', 'https://example.com');
        $result = get_structured_content($item, 'Blog');
        $this->assertStringContainsString('Originally written on', $result);
    }

    public function testGetStructuredContentEscapesTitleSlashes(): void
    {
        $item = $this->makeItem("It's a test", 'Content', 'https://example.com');
        $result = get_structured_content($item, 'Blog');
        $this->assertStringContainsString("title: It\\'s a test", $result);
    }

    public function testGetStructuredContentReturnsString(): void
    {
        $item = $this->makeItem('Title', 'Body', 'https://example.com');
        $this->assertIsString(get_structured_content($item, 'Blog'));
    }

    // --- purge_deleted_posts ---

    protected function setUpDb(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        $schema = R::dispense('post');
        $schema->deleted    = null;
        $schema->deleted_at = null;
        R::store($schema);
        R::exec('DELETE FROM post');
    }

    public function testPurgeDeletedPostsHardDeletesPostsOlderThan30Days(): void
    {
        $this->setUpDb();

        $old = R::dispense('post');
        $old->body       = 'Old deleted post';
        $old->deleted    = 1;
        $old->deleted_at = date('Y-m-d H:i:s', strtotime('-31 days'));
        $old->created    = date('Y-m-d H:i:s', strtotime('-60 days'));
        $old->updated    = date('Y-m-d H:i:s', strtotime('-31 days'));
        R::store($old);
        $oldId = $old->id;

        purge_deleted_posts();

        $loaded = R::load('post', $oldId);
        $this->assertSame(0, $loaded->id);
    }

    public function testPurgeDeletedPostsDoesNotHardDeleteRecentlyDeletedPosts(): void
    {
        $this->setUpDb();

        $recent = R::dispense('post');
        $recent->body       = 'Recently deleted post';
        $recent->deleted    = 1;
        $recent->deleted_at = date('Y-m-d H:i:s', strtotime('-5 days'));
        $recent->created    = date('Y-m-d H:i:s');
        $recent->updated    = date('Y-m-d H:i:s');
        R::store($recent);
        $recentId = $recent->id;

        purge_deleted_posts();

        $loaded = R::load('post', $recentId);
        $this->assertSame($recentId, $loaded->id);
    }

    public function testPurgeDeletedPostsDoesNotAffectLivePosts(): void
    {
        $this->setUpDb();

        $live = R::dispense('post');
        $live->body    = 'Live post';
        $live->created = date('Y-m-d H:i:s');
        $live->updated = date('Y-m-d H:i:s');
        R::store($live);
        $liveId = $live->id;

        purge_deleted_posts();

        $loaded = R::load('post', $liveId);
        $this->assertSame($liveId, $loaded->id);
    }
}

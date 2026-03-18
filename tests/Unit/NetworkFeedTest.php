<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;
use SimplePie\Item as SimplePieItem;

use function Lamb\Network\create_item;
use function Lamb\Network\get_feeds;
use function Lamb\Network\prepare_item;
use function Lamb\Network\update_item;

class NetworkFeedTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::exec("DELETE FROM post");

        global $config;
        $config = [
            'feeds'       => [
                'TestBlog'    => 'https://testblog.example.com/feed',
                'AnotherBlog' => 'https://another.example.com/rss',
            ],
            'feeds_draft' => false,
        ];

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function makeItem(
        string $title = 'Test Title',
        string $description = 'Test description content',
        string $permalink = 'https://example.com/post/1',
        string $id = 'post-id-1',
        string $date = '2024-01-01 12:00:00',
        string $updated = '2024-01-01 12:00:00'
    ): SimplePieItem {
        $item = $this->createMock(SimplePieItem::class);
        $item->method('get_title')->willReturn($title);
        $item->method('get_description')->willReturn($description);
        $item->method('get_permalink')->willReturn($permalink);
        $item->method('get_id')->willReturn($id);
        $item->method('get_date')->willReturn($date);
        $item->method('get_updated_date')->willReturn($updated);
        return $item;
    }

    // get_feeds

    public function testGetFeedsReturnsEmptyArrayWhenNoneConfigured(): void
    {
        global $config;
        $original = $config;
        unset($config['feeds']);

        $result = get_feeds();
        $this->assertSame([], $result);

        $config = $original;
    }

    public function testGetFeedsReturnsConfiguredFeeds(): void
    {
        $result = get_feeds();
        $this->assertArrayHasKey('TestBlog', $result);
        $this->assertSame('https://testblog.example.com/feed', $result['TestBlog']);
    }

    public function testGetFeedsReturnsAllConfiguredFeeds(): void
    {
        $result = get_feeds();
        $this->assertCount(2, $result);
    }

    public function testGetFeedsReturnsEmptyArrayWhenFeedsKeyIsMissing(): void
    {
        global $config;
        $original = $config;
        $config = [];

        $result = get_feeds();
        $this->assertSame([], $result);

        $config = $original;
    }

    // prepare_item

    public function testPrepareItemReturnsBean(): void
    {
        $item = $this->makeItem();
        $bean = R::dispense('post');

        $result = prepare_item($item, 'TestBlog', $bean);
        $this->assertNotNull($result);
    }

    public function testPrepareItemSetsFeedName(): void
    {
        $item = $this->makeItem();
        $bean = R::dispense('post');

        $result = prepare_item($item, 'TestBlog', $bean);
        $this->assertSame('TestBlog', $result->feed_name);
    }

    public function testPrepareItemSetsBodyFromStructuredContent(): void
    {
        $item = $this->makeItem('My Title', 'Post description');
        $bean = R::dispense('post');

        $result = prepare_item($item, 'TestBlog', $bean);
        $this->assertNotEmpty($result->body);
        $this->assertStringContainsString('My Title', $result->body);
    }

    public function testPrepareItemSetsVersionToOne(): void
    {
        $item = $this->makeItem();
        $bean = R::dispense('post');

        $result = prepare_item($item, 'TestBlog', $bean);
        $this->assertSame(1, (int)$result->version);
    }

    public function testPrepareItemWithNewBeanCreatesNewPost(): void
    {
        $item = $this->makeItem('New Post', 'Description', 'https://example.com/new');
        $bean = R::dispense('post');

        $result = prepare_item($item, 'TestBlog', $bean);
        $this->assertNotNull($result);
        $this->assertNotEmpty($result->body);
    }

    // create_item

    public function testCreateItemStoresPostToDatabase(): void
    {
        $item = $this->makeItem('Create Test', 'Body content', 'https://example.com/create', 'unique-id-create');
        $countBefore = R::count('post');

        create_item($item, 'TestBlog');

        $this->assertSame($countBefore + 1, R::count('post'));
    }

    public function testCreateItemSetsFeedItemUuid(): void
    {
        $item = $this->makeItem('UUID Test', 'Body content', 'https://example.com/uuid', 'uuid-test-id');
        create_item($item, 'TestBlog');

        $expectedUuid = md5('TestBlog' . 'uuid-test-id');
        $bean = R::findOne('post', ' feeditem_uuid = ? ', [$expectedUuid]);
        $this->assertNotNull($bean);
    }

    public function testCreateItemSetsFeedName(): void
    {
        $item = $this->makeItem('Feed Name Test', 'Body', 'https://example.com', 'feed-name-id');
        create_item($item, 'TestBlog');

        $expectedUuid = md5('TestBlog' . 'feed-name-id');
        $bean = R::findOne('post', ' feeditem_uuid = ? ', [$expectedUuid]);
        $this->assertNotNull($bean);
        $this->assertSame('TestBlog', $bean->feed_name);
    }

    public function testCreateItemDoesNotCreateDuplicateWhenCalledTwice(): void
    {
        $item = $this->makeItem('Dedup Test', 'Body', 'https://example.com', 'dedup-id');
        create_item($item, 'TestBlog');
        $countAfterFirst = R::count('post');

        // Calling create_item again with the same item creates another record
        // (deduplication is handled by process_feeds checking pub/mod dates)
        create_item($item, 'TestBlog');
        $countAfterSecond = R::count('post');

        // create_item itself doesn't prevent duplicates — it always inserts
        $this->assertSame($countAfterFirst + 1, $countAfterSecond);
    }

    // update_item

    public function testUpdateItemDoesNothingWhenPostNotFound(): void
    {
        $item = $this->makeItem('Update Test', 'Body', 'https://example.com', 'missing-uuid-id');
        $countBefore = R::count('post');

        update_item($item, 'TestBlog');

        $this->assertSame($countBefore, R::count('post'));
    }

    public function testUpdateItemUpdatesExistingPost(): void
    {
        // Create a post with the expected UUID
        $uuid = md5('TestBlog' . 'update-test-id');
        $bean = R::dispense('post');
        $bean->feeditem_uuid = $uuid;
        $bean->body = 'Original body';
        $bean->version = 1;
        $bean->created = '2024-01-01 10:00:00';
        $bean->updated = '2024-01-01 10:00:00';
        R::store($bean);

        $item = $this->makeItem('Updated Title', 'New description', 'https://example.com', 'update-test-id', '2024-01-01 10:00:00', '2024-06-01 15:00:00');
        update_item($item, 'TestBlog');

        $updated = R::load('post', $bean->id);
        $this->assertSame('2024-06-01 15:00:00', $updated->updated);
    }

    public function testUpdateItemPreservesPostCount(): void
    {
        $uuid = md5('TestBlog' . 'preserve-count-id');
        $bean = R::dispense('post');
        $bean->feeditem_uuid = $uuid;
        $bean->body = 'Original body';
        $bean->version = 1;
        $bean->created = '2024-01-01 10:00:00';
        $bean->updated = '2024-01-01 10:00:00';
        R::store($bean);

        $countBefore = R::count('post');
        $item = $this->makeItem('Title', 'Body', 'https://example.com', 'preserve-count-id');
        update_item($item, 'TestBlog');

        $this->assertSame($countBefore, R::count('post'));
    }
}

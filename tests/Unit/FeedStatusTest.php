<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;
use SimplePie\Item as SimplePieItem;
use SimplePie\SimplePie;

use function Lamb\Network\feed_status_bean;
use function Lamb\Network\get_feed_statuses;
use function Lamb\Network\prune_feed_status;
use function Lamb\Network\record_feed_crawl;

class FeedStatusTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::nuke();

        global $config;
        $config = [
            'feeds'       => [
                'TestBlog' => 'https://testblog.example.com/feed',
            ],
            'feeds_draft' => false,
        ];
    }

    private function makeItem(
        string $id = 'post-id-1',
        string $title = 'Item Title',
        int $date = 0,
        int $updated = 0
    ): SimplePieItem {
        $item = $this->createMock(SimplePieItem::class);
        $item->method('get_id')->willReturn($id);
        $item->method('get_title')->willReturn($title);
        $item->method('get_description')->willReturn('Body content');
        $item->method('get_permalink')->willReturn('https://example.com/' . $id);
        $item->method('get_date')->willReturn((string)$date);
        $item->method('get_updated_date')->willReturn((string)$updated);
        return $item;
    }

    private function makeFeed(array $data, $error, array $items): SimplePie
    {
        $feed = $this->createMock(SimplePie::class);
        $feed->data = $data;
        $feed->method('error')->willReturn($error);
        $feed->method('get_items')->willReturn($items);
        $feed->method('get_title')->willReturn('A Feed');
        return $feed;
    }

    // feed_status_bean

    public function testFeedStatusBeanIsKeyedByMd5OfNameAndUrl(): void
    {
        $bean = feed_status_bean('TestBlog', 'https://testblog.example.com/feed');
        $this->assertSame(md5('TestBlog' . 'https://testblog.example.com/feed'), $bean->feedkey);
    }

    public function testFeedStatusBeanSeedsSuccessWatermarkFromLegacyOption(): void
    {
        $key    = md5('TestBlog' . 'https://testblog.example.com/feed');
        $option = R::dispense('option');
        $option->name  = 'last_processed_date_' . $key;
        $option->value = 1700000000;
        R::store($option);

        $bean = feed_status_bean('TestBlog', 'https://testblog.example.com/feed');
        $this->assertSame(1700000000, (int)$bean->last_success);
    }

    // record_feed_crawl — failure path

    public function testFailedFetchDoesNotAdvanceSuccessWatermark(): void
    {
        // Seed an existing success watermark.
        $status = feed_status_bean('TestBlog', 'https://testblog.example.com/feed');
        $status->last_success = 1700000000;
        R::store($status);

        $feed   = $this->makeFeed([], 'cURL error 6: Could not resolve host', []);
        $result = record_feed_crawl('TestBlog', 'https://testblog.example.com/feed', $feed);

        $this->assertFalse($result['ok']);

        $reloaded = feed_status_bean('TestBlog', 'https://testblog.example.com/feed');
        $this->assertSame(1700000000, (int)$reloaded->last_success);
    }

    public function testFailedFetchRecordsErrorMessage(): void
    {
        $feed = $this->makeFeed([], 'cURL error 6: Could not resolve host', []);
        record_feed_crawl('TestBlog', 'https://testblog.example.com/feed', $feed);

        $status = feed_status_bean('TestBlog', 'https://testblog.example.com/feed');
        $this->assertStringContainsString('Could not resolve host', (string)$status->error_message);
        $this->assertGreaterThan(0, (int)$status->last_error);
    }

    public function testEmptyDataIsTreatedAsFailure(): void
    {
        $feed   = $this->makeFeed([], null, []);
        $result = record_feed_crawl('TestBlog', 'https://testblog.example.com/feed', $feed);

        $this->assertFalse($result['ok']);
        $status = feed_status_bean('TestBlog', 'https://testblog.example.com/feed');
        $this->assertNotEmpty((string)$status->error_message);
    }

    public function testFailedFetchStillAdvancesLastAttempt(): void
    {
        $feed = $this->makeFeed([], 'boom', []);
        record_feed_crawl('TestBlog', 'https://testblog.example.com/feed', $feed);

        $status = feed_status_bean('TestBlog', 'https://testblog.example.com/feed');
        $this->assertGreaterThan(0, (int)$status->last_attempt);
    }

    // record_feed_crawl — success path

    public function testSuccessfulCrawlAdvancesSuccessWatermarkAndClearsError(): void
    {
        // Pre-seed an error so we can prove a good crawl clears it.
        $status = feed_status_bean('TestBlog', 'https://testblog.example.com/feed');
        $status->error_message = 'old failure';
        $status->last_error    = 1700000000;
        R::store($status);

        $feed   = $this->makeFeed(['type' => 1], null, []);
        $result = record_feed_crawl('TestBlog', 'https://testblog.example.com/feed', $feed);

        $this->assertTrue($result['ok']);
        $reloaded = feed_status_bean('TestBlog', 'https://testblog.example.com/feed');
        $this->assertGreaterThan(0, (int)$reloaded->last_success);
        $this->assertSame('', (string)$reloaded->error_message);
    }

    public function testSuccessfulCrawlCreatesNewItemsAndCountsThem(): void
    {
        R::exec('DELETE FROM post');
        $future = time() + 3600; // newer than the (zero) watermark
        $items  = [
            $this->makeItem('a', 'First', $future, $future),
            $this->makeItem('b', 'Second', $future, $future),
        ];
        $feed   = $this->makeFeed(['type' => 1], null, $items);

        $result = record_feed_crawl('TestBlog', 'https://testblog.example.com/feed', $feed);

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['items']);
        $status = feed_status_bean('TestBlog', 'https://testblog.example.com/feed');
        $this->assertSame(2, (int)$status->item_count);
        $this->assertSame(2, R::count('post'));
    }

    public function testItemsOlderThanWatermarkAreSkipped(): void
    {
        R::exec('DELETE FROM post');
        $status = feed_status_bean('TestBlog', 'https://testblog.example.com/feed');
        $status->last_success = time() + 7200; // watermark in the future
        R::store($status);

        $items = [$this->makeItem('old', 'Old', time(), time())];
        $feed  = $this->makeFeed(['type' => 1], null, $items);

        $result = record_feed_crawl('TestBlog', 'https://testblog.example.com/feed', $feed);
        $this->assertSame(0, $result['items']);
        $this->assertSame(0, R::count('post'));
    }

    // get_feed_statuses

    public function testGetFeedStatusesReturnsRowPerConfiguredFeed(): void
    {
        global $config;
        $config['feeds'] = [
            'One' => 'https://one.example.com/feed',
            'Two' => 'https://two.example.com/feed',
        ];

        $rows = get_feed_statuses();
        $this->assertCount(2, $rows);
        $this->assertSame('One', $rows[0]['name']);
        $this->assertSame('Two', $rows[1]['name']);
        $this->assertArrayHasKey('last_success', $rows[0]);
        $this->assertArrayHasKey('error_message', $rows[0]);
    }

    // prune_feed_status

    public function testPruneRemovesStatusForFeedsNoLongerInConfig(): void
    {
        R::store(feed_status_bean('TestBlog', 'https://testblog.example.com/feed'));
        R::store(feed_status_bean('GoneBlog', 'https://gone.example.com/feed'));

        // 'TestBlog' is still in config (from setUp); 'GoneBlog' is not.
        $removed = prune_feed_status();

        $this->assertSame(1, $removed);
        $this->assertNull(R::findOne('feedstatus', ' feedkey = ? ', [md5('GoneBlog' . 'https://gone.example.com/feed')]));
        $this->assertNotNull(R::findOne('feedstatus', ' feedkey = ? ', [md5('TestBlog' . 'https://testblog.example.com/feed')]));
    }
}

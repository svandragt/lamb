<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;
use SimplePie\Item as SimplePieItem;
use SimplePie\SimplePie;

use function Lamb\Network\begin_crawl;
use function Lamb\Network\feed_fetch_due;
use function Lamb\Network\feed_status_bean;
use function Lamb\Network\get_feed_statuses;
use function Lamb\Network\prune_feed_status;
use function Lamb\Network\record_crawl_failure;
use function Lamb\Network\record_crawl_success;
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
        $status->last_item_date = time() + 7200; // watermark in the future
        R::store($status);

        $items = [$this->makeItem('old', 'Old', time(), time())];
        $feed  = $this->makeFeed(['type' => 1], null, $items);

        $result = record_feed_crawl('TestBlog', 'https://testblog.example.com/feed', $feed);
        $this->assertSame(0, $result['items']);
        $this->assertSame(0, R::count('post'));
    }

    // begin_crawl / record_crawl_failure / record_crawl_success

    public function testBeginCrawlStampsAttemptWithoutTouchingSuccessWatermark(): void
    {
        $seed = feed_status_bean('TestBlog', 'https://testblog.example.com/feed');
        $seed->last_success = 1700000000;
        R::store($seed);

        [$status, $now] = begin_crawl('TestBlog', 'https://testblog.example.com/feed');

        $this->assertGreaterThan(0, $now);
        $this->assertSame($now, (int)$status->last_attempt);
        $this->assertSame(1700000000, (int)$status->last_success);
    }

    public function testBeginCrawlPersistsTheAttemptBeforeTheFetchRuns(): void
    {
        // record_crawl_success()/record_crawl_failure() only run when the fetch
        // returns. A fetch that never returns (the worker OOMs on a hostile feed
        // body, hits max_execution_time, fatals in the parser) used to leave
        // last_attempt untouched on disk, so /_cron found the same feed due on
        // the next run and died at it again — starving every feed after it, the
        // WebSub pings and the whole outbound webmention queue, all of which sit
        // downstream of that loop in process_feeds().
        [, $now] = begin_crawl('TestBlog', 'https://testblog.example.com/feed');

        $reloaded = R::findOne('feedstatus', ' feedkey = ? ', [md5('TestBlog' . 'https://testblog.example.com/feed')]);

        $this->assertNotNull($reloaded);
        $this->assertSame($now, (int)$reloaded->last_attempt);
    }

    public function testBeginCrawlMakesTheFeedNotDueEvenIfTheCrawlNeverReports(): void
    {
        // The observable consequence: the per-feed window is gated on
        // last_attempt, so a crawl that never reports an outcome still holds the
        // feed off for the full window instead of being retried every run.
        [, $now] = begin_crawl('TestBlog', 'https://testblog.example.com/feed');

        $status = feed_status_bean('TestBlog', 'https://testblog.example.com/feed');

        $this->assertFalse(feed_fetch_due((int)$status->last_attempt, $now));
        $this->assertTrue(feed_fetch_due((int)$status->last_attempt, $now + FEED_FETCH_INTERVAL));
    }

    public function testBeginCrawlDoesNotInsertASecondRowForTheOutcome(): void
    {
        // begin_crawl() stores, then record_crawl_*() stores the same bean again.
        // If the second store inserted instead of updating, the Logs tab would
        // grow a duplicate row per crawl and prune_feed_status() would not clear
        // it (the feedkey still matches a configured feed).
        [$status, $now] = begin_crawl('TestBlog', 'https://testblog.example.com/feed');
        record_crawl_success($status, $now, 2);

        $rows = R::find('feedstatus', ' feedkey = ? ', [md5('TestBlog' . 'https://testblog.example.com/feed')]);

        $this->assertCount(1, $rows);
    }

    public function testRecordCrawlFailurePersistsMessageAndReturnsOutcome(): void
    {
        [$status, $now] = begin_crawl('TestBlog', 'https://testblog.example.com/feed');

        $result = record_crawl_failure($status, $now, 'boom');

        $this->assertSame(['ok' => false, 'items' => 0, 'error' => 'boom'], $result);
        $reloaded = feed_status_bean('TestBlog', 'https://testblog.example.com/feed');
        $this->assertSame('boom', (string)$reloaded->error_message);
        $this->assertSame($now, (int)$reloaded->last_error);
        $this->assertSame($now, (int)$reloaded->last_attempt);
        $this->assertSame(0, (int)$reloaded->last_success);
    }

    public function testRecordCrawlSuccessAdvancesWatermarkAndClearsError(): void
    {
        $seed = feed_status_bean('TestBlog', 'https://testblog.example.com/feed');
        $seed->error_message = 'old failure';
        R::store($seed);

        [$status, $now] = begin_crawl('TestBlog', 'https://testblog.example.com/feed');
        $result = record_crawl_success($status, $now, 3);

        $this->assertSame(['ok' => true, 'items' => 3, 'error' => null], $result);
        $reloaded = feed_status_bean('TestBlog', 'https://testblog.example.com/feed');
        $this->assertSame($now, (int)$reloaded->last_success);
        $this->assertSame(3, (int)$reloaded->item_count);
        $this->assertSame('', (string)$reloaded->error_message);
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

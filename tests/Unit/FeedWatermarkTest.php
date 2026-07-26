<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;
use SimplePie\Item as SimplePieItem;
use SimplePie\SimplePie;

use function Lamb\Network\feed_status_bean;
use function Lamb\Network\ingest_item;
use function Lamb\Network\record_feed_crawl;

/**
 * The feed watermark: an item is new relative to the newest item already seen
 * in that feed, not relative to the wall-clock time of the last crawl.
 *
 * Keying "is this item new?" on the crawl clock silently dropped items: any
 * crawl that succeeded without seeing the item (a cached/CDN-stale copy of the
 * feed, a feed that publishes with a lag) still stamped `last_success = now`,
 * and the item's own publication date was by then older than that stamp, so it
 * was never created and never would be.
 */
class FeedWatermarkTest extends TestCase
{
    private const NAME = 'lamb-releases';
    private const URL  = 'https://github.com/svandragt/lamb/releases.atom';

    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::nuke();

        global $config;
        $config = [
            'feeds'       => [self::NAME => self::URL],
            'feeds_draft' => true,
        ];
    }

    private function makeItem(string $id, int $date, ?int $updated = null): SimplePieItem
    {
        $updated ??= $date;
        $item = $this->createMock(SimplePieItem::class);
        $item->method('get_id')->willReturn($id);
        $item->method('get_title')->willReturn('Lamb ' . $id);
        $item->method('get_description')->willReturn('Release notes');
        $item->method('get_permalink')->willReturn('https://example.com/' . $id);
        $item->method('get_date')->willReturnCallback(
            fn(string $format = 'U') => $format === 'U' ? (string) $date : date($format, $date)
        );
        $item->method('get_updated_date')->willReturnCallback(
            fn(string $format = 'U') => $format === 'U' ? (string) $updated : date($format, $updated)
        );
        return $item;
    }

    /**
     * @param list<SimplePieItem> $items
     */
    private function makeFeed(array $items): SimplePie
    {
        $feed = $this->createMock(SimplePie::class);
        $feed->data = ['type' => 1];
        $feed->method('error')->willReturn(null);
        $feed->method('get_items')->willReturn($items);
        $feed->method('get_title')->willReturn('Release notes from lamb');
        return $feed;
    }

    public function testItemPublishedBeforeTheLastCrawlIsStillIngested(): void
    {
        // A crawl succeeded a minute ago against a stale copy of the feed, so
        // the item was not in it. The item itself was published before that.
        $status = feed_status_bean(self::NAME, self::URL);
        $status->last_success = time();
        R::store($status);

        $feed = $this->makeFeed([$this->makeItem('0.12.0', time() - 3600)]);
        $result = record_feed_crawl(self::NAME, self::URL, $feed);

        $this->assertSame(1, $result['items']);
        $this->assertSame(1, R::count('post'));
    }

    public function testSuccessfulCrawlRecordsTheNewestItemDate(): void
    {
        $newest = time() - 60;
        $feed   = $this->makeFeed([
            $this->makeItem('0.11.0', $newest - 86400),
            $this->makeItem('0.12.0', $newest),
        ]);

        record_feed_crawl(self::NAME, self::URL, $feed);

        $status = feed_status_bean(self::NAME, self::URL);
        $this->assertSame($newest, (int) $status->last_item_date);
    }

    public function testItemOlderThanTheNewestSeenIsNotRecreatedAfterPurge(): void
    {
        // The high-water mark is what stops a hard-purged post from being
        // resurrected by an item still sitting in the feed window.
        $newest = time() - 60;
        $status = feed_status_bean(self::NAME, self::URL);
        $status->last_item_date = $newest;
        R::store($status);

        $feed = $this->makeFeed([$this->makeItem('0.11.0', $newest - 86400)]);
        $result = record_feed_crawl(self::NAME, self::URL, $feed);

        $this->assertSame(0, $result['items']);
        $this->assertSame(0, R::count('post'));
    }

    public function testItemDateWatermarkDoesNotRegressOnALaterCrawl(): void
    {
        $newest = time() - 60;
        record_feed_crawl(self::NAME, self::URL, $this->makeFeed([$this->makeItem('0.12.0', $newest)]));
        // A later crawl of a feed whose newest entry has scrolled out of the
        // window must not lower the mark and re-open the door to old items.
        record_feed_crawl(self::NAME, self::URL, $this->makeFeed([$this->makeItem('0.11.0', $newest - 86400)]));

        $status = feed_status_bean(self::NAME, self::URL);
        $this->assertSame($newest, (int) $status->last_item_date);
    }

    public function testExistingPostIsResyncedWhenTheSourceChangedSinceLastSync(): void
    {
        // The re-sync decision belongs to the post, not to the crawl clock: the
        // item was modified after the copy we stored, whatever the watermark says.
        $bean = R::dispense('post');
        $bean->feeditem_uuid = md5(self::NAME . '0.12.0');
        $bean->body    = 'Original body';
        $bean->version = 1;
        $bean->created = '2026-07-25 11:34:15';
        $bean->updated = '2026-07-25 11:34:15';
        R::store($bean);

        $status = feed_status_bean(self::NAME, self::URL);
        $status->last_item_date = PHP_INT_MAX; // no item can beat this
        R::store($status);

        $item = $this->makeItem('0.12.0', strtotime('2026-07-25 11:34:15'), strtotime('2026-07-25 18:00:00'));
        $this->assertTrue(ingest_item($item, self::NAME, PHP_INT_MAX));

        $reloaded = R::load('post', $bean->id);
        $this->assertSame('2026-07-25 18:00:00', $reloaded->updated);
    }

    public function testExistingPostIsNotResyncedWhenTheSourceIsUnchanged(): void
    {
        $bean = R::dispense('post');
        $bean->feeditem_uuid = md5(self::NAME . '0.12.0');
        $bean->body    = 'Original body';
        $bean->version = 1;
        $bean->created = '2026-07-25 11:34:15';
        $bean->updated = '2026-07-25 11:34:15';
        R::store($bean);

        $item = $this->makeItem('0.12.0', strtotime('2026-07-25 11:34:15'));
        $this->assertFalse(ingest_item($item, self::NAME, 0));

        $reloaded = R::load('post', $bean->id);
        $this->assertSame('Original body', $reloaded->body);
    }
}

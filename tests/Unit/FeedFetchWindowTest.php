<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimplePie\SimplePie;

use function Lamb\Network\configure_simplepie_feed;
use function Lamb\Network\feed_fetch_due;

/**
 * SimplePie's own cache must not outlive the /_cron re-fetch window.
 *
 * SimplePie defaults to caching a feed for an hour while /_cron re-fetches every
 * half hour, so every other crawl read an up-to-an-hour-old copy of the feed and
 * still recorded a success. Aligning the two means a crawl always revalidates.
 */
class FeedFetchWindowTest extends TestCase
{
    public function testCacheDurationDoesNotOutliveTheFetchWindow(): void
    {
        $feed = new SimplePie();
        configure_simplepie_feed($feed, 'https://example.com/feed.atom');

        $this->assertLessThanOrEqual(FEED_FETCH_INTERVAL, $feed->cache_duration);
    }

    public function testConfigureAppliesTheFetchTimeout(): void
    {
        $feed = new SimplePie();
        configure_simplepie_feed($feed, 'https://example.com/feed.atom');

        $this->assertSame(FEED_FETCH_TIMEOUT, $feed->timeout);
    }

    public function testFetchWindowMatchesTheConfiguredInterval(): void
    {
        $this->assertFalse(feed_fetch_due(1000, 1000 + FEED_FETCH_INTERVAL - 1));
        $this->assertTrue(feed_fetch_due(1000, 1000 + FEED_FETCH_INTERVAL));
    }
}

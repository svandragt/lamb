<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimplePie\SimplePie;
use Tests\Support\Filesystem;

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
    private string $tmp;
    private string|false $previous_env;

    /**
     * Points the feed cache at a temporary data directory. Without LAMB_DATA_DIR
     * the cache path is `../data`, relative to the working directory, which is
     * outside the checkout and not writable in the dev Docker container.
     */
    protected function setUp(): void
    {
        $this->previous_env = getenv('LAMB_DATA_DIR');
        $this->tmp = sys_get_temp_dir() . '/lamb-feed-window-' . bin2hex(random_bytes(6));
        $this->assertTrue(mkdir($this->tmp, 0755, true), 'Cannot create the temporary data directory');
        putenv('LAMB_DATA_DIR=' . $this->tmp);
    }

    protected function tearDown(): void
    {
        if ($this->previous_env === false) {
            putenv('LAMB_DATA_DIR');
        } else {
            putenv('LAMB_DATA_DIR=' . $this->previous_env);
        }
        Filesystem::removeDirectory($this->tmp);
    }

    public function testCacheDurationDoesNotOutliveTheFetchWindow(): void
    {
        $feed = $this->configuredFeed();

        $this->assertLessThanOrEqual(FEED_FETCH_INTERVAL, $feed->cache_duration);
    }

    public function testConfigureAppliesTheFetchTimeout(): void
    {
        $feed = $this->configuredFeed();

        $this->assertSame(FEED_FETCH_TIMEOUT, $feed->timeout);
    }

    public function testFetchWindowMatchesTheConfiguredInterval(): void
    {
        $this->assertFalse(feed_fetch_due(1000, 1000 + FEED_FETCH_INTERVAL - 1));
        $this->assertTrue(feed_fetch_due(1000, 1000 + FEED_FETCH_INTERVAL));
    }

    /**
     * Configures a feed and checks that its cache landed in the temporary data directory,
     * so the test never reads a feed that wrote outside it.
     */
    private function configuredFeed(): SimplePie
    {
        $feed = new SimplePie();
        configure_simplepie_feed($feed, 'https://example.com/feed.atom');
        $this->assertDirectoryExists($this->tmp . '/cache/simplepie');
        return $feed;
    }
}

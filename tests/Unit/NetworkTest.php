<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use SimplePie\Item as SimplePieItem;
use RedBeanPHP\R;

use function Lamb\Network\acquire_cron_lock;
use function Lamb\Network\attributed_content;
use function Lamb\Network\count_line;
use function Lamb\Network\crawl_line;
use function Lamb\Network\cron_run_due;
use function Lamb\Network\feed_fetch_due;
use function Lamb\Network\get_structured_content;
use function Lamb\Network\purge_deleted_posts;
use function Lamb\Network\webmention_line;

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

    // A hostile feed title must not be able to inject extra YAML front-matter
    // keys (e.g. slug, created) by embedding newlines.

    public function testGetStructuredContentTitleWithNewlineDoesNotInjectFrontMatterKeys(): void
    {
        $item = $this->makeItem("Innocent\nslug: /evil", 'Content', 'https://example.com');
        $result = get_structured_content($item, 'Blog');
        // The injected "slug:" must not appear on its own front-matter line.
        $this->assertStringNotContainsString("\nslug:", $result);
        // And the title must collapse to a single line.
        $this->assertStringContainsString('title: Innocent slug: /evil', $result);
    }

    public function testGetStructuredContentTitleCannotCloseFrontMatterEarly(): void
    {
        // A "---" inside the title would otherwise split the front-matter block.
        $item = $this->makeItem('Before --- After', 'Content', 'https://example.com');
        $result = get_structured_content($item, 'Blog');
        $this->assertStringNotContainsString('Before --- After', $result);
    }

    public function testGetStructuredContentTitleIsLengthCapped(): void
    {
        $item = $this->makeItem(str_repeat('a', 500), 'Content', 'https://example.com');
        $result = get_structured_content($item, 'Blog');
        // Extract the title line and assert it is not unbounded.
        preg_match('/title: (.*)/', $result, $m);
        $this->assertNotEmpty($m);
        $this->assertLessThanOrEqual(200, strlen(trim($m[1])));
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

    // acquire_cron_lock — regression: /_cron's rate-limit watermark is only
    // written after a full run completes, so without a mutual-exclusion lock,
    // a burst of concurrent (unauthenticated) requests would all read the
    // same stale watermark and run in parallel.

    private function lockPath(): string
    {
        return sys_get_temp_dir() . '/lamb_cron_lock_test_' . uniqid('', true) . '.lock';
    }

    public function testAcquireCronLockSucceedsWhenUnlocked(): void
    {
        $path = $this->lockPath();
        $handle = acquire_cron_lock($path);

        $this->assertNotNull($handle);
        $this->assertFileExists($path);

        fclose($handle);
        unlink($path);
    }

    public function testAcquireCronLockFailsWhileAlreadyHeld(): void
    {
        $path = $this->lockPath();
        $first = acquire_cron_lock($path);
        $this->assertNotNull($first, 'precondition: first acquisition must succeed');

        $second = acquire_cron_lock($path);
        $this->assertNull($second, 'a concurrent run must not acquire the lock while another holds it');

        fclose($first);
        unlink($path);
    }

    public function testAcquireCronLockSucceedsAfterPriorHolderReleases(): void
    {
        $path = $this->lockPath();
        $first = acquire_cron_lock($path);
        $this->assertNotNull($first);
        fclose($first);

        $second = acquire_cron_lock($path);
        $this->assertNotNull($second, 'the lock must be acquirable again once released');

        fclose($second);
        unlink($path);
    }

    public function testAcquireCronLockCreatesLockFileWhenAbsent(): void
    {
        $path = $this->lockPath();
        $this->assertFileDoesNotExist($path);

        $handle = acquire_cron_lock($path);
        $this->assertFileExists($path);

        fclose($handle);
        unlink($path);
    }

    // cron_run_due — the whole-run rate limit (1 minute)

    public function testCronRunIsNotDueWithinAMinuteOfTheLastRun(): void
    {
        $now = 1_700_000_000;
        $this->assertFalse(cron_run_due($now - 59, $now));
    }

    public function testCronRunIsDueAfterAFullMinute(): void
    {
        $now = 1_700_000_000;
        $this->assertTrue(cron_run_due($now - MINUTE_IN_SECONDS, $now));
    }

    public function testCronRunIsDueWhenItHasNeverRun(): void
    {
        $this->assertTrue(cron_run_due(0, 1_700_000_000));
    }

    // feed_fetch_due — the per-feed rate limit (30 minutes)

    public function testFeedFetchIsNotDueWithinThirtyMinutesOfTheLastAttempt(): void
    {
        $now = 1_700_000_000;
        $this->assertFalse(feed_fetch_due($now - (29 * MINUTE_IN_SECONDS), $now));
    }

    public function testFeedFetchIsDueAfterThirtyMinutes(): void
    {
        $now = 1_700_000_000;
        $this->assertTrue(feed_fetch_due($now - (30 * MINUTE_IN_SECONDS), $now));
    }

    public function testFeedFetchIsDueForAFeedNeverAttempted(): void
    {
        $this->assertTrue(feed_fetch_due(0, 1_700_000_000));
    }

    // count_line — maintenance summary lines are omitted when nothing happened

    public function testCountLineIsEmptyForZero(): void
    {
        $this->assertSame('', count_line(0, 'Purged %d deleted post(s).'));
    }

    public function testCountLineFormatsAndTerminatesTheLine(): void
    {
        $this->assertSame('Purged 3 deleted post(s).' . PHP_EOL, count_line(3, 'Purged %d deleted post(s).'));
    }

    // crawl_line

    public function testCrawlLineReportsIngestedCountOnSuccess(): void
    {
        $line = crawl_line('MyBlog', ['ok' => true, 'items' => 2, 'error' => null]);
        $this->assertStringContainsString('OK: MyBlog', $line);
        $this->assertStringContainsString('2 item(s)', $line);
    }

    public function testCrawlLineReportsTheErrorOnFailure(): void
    {
        $line = crawl_line('MyBlog', ['ok' => false, 'items' => 0, 'error' => 'could not resolve host']);
        $this->assertStringContainsString('FAILED: MyBlog', $line);
        $this->assertStringContainsString('could not resolve host', $line);
    }

    // webmention_line

    public function testWebmentionLineIsEmptyWhenNothingHappened(): void
    {
        $this->assertSame('', webmention_line(['sent' => 0, 'failed' => 0, 'skipped' => 0, 'cancelled' => 0]));
    }

    public function testWebmentionLineReportsEveryCounter(): void
    {
        $line = webmention_line(['sent' => 1, 'failed' => 2, 'skipped' => 3, 'cancelled' => 4]);
        $this->assertStringContainsString('sent: 1', $line);
        $this->assertStringContainsString('failed: 2', $line);
        $this->assertStringContainsString('skipped: 3', $line);
        $this->assertStringContainsString('cancelled: 4', $line);
    }
}

<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\OODBBean;
use RedBeanPHP\R;

use function Lamb\Config\save_ini_text;
use function Lamb\get_option;
use function Lamb\Response\bump_content_timestamp;
use function Lamb\Response\latest_content_timestamp;
use function Lamb\Response\restore_post;
use function Lamb\Response\soft_delete_post;
use function Lamb\set_option;

/**
 * latest_content_timestamp() drives the conditional-GET validator for anonymous
 * pages. It must reflect the most recent of: the latest published post, and the
 * last config edit — so changing settings invalidates cached pages immediately.
 */
class LatestContentTimestampTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::nuke();
    }

    private function makePost(string $updated): OODBBean
    {
        $post = R::dispense('post');
        $post->updated = $updated;
        // Columns referenced by SQL_PUBLISHED; always present in a real schema.
        $post->draft = 0;
        $post->deleted = 0;
        R::store($post);

        return $post;
    }

    public function testZeroWhenNoPostsAndNoConfig(): void
    {
        $this->assertSame(0, latest_content_timestamp());
    }

    public function testUsesConfigTimestampWhenNoPosts(): void
    {
        $before = time();
        save_ini_text("site_title = Test\n");
        $this->assertGreaterThanOrEqual($before, latest_content_timestamp());
    }

    public function testUsesPostTimestampWhenNewerThanConfig(): void
    {
        save_ini_text("site_title = Test\n");
        R::exec("UPDATE option SET updated = '2000-01-01 00:00:00' WHERE name = 'site_config_ini'");
        $this->makePost('2030-06-01 12:00:00');

        $this->assertSame(strtotime('2030-06-01 12:00:00'), latest_content_timestamp());
    }

    public function testUsesConfigTimestampWhenNewerThanPosts(): void
    {
        $this->makePost('2000-01-01 00:00:00');
        $before = time();
        save_ini_text("site_title = Test\n");

        $this->assertGreaterThanOrEqual($before, latest_content_timestamp());
    }

    /**
     * Core regression for #669: trashing the post that currently holds the
     * newest `updated` must not pull the validator backwards, or a date-only
     * cache/client is served a stale 304 for content that has since changed.
     */
    public function testTrashingNewestPostDoesNotLowerTimestamp(): void
    {
        $this->makePost('2000-01-01 00:00:00');
        $newest = $this->makePost('2030-06-01 12:00:00');

        $before = latest_content_timestamp();

        soft_delete_post($newest);

        $this->assertGreaterThanOrEqual($before, latest_content_timestamp());
    }

    /**
     * Restoring a post is a content mutation too: the mark must still only
     * move forward, never fall back to whatever MAX(updated) happens to be
     * once the restored row is back among the published set.
     */
    public function testRestoringPostDoesNotLowerTimestamp(): void
    {
        $post = $this->makePost('2030-06-01 12:00:00');
        soft_delete_post($post);
        $before = latest_content_timestamp();

        restore_post($post);

        $this->assertGreaterThanOrEqual($before, latest_content_timestamp());
    }

    /**
     * Existing installs upgrading to #669 have no stored mark yet; the first
     * read must backfill it from the current MAX(updated) rather than
     * reporting 0, which would look like "everything just changed" to every
     * client.
     */
    public function testBackfillsFromExistingPostsWhenMarkUnset(): void
    {
        $this->makePost('2030-06-01 12:00:00');

        $this->assertSame(strtotime('2030-06-01 12:00:00'), latest_content_timestamp());
    }

    /**
     * bump_content_timestamp() is the shared chokepoint all three
     * content-mutation sites call through. Seeded with a mark set further in
     * the future than the test clock, it must still never store a lower
     * value — the monotonic guarantee, independent of wall-clock timing.
     */
    public function testBumpNeverLowersAKnownFutureMark(): void
    {
        $future = strtotime('2099-01-01 00:00:00');
        set_option(get_option('content_modified_ts', 0), $future);

        bump_content_timestamp();

        $this->assertGreaterThan($future, latest_content_timestamp());
    }
}

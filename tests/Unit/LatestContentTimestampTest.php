<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Config\save_ini_text;
use function Lamb\Response\latest_content_timestamp;

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

    private function makePost(string $updated): void
    {
        $post = R::dispense('post');
        $post->updated = $updated;
        // Columns referenced by SQL_PUBLISHED; always present in a real schema.
        $post->draft = 0;
        $post->deleted = 0;
        R::store($post);
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
}

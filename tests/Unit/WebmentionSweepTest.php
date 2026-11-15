<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\OODBBean;
use RedBeanPHP\R;

use function Lamb\Bootstrap\ensure_schema;
use function Lamb\Webmention\enqueue_for_recent_posts;

/**
 * Post\save() emits post.published after its commit, and emit() only logs a
 * subscriber's SQL failure, so a post can publish without its webmentions ever
 * being queued. /_cron retries the recent ones that have no outbox rows.
 */
class WebmentionSweepTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::nuke();
        // The production schema: it has every column the sweep's query names.
        ensure_schema();
    }

    /**
     * @param array<string, mixed> $fields Post columns to set on top of a published post with one external link.
     */
    private function post(array $fields = []): OODBBean
    {
        $post = R::dispense('post');
        $post->body = 'Hello';
        $post->transformed = '<p><a href="https://other.example/a">a</a></p>';
        $post->created = date('Y-m-d H:i:s', time() - 3600);
        $post->updated = date('Y-m-d H:i:s', time() - 3600);
        $post->version = 1;
        foreach ($fields as $name => $value) {
            $post->$name = $value;
        }
        R::store($post);

        return $post;
    }

    private function hoursAgo(int $hours): string
    {
        return date('Y-m-d H:i:s', time() - $hours * 3600);
    }

    private function queued(OODBBean $post): int
    {
        return R::count('webmentionoutbox', ' post_id = ? ', [$post->id]);
    }

    public function testItQueuesARecentPostWithNoRows(): void
    {
        $post = $this->post();

        $this->assertSame(1, enqueue_for_recent_posts());

        $row = R::findOne('webmentionoutbox', ' post_id = ? ', [$post->id]);
        $this->assertSame('https://other.example/a', $row->target);
        $this->assertSame('pending', $row->status);
    }

    public function testItQueuesAPostEditedRecentlyThatWasPublishedLongAgo(): void
    {
        $post = $this->post(['created' => $this->hoursAgo(72), 'updated' => $this->hoursAgo(1)]);

        $this->assertSame(1, enqueue_for_recent_posts());
        $this->assertSame(1, $this->queued($post));
    }

    public function testItQueuesAScheduledPostOnceItsTimeHasPassed(): void
    {
        // Saved three days ago for yesterday: `updated` is old, `created` is recent.
        $post = $this->post(['created' => $this->hoursAgo(2), 'updated' => $this->hoursAgo(72)]);

        $this->assertSame(1, enqueue_for_recent_posts());
        $this->assertSame(1, $this->queued($post));
    }

    public function testItSkipsAPostOlderThan24Hours(): void
    {
        $post = $this->post(['created' => $this->hoursAgo(48), 'updated' => $this->hoursAgo(25)]);

        $this->assertSame(0, enqueue_for_recent_posts());
        $this->assertSame(0, $this->queued($post));
    }

    public function testItSkipsAPostThatAlreadyHasRows(): void
    {
        $post = $this->post();
        $row = R::dispense('webmentionoutbox');
        $row->post_id = $post->id;
        $row->source = ROOT_URL . '/status/' . $post->id;
        $row->target = 'https://other.example/a';
        $row->status = 'failed';
        $row->attempts = 3;
        $row->created = $this->hoursAgo(1);
        R::store($row);

        $this->assertSame(0, enqueue_for_recent_posts());

        $this->assertSame(1, $this->queued($post));
        $this->assertSame('failed', R::findOne('webmentionoutbox')->status, 'a failed row is retried by edits, not by the sweep');
    }

    public function testRunningTwiceDoesNotDuplicateRows(): void
    {
        $post = $this->post();

        $this->assertSame(1, enqueue_for_recent_posts());
        $this->assertSame(0, enqueue_for_recent_posts());

        $this->assertSame(1, $this->queued($post));
    }

    public function testAPostWithNoLinksQueuesNothingAndCanBeCheckedAgain(): void
    {
        $post = $this->post(['transformed' => '<p>No links here.</p>']);

        $this->assertSame(0, enqueue_for_recent_posts());
        $this->assertSame(0, enqueue_for_recent_posts());

        $this->assertSame(0, $this->queued($post));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function ineligiblePosts(): array
    {
        return [
            'draft' => [['draft' => 1]],
            'deleted' => [['deleted' => 1]],
            'scheduled' => [['created' => date('Y-m-d H:i:s', time() + 3600)]],
            'feed item' => [['feed_name' => 'Some feed']],
            // An import stamps `updated` with the import time, but never notifies.
            'imported or restored post' => [['import_uuid' => 'wordpress-abc']],
        ];
    }

    /**
     * @param array<string, mixed> $fields
     * @dataProvider ineligiblePosts
     */
    public function testItSkipsPostsThePublishPathDoesNotNotifyFor(array $fields): void
    {
        $post = $this->post($fields);

        $this->assertSame(0, enqueue_for_recent_posts());
        $this->assertSame(0, $this->queued($post));
    }

    public function testAFailingPostDoesNotStopTheSweepAndIsRetriedNextRun(): void
    {
        $failing = $this->post(['transformed' => '<p><a href="https://other.example/fail">a</a></p>']);
        $other = $this->post();
        R::exec("CREATE TRIGGER outbox_block_insert BEFORE INSERT ON webmentionoutbox WHEN NEW.target LIKE '%/fail' BEGIN SELECT RAISE(ABORT, 'boom'); END");

        $log = tempnam(sys_get_temp_dir(), 'lamb-log');
        $previous_log = ini_set('error_log', $log);
        try {
            $this->assertSame(1, enqueue_for_recent_posts());
            $logged = (string) file_get_contents($log);
        } finally {
            ini_set('error_log', (string) $previous_log);
            unlink($log);
            R::exec('DROP TRIGGER outbox_block_insert');
        }

        $this->assertSame(0, $this->queued($failing));
        $this->assertSame(1, $this->queued($other));
        $this->assertStringContainsString('boom', $logged);

        $this->assertSame(1, enqueue_for_recent_posts());
        $this->assertSame(1, $this->queued($failing));
    }
}

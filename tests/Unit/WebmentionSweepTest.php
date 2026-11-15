<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\OODBBean;
use RedBeanPHP\R;

use function Lamb\Bootstrap\ensure_schema;
use function Lamb\parse_bean;
use function Lamb\Post\populate_bean;
use function Lamb\Post\register_default_subscribers;
use function Lamb\Post\reset_subscribers;
use function Lamb\Post\save;
use function Lamb\Response\apply_checkbox_toggle;
use function Lamb\Webmention\enqueue_pending_posts;

use const Lamb\Webmention\ENQUEUE_SWEEP_LIMIT;

/**
 * Post\save() marks a notify save `webmentions_pending` and emits post.published
 * after its commit. The subscriber clears the mark once the webmentions are
 * queued, but emit() only logs its SQL failure (a locked database), so a failed
 * queueing leaves the mark set and /_cron retries the post.
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

        global $config;
        $config = ['websub_hubs' => '', 'site_title' => 'Test Blog', 'menu_items' => [], 'feeds' => []];

        // No subscribers: a notify save marks the post and queues nothing, which is
        // what a failed queueing leaves behind. A test that wants the queueing registers them.
        reset_subscribers();
    }

    protected function tearDown(): void
    {
        reset_subscribers();
    }

    /**
     * @param array<string, mixed> $fields Post columns to set on top of a published, flagged post with one external link.
     */
    private function flaggedPost(array $fields = []): OODBBean
    {
        $post = R::dispense('post');
        $post->body = 'Hello';
        $post->transformed = '<p><a href="https://other.example/a">a</a></p>';
        $post->created = date('Y-m-d H:i:s', time() - 3600);
        $post->updated = date('Y-m-d H:i:s', time() - 3600);
        $post->version = 1;
        $post->webmentions_pending = 1;
        foreach ($fields as $name => $value) {
            $post->$name = $value;
        }
        R::store($post);

        return $post;
    }

    private function queued(OODBBean $post): int
    {
        return R::count('webmentionoutbox', ' post_id = ? ', [$post->id]);
    }

    private function pending(OODBBean $post): int
    {
        return (int) R::load('post', $post->id)->webmentions_pending;
    }

    /**
     * Runs $fn with every insert into the outbox failing, and the error log silenced.
     *
     * @param callable(): void $fn
     */
    private function whileQueueingFails(callable $fn, string $when = ''): void
    {
        $log = tempnam(sys_get_temp_dir(), 'lamb-log');
        $previous_log = ini_set('error_log', $log);
        R::exec("CREATE TRIGGER outbox_block_insert BEFORE INSERT ON webmentionoutbox $when BEGIN SELECT RAISE(ABORT, 'boom'); END");
        try {
            $fn();
        } finally {
            R::exec('DROP TRIGGER outbox_block_insert');
            ini_set('error_log', (string) $previous_log);
            unlink($log);
        }
    }

    public function testAPublishWhoseQueueingFailedIsQueuedByTheNextSweep(): void
    {
        register_default_subscribers();
        $bean = populate_bean('See [a](https://other.example/a).');
        $this->whileQueueingFails(static fn() => save($bean, ['notify' => true]));

        $this->assertSame(0, $this->queued($bean));
        $this->assertSame(1, $this->pending($bean), 'the failed queueing leaves the mark set');

        $this->assertSame(1, enqueue_pending_posts());

        $this->assertSame(1, $this->queued($bean));
        $this->assertSame(0, $this->pending($bean));
    }

    public function testASuccessfulPublishClearsTheMark(): void
    {
        register_default_subscribers();
        $bean = populate_bean('See [a](https://other.example/a).');

        save($bean, ['notify' => true]);

        $this->assertSame(1, $this->queued($bean));
        $this->assertSame(0, $this->pending($bean));
        $this->assertSame(0, enqueue_pending_posts());
    }

    public function testAnEditThatAddsALinkToAPostWithRowsIsRetried(): void
    {
        register_default_subscribers();
        $bean = populate_bean('See [a](https://other.example/a).');
        save($bean, ['notify' => true]);
        $this->assertSame(1, $this->queued($bean));

        $bean->body = 'See [a](https://other.example/a) and [b](https://other.example/b).';
        parse_bean($bean);
        $this->whileQueueingFails(static fn() => save($bean, ['notify' => true]));

        $this->assertSame(1, $this->queued($bean), 'only the first link made it');
        $this->assertSame(1, $this->pending($bean));

        $this->assertSame(1, enqueue_pending_posts());

        $this->assertSame(2, $this->queued($bean));
        $this->assertSame(0, $this->pending($bean));
    }

    public function testACheckboxToggleSetsNoMarkAndQueuesNothing(): void
    {
        register_default_subscribers();
        // A published post with a link and no outbox rows, as an old or imported post has.
        $bean = populate_bean("- [ ] read [a](https://other.example/a)\n");
        R::store($bean);

        $this->assertTrue(apply_checkbox_toggle((int) $bean->id, 0, true));

        $this->assertSame(0, $this->pending($bean));
        $this->assertSame(0, enqueue_pending_posts());
        $this->assertSame(0, $this->queued($bean));
    }

    public function testAPostMarkedPendingIsQueuedOnlyOnce(): void
    {
        $post = $this->flaggedPost();

        $this->assertSame(1, enqueue_pending_posts());
        $this->assertSame(0, enqueue_pending_posts());

        $this->assertSame(1, $this->queued($post));
    }

    public function testAPostWithNoLinksClearsTheMarkWithoutOpeningATransaction(): void
    {
        $post = $this->flaggedPost(['transformed' => '<p>No links here.</p>']);

        $logger = R::debug(true, 1);
        try {
            $this->assertSame(0, enqueue_pending_posts());
        } finally {
            R::debug(false);
        }

        $this->assertSame([], $logger->grep('BEGIN'), 'a post with no links needs no write lock');
        $this->assertSame(0, $this->pending($post));
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
        ];
    }

    /**
     * @param array<string, mixed> $fields
     * @dataProvider ineligiblePosts
     */
    public function testItSkipsPostsThePublishPathDoesNotNotifyFor(array $fields): void
    {
        $post = $this->flaggedPost($fields);

        $this->assertSame(0, enqueue_pending_posts());
        $this->assertSame(0, $this->queued($post));
    }

    public function testAScheduledPostIsQueuedOnceItsTimeHasPassed(): void
    {
        $post = $this->flaggedPost(['created' => date('Y-m-d H:i:s', time() + 3600)]);
        $this->assertSame(0, enqueue_pending_posts());

        $post->created = date('Y-m-d H:i:s', time() - 60);
        R::store($post);

        $this->assertSame(1, enqueue_pending_posts());
        $this->assertSame(1, $this->queued($post));
    }

    public function testAFailingPostDoesNotStopTheSweepAndIsRetriedNextRun(): void
    {
        $failing = $this->flaggedPost(['transformed' => '<p><a href="https://other.example/fail">a</a></p>']);
        $other = $this->flaggedPost();

        $this->whileQueueingFails(function (): void {
            $this->assertSame(1, enqueue_pending_posts());
        }, "WHEN NEW.target LIKE '%/fail'");

        $this->assertSame(0, $this->queued($failing));
        $this->assertSame(1, $this->pending($failing));
        $this->assertSame(1, $this->queued($other));
        $this->assertSame(0, $this->pending($other));

        $this->assertSame(1, enqueue_pending_posts());
        $this->assertSame(1, $this->queued($failing));
    }

    public function testASweepHandlesAtMostTheLimitAndLeavesTheRestForTheNextRun(): void
    {
        for ($i = 0; $i < ENQUEUE_SWEEP_LIMIT + 1; $i++) {
            $this->flaggedPost(['transformed' => '<p>No links here.</p>']);
        }

        enqueue_pending_posts();
        $this->assertSame(1, R::count('post', ' webmentions_pending = 1 '));

        enqueue_pending_posts();
        $this->assertSame(0, R::count('post', ' webmentions_pending = 1 '));
    }
}

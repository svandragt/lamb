<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Post\on;
use function Lamb\Post\populate_bean;
use function Lamb\Post\register_default_subscribers;
use function Lamb\Post\reset_subscribers;
use function Lamb\Post\save;

/**
 * The executable half of the save() funnel: which events fire, with which
 * context, from the one call site converted so far (Response\apply_checkbox_toggle,
 * an existing post with an empty context) plus the flags the funnel implements.
 */
class PostSaveTest extends TestCase
{
    /** @var list<string> */
    private array $events = [];

    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::nuke();

        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'http://localhost');
        }

        global $config;
        $config = ['websub_hubs' => ''];

        reset_subscribers();
        $this->events = [];
        foreach (['post.created', 'post.updated', 'post.published'] as $event) {
            on($event, function () use ($event): void {
                $this->events[] = $event;
            });
        }
    }

    public function testExistingPostEmitsUpdated(): void
    {
        // The checkbox toggle's shape: a loaded (already-stored) bean, empty context.
        $bean = populate_bean('A status.');
        R::store($bean);

        save($bean);

        $this->assertSame(['post.updated'], $this->events);
    }

    public function testNewBeanEmitsCreated(): void
    {
        $bean = populate_bean('A status.');

        save($bean);

        $this->assertSame(['post.created'], $this->events);
        $this->assertNotEmpty($bean->id);
    }

    public function testNotifyEmitsPublishedAfterTheLifecycleEvent(): void
    {
        $bean = populate_bean('A status.');

        save($bean, ['notify' => true]);

        $this->assertSame(['post.created', 'post.published'], $this->events);
    }

    public function testAnEmptyContextNeverPublishes(): void
    {
        $bean = populate_bean('A status.');
        R::store($bean);

        save($bean);

        $this->assertNotContains('post.published', $this->events);
    }

    public function testFinalizeSlugDeduplicatesAndPersists(): void
    {
        $text = "---\nslug: shared\n---\nContent.";

        $first = populate_bean($text);
        save($first, ['finalize_slug' => true]);

        $second = populate_bean($text);
        save($second, ['finalize_slug' => true]);

        $this->assertSame('shared', $first->slug);
        $this->assertSame('shared-' . $second->id, $second->slug);
        $this->assertStringContainsString('slug: shared-' . $second->id, (string) $second->body);
    }

    public function testWithoutFinalizeSlugACollidingSlugIsLeftAsIs(): void
    {
        $text = "---\nslug: shared\n---\nContent.";

        $first = populate_bean($text);
        save($first);

        $second = populate_bean($text);
        save($second);

        // No finalize step: the funnel stored the bean verbatim, so both keep the
        // colliding slug (the create sites that want dedup pass finalize_slug).
        $this->assertSame('shared', $second->slug);
    }

    public function testLockIfFeedSourcedMarksAFeedPostAuthorOwned(): void
    {
        $bean = populate_bean('A status.');
        $bean->feeditem_uuid = md5('feed-item');

        save($bean, ['lock_if_feed_sourced' => true]);

        $this->assertSame(1, (int) $bean->feed_locked);
    }

    public function testLockIfFeedSourcedLeavesANonFeedPostUntouched(): void
    {
        $bean = populate_bean('A status.');
        $bean->feeditem_uuid = md5('feed-item');

        save($bean);

        $this->assertEmpty($bean->feed_locked);
    }

    public function testDefaultSubscribersQueueAWebmentionOnPublish(): void
    {
        // Proves the real wiring, not just the spy: register_default_subscribers()
        // subscribes Webmention\enqueue_for_post to post.published.
        reset_subscribers();
        register_default_subscribers();

        $bean = populate_bean('A status.');
        $bean->transformed = '<a href="https://other.example/a">link</a>';

        save($bean, ['notify' => true]);

        $this->assertCount(1, R::findAll('webmentionoutbox'));
    }

    public function testAnEmptyContextDoesNotQueueAWebmention(): void
    {
        reset_subscribers();
        register_default_subscribers();

        $bean = populate_bean('A status.');
        $bean->transformed = '<a href="https://other.example/a">link</a>';

        save($bean);

        $this->assertCount(0, R::findAll('webmentionoutbox'));
    }
}

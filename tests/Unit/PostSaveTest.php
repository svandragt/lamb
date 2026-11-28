<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;
use RedBeanPHP\RedException\SQL;

use function Lamb\get_option;
use function Lamb\Post\on;
use function Lamb\Post\populate_bean;
use function Lamb\Post\register_default_subscribers;
use function Lamb\Post\reset_subscribers;
use function Lamb\Post\save;
use function Lamb\set_option;

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

    protected function tearDown(): void
    {
        // The registry is static: a throwing subscriber must not outlive its test.
        reset_subscribers();
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

    public function testACreateAndAnEditStillPersist(): void
    {
        $text = "---\nslug: shared\n---\nContent.";
        // The first save creates the schema; the rest run frozen, as in production.
        save(populate_bean($text), ['finalize_slug' => true]);
        R::freeze(true);
        try {
            $second = populate_bean($text);
            save($second, ['finalize_slug' => true]);

            $loaded = R::load('post', $second->id);
            $loaded->body = "---\nslug: " . $loaded->slug . "\n---\nEdited.";
            save($loaded);
        } finally {
            R::freeze(false);
        }

        $this->assertSame(2, R::count('post'));
        $this->assertStringContainsString('Edited.', (string) R::load('post', $second->id)->body);
    }

    /**
     * Seeds a post that owns the slug `taken`, so a create with that slug and
     * finalize_slug stores twice: an INSERT, then an UPDATE that suffixes the slug.
     */
    private function seedTakenSlug(): void
    {
        R::store(populate_bean("---\nslug: taken\n---\nSeed."));
    }

    public function testAFailedCreateCanBeRetriedAsAnInsert(): void
    {
        $this->seedTakenSlug();
        R::exec("CREATE TRIGGER post_block_update BEFORE UPDATE ON post BEGIN SELECT RAISE(ABORT, 'boom'); END");
        $bean = populate_bean("---\nslug: taken\n---\nNew.");
        $original_body = $bean->body;

        try {
            save($bean, ['finalize_slug' => true]);
            $this->fail('The second store should have thrown.');
        } catch (SQL) {
            R::exec('DROP TRIGGER post_block_update');
        }

        // The rolled-back row is gone, so the bean must not still point at its id.
        $this->assertEmpty($bean->id);
        $this->assertSame('taken', $bean->slug);
        $this->assertSame($original_body, $bean->body);

        save($bean, ['finalize_slug' => true]);

        $this->assertSame(2, R::count('post'));
        $this->assertSame('taken-' . $bean->id, $bean->slug);
    }

    public function testAFailedEditPutsTheSlugAndBodyBackButKeepsTheId(): void
    {
        $this->seedTakenSlug();
        $mine = populate_bean("---\nslug: mine\n---\nMine.");
        $id = (int) R::store($mine);

        // Only the finalised slug (`taken-<id>`) is refused, so the first store of the
        // edit succeeds and the second one, written by finalize_slug(), throws.
        R::exec("CREATE TRIGGER post_block_update BEFORE UPDATE ON post WHEN NEW.slug LIKE 'taken-%' BEGIN SELECT RAISE(ABORT, 'boom'); END");
        $mine->slug = 'taken';
        $mine->body = "---\nslug: taken\n---\nMine, edited.";
        $edited_body = $mine->body;

        try {
            save($mine, ['finalize_slug' => true]);
            $this->fail('The second store should have thrown.');
        } catch (SQL) {
            R::exec('DROP TRIGGER post_block_update');
        }

        $this->assertSame($id, (int) $mine->id);
        $this->assertSame('taken', $mine->slug);
        $this->assertSame($edited_body, $mine->body);
        $this->assertSame('mine', R::load('post', $id)->slug);
    }

    public function testASubscriberThatFailsTheDatabaseDoesNotFailACommittedSave(): void
    {
        $later = [];
        on('post.created', function (): void {
            throw new SQL('subscriber broke');
        });
        on('post.published', function () use (&$later): void {
            $later[] = 'published';
        });

        $log = tempnam(sys_get_temp_dir(), 'lamb-log');
        $previous_log = ini_set('error_log', $log);
        try {
            $bean = populate_bean('A status.');
            save($bean, ['notify' => true]);
            $logged = (string) file_get_contents($log);
        } finally {
            ini_set('error_log', (string) $previous_log);
            unlink($log);
        }

        $this->assertSame(1, R::count('post'));
        $this->assertNotEmpty($bean->id);
        $this->assertSame(['published'], $later, 'The next subscriber still runs.');
        $this->assertStringContainsString('post.created', $logged);
        $this->assertStringContainsString(SQL::class, $logged);
        $this->assertStringContainsString('subscriber broke', $logged);
        $this->assertStringContainsString('PostSaveTest.php:', $logged);
    }

    public function testASubscriberErrorThatIsNotADatabaseFailureSurfaces(): void
    {
        on('post.created', function (): void {
            throw new \Error('programming error');
        });

        try {
            save(populate_bean('A status.'));
            $this->fail('The Error should have propagated.');
        } catch (\Error $e) {
            $this->assertSame('programming error', $e->getMessage());
        }

        $this->assertSame(1, R::count('post'), 'The save had already committed.');
    }

    public function testAFailedContentMarkWriteRollsTheSaveBack(): void
    {
        set_option(get_option('seed', 1), 1);
        R::exec("CREATE TRIGGER option_block_insert BEFORE INSERT ON option BEGIN SELECT RAISE(ABORT, 'boom'); END");

        try {
            save(populate_bean('A status.'));
            $this->fail('The content mark write should have thrown.');
        } catch (SQL) {
            R::exec('DROP TRIGGER option_block_insert');
        }

        $this->assertSame(0, R::count('post'));
    }

    public function testAFailedSaveRestoresEveryPropertyItSet(): void
    {
        $this->seedTakenSlug();
        R::exec("CREATE TRIGGER post_block_insert BEFORE INSERT ON post BEGIN SELECT RAISE(ABORT, 'boom'); END");
        $bean = populate_bean('Fresh.');
        $bean->feeditem_uuid = 'uuid-1';
        $before = $bean->export();

        try {
            save($bean, ['lock_if_feed_sourced' => true]);
            $this->fail('The store should have thrown.');
        } catch (SQL) {
            R::exec('DROP TRIGGER post_block_insert');
        }

        // feed_locked is set before the store; it must not outlive the failed save.
        $this->assertSame($before, $bean->export());
    }

    public function testAFailedSlugChangeRedirectRollsTheSaveBack(): void
    {
        $redirect = R::dispense('redirect');
        $redirect->from_slug = '';
        $redirect->to_url = '';
        R::store($redirect);
        R::exec('DELETE FROM redirect');
        R::exec("CREATE TRIGGER redirect_block_insert BEFORE INSERT ON redirect BEGIN SELECT RAISE(ABORT, 'boom'); END");

        $bean = populate_bean("---\nslug: new\n---\nBody.");

        try {
            save($bean, ['redirect_on_slug_change' => true, 'old_slug' => 'old']);
            $this->fail('The redirect write should have thrown.');
        } catch (SQL) {
            R::exec('DROP TRIGGER redirect_block_insert');
        }

        $this->assertSame(0, R::count('post'));
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

    public function testRedirectOnSlugChangeRecordsARedirectFromTheOldSlug(): void
    {
        R::exec('DELETE FROM redirect WHERE 1');
        $bean = populate_bean("---\nslug: old\n---\nContent.");
        R::store($bean);
        $bean->slug = 'new';

        save($bean, ['redirect_on_slug_change' => true, 'old_slug' => 'old']);

        $redirect = R::findOne('redirect', ' from_slug = ? ', ['old']);
        $this->assertNotNull($redirect);
        $this->assertSame('/new', $redirect->to_url);
    }

    public function testRedirectOnSlugChangeRecordsNothingWhenTheSlugIsUnchanged(): void
    {
        R::exec('DELETE FROM redirect WHERE 1');
        $bean = populate_bean("---\nslug: same\n---\nContent.");
        R::store($bean);

        save($bean, ['redirect_on_slug_change' => true, 'old_slug' => 'same']);

        $this->assertCount(0, R::findAll('redirect'));
    }

    public function testRedirectOnSlugChangeRecordsNothingWhenOldSlugIsEmpty(): void
    {
        R::exec('DELETE FROM redirect WHERE 1');
        $bean = populate_bean("---\nslug: new\n---\nContent.");

        save($bean, ['redirect_on_slug_change' => true, 'old_slug' => '']);

        $this->assertCount(0, R::findAll('redirect'));
    }

    public function testWithoutRedirectOnSlugChangeNoRedirectIsRecordedEvenWhenTheSlugChanged(): void
    {
        R::exec('DELETE FROM redirect WHERE 1');
        $bean = populate_bean("---\nslug: old\n---\nContent.");
        R::store($bean);
        $bean->slug = 'new';

        save($bean, ['old_slug' => 'old']);

        $this->assertCount(0, R::findAll('redirect'));
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

    // Migrated from the now-deleted Lamb\notify_post_subscribers(), which fanned
    // publish notifications out to both subsystems directly. That call is gone
    // (#717); the same guarantee — a publish reaches both webmention and
    // websub — now lives in register_default_subscribers() wiring both to
    // post.published. The webmention half is exercised end-to-end above
    // (testDefaultSubscribersQueueAWebmentionOnPublish); websub's own ping
    // behaviour is unit-tested in WebsubTest, so this only has to prove both
    // are actually on the post.published registry.
    public function testRegisterDefaultSubscribersWiresBothWebmentionAndWebsubToPublish(): void
    {
        reset_subscribers();
        register_default_subscribers();

        $this->assertSame(
            ['\Lamb\Webmention\enqueue_for_post', '\Lamb\Websub\ping_for_post'],
            \Lamb\Hooks\callbacks('post.published')
        );
    }

    public function testANotifySaveMarksWebmentionsPendingWithoutQueueingAnything(): void
    {
        // No subscriber is registered: save() only writes the column, it does not call the webmention module.
        $bean = populate_bean('A status.');

        save($bean, ['notify' => true]);

        $this->assertGreaterThan(0, (int) R::load('post', $bean->id)->webmentions_pending);
    }

    public function testEachNotifySaveMarksThePostWithItsOwnToken(): void
    {
        // The subscriber clears the mark only if it still holds the token its own save wrote,
        // so a later save's mark survives an earlier save's slow subscriber.
        $bean = populate_bean('A status.');

        save($bean, ['notify' => true]);
        $first = (int) $bean->webmentions_pending;
        save($bean, ['notify' => true]);
        $second = (int) $bean->webmentions_pending;

        $this->assertGreaterThan(0, $first);
        $this->assertGreaterThan(0, $second);
        $this->assertNotSame($first, $second);
        $this->assertSame($second, (int) R::load('post', $bean->id)->webmentions_pending);
    }

    public function testASaveWithoutNotifyDoesNotMarkWebmentionsPending(): void
    {
        $bean = populate_bean('A status.');

        save($bean, ['finalize_slug' => true]);

        $this->assertSame(0, (int) R::load('post', $bean->id)->webmentions_pending);
    }

    public function testAFailedNotifySaveLeavesTheMarkAsItWas(): void
    {
        // Store the column first: fluid mode would otherwise add it inside the save's
        // transaction, and the rollback would remove it, so there would be no mark to keep.
        $bean = populate_bean('A status.');
        $bean->webmentions_pending = 0;
        save($bean);
        R::exec("CREATE TRIGGER post_block_update BEFORE UPDATE ON post BEGIN SELECT RAISE(ABORT, 'boom'); END");

        try {
            save($bean, ['notify' => true]);
            $this->fail('The store should have thrown.');
        } catch (SQL) {
            // Expected.
        } finally {
            R::exec('DROP TRIGGER post_block_update');
        }

        // The rollback restored the row. The bean has to follow, or a retry would send a token
        // that was never stored.
        $this->assertSame(0, $bean->webmentions_pending);
        $this->assertSame('0', R::getCell('SELECT webmentions_pending FROM post WHERE id = ?', [$bean->id]));
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function postsThatSendNoWebmentions(): array
    {
        return [
            'draft' => [['draft' => 1]],
            'feed-sourced post' => [['feed_name' => 'Some feed']],
        ];
    }

    /**
     * @param array<string, mixed> $fields
     * @dataProvider postsThatSendNoWebmentions
     */
    public function testANotifySaveOfAPostThatSendsNoWebmentionsIsNotMarked(array $fields): void
    {
        // The edit form and Micropub always notify. A mark nobody will ever clear would stay set.
        $bean = populate_bean('A status.');
        foreach ($fields as $name => $value) {
            $bean->$name = $value;
        }

        save($bean, ['notify' => true]);

        $this->assertEmpty(R::load('post', $bean->id)->webmentions_pending);
    }
}

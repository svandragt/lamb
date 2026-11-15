<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;
use RedBeanPHP\RedException\SQL;
use Symfony\Component\Process\Process;

use function Lamb\get_option;
use function Lamb\Response\restore_post;
use function Lamb\Response\soft_delete_post;
use function Lamb\set_option;

/**
 * Trashing and restoring a post stores the post, queues or reconciles its
 * webmention re-sends and moves the content mark. A failure in any later step
 * must undo the earlier ones, or the post is trashed with its receivers never
 * told, and clients get a stale 304.
 */
class PostDeleteRestoreAtomicTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::nuke();
    }

    private function seedPost(bool $deleted = false): \RedBeanPHP\OODBBean
    {
        $post = R::dispense('post');
        $post->body = 'Hello';
        $post->transformed = '<p>Hello</p>';
        $post->created = '2026-01-01 00:00:00';
        $post->updated = '2026-01-01 00:00:00';
        $post->version = 1;
        $post->deleted = $deleted ? 1 : null;
        $post->deleted_at = $deleted ? '2026-01-02 00:00:00' : null;
        R::store($post);

        return $post;
    }

    private function seedOutboxRow(int $post_id, string $status, int $resend = 0): int
    {
        $row = R::dispense('webmentionoutbox');
        $row->post_id = $post_id;
        $row->source = 'http://localhost/status/' . $post_id;
        $row->target = 'https://other.example/a';
        $row->status = $status;
        $row->attempts = 1;
        $row->created = '2026-01-01 00:00:00';
        $row->resend = $resend;

        return (int) R::store($row);
    }

    /** Creates the `option` table, so a trigger can name it and the content mark is a fresh INSERT. */
    private function seedOptionTable(): void
    {
        set_option(get_option('seed', 1), 1);
    }

    private function failNextWrite(string $table, string $event = 'UPDATE'): void
    {
        R::exec("CREATE TRIGGER block_write BEFORE $event ON $table BEGIN SELECT RAISE(ABORT, 'boom'); END");
    }

    private function dropFailure(): void
    {
        R::exec('DROP TRIGGER IF EXISTS block_write');
    }

    public function testADeleteWhoseResendQueueFailsLeavesThePostLive(): void
    {
        $post = $this->seedPost();
        $row_id = $this->seedOutboxRow((int) $post->id, 'sent');
        $this->failNextWrite('webmentionoutbox');

        try {
            soft_delete_post($post);
            $this->fail('The resend queue write should have thrown.');
        } catch (SQL) {
            $this->dropFailure();
        }

        $this->assertEmpty(R::load('post', $post->id)->deleted);
        $this->assertSame('sent', R::load('webmentionoutbox', $row_id)->status);
    }

    public function testADeleteWhoseContentMarkFailsLeavesNoPartialChange(): void
    {
        $post = $this->seedPost();
        $row_id = $this->seedOutboxRow((int) $post->id, 'sent');
        $this->seedOptionTable();
        $this->failNextWrite('option', 'INSERT');

        try {
            soft_delete_post($post);
            $this->fail('The content mark write should have thrown.');
        } catch (SQL) {
            $this->dropFailure();
        }

        $loaded = R::load('post', $post->id);
        $this->assertEmpty($loaded->deleted);
        $this->assertEmpty($loaded->deleted_at);
        $row = R::load('webmentionoutbox', $row_id);
        $this->assertSame('sent', $row->status);
        $this->assertEmpty($row->resend);
    }

    public function testARestoreWhoseResendReconcileFailsLeavesThePostTrashed(): void
    {
        $post = $this->seedPost(true);
        $row_id = $this->seedOutboxRow((int) $post->id, 'pending', 1);
        $this->failNextWrite('webmentionoutbox');

        try {
            restore_post($post);
            $this->fail('The reconcile write should have thrown.');
        } catch (SQL) {
            $this->dropFailure();
        }

        $this->assertSame(1, (int) R::load('post', $post->id)->deleted);
        $this->assertSame('pending', R::load('webmentionoutbox', $row_id)->status);
    }

    public function testARestoreWhoseContentMarkFailsLeavesNoPartialChange(): void
    {
        $post = $this->seedPost(true);
        $row_id = $this->seedOutboxRow((int) $post->id, 'pending', 1);
        $this->seedOptionTable();
        $this->failNextWrite('option', 'INSERT');

        try {
            restore_post($post);
            $this->fail('The content mark write should have thrown.');
        } catch (SQL) {
            $this->dropFailure();
        }

        $loaded = R::load('post', $post->id);
        $this->assertSame(1, (int) $loaded->deleted);
        $this->assertSame('2026-01-02 00:00:00', $loaded->deleted_at);
        $row = R::load('webmentionoutbox', $row_id);
        $this->assertSame('pending', $row->status);
        $this->assertSame(1, (int) $row->resend);
    }

    public function testAFailedDeleteShowsTheAuthorAnError(): void
    {
        $result = $this->submitWithFailingContentMark('redirect_deleted', false);

        $this->assertNotEmpty(array_filter($result['flash'], fn ($m) => str_contains($m, 'Failed to delete')));
        $this->assertSame(0, $result['deleted']);
    }

    public function testAFailedRestoreShowsTheAuthorAnError(): void
    {
        $result = $this->submitWithFailingContentMark('redirect_restored', true);

        $this->assertNotEmpty(array_filter($result['flash'], fn ($m) => str_contains($m, 'Failed to restore')));
        $this->assertSame(1, $result['deleted']);
    }

    public function testAFailedDeleteReturnsToThePostsOwnPageNotTheHomePage(): void
    {
        // The post is still live, so the page the author pressed delete on still exists.
        $result = $this->submitWithFailingContentMark('redirect_deleted', false, 'http://localhost/status/1');

        $this->assertSame('/status/1', $result['location']);
    }

    public function testAFailedDeleteReturnsToTheListingItWasPressedOn(): void
    {
        $result = $this->submitWithFailingContentMark('redirect_deleted', false, 'http://localhost/tag/php?page=2');

        $this->assertSame('/tag/php?page=2', $result['location']);
    }

    public function testAFailedDeleteRestoresTheBeansFields(): void
    {
        $post = $this->seedPost();
        $this->seedOptionTable();
        $this->failNextWrite('option', 'INSERT');

        try {
            soft_delete_post($post);
            $this->fail('The content mark write should have thrown.');
        } catch (SQL) {
            $this->dropFailure();
        }

        // A caller that reuses the bean must not see a deleted post that is live in the database.
        $this->assertEmpty($post->deleted);
        $this->assertEmpty($post->deleted_at);
    }

    public function testAFailedRestoreRestoresTheBeansFields(): void
    {
        $post = $this->seedPost(true);
        $this->seedOptionTable();
        $this->failNextWrite('option', 'INSERT');

        try {
            restore_post($post);
            $this->fail('The content mark write should have thrown.');
        } catch (SQL) {
            $this->dropFailure();
        }

        $this->assertSame(1, (int) $post->deleted);
        $this->assertSame('2026-01-02 00:00:00', $post->deleted_at);
    }

    /**
     * Posts the delete or restore form for one post while the content mark write fails.
     *
     * The handlers end in redirect_uri(), which die()s, so they run in a
     * subprocess that reports the outcome from a shutdown function.
     *
     * @param string $handler Which Lamb\Response handler to call.
     * @param bool   $deleted Whether the post starts in the trash.
     * @param string $referer The request Referer, or '' for none.
     * @return array{flash: list<string>, deleted: int, location: string} The Location the handler redirected to.
     * @throws \JsonException When the subprocess prints something that is not JSON.
     */
    private function submitWithFailingContentMark(string $handler, bool $deleted, string $referer = ''): array
    {
        // The child defines Lamb\Response\header() before it starts: PHP resolves the
        // unqualified header() call in redirect_uri() to the namespaced function first, so the
        // redirect is captured without Xdebug (the CLI SAPI keeps no headers for headers_list()).
        $script = <<<'PHP'
        namespace Lamb\Response {
            function header(string $header, bool $replace = true, int $response_code = 0): void
            {
                $GLOBALS['sent'][] = $header;
            }
        }

        namespace {
            use RedBeanPHP\R;

            require $argv[1];
            define('ROOT_URL', 'http://localhost');
            R::setup('sqlite::memory:');
            \Lamb\Bootstrap\ensure_schema();

            $post = R::dispense('post');
            $post->body = 'Hello';
            $post->created = '2026-01-01 00:00:00';
            $post->updated = '2026-01-01 00:00:00';
            $post->deleted = $argv[3] === '1' ? 1 : null;
            $id = R::store($post);
            R::exec("CREATE TRIGGER block_write BEFORE INSERT ON option BEGIN SELECT RAISE(ABORT, 'boom'); END");

            $_SERVER['REQUEST_METHOD'] = 'POST';
            $_SESSION = [SESSION_LOGIN => true, HIDDEN_CSRF_NAME => 'tok'];
            $_POST = [HIDDEN_CSRF_NAME => 'tok'];
            if ($argv[4] !== '') {
                $_SERVER['HTTP_REFERER'] = $argv[4];
            }

            register_shutdown_function(function () use ($id) {
                $location = preg_grep('/^Location:/', $GLOBALS['sent'] ?? []);
                echo json_encode([
                    'flash' => $_SESSION['flash'] ?? [],
                    'deleted' => (int) R::load('post', $id)->deleted,
                    'location' => substr((string) (array_values($location)[0] ?? ''), strlen('Location: ')),
                ]);
            });
            ('\Lamb\Response\\' . $argv[2])([(string) $id]);
        }
        PHP;

        $process = new Process([
            'php', '-r', $script,
            dirname(__DIR__, 2) . '/vendor/autoload.php',
            $handler,
            $deleted ? '1' : '0',
            $referer,
        ]);
        $process->run();

        /** @var array{flash: list<string>, deleted: int, location: string} */
        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }
}

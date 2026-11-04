<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;
use SimplePie\Item as SimplePieItem;
use Symfony\Component\Process\Process;

use function Lamb\Post\populate_bean;

/**
 * Editing a feed draft on /edit must not publish it (#924). The edit form posts
 * the stored body back, so the draft survives only when the body's front matter
 * carries `draft: true`.
 */
class FeedDraftEditTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);

        global $config;
        $config = ['feeds_draft' => true];
    }

    /**
     * The body ingestion stores for a new feed item when feeds_draft is on, and
     * the text the edit form shows for it.
     */
    private function ingestedDraftBody(): string
    {
        $item = $this->createMock(SimplePieItem::class);
        $item->method('get_date')->willReturn('2024-01-01 00:00:00');
        $item->method('get_updated_date')->willReturn('2024-01-01 00:00:00');
        $item->method('get_id')->willReturn('edit-draft-1');

        $body = "---\ntitle: Hello feed\n---\n\nSee [elsewhere](https://other.example/a).";

        return populate_bean($body, $item, 'TestBlog')->body;
    }

    public function testEditingAFeedDraftWithoutTouchingItsFrontMatterKeepsItADraft(): void
    {
        $result = $this->submitEdit($this->ingestedDraftBody(), static fn(string $body): string => $body . "\n\nTidied.");

        $this->assertSame(1, $result['draft']);
        $this->assertStringContainsString('draft: true', $result['body']);
        $this->assertSame(0, $result['outbox']);
    }

    public function testRemovingDraftTrueFromAFeedDraftPublishesIt(): void
    {
        $result = $this->submitEdit(
            $this->ingestedDraftBody(),
            static fn(string $body): string => str_replace("draft: true\n", '', $body)
        );

        $this->assertSame(0, $result['draft']);
    }

    /**
     * Stores a feed draft with the given body, posts an edit through
     * redirect_edited() and reports what was stored.
     *
     * A saved edit ends in redirect_uri(), which die()s, so the handler runs in
     * a subprocess that reports the outcome from a shutdown function. The stored
     * body goes in unchanged and the edit callback runs here, so the posted text
     * is exactly what the edit form would hold.
     *
     * @param string                  $stored The body the feed draft is stored with.
     * @param callable(string):string $edit   Turns the stored body into the posted text.
     * @return array{draft: int, body: string, outbox: int}
     * @throws \JsonException When the subprocess prints something that is not JSON.
     */
    private function submitEdit(string $stored, callable $edit): array
    {
        $script = <<<'PHP'
        use RedBeanPHP\R;

        require $argv[1];
        define('ROOT_URL', 'http://localhost');
        R::setup('sqlite::memory:');
        \Lamb\Bootstrap\ensure_schema();
        \Lamb\Post\register_default_subscribers();

        $post = R::dispense('post');
        $post->body = $argv[2];
        $post->slug = 'hello-feed';
        $post->draft = 1;
        $post->feed_name = 'TestBlog';
        $post->feeditem_uuid = md5('TestBlog' . 'edit-draft-1');
        $post->version = POST_VERSION;
        $post->created = '2024-01-01 00:00:00';
        $post->updated = '2024-01-01 00:00:00';
        $id = R::store($post);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SESSION = [SESSION_LOGIN => true, HIDDEN_CSRF_NAME => 'tok'];
        $_POST = [
            HIDDEN_CSRF_NAME => 'tok',
            'submit' => SUBMIT_EDIT,
            'id' => (string) $id,
            'contents' => $argv[3],
        ];

        register_shutdown_function(function () use ($id) {
            $saved = R::load('post', $id);
            echo json_encode([
                'draft' => (int) $saved->draft,
                'body' => $saved->body,
                'outbox' => (int) R::count('webmentionoutbox'),
            ]);
        });
        \Lamb\Response\redirect_edited();
        PHP;

        $process = new Process([
            'php', '-r', $script,
            dirname(__DIR__, 2) . '/vendor/autoload.php',
            $stored,
            $edit($stored),
        ]);
        $process->run();

        /** @var array{draft: int, body: string, outbox: int} */
        return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
    }
}

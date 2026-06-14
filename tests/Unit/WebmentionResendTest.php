<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Webmention\cancel_deletion_resends;
use function Lamb\Webmention\enqueue_deletion_resends;
use function Lamb\Webmention\process_outbound;

class WebmentionResendTest extends TestCase
{
    private int $postId;

    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::nuke();

        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'https://example.com');
        }

        $post = R::dispense('post');
        $post->body = 'Hello';
        $post->transformed = '<p>Hello</p>';
        $post->created = '2026-01-01 00:00:00';
        $post->updated = '2026-01-01 00:00:00';
        $post->version = 1;
        $this->postId = (int) R::store($post);
    }

    private function seedRow(string $status, string $target = 'https://other.example/a'): int
    {
        $row = R::dispense('webmentionoutbox');
        $row->post_id = $this->postId;
        $row->source = ROOT_URL . '/status/' . $this->postId;
        $row->target = $target;
        $row->endpoint = 'https://other.example/wm';
        $row->status = $status;
        $row->attempts = $status === 'sent' ? 1 : 0;
        $row->created = '2026-01-01 00:00:00';
        return (int) R::store($row);
    }

    private function deletePost(): void
    {
        $post = R::load('post', $this->postId);
        $post->deleted = 1;
        R::store($post);
    }

    /** @return array{0: callable, 1: callable} */
    private function unreachableNetwork(): array
    {
        return [
            function (string $url): ?array {
                $this->fail('fetcher must not be called');
            },
            function (string $e, string $s, string $t): int {
                $this->fail('sender must not be called');
            },
        ];
    }

    public function testEnqueueResendsFlipsSentRowsToPendingWithMarker(): void
    {
        $id = $this->seedRow('sent');

        $count = enqueue_deletion_resends($this->postId);

        $this->assertSame(1, $count);
        $row = R::load('webmentionoutbox', $id);
        $this->assertSame('pending', $row->status);
        $this->assertEquals(1, $row->resend);
    }

    public function testEnqueueResendsIgnoresNeverSentPendingRows(): void
    {
        $id = $this->seedRow('pending');

        $count = enqueue_deletion_resends($this->postId);

        $this->assertSame(0, $count);
        $row = R::load('webmentionoutbox', $id);
        $this->assertSame('pending', $row->status);
        $this->assertEmpty($row->resend);
    }

    public function testProcessSendsResendEvenThoughSourceIsDeleted(): void
    {
        $this->seedRow('sent');
        enqueue_deletion_resends($this->postId);
        $this->deletePost();

        $fetcher = fn (string $url) => ['headers' => ['<https://other.example/wm>; rel="webmention"'], 'body' => ''];
        $sender = fn (string $e, string $s, string $t): int => 202;

        $result = process_outbound($fetcher, $sender);

        $this->assertSame(1, $result['sent'], 'a deletion re-send must be sent, not cancelled by the deleted-source guard');
        $this->assertSame('sent', R::findOne('webmentionoutbox')->status);
    }

    public function testProcessStillCancelsNormalPendingRowForDeletedPost(): void
    {
        // Regression for #329: a never-sent pending row for a deleted post is
        // still cancelled (only resend-marked rows survive the guard).
        $this->seedRow('pending');
        $this->deletePost();

        [$fetcher, $sender] = $this->unreachableNetwork();
        $result = process_outbound($fetcher, $sender);

        $this->assertSame(1, $result['cancelled']);
        $this->assertSame('cancelled', R::findOne('webmentionoutbox')->status);
    }

    public function testCancelResendsRevertsPendingResendsToSent(): void
    {
        $id = $this->seedRow('sent');
        enqueue_deletion_resends($this->postId);

        $count = cancel_deletion_resends($this->postId);

        $this->assertSame(1, $count);
        $row = R::load('webmentionoutbox', $id);
        $this->assertSame('sent', $row->status);
        $this->assertEmpty($row->resend);
    }

    public function testProcessAbandonsResendWhenPostIsAliveAgain(): void
    {
        // The resend was queued, but the post is no longer deleted (restored
        // before cron drained the queue). No deletion notification must go out.
        $this->seedRow('sent');
        enqueue_deletion_resends($this->postId);
        // Post left not-deleted.

        [$fetcher, $sender] = $this->unreachableNetwork();
        $result = process_outbound($fetcher, $sender);

        $row = R::findOne('webmentionoutbox');
        $this->assertSame('sent', $row->status);
        $this->assertEmpty($row->resend);
        $this->assertSame(1, $result['cancelled']);
    }
}

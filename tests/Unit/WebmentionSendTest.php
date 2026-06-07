<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Webmention\discover_endpoint;
use function Lamb\Webmention\enqueue_for_post;
use function Lamb\Webmention\enqueue_outbound;
use function Lamb\Webmention\extract_outbound_links;
use function Lamb\Webmention\process_outbound;

class WebmentionSendTest extends TestCase
{
    private int $postId;

    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);

        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'https://example.com');
        }

        R::exec('DELETE FROM webmentionoutbox WHERE 1');

        $post = R::dispense('post');
        $post->body = 'Hello';
        $post->transformed = '<p>Hello</p>';
        $post->created = '2026-01-01 00:00:00';
        $post->updated = '2026-01-01 00:00:00';
        $post->version = 1;
        $this->postId = (int) R::store($post);
    }

    // extract_outbound_links ------------------------------------------------

    public function testExtractReturnsOnlyExternalLinks(): void
    {
        $html = '<p><a href="https://other.example/a">x</a> '
            . '<a href="' . ROOT_URL . '/status/1">self</a> '
            . '<a href="/relative">rel</a> '
            . '<a href="mailto:a@b.c">mail</a></p>';

        $links = extract_outbound_links($html);
        $this->assertSame(['https://other.example/a'], $links);
    }

    public function testExtractDeduplicates(): void
    {
        $html = '<a href="https://other.example/a">1</a><a href="https://other.example/a">2</a>';
        $this->assertSame(['https://other.example/a'], extract_outbound_links($html));
    }

    // discover_endpoint -----------------------------------------------------

    public function testDiscoverFromLinkHeader(): void
    {
        $endpoint = discover_endpoint('', ['<https://other.example/wm>; rel="webmention"'], 'https://other.example/post');
        $this->assertSame('https://other.example/wm', $endpoint);
    }

    public function testDiscoverFromRelativeLinkHeader(): void
    {
        $endpoint = discover_endpoint('', ['</wm>; rel="webmention"'], 'https://other.example/post');
        $this->assertSame('https://other.example/wm', $endpoint);
    }

    public function testDiscoverFromHtmlLinkTag(): void
    {
        $html = '<html><head><link rel="webmention" href="/wm"></head></html>';
        $this->assertSame('https://other.example/wm', discover_endpoint($html, [], 'https://other.example/post'));
    }

    public function testDiscoverFromHtmlAnchor(): void
    {
        $html = '<a href="https://other.example/wm" rel="webmention">wm</a>';
        $this->assertSame('https://other.example/wm', discover_endpoint($html, [], 'https://other.example/post'));
    }

    public function testDiscoverHeaderTakesPrecedenceOverHtml(): void
    {
        $html = '<link rel="webmention" href="/html-wm">';
        $endpoint = discover_endpoint($html, ['<https://other.example/header-wm>; rel="webmention"'], 'https://other.example/post');
        $this->assertSame('https://other.example/header-wm', $endpoint);
    }

    public function testDiscoverReturnsNullWhenAbsent(): void
    {
        $this->assertNull(discover_endpoint('<p>no endpoint here</p>', [], 'https://other.example/post'));
    }

    // enqueue_outbound ------------------------------------------------------

    public function testEnqueueCreatesPendingRowsForExternalLinks(): void
    {
        $source = ROOT_URL . '/status/1';
        $html = '<a href="https://other.example/a">a</a><a href="https://third.example/b">b</a>';

        $count = enqueue_outbound($this->postId, $source, $html);
        $this->assertSame(2, $count);
        $this->assertSame(2, R::count('webmentionoutbox', ' status = ? ', ['pending']));
    }

    public function testEnqueueDeduplicatesAcrossEdits(): void
    {
        $source = ROOT_URL . '/status/1';
        $html = '<a href="https://other.example/a">a</a>';

        enqueue_outbound($this->postId, $source, $html);
        enqueue_outbound($this->postId, $source, $html);
        $this->assertSame(1, R::count('webmentionoutbox'));
    }

    public function testEnqueueRetriesFailedRow(): void
    {
        $source = ROOT_URL . '/status/1';
        $html = '<a href="https://other.example/a">a</a>';
        enqueue_outbound($this->postId, $source, $html);

        $row = R::findOne('webmentionoutbox');
        $row->status = 'failed';
        R::store($row);

        enqueue_outbound($this->postId, $source, $html);
        $row = R::findOne('webmentionoutbox');
        $this->assertSame('pending', $row->status);
    }

    public function testEnqueueDoesNotResendAlreadySent(): void
    {
        $source = ROOT_URL . '/status/1';
        $html = '<a href="https://other.example/a">a</a>';
        enqueue_outbound($this->postId, $source, $html);

        $row = R::findOne('webmentionoutbox');
        $row->status = 'sent';
        R::store($row);

        enqueue_outbound($this->postId, $source, $html);
        $row = R::findOne('webmentionoutbox');
        $this->assertSame('sent', $row->status);
    }

    // enqueue_for_post (reply targets) --------------------------------------

    private function dispenseReply(string $transformed, string $replyTo): \RedBeanPHP\OODBBean
    {
        $post = R::dispense('post');
        $post->body = 'A reply';
        $post->transformed = $transformed;
        $post->in_reply_to = $replyTo;
        $post->created = '2026-01-01 00:00:00';
        $post->updated = '2026-01-01 00:00:00';
        $post->version = 1;
        R::store($post);

        return $post;
    }

    public function testEnqueueForPostQueuesExternalReplyTarget(): void
    {
        $target = 'https://other.example/their-post';
        $post = $this->dispenseReply('<p>A reply with no links</p>', $target);

        enqueue_for_post($post);

        $row = R::findOne('webmentionoutbox', ' target = ? ', [$target]);
        $this->assertNotNull($row);
        $this->assertSame('pending', $row->status);
        $this->assertSame(ROOT_URL . '/status/' . $post->id, $row->source);
    }

    public function testEnqueueForPostSkipsSameSiteReplyTarget(): void
    {
        $post = $this->dispenseReply('<p>No links</p>', ROOT_URL . '/status/1');

        enqueue_for_post($post);

        $this->assertSame(0, R::count('webmentionoutbox'));
    }

    public function testEnqueueForPostDeduplicatesReplyTargetAlsoInBody(): void
    {
        $target = 'https://other.example/their-post';
        $post = $this->dispenseReply('<p><a href="' . $target . '">link</a></p>', $target);

        enqueue_for_post($post);

        $this->assertSame(1, R::count('webmentionoutbox', ' target = ? ', [$target]));
    }

    public function testEnqueueForPostQueuesScheduledPost(): void
    {
        $post = R::dispense('post');
        $post->body = 'Scheduled';
        $post->transformed = '<p><a href="https://other.example/a">a</a></p>';
        $post->created = date('Y-m-d H:i:s', time() + 3600);
        $post->updated = date('Y-m-d H:i:s');
        $post->version = 1;
        R::store($post);

        enqueue_for_post($post);

        $this->assertSame(1, R::count('webmentionoutbox', ' status = ? ', ['pending']));
    }

    public function testEnqueueForPostSkipsFeedItems(): void
    {
        $post = R::dispense('post');
        $post->body = 'Ingested';
        $post->transformed = '<p><a href="https://other.example/a">a</a></p>';
        $post->feed_name = 'somefeed';
        $post->created = '2026-01-01 00:00:00';
        $post->updated = '2026-01-01 00:00:00';
        $post->version = 1;
        R::store($post);

        enqueue_for_post($post);

        $this->assertSame(0, R::count('webmentionoutbox'));
    }

    // enqueue_outbound (stale rows) ------------------------------------------

    public function testEnqueueCancelsStalePendingRowsForRemovedLinks(): void
    {
        $source = ROOT_URL . '/status/1';
        enqueue_outbound($this->postId, $source, '<a href="https://other.example/a">a</a><a href="https://third.example/b">b</a>');

        enqueue_outbound($this->postId, $source, '<a href="https://third.example/b">b</a>');

        $removed = R::findOne('webmentionoutbox', ' target = ? ', ['https://other.example/a']);
        $kept = R::findOne('webmentionoutbox', ' target = ? ', ['https://third.example/b']);
        $this->assertSame('cancelled', $removed->status);
        $this->assertSame('pending', $kept->status);
    }

    public function testEnqueueLeavesSentRowsForRemovedLinks(): void
    {
        $source = ROOT_URL . '/status/1';
        enqueue_outbound($this->postId, $source, '<a href="https://other.example/a">a</a>');
        $row = R::findOne('webmentionoutbox');
        $row->status = 'sent';
        R::store($row);

        enqueue_outbound($this->postId, $source, '<p>no links</p>');

        $this->assertSame('sent', R::findOne('webmentionoutbox')->status);
    }

    public function testEnqueueRequeuesCancelledRowWhenLinkRestored(): void
    {
        $source = ROOT_URL . '/status/1';
        enqueue_outbound($this->postId, $source, '<a href="https://other.example/a">a</a>');
        $row = R::findOne('webmentionoutbox');
        $row->status = 'cancelled';
        R::store($row);

        enqueue_outbound($this->postId, $source, '<a href="https://other.example/a">a</a>');

        $this->assertSame('pending', R::findOne('webmentionoutbox')->status);
    }

    // process_outbound ------------------------------------------------------

    private function seedPending(string $target): void
    {
        enqueue_outbound($this->postId, ROOT_URL . '/status/1', '<a href="' . $target . '">x</a>');
    }

    /**
     * A fetcher/sender pair that fails the test if either is invoked.
     *
     * @return array{0: callable, 1: callable}
     */
    private function unreachableNetwork(): array
    {
        $fetcher = function (string $url): ?array {
            $this->fail('fetcher must not be called');
        };
        $sender = function (string $e, string $s, string $t): int {
            $this->fail('sender must not be called');
        };

        return [$fetcher, $sender];
    }

    public function testProcessMarksSentOnSuccess(): void
    {
        $target = 'https://other.example/a';
        $this->seedPending($target);

        $fetcher = fn (string $url) => ['headers' => ['<https://other.example/wm>; rel="webmention"'], 'body' => ''];
        $sender = function (string $endpoint, string $source, string $tgt): int {
            return 202;
        };

        $result = process_outbound($fetcher, $sender);
        $this->assertSame(1, $result['sent']);
        $this->assertSame('sent', R::findOne('webmentionoutbox')->status);
    }

    public function testProcessMarksSkippedWhenNoEndpoint(): void
    {
        $this->seedPending('https://other.example/a');

        $fetcher = fn (string $url) => ['headers' => [], 'body' => '<p>no endpoint</p>'];
        $sender = fn (string $e, string $s, string $t): int => 200;

        $result = process_outbound($fetcher, $sender);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame('skipped', R::findOne('webmentionoutbox')->status);
    }

    public function testProcessSkipsNonHttpDiscoveredEndpoint(): void
    {
        $this->seedPending('https://other.example/a');

        $fetcher = fn (string $url) => ['headers' => ['<file:///etc/passwd>; rel="webmention"'], 'body' => ''];
        $sender = function (string $e, string $s, string $t): int {
            $this->fail('sender must not be called for a non-http endpoint');
        };

        $result = process_outbound($fetcher, $sender);
        $this->assertSame(1, $result['skipped']);
        $this->assertSame('skipped', R::findOne('webmentionoutbox')->status);
    }

    public function testProcessMarksFailedOnBadStatus(): void
    {
        $this->seedPending('https://other.example/a');

        $fetcher = fn (string $url) => ['headers' => ['<https://other.example/wm>; rel="webmention"'], 'body' => ''];
        $sender = fn (string $e, string $s, string $t): int => 400;

        $result = process_outbound($fetcher, $sender);
        $this->assertSame(1, $result['failed']);
        $this->assertSame('failed', R::findOne('webmentionoutbox')->status);
    }

    // process_outbound (source-post guard) -----------------------------------

    public function testProcessLeavesRowsPendingWhileSourcePostIsScheduled(): void
    {
        $post = R::load('post', $this->postId);
        $post->created = date('Y-m-d H:i:s', time() + 3600);
        R::store($post);
        $this->seedPending('https://other.example/a');

        [$fetcher, $sender] = $this->unreachableNetwork();
        process_outbound($fetcher, $sender);

        $row = R::findOne('webmentionoutbox');
        $this->assertSame('pending', $row->status);
        $this->assertSame(0, (int) $row->attempts);
    }

    public function testProcessCancelsRowsForDeletedSourcePost(): void
    {
        $this->seedPending('https://other.example/a');
        $post = R::load('post', $this->postId);
        $post->deleted = 1;
        R::store($post);

        [$fetcher, $sender] = $this->unreachableNetwork();
        $result = process_outbound($fetcher, $sender);

        $this->assertSame(1, $result['cancelled']);
        $this->assertSame('cancelled', R::findOne('webmentionoutbox')->status);
    }

    public function testProcessCancelsRowsForDraftSourcePost(): void
    {
        $this->seedPending('https://other.example/a');
        $post = R::load('post', $this->postId);
        $post->draft = 1;
        R::store($post);

        [$fetcher, $sender] = $this->unreachableNetwork();
        $result = process_outbound($fetcher, $sender);

        $this->assertSame(1, $result['cancelled']);
        $this->assertSame('cancelled', R::findOne('webmentionoutbox')->status);
    }

    public function testProcessCancelsRowsForMissingSourcePost(): void
    {
        $this->seedPending('https://other.example/a');
        R::trash(R::load('post', $this->postId));

        [$fetcher, $sender] = $this->unreachableNetwork();
        $result = process_outbound($fetcher, $sender);

        $this->assertSame(1, $result['cancelled']);
        $this->assertSame('cancelled', R::findOne('webmentionoutbox')->status);
    }

    public function testProcessSendsOnceScheduledPostBecomesPublic(): void
    {
        $post = R::load('post', $this->postId);
        $post->created = date('Y-m-d H:i:s', time() - 60);
        R::store($post);
        $this->seedPending('https://other.example/a');

        $fetcher = fn (string $url) => ['headers' => ['<https://other.example/wm>; rel="webmention"'], 'body' => ''];
        $sender = fn (string $e, string $s, string $t): int => 202;

        $result = process_outbound($fetcher, $sender);
        $this->assertSame(1, $result['sent']);
        $this->assertSame('sent', R::findOne('webmentionoutbox')->status);
    }
}

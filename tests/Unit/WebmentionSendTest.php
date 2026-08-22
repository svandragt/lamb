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

    public function testEnqueueForPostQueuesMultipleReplyTargets(): void
    {
        $post = $this->dispenseReply(
            '<p>A reply with no links</p>',
            'https://other.example/their-post https://third.example/their-post'
        );

        enqueue_for_post($post);

        $this->assertNotNull(R::findOne('webmentionoutbox', ' target = ? ', ['https://other.example/their-post']));
        $this->assertNotNull(R::findOne('webmentionoutbox', ' target = ? ', ['https://third.example/their-post']));
        $this->assertSame(2, R::count('webmentionoutbox'));
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

    public function testEnqueueOutboundQueuesEachSpaceSeparatedReplyTarget(): void
    {
        // #583: `in_reply_to` may hold several space-separated targets, the
        // same convention `syndicated_to` uses; each becomes its own row.
        $source = ROOT_URL . '/status/1';
        $replyTo = 'https://other.example/a https://third.example/b';

        $count = enqueue_outbound($this->postId, $source, '<p>No links</p>', $replyTo);

        $this->assertSame(2, $count);
        $this->assertNotNull(R::findOne('webmentionoutbox', ' target = ? ', ['https://other.example/a']));
        $this->assertNotNull(R::findOne('webmentionoutbox', ' target = ? ', ['https://third.example/b']));
    }

    public function testEnqueueOutboundSkipsSameSiteTargetAmongMultiple(): void
    {
        $source = ROOT_URL . '/status/1';
        $replyTo = 'https://other.example/a ' . ROOT_URL . '/status/1';

        $count = enqueue_outbound($this->postId, $source, '<p>No links</p>', $replyTo);

        $this->assertSame(1, $count);
        $this->assertSame(0, R::count('webmentionoutbox', ' target = ? ', [ROOT_URL . '/status/1']));
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
     * A fake DNS resolver so tests using example.com/example.org/etc.
     * hostnames (RFC 2606, not actually resolvable) pass the SSRF
     * public-host check without a real network lookup.
     *
     * @return callable(string): string[]
     */
    private function publicResolver(): callable
    {
        return fn (string $host): array => ['93.184.216.34'];
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

        $result = process_outbound($fetcher, $sender, 20, $this->publicResolver());
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

        $result = process_outbound($fetcher, $sender, 20, $this->publicResolver());
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

    // process_outbound (deferred rows must not starve the queue) -------------

    /**
     * A post whose `created` is in the future, so its outbox rows defer.
     */
    private function schedulePost(): int
    {
        $post = R::dispense('post');
        $post->body = 'Later';
        $post->transformed = '<p>Later</p>';
        $post->created = date('Y-m-d H:i:s', time() + 3600);
        $post->updated = date('Y-m-d H:i:s');
        $post->version = 1;

        return (int) R::store($post);
    }

    /**
     * Queues $count rows for one post in a single enqueue_outbound() call.
     *
     * One call, not $count of them: enqueue_outbound() cancels pending rows for
     * the same source whose target is absent from the HTML it is given, so
     * calling it once per link would leave only the last row pending. A single
     * call is also how a real post with several links enqueues — which is why
     * every row it writes carries the same `created` second.
     *
     * @return string The source URL the rows share.
     */
    private function seedPendingBatch(int $postId, string $sourcePath, string $hostPrefix, int $count): string
    {
        $html = '';
        for ($i = 0; $i < $count; $i++) {
            $html .= '<a href="https://' . $hostPrefix . $i . '.example/a">x</a>';
        }
        $source = ROOT_URL . $sourcePath;
        $this->assertSame($count, enqueue_outbound($postId, $source, $html));

        return $source;
    }

    public function testProcessDoesNotLetDeferredRowsConsumeTheSendWindow(): void
    {
        // A deferred row stays pending, so it stays at the front of the queue.
        // Once $limit scheduled posts' links were queued they filled the whole
        // window on every run and nothing was ever sent again — silently, since
        // a deferred row increments no counter and webmention_line() prints
        // nothing when all four are zero.
        $this->seedPendingBatch($this->schedulePost(), '/status/2', 's', 20);
        $this->seedPending('https://live.example/a');

        $fetcher = fn (string $url) => ['headers' => ['<https://live.example/wm>; rel="webmention"'], 'body' => ''];
        $sender = fn (string $e, string $s, string $t): int => 202;

        $result = process_outbound($fetcher, $sender, 20, $this->publicResolver());

        $this->assertSame(1, $result['sent']);
        $this->assertSame(
            'sent',
            R::findOne('webmentionoutbox', ' target = ? ', ['https://live.example/a'])->status
        );
        $this->assertCount(20, R::find('webmentionoutbox', ' status = ? ', ['pending']));
    }

    public function testProcessStillCapsSendsAtTheLimit(): void
    {
        // The window bounds sends, and that has to stay true: paging past
        // deferred rows must not turn $limit into "everything deliverable".
        $this->seedPendingBatch($this->postId, '/status/1', 't', 25);

        $fetcher = fn (string $url) => ['headers' => ['<https://t.example/wm>; rel="webmention"'], 'body' => ''];
        $sender = fn (string $e, string $s, string $t): int => 202;

        $result = process_outbound($fetcher, $sender, 20, $this->publicResolver());

        $this->assertSame(20, $result['sent']);
        $this->assertCount(5, R::find('webmentionoutbox', ' status = ? ', ['pending']));
    }

    public function testProcessSendsEachRowOnceWhenCreatedTimestampsTie(): void
    {
        // Every row from one enqueue_outbound() call shares a `created` second,
        // so `created` alone is not a total order — paging over a non-total
        // order could send a row twice or skip one. The query breaks the tie
        // on `id`.
        $this->seedPendingBatch($this->schedulePost(), '/status/2', 's', 5);
        $this->seedPendingBatch($this->postId, '/status/1', 'l', 5);

        $sent = [];
        $fetcher = fn (string $url) => ['headers' => ['<https://l.example/wm>; rel="webmention"'], 'body' => ''];
        $sender = function (string $e, string $s, string $target) use (&$sent): int {
            $sent[] = $target;
            return 202;
        };

        $result = process_outbound($fetcher, $sender, 20, $this->publicResolver());

        $this->assertSame(5, $result['sent']);
        $this->assertSame($sent, array_values(array_unique($sent)));
        $this->assertCount(5, R::find('webmentionoutbox', ' status = ? ', ['pending']));
    }

    public function testProcessTerminatesWhenEveryPendingRowIsDeferred(): void
    {
        // The paging loop must not spin when there is nothing it can deliver.
        $this->seedPendingBatch($this->schedulePost(), '/status/2', 's', 25);

        [$fetcher, $sender] = $this->unreachableNetwork();
        $result = process_outbound($fetcher, $sender, 20, $this->publicResolver());

        $this->assertSame(['sent' => 0, 'failed' => 0, 'skipped' => 0, 'cancelled' => 0], $result);
        $this->assertCount(25, R::find('webmentionoutbox', ' status = ? ', ['pending']));
    }

    public function testProcessSendsOnceScheduledPostBecomesPublic(): void
    {
        $post = R::load('post', $this->postId);
        $post->created = date('Y-m-d H:i:s', time() - 60);
        R::store($post);
        $this->seedPending('https://other.example/a');

        $fetcher = fn (string $url) => ['headers' => ['<https://other.example/wm>; rel="webmention"'], 'body' => ''];
        $sender = fn (string $e, string $s, string $t): int => 202;

        $result = process_outbound($fetcher, $sender, 20, $this->publicResolver());
        $this->assertSame(1, $result['sent']);
        $this->assertSame('sent', R::findOne('webmentionoutbox')->status);
    }
}

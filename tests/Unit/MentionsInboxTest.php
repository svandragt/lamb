<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Bootstrap\ensure_schema;
use function Lamb\Response\count_webmentions;
use function Lamb\Response\respond_mentions;

use const Lamb\Webmention\WEBMENTION_DISPLAY_LIMIT;

/**
 * The /mentions list and the toolbar count read the same rows: received
 * webmentions, not the outbound queue.
 */
class MentionsInboxTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::nuke();
        ensure_schema();
        $_SESSION[SESSION_LOGIN] = true;
    }

    protected function tearDown(): void
    {
        unset($_SESSION[SESSION_LOGIN]);
    }

    private function post(?int $deleted = null): int
    {
        $post = R::dispense('post');
        $post->body = 'A post';
        $post->slug = '';
        $post->created = '2026-01-01 09:00:00';
        $post->draft = 0;
        $post->deleted = $deleted;
        return (int) R::store($post);
    }

    private function mention(int $postId, string $created, ?string $verifiedAt = null): void
    {
        $row = R::dispense('webmention');
        $row->source = 'https://source.example/' . $created;
        $row->target = 'https://example.test/status/' . $postId;
        $row->post_id = $postId;
        $row->type = 'mention';
        $row->status = 'pending';
        $row->created = $created;
        $row->verified_at = $verifiedAt ?? $created;
        R::store($row);
    }

    public function testCountIgnoresTheOutboundQueue(): void
    {
        $postId = $this->post();
        $out = R::dispense('webmentionoutbox');
        $out->post_id = $postId;
        $out->source = 'https://example.test/status/' . $postId;
        $out->target = 'https://other.example/';
        $out->status = 'pending';
        R::store($out);
        $this->assertSame(0, count_webmentions());

        $this->mention($postId, '2026-01-01 10:00:00');
        $this->assertSame(1, count_webmentions());
    }

    public function testListIsNewestFirstAndBounded(): void
    {
        $postId = $this->post();
        for ($i = 0; $i < WEBMENTION_DISPLAY_LIMIT + 5; $i++) {
            $this->mention($postId, sprintf('2026-01-01 10:%02d:%02d', intdiv($i, 60), $i % 60));
        }

        $mentions = respond_mentions()['mentions'];

        $this->assertCount(WEBMENTION_DISPLAY_LIMIT, $mentions);
        $this->assertGreaterThan($mentions[1]['mention']->created, $mentions[0]['mention']->created);
    }

    public function testListIsOrderedByTheVerifiedTimeItShows(): void
    {
        $postId = $this->post();
        $this->mention($postId, '2026-01-01 10:00:00', '2026-01-03 10:00:00');
        $this->mention($postId, '2026-01-02 10:00:00', '2026-01-02 10:00:00');

        $mentions = respond_mentions()['mentions'];

        $this->assertSame('2026-01-03 10:00:00', $mentions[0]['mention']->verified_at);
        $this->assertSame('2026-01-02 10:00:00', $mentions[1]['mention']->verified_at);
    }

    public function testMentionOfATrashedPostIsNeitherCountedNorListed(): void
    {
        $this->mention($this->post(1), '2026-01-01 10:00:00');

        $this->assertSame(0, count_webmentions());
        $this->assertSame([], respond_mentions()['mentions']);
    }

    public function testMentionOfAPurgedPostIsNeitherCountedNorListed(): void
    {
        $this->mention(999, '2026-01-01 10:00:00');

        $this->assertSame(0, count_webmentions());
        $this->assertSame([], respond_mentions()['mentions']);
    }

    public function testEveryListedMentionCarriesItsPostWhenOthersAreMissing(): void
    {
        $postId = $this->post();
        $this->mention($postId, '2026-01-01 10:00:00');
        $this->mention(999, '2026-01-02 10:00:00');

        $mentions = respond_mentions()['mentions'];

        $this->assertCount(1, $mentions);
        $this->assertSame($postId, (int) $mentions[0]['post']->id);
        $this->assertSame(1, count_webmentions());
    }
}

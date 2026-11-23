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

    private function mention(int $postId, string $created): void
    {
        $row = R::dispense('webmention');
        $row->source = 'https://source.example/' . $created;
        $row->target = 'https://example.test/status/' . $postId;
        $row->post_id = $postId;
        $row->type = 'mention';
        $row->status = 'pending';
        $row->created = $created;
        $row->verified_at = $created;
        R::store($row);
    }

    public function testCountIgnoresTheOutboundQueue(): void
    {
        $out = R::dispense('webmentionoutbox');
        $out->post_id = 1;
        $out->source = 'https://example.test/status/1';
        $out->target = 'https://other.example/';
        $out->status = 'pending';
        R::store($out);
        $this->assertSame(0, count_webmentions());

        $this->mention(1, '2026-01-01 10:00:00');
        $this->assertSame(1, count_webmentions());
    }

    public function testListIsNewestFirstAndBounded(): void
    {
        for ($i = 0; $i < WEBMENTION_DISPLAY_LIMIT + 5; $i++) {
            $this->mention(1, sprintf('2026-01-01 10:%02d:%02d', intdiv($i, 60), $i % 60));
        }

        $mentions = respond_mentions()['mentions'];

        $this->assertCount(WEBMENTION_DISPLAY_LIMIT, $mentions);
        $this->assertGreaterThan($mentions[1]['mention']->created, $mentions[0]['mention']->created);
    }

    public function testMentionOfAMissingPostHasNoPost(): void
    {
        $this->mention(999, '2026-01-01 10:00:00');
        $this->assertNull(respond_mentions()['mentions'][0]['post']);
    }
}

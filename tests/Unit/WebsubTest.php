<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Websub\ping_for_post;
use function Lamb\Websub\ping_hub;

class WebsubTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);

        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'https://example.com');
        }
    }

    // ping_hub ---------------------------------------------------------------

    public function testPingHubPostsBothFeedUrlsToConfiguredHub(): void
    {
        $pings = [];
        $sender = function (string $hub, string $topic) use (&$pings): void {
            $pings[] = [$hub, $topic];
        };

        ping_hub(['websub_hubs' => 'https://hub.example.com/'], $sender);

        $this->assertSame([
            ['https://hub.example.com/', ROOT_URL . '/feed'],
            ['https://hub.example.com/', ROOT_URL . '/feed.json'],
        ], $pings);
    }

    public function testPingHubPingsEveryConfiguredHub(): void
    {
        $pings = [];
        $sender = function (string $hub, string $topic) use (&$pings): void {
            $pings[] = [$hub, $topic];
        };

        ping_hub(['websub_hubs' => 'https://hub-a.example.com/, https://hub-b.example.com/'], $sender);

        $this->assertSame([
            ['https://hub-a.example.com/', ROOT_URL . '/feed'],
            ['https://hub-a.example.com/', ROOT_URL . '/feed.json'],
            ['https://hub-b.example.com/', ROOT_URL . '/feed'],
            ['https://hub-b.example.com/', ROOT_URL . '/feed.json'],
        ], $pings);
    }

    public function testHubUrlsParsesCommaSeparatedValues(): void
    {
        $this->assertSame([], \Lamb\Websub\hub_urls([]));
        $this->assertSame([], \Lamb\Websub\hub_urls(['websub_hubs' => ' , ']));
        $this->assertSame(
            ['https://hub.example.com/'],
            \Lamb\Websub\hub_urls(['websub_hubs' => ' https://hub.example.com/ '])
        );
        $this->assertSame(
            ['https://hub-a.example.com/', 'https://hub-b.example.com/'],
            \Lamb\Websub\hub_urls(['websub_hubs' => 'https://hub-a.example.com/, https://hub-b.example.com/,'])
        );
    }

    public function testPingHubIsNoOpWhenHubNotConfigured(): void
    {
        $called = false;
        $sender = function () use (&$called): void {
            $called = true;
        };

        ping_hub([], $sender);
        ping_hub(['websub_hubs' => '  '], $sender);
        $this->assertFalse($called, 'Sender must not be invoked without a configured hub');
    }

    // ping_for_post ----------------------------------------------------------

    public function testPingForPostPingsForEligiblePost(): void
    {
        $bean = $this->storedPost();

        $pings = 0;
        ping_for_post($bean, ['websub_hubs' => 'https://hub.example.com/'], function () use (&$pings): void {
            $pings++;
        });

        $this->assertSame(2, $pings, 'A published local post should ping the hub for both feeds');
    }

    public function testPingForPostSkipsDraftsFeedItemsAndScheduledPosts(): void
    {
        $draft = $this->storedPost();
        $draft->draft = 1;

        $feedItem = $this->storedPost();
        $feedItem->feed_name = 'some-feed';

        $scheduled = $this->storedPost();
        $scheduled->created = date('Y-m-d H:i:s', time() + 3600);

        $unsaved = R::dispense('post');

        $called = false;
        $sender = function () use (&$called): void {
            $called = true;
        };

        foreach ([$draft, $feedItem, $scheduled, $unsaved] as $bean) {
            ping_for_post($bean, ['websub_hubs' => 'https://hub.example.com/'], $sender);
        }

        $this->assertFalse($called, 'Drafts, feed items, scheduled and unsaved posts must not ping the hub');
    }

    /**
     * ping_for_post() must ask is_draft() rather than re-deriving "is this a
     * draft" with its own truthiness check. A `draft` column value that is
     * truthy but not canonically `1` (never written by any real save path
     * today, but not guarded against either) is not a draft per is_draft()'s
     * own definition, so the hub must still be pinged.
     */
    public function testPingForPostPingsWhenDraftColumnIsNonCanonicalTruthyValue(): void
    {
        $bean = $this->storedPost();
        $bean->draft = 2;

        $pings = 0;
        ping_for_post($bean, ['websub_hubs' => 'https://hub.example.com/'], function () use (&$pings): void {
            $pings++;
        });

        $this->assertSame(2, $pings, 'A non-canonical truthy draft value must not be treated as a draft');
    }

    private function storedPost(): \RedBeanPHP\OODBBean
    {
        $bean = R::dispense('post');
        $bean->body = 'Hello';
        $bean->transformed = '<p>Hello</p>';
        $bean->created = '2026-01-01 00:00:00';
        $bean->updated = '2026-01-01 00:00:00';
        $bean->version = 1;
        R::store($bean);
        return $bean;
    }
}

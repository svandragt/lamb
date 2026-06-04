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
        $sender = function (string $hub, string $topic) use (&$pings): int {
            $pings[] = [$hub, $topic];
            return 204;
        };

        $results = ping_hub(['websub_hub' => 'https://hub.example.com/'], $sender);

        $this->assertSame([
            ['https://hub.example.com/', ROOT_URL . '/feed'],
            ['https://hub.example.com/', ROOT_URL . '/feed.json'],
        ], $pings);
        $this->assertSame([
            ROOT_URL . '/feed'      => 204,
            ROOT_URL . '/feed.json' => 204,
        ], $results);
    }

    public function testPingHubIsNoOpWhenHubNotConfigured(): void
    {
        $called = false;
        $sender = function () use (&$called): int {
            $called = true;
            return 204;
        };

        $this->assertSame([], ping_hub([], $sender));
        $this->assertSame([], ping_hub(['websub_hub' => '  '], $sender));
        $this->assertFalse($called, 'Sender must not be invoked without a configured hub');
    }

    // ping_for_post ----------------------------------------------------------

    public function testPingForPostPingsForEligiblePost(): void
    {
        $bean = $this->storedPost();

        $pings = 0;
        ping_for_post($bean, ['websub_hub' => 'https://hub.example.com/'], function () use (&$pings): int {
            $pings++;
            return 204;
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
        $sender = function () use (&$called): int {
            $called = true;
            return 204;
        };

        foreach ([$draft, $feedItem, $scheduled, $unsaved] as $bean) {
            ping_for_post($bean, ['websub_hub' => 'https://hub.example.com/'], $sender);
        }

        $this->assertFalse($called, 'Drafts, feed items, scheduled and unsaved posts must not ping the hub');
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

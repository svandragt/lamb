<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\get_option;
use function Lamb\set_option;
use function Lamb\Websub\ping_scheduled_publishes;

class WebsubScheduledTest extends TestCase
{
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
    }

    /** @var list<array{string,string}> */
    private array $pings = [];

    private function sender(): callable
    {
        $this->pings = [];
        return function (string $hub, string $topic): void {
            $this->pings[] = [$hub, $topic];
        };
    }

    private function config(): array
    {
        return ['websub_hubs' => 'https://hub.example.com/'];
    }

    private function seedWatermark(string $datetime): void
    {
        // Persist the option so the run is not treated as the first run.
        set_option(get_option('websub_last_scheduled_publish', 0), strtotime($datetime));
    }

    private function post(array $fields): void
    {
        $bean = R::dispense('post');
        $bean->body = 'x';
        $bean->draft = 0;
        $bean->deleted = 0;
        $bean->feed_name = '';
        foreach ($fields as $k => $v) {
            $bean->$k = $v;
        }
        R::store($bean);
    }

    public function testPingsHubWhenScheduledPostCrossesIntoPublic(): void
    {
        $this->seedWatermark('2020-01-01 00:00:00');
        // created > updated marks a scheduled post; created is now in the past.
        $this->post(['created' => '2020-06-01 00:00:00', 'updated' => '2020-05-01 00:00:00']);

        $count = ping_scheduled_publishes($this->config(), $this->sender());

        $this->assertSame(1, $count);
        $this->assertNotEmpty($this->pings, 'the hub should be pinged');
    }

    public function testDoesNotPingNormalPost(): void
    {
        $this->seedWatermark('2020-01-01 00:00:00');
        // Normal post: created == updated (not scheduled) — already pinged at save.
        $this->post(['created' => '2020-06-01 00:00:00', 'updated' => '2020-06-01 00:00:00']);

        $count = ping_scheduled_publishes($this->config(), $this->sender());

        $this->assertSame(0, $count);
        $this->assertEmpty($this->pings);
    }

    public function testDoesNotPingStillFutureScheduledPost(): void
    {
        $this->seedWatermark('2020-01-01 00:00:00');
        $this->post(['created' => '2099-01-01 00:00:00', 'updated' => '2026-01-01 00:00:00']);

        $count = ping_scheduled_publishes($this->config(), $this->sender());

        $this->assertSame(0, $count);
        $this->assertEmpty($this->pings);
    }

    public function testDoesNotPingDraftScheduledPost(): void
    {
        $this->seedWatermark('2020-01-01 00:00:00');
        $this->post(['created' => '2020-06-01 00:00:00', 'updated' => '2020-05-01 00:00:00', 'draft' => 1]);

        $this->assertSame(0, ping_scheduled_publishes($this->config(), $this->sender()));
    }

    public function testDoesNotPingFeedItem(): void
    {
        $this->seedWatermark('2020-01-01 00:00:00');
        $this->post([
            'created' => '2020-06-01 00:00:00',
            'updated' => '2020-05-01 00:00:00',
            'feed_name' => 'somefeed',
        ]);

        $this->assertSame(0, ping_scheduled_publishes($this->config(), $this->sender()));
    }

    public function testDoesNotRepingAfterWatermarkAdvances(): void
    {
        $this->seedWatermark('2020-01-01 00:00:00');
        $this->post(['created' => '2020-06-01 00:00:00', 'updated' => '2020-05-01 00:00:00']);

        $this->assertSame(1, ping_scheduled_publishes($this->config(), $this->sender()));
        // The watermark has advanced past the post's created date.
        $this->assertSame(0, ping_scheduled_publishes($this->config(), $this->sender()));
    }

    public function testFirstRunSeedsWatermarkWithoutPinging(): void
    {
        // No watermark option seeded — this is the first run on the install.
        $this->post(['created' => '2020-06-01 00:00:00', 'updated' => '2020-05-01 00:00:00']);

        $count = ping_scheduled_publishes($this->config(), $this->sender());

        $this->assertSame(0, $count, 'first run watches from now rather than sweeping history');
        $this->assertEmpty($this->pings);
    }
}

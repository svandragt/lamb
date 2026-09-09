<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Network\feed_status_bean;
use function Lamb\Network\run_feed_cycle;

/**
 * process_feeds() cannot be unit-tested directly — it calls header(), takes a
 * file lock and ends in exit()/die() — so the orchestration it delegates to
 * run_feed_cycle() (a failing feed must not abort the run, a good feed
 * alongside it still ingests, both drains always run, the watermark still
 * advances) is exercised here instead, with every collaborator injected so
 * nothing touches the network.
 */
class RunFeedCycleTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::nuke();
    }

    /** @return callable(): int */
    private function noopWebsubDrain(): callable
    {
        return fn(): int => 0;
    }

    /** @return callable(): array{sent: int, failed: int, skipped: int, cancelled: int} */
    private function noopWebmentionDrain(): callable
    {
        return fn(): array => ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'cancelled' => 0];
    }

    /** @return callable(string): void */
    private function collectingOutput(array &$lines): callable
    {
        return function (string $line) use (&$lines): void {
            $lines[] = $line;
        };
    }

    private function due(string $name, string $url, int $lastAttempt = 0): void
    {
        $status = feed_status_bean($name, $url);
        $status->last_attempt = $lastAttempt;
        R::store($status);
    }

    public function testAFailingFeedProducesAFailedLine(): void
    {
        $this->due('bad', 'https://example.com/bad');
        $lines = [];

        run_feed_cycle(
            feeds: ['bad' => 'https://example.com/bad'],
            crawler: fn(string $name, string $url): array => ['ok' => false, 'items' => 0, 'error' => 'boom'],
            websub_drain: $this->noopWebsubDrain(),
            webmention_drain: $this->noopWebmentionDrain(),
            output: $this->collectingOutput($lines)
        );

        $this->assertStringContainsString('FAILED: bad - boom', implode('', $lines));
    }

    public function testAGoodFeedAlongsideAFailingOneStillIngests(): void
    {
        $this->due('bad', 'https://example.com/bad');
        $this->due('good', 'https://example.com/good');
        $lines = [];

        run_feed_cycle(
            feeds: ['bad' => 'https://example.com/bad', 'good' => 'https://example.com/good'],
            crawler: fn(string $name, string $url): array => $name === 'bad'
                ? ['ok' => false, 'items' => 0, 'error' => 'boom']
                : ['ok' => true, 'items' => 3, 'error' => null],
            websub_drain: $this->noopWebsubDrain(),
            webmention_drain: $this->noopWebmentionDrain(),
            output: $this->collectingOutput($lines)
        );

        $report = implode('', $lines);
        $this->assertStringContainsString('FAILED: bad - boom', $report);
        $this->assertStringContainsString('OK: good - 3 item(s) ingested', $report);
    }

    public function testTheRunReachesTheDrainsEvenWhenAFeedFails(): void
    {
        $this->due('bad', 'https://example.com/bad');
        $drained = false;
        $lines = [];

        run_feed_cycle(
            feeds: ['bad' => 'https://example.com/bad'],
            crawler: fn(string $name, string $url): array => ['ok' => false, 'items' => 0, 'error' => 'boom'],
            websub_drain: function () use (&$drained): int {
                $drained = true;
                return 0;
            },
            webmention_drain: $this->noopWebmentionDrain(),
            output: $this->collectingOutput($lines)
        );

        $this->assertTrue($drained);
    }

    public function testTheWatermarkSetterReceivesTheAdvancedTimestamp(): void
    {
        $now = time();
        $seen = null;
        $lines = [];

        run_feed_cycle(
            feeds: [],
            websub_drain: $this->noopWebsubDrain(),
            webmention_drain: $this->noopWebmentionDrain(),
            advance_watermark: function (int $timestamp) use (&$seen): void {
                $seen = $timestamp;
            },
            output: $this->collectingOutput($lines),
            clock: fn(): int => $now
        );

        $this->assertSame($now, $seen);
    }

    public function testBothDrainsRunEvenWhenTheCrawlLoopThrows(): void
    {
        $this->due('exploding', 'https://example.com/exploding');
        $websubRan = false;
        $webmentionRan = false;
        $lines = [];

        try {
            run_feed_cycle(
                feeds: ['exploding' => 'https://example.com/exploding'],
                crawler: function (): array {
                    throw new \RuntimeException('unexpected crawl failure');
                },
                websub_drain: function () use (&$websubRan): int {
                    $websubRan = true;
                    return 0;
                },
                webmention_drain: function () use (&$webmentionRan): array {
                    $webmentionRan = true;
                    return ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'cancelled' => 0];
                },
                output: $this->collectingOutput($lines)
            );
            $this->fail('Expected the unguarded crawler throw to propagate.');
        } catch (\RuntimeException $e) {
            $this->assertSame('unexpected crawl failure', $e->getMessage());
        }

        $this->assertTrue($websubRan);
        $this->assertTrue($webmentionRan);
    }

    public function testAFeedNotYetDueIsSkippedWithoutCallingTheCrawler(): void
    {
        $this->due('recent', 'https://example.com/recent', time());
        $called = false;
        $lines = [];

        run_feed_cycle(
            feeds: ['recent' => 'https://example.com/recent'],
            crawler: function () use (&$called): array {
                $called = true;
                return ['ok' => true, 'items' => 1, 'error' => null];
            },
            websub_drain: $this->noopWebsubDrain(),
            webmention_drain: $this->noopWebmentionDrain(),
            output: $this->collectingOutput($lines)
        );

        $this->assertFalse($called);
    }
}

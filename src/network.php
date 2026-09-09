<?php

namespace Lamb\Network;

use JetBrains\PhpStorm\NoReturn;
use RedBeanPHP\R;

use function Lamb\get_option;
use function Lamb\set_option;

// MINUTE_IN_SECONDS is defined in constants.php
//
// Feed ingestion is split across src/network/ (all in this namespace):
//   - sources.php   feed config, SimplePie setup, RSS/Atom crawl recording
//   - json_feed.php JSON Feed (jsonfeed.org) detection, parsing and adapter
//   - ingest.php    turning a feed item into a post (dedup, slug, citation)
//   - status.php    the per-feed `feedstatus` health bean (read by the Logs tab)
//
// The `/_cron` route is registered centrally in Lamb\Route\register_app_routes().

/**
 * Whether a whole /_cron run may proceed.
 *
 * /_cron is unauthenticated and hit by an external scheduler, so runs are capped
 * at one a minute. The watermark is only written once a run completes.
 *
 * @param int $last_run Unix timestamp of the last completed run (0 = never).
 * @param int $now      Current Unix timestamp.
 * @return bool
 */
function cron_run_due(int $last_run, int $now): bool
{
    return ($now - $last_run) >= MINUTE_IN_SECONDS;
}

/**
 * Whether an individual feed is due another fetch.
 *
 * Gated on the last *attempt*, not the last success, so a failing feed is retried
 * on schedule rather than locked out — and a healthy one is not re-fetched inside
 * the 30-minute window.
 *
 * @param int $last_attempt Unix timestamp of the last fetch attempt (0 = never).
 * @param int $now          Current Unix timestamp.
 * @return bool
 */
function feed_fetch_due(int $last_attempt, int $now): bool
{
    return ($now - $last_attempt) >= FEED_FETCH_INTERVAL;
}

/**
 * A maintenance report line, or '' when the count is zero.
 *
 * The cron output stays quiet about work that did not happen, so every
 * maintenance step formats its line the same way.
 *
 * @param int    $count    How many rows the step touched.
 * @param string $template A sprintf template taking the count (e.g. 'Purged %d post(s).').
 * @return string The line including its newline, or ''.
 */
function count_line(int $count, string $template): string
{
    return $count > 0 ? sprintf($template, $count) . PHP_EOL : '';
}

/**
 * The per-feed outcome line for the cron output.
 *
 * @param string $name   Feed name from config.
 * @param array{ok: bool, items: int, error: ?string} $result As returned by crawl_feed().
 * @return string The line including its newline.
 */
function crawl_line(string $name, array $result): string
{
    return $result['ok']
        ? sprintf('OK: %s - %d item(s) ingested' . PHP_EOL, $name, $result['items'])
        : sprintf('FAILED: %s - %s' . PHP_EOL, $name, $result['error']);
}

/**
 * The outbound-webmention summary line, or '' when the queue was idle.
 *
 * @param array{sent: int, failed: int, skipped: int, cancelled: int} $sent As returned by process_outbound().
 * @return string The line including its newline, or ''.
 */
function webmention_line(array $sent): string
{
    if (!$sent['sent'] && !$sent['failed'] && !$sent['skipped'] && !$sent['cancelled']) {
        return '';
    }

    return sprintf(
        'Webmentions sent: %d, failed: %d, skipped: %d, cancelled: %d' . PHP_EOL,
        $sent['sent'],
        $sent['failed'],
        $sent['skipped'],
        $sent['cancelled']
    );
}

/**
 * Crawls every due feed, then drains the notification queues and advances the
 * run watermark.
 *
 * Extracted from process_feeds() so the run's orchestration is unit-testable —
 * process_feeds() itself is not, because it sends a header, takes a file lock
 * and ends in exit()/die(). Every collaborator is injectable so a test drives
 * the loop and drains without touching the network; real /_cron requests get
 * the production collaborators from the defaults, and the output is byte-for-byte
 * what process_feeds() emitted inline before.
 *
 * The watermark advance and both drains run in a `finally`, so a feed that fails
 * — or the loop throwing outright — can never starve webmention/WebSub delivery.
 * The watermark is advanced before draining so a partial run still rate-limits
 * the next one even if a drain throws; the drains then retry on the next run.
 *
 * @param array<string, string>|null $feeds Feed name => URL map (default get_feeds()).
 * @param callable(string, string): array{ok: bool, items: int, error: ?string}|null $crawler
 *        Per-feed crawler (default crawl_feed_guarded(), which never throws).
 * @param callable(): int|null $websub_drain WebSub drain returning the ping count
 *        (default ping_scheduled_publishes()).
 * @param callable(): array{sent: int, failed: int, skipped: int, cancelled: int}|null $webmention_drain
 *        Outbound-webmention drain (default process_outbound()).
 * @param callable(int): void|null $advance_watermark Persists the run timestamp
 *        (default writes the last_processed_date option).
 * @param callable(string): void|null $output Output sink (default echo).
 * @param callable(): int|null $clock Current Unix timestamp (default time()).
 * @return void
 */
function run_feed_cycle(
    ?array $feeds = null,
    ?callable $crawler = null,
    ?callable $websub_drain = null,
    ?callable $webmention_drain = null,
    ?callable $advance_watermark = null,
    ?callable $output = null,
    ?callable $clock = null
): void {
    $feeds ??= get_feeds();
    $crawler ??= static fn(string $name, string $url): array => crawl_feed_guarded($name, $url);
    $websub_drain ??= static fn(): int => \Lamb\Websub\ping_scheduled_publishes();
    $webmention_drain ??= static fn(): array => \Lamb\Webmention\process_outbound();
    $output ??= static function (string $line): void {
        echo $line;
    };
    $clock ??= static fn(): int => time();
    $advance_watermark ??= static function (int $timestamp): void {
        set_option(get_option('last_processed_date', 0), $timestamp);
    };

    try {
        $output("Updating feeds..." . PHP_EOL);
        foreach ($feeds as $name => $url) {
            flush();
            $status = feed_status_bean($name, $url);
            if (!feed_fetch_due((int)$status->last_attempt, $clock())) {
                $output('Skipped ' . $url . PHP_EOL);
                continue;
            }

            $output(crawl_line($name, $crawler($name, $url)));
        }
    } finally {
        $advance_watermark($clock());

        $output(count_line(
            $websub_drain(),
            'WebSub: pinged hub for %d scheduled post(s) now published.'
        ));
        $output(webmention_line($webmention_drain()));
    }
}

#[NoReturn] function process_feeds(): void
{
    header('Content-Type: text/plain');

    // A single feed fetch may take up to FEED_FETCH_TIMEOUT, so a handful of slow
    // feeds can outlast a web request's PHP limit (typically 30s under FPM). A
    // timeout mid-crawl skips the notification drains and the watermark write
    // below, and because the watermark is unwritten the next run walks the same
    // feeds and dies the same way — webmentions then never deliver. Raise the
    // limit so a normal run (each fetch bounded to FEED_FETCH_TIMEOUT) finishes
    // well within it. See network/README.md ("Finishing the run").
    //
    // A finite cap, not 0: the run holds the cron flock until the process ends,
    // so an unbounded run that wedged would leave every later /_cron stuck on
    // "Already running". 30 minutes is far above any legitimate run yet still
    // frees the lock if one hangs. The cap only bites a CPU-bound wedge, though —
    // on Unix max_execution_time excludes time blocked in a syscall, so a hung
    // socket is still caught only by the per-fetch curl/SimplePie timeouts, which
    // must stay in place.
    set_time_limit(1800);

    // Serialise overlapping runs before anything else: /_cron is unauthenticated
    // and the rate-limit watermark is only written after all work finishes, so a
    // concurrent burst without this lock would run in parallel on the same stale
    // watermark. See network/README.md ("The run") for what that duplicates.
    $lock = acquire_cron_lock();
    if ($lock === false) {
        http_response_code(500);
        die('Cannot open the cron lock at ' . cron_lock_path() . ' — is the data directory writable?');
    }
    if ($lock === null) {
        die('Already running, try again later.');
    }

    $cron_last_updated = get_option('last_processed_date', 0);
    if (!cron_run_due((int)$cron_last_updated->value, time())) {
        die('Too often, try again later.');
    }

    echo count_line(purge_deleted_posts(), 'Purged %d deleted post(s).');
    echo count_line(prune_feed_status(), 'Pruned %d stale feed status row(s).');
    echo count_line(\Lamb\flatten_redirects(), 'Flattened %d redirect(s).');

    // Reuse the option bean already fetched for the rate-limit check rather than
    // re-reading it, and keep date('U') as the run timestamp (== time()).
    run_feed_cycle(
        advance_watermark: static function (int $timestamp) use ($cron_last_updated): void {
            set_option($cron_last_updated, $timestamp);
        },
    );

    exit('Done');
}

/**
 * The path of the lock file serializing /_cron runs. Lives in the install's
 * data directory, wherever that is — see Bootstrap\data_dir().
 */
function cron_lock_path(): string
{
    return \Lamb\Bootstrap\data_dir() . '/cron.lock';
}

/**
 * Acquires an exclusive, non-blocking lock serialising /_cron runs.
 *
 * The handle must stay referenced until the request ends: the lock releases
 * when it closes, so no explicit unlock is needed as long as every
 * process_feeds() exit path terminates via die()/exit().
 *
 * The two failure modes are kept apart deliberately — a lock file that cannot
 * be *opened* is a broken install (false), not another run in progress (null);
 * collapsing them once silently stopped every cron run. See network/README.md
 * ("The run").
 *
 * @param string|null $path Lock file path; defaults to cron_lock_path().
 * @return resource|false|null The open lock-file handle; null when another run
 *                             holds the lock; false when the lock file cannot
 *                             be opened.
 */
function acquire_cron_lock(?string $path = null)
{
    $handle = @fopen($path ?? cron_lock_path(), 'c');
    if ($handle === false) {
        return false;
    }
    if (!flock($handle, LOCK_EX | LOCK_NB)) {
        fclose($handle);
        return null;
    }

    return $handle;
}

/**
 * Fetches and ingests one configured feed, dispatching by source type: JSON Feed
 * URLs (`.json`) go through the JSON parser, everything else through SimplePie.
 * Returns the crawl outcome for the cron summary line.
 *
 * @param string $name Feed name from config.
 * @param string $url  Feed URL from config.
 * @return array{ok: bool, items: int, error: ?string}
 */
function crawl_feed(string $name, string $url): array
{
    // JSON Feed sources are not XML, so SimplePie cannot parse them. Route
    // .json URLs through a small JSON parser instead; RSS/Atom is unchanged.
    if (is_json_feed_url($url)) {
        echo PHP_EOL . "Processing " . $url . PHP_EOL;
        return record_json_feed_crawl($name, $url);
    }

    $feed = init_simplepie_feed($url);
    echo PHP_EOL . "Processing " . $feed->get_title() . PHP_EOL;
    return record_feed_crawl($name, $url, $feed);
}

/**
 * Crawls one feed, turning any throw into the crawl error shape.
 *
 * crawl_feed() only catches SQL around individual stores, so a throw from the
 * JSON or SimplePie path would abort the whole /_cron run and starve the
 * notification drains — the same outage a mid-crawl timeout causes. Guarding
 * here lets one bad feed report a failure and the run continue to the next feed
 * and the drains.
 *
 * @param string $name Feed name from config.
 * @param string $url  Feed URL from config.
 * @param (callable(string, string): array{ok: bool, items: int, error: ?string})|null $crawler
 *        The crawler to run; defaults to crawl_feed(). Injectable for tests.
 * @return array{ok: bool, items: int, error: ?string}
 */
function crawl_feed_guarded(string $name, string $url, ?callable $crawler = null): array
{
    $crawler ??= crawl_feed(...);
    try {
        return $crawler($name, $url);
    } catch (\Throwable $e) {
        // Stamp the attempt so a feed that throws before begin_crawl() runs backs
        // off on the per-feed gate instead of being re-attempted every run. The
        // Atom path fetches inside init_simplepie_feed() *before* record_feed_crawl()
        // stamps last_attempt, so a SimplePie init() throw would otherwise leave
        // the feed permanently due (#705). begin_crawl() is idempotent, so a throw
        // after it already ran just re-stamps.
        begin_crawl($name, $url);
        return ['ok' => false, 'items' => 0, 'error' => $e->getMessage()];
    }
}

/** @noinspection PhpUnused */
/**
 * Hard-delete posts that were soft-deleted more than 30 days ago.
 *
 * @return int Number of posts permanently deleted.
 */
function purge_deleted_posts(): int
{
    $cutoff = date('Y-m-d H:i:s', strtotime('-30 days'));
    // Backfill any rows trashed before deleted_at tracking existed.
    R::exec('UPDATE post SET deleted_at = ? WHERE deleted = 1 AND deleted_at IS NULL', [date('Y-m-d H:i:s')]);
    $posts  = R::find('post', ' deleted = 1 AND deleted_at < ? ', [$cutoff]);
    foreach ($posts as $post) {
        R::trash($post);
    }
    return count($posts);
}

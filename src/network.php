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

#[NoReturn] function process_feeds(): void
{
    header('Content-Type: text/plain');

    // /_cron is unauthenticated and meant to be hit by an external cron job
    // (see docs/cron-scheduled-tasks.md), but nothing stops anyone from
    // flooding it with concurrent requests. The rate-limit watermark below is
    // only written after all work below completes, so without a lock, every
    // request in such a burst reads the same stale watermark, passes the
    // "too often" check, and proceeds in parallel — multiplying outbound
    // feed/webmention HTTP calls and risking duplicate feed-item ingestion
    // (no unique constraint on `feeditem_uuid`) and duplicate outbound
    // webmention sends (no atomic claim on a queued row). Acquiring a
    // non-blocking exclusive lock first serializes overlapping runs instead.
    $lock = acquire_cron_lock();
    if ($lock === null) {
        die('Already running, try again later.');
    }

    $feeds = get_feeds();

    $cron_last_updated = get_option('last_processed_date', 0);
    if (!cron_run_due((int)$cron_last_updated->value, time())) {
        die('Too often, try again later.');
    }

    echo count_line(purge_deleted_posts(), 'Purged %d deleted post(s).');
    echo count_line(prune_feed_status(), 'Pruned %d stale feed status row(s).');
    echo count_line(\Lamb\flatten_redirects(), 'Flattened %d redirect(s).');

    echo("Updating feeds..." . PHP_EOL);
    foreach ($feeds as $name => $url) {
        flush();
        $status = feed_status_bean($name, $url);
        if (!feed_fetch_due((int)$status->last_attempt, time())) {
            echo('Skipped ' . $url . PHP_EOL);
            continue;
        }

        echo crawl_line($name, crawl_feed($name, $url));
    }

    echo count_line(
        \Lamb\Websub\ping_scheduled_publishes(),
        'WebSub: pinged hub for %d scheduled post(s) now published.'
    );
    echo webmention_line(\Lamb\Webmention\process_outbound());

    set_option($cron_last_updated, (int)date('U'));
    exit('Done');
}

/**
 * Acquires an exclusive, non-blocking lock serializing /_cron runs.
 *
 * The returned handle must be kept referenced for the remainder of the
 * request: the lock releases automatically once it (and the underlying file
 * descriptor) is closed or the request ends, so an explicit unlock isn't
 * needed as long as the caller never returns normally before then (every
 * process_feeds() exit path terminates the request via die()/exit()).
 *
 * @param string $path Lock file path; created if absent.
 * @return resource|null The open lock-file handle, or null when the lock is
 *                        already held (another run is in progress) or the
 *                        lock file itself couldn't be opened.
 */
function acquire_cron_lock(string $path = '../data/cron.lock')
{
    $handle = @fopen($path, 'c');
    if ($handle === false) {
        return null;
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

<?php

namespace Lamb\Network;

use RedBeanPHP\OODBBean;
use RedBeanPHP\R;

/**
 * Returns (creating if needed) the per-feed status bean keyed by md5(name . url) —
 * the same key the legacy `last_processed_date_*` option used.
 *
 * The bean records crawl *health*; config remains the source of truth for which
 * feeds exist. A freshly dispensed bean seeds its success watermark from any legacy
 * `last_processed_date_<key>` option so existing installs do not re-ingest (and
 * duplicate) every item on the first run after upgrade.
 *
 * `last_item_date` is the ingestion watermark (see ingest_item()); `last_success`
 * is only crawl health. They are separate because the first answers "how new is
 * the newest entry we have seen?" and the second "when did we last reach the
 * host?" — conflating them is what silently dropped items.
 *
 * @param string $name Feed name from config.
 * @param string $url  Feed URL from config.
 * @return OODBBean    Existing or freshly dispensed (unsaved) feedstatus bean.
 */
function feed_status_bean(string $name, string $url): OODBBean
{
    $key  = md5($name . $url);
    $bean = R::findOneOrDispense('feedstatus', ' feedkey = ? ', [$key]);
    $bean->feedkey = $key;
    if ((int)$bean->id === 0) {
        $bean->name         = $name;
        $bean->url          = $url;
        $legacy             = R::findOne('option', ' name = ? ', ['last_processed_date_' . $key]);
        $bean->last_success = $legacy ? (int)$legacy->value : 0;
        $bean->last_item_date = 0;
        $bean->last_attempt = 0;
        $bean->last_error   = 0;
        $bean->item_count   = 0;
        $bean->error_message = '';
    }

    return $bean;
}

/**
 * Opens a crawl: loads the feed's status bean and stamps the attempt timestamp.
 *
 * Every source type records an *attempt* before it fetches, so a feed that keeps
 * failing is still rate-limited by /_cron's 30-minute per-feed window (that window
 * is gated on `last_attempt`, not `last_success`).
 *
 * The stamp is *persisted* here rather than left for
 * record_crawl_success()/record_crawl_failure(), because those only run when the
 * fetch returns. A fetch that never returns — the worker OOMs on a hostile feed
 * body, hits max_execution_time, or fatals in the parser — left `last_attempt`
 * untouched on disk, so the feed was still due on the next run and /_cron died at
 * the same feed every time. Everything after it in process_feeds() is downstream
 * of that loop: the remaining feeds, the WebSub pings for newly published
 * scheduled posts, and the entire outbound webmention queue never ran again.
 * Writing the attempt before the fetch costs one row write per crawl and turns
 * that into one lost run per 30-minute window.
 *
 * The bean is returned so record_crawl_success()/record_crawl_failure() can store
 * the outcome on it; they overwrite this row rather than insert a second one.
 *
 * @param string $name Feed name from config.
 * @param string $url  Feed URL from config.
 * @return array{0: OODBBean, 1: int} The status bean and the attempt timestamp.
 */
function begin_crawl(string $name, string $url): array
{
    $status = feed_status_bean($name, $url);
    $now    = (int)date('U');
    $status->last_attempt = $now;
    R::store($status);

    return [$status, $now];
}

/**
 * Records a failed crawl: stamps the error without advancing the success watermark.
 *
 * Leaving `last_success` alone is what keeps a failed fetch from swallowing items —
 * the next successful crawl still ingests everything newer than the last *success*.
 *
 * @param OODBBean $status  The status bean from begin_crawl().
 * @param int      $now     The attempt timestamp from begin_crawl().
 * @param string   $message The error to surface on the Logs tab.
 * @return array{ok: bool, items: int, error: ?string}
 */
function record_crawl_failure(OODBBean $status, int $now, string $message): array
{
    $status->last_error    = $now;
    $status->error_message = $message;
    R::store($status);

    return ['ok' => false, 'items' => 0, 'error' => $message];
}

/**
 * Records a successful crawl: stamps crawl health, counts items, clears any error,
 * and raises the ingestion watermark to the newest entry the feed offered.
 *
 * The watermark only ever moves forward. A feed whose newest entry has scrolled
 * out of its window (or that briefly serves a truncated copy) must not lower it,
 * or every older entry still listed becomes eligible for ingestion again.
 *
 * @param OODBBean $status      The status bean from begin_crawl().
 * @param int      $now         The attempt timestamp from begin_crawl().
 * @param int      $items       Number of items created or updated this run.
 * @param int|null $newest_item Newest entry publication timestamp seen, or null
 *                              when the feed carried no dated entries.
 * @return array{ok: bool, items: int, error: ?string}
 */
function record_crawl_success(OODBBean $status, int $now, int $items, ?int $newest_item = null): array
{
    $status->last_success  = $now;
    $status->item_count    = $items;
    $status->error_message = '';
    if ($newest_item !== null) {
        $status->last_item_date = max((int)$status->last_item_date, $newest_item);
    }
    R::store($status);

    return ['ok' => true, 'items' => $items, 'error' => null];
}

/**
 * Returns the persisted crawl status for every configured feed, in config order.
 *
 * Feeds with no stored health yet (never crawled) get a zeroed row so the Logs tab
 * lists them too. Config is the source of truth for which feeds exist.
 *
 * @return array<int, array{name:string, url:string, last_attempt:int, last_success:int, last_error:int, error_message:string, item_count:int}>
 */
function get_feed_statuses(): array
{
    $out = [];
    foreach (get_feeds() as $name => $url) {
        $bean = R::findOne('feedstatus', ' feedkey = ? ', [md5($name . $url)]);
        $out[] = [
            'name'          => (string)$name,
            'url'           => (string)$url,
            'last_attempt'  => $bean ? (int)$bean->last_attempt : 0,
            'last_success'  => $bean ? (int)$bean->last_success : 0,
            'last_error'    => $bean ? (int)$bean->last_error : 0,
            'error_message' => $bean ? (string)$bean->error_message : '',
            'item_count'    => $bean ? (int)$bean->item_count : 0,
        ];
    }

    return $out;
}

/**
 * Deletes feedstatus beans for feeds that are no longer present in config.
 *
 * @return int Number of stale status rows removed.
 */
function prune_feed_status(): int
{
    $keys = [];
    foreach (get_feeds() as $name => $url) {
        $keys[] = md5($name . $url);
    }

    $removed = 0;
    foreach (R::findAll('feedstatus') as $bean) {
        if (!in_array($bean->feedkey, $keys, true)) {
            R::trash($bean);
            $removed++;
        }
    }

    return $removed;
}

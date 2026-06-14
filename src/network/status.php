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
        $bean->last_attempt = 0;
        $bean->last_error   = 0;
        $bean->item_count   = 0;
        $bean->error_message = '';
    }

    return $bean;
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

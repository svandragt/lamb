<?php

namespace Lamb\Network;

use RedBeanPHP\OODBBean;
use RedBeanPHP\R;
use RedBeanPHP\RedException\SQL;
use SimplePie\Item as SimplePieItem;

use function Lamb\Post\finalize_and_store_post;
use function Lamb\Post\finalize_slug;
use function Lamb\Post\populate_bean;

/**
 * Decides whether a single feed item is created, updated, or skipped, keyed on
 * its `feeditem_uuid` rather than dates alone.
 *
 * Deduplication lives here: an item that already has a post is never recreated
 * (the source of the recreated-draft bug when a feed re-stamps an item's
 * publication date past the watermark). A brand-new item is created only when
 * its publication date is newer than the watermark. An already-ingested post is
 * re-synced from the source only when the item was modified after the watermark
 * AND the author has not taken the post over via the edit form
 * (`feed_locked`) — so a published, re-slugged post is left intact.
 *
 * @param SimplePieItem|JsonFeedItem $item      The feed item.
 * @param string        $name      Feed name from config.
 * @param int           $watermark The feed's last-success timestamp.
 * @return bool True when a post was created or updated (counts toward the run total).
 */
function ingest_item(SimplePieItem|JsonFeedItem $item, string $name, int $watermark): bool
{
    $uuid     = md5($name . $item->get_id());
    $existing = R::findOne('post', ' feeditem_uuid = ? ', [$uuid]);

    if (!$existing) {
        if ((int) $item->get_date('U') > $watermark) {
            create_item($item, $name);
            return true;
        }
        return false;
    }

    if (!$existing->feed_locked && (int) $item->get_updated_date('U') > $watermark) {
        update_item($item, $name);
        return true;
    }

    return false;
}

function update_item(SimplePieItem|JsonFeedItem $item, string $name): void
{
    $uuid = md5($name . $item->get_id());
    $bean = R::findOne('post', ' feeditem_uuid = ?', [$uuid]);
    if (!$bean) {
        // Record not found
        return;
    }
    $bean = prepare_item($item, $name, $bean);
    $bean->updated = $item->get_updated_date("Y-m-d H:i:s");
    finalize_slug($bean);

    try {
        R::store($bean);
    } catch (SQL) {
        // continue
    }
}

function prepare_item(SimplePieItem|JsonFeedItem $item, string $name, ?OODBBean $bean = null): OODBBean
{
    $contents = get_structured_content($item, $name);

    return populate_bean($contents, $item, $name, $bean);
}

function create_item(SimplePieItem|JsonFeedItem $item, string $name): void
{
    $contents = get_structured_content($item, $name);
    $bean = populate_bean($contents, $item, $name);

    try {
        // Reserved-route and duplicate slugs (e.g. two same-titled items in
        // one feed) get an id suffix; the final slug is pinned into the
        // body's front matter so cron updates re-derive it unchanged.
        finalize_and_store_post($bean);
    } catch (SQL) {
        // continue
    }
}

/**
 * @param SimplePieItem|JsonFeedItem $item
 * @param string $name
 * @return string
 */
function get_structured_content(SimplePieItem|JsonFeedItem $item, string $name): string
{
    $contents = attributed_content($item, $name);
    $title = sanitize_feed_title($item->get_title() ?? '');
    if (!empty($title)) {
        $contents = <<<MATTER
---
title: {$title}
---

{$contents}
MATTER;
    }
    return $contents;
}

/**
 * Sanitises a remote feed title before it is embedded in a post's YAML front matter.
 *
 * Front matter is delimited by `---` and parsed as YAML, so an untrusted title
 * containing newlines could inject extra keys (e.g. `slug`, `created`) and a `---`
 * sequence could close the block early. Whitespace is collapsed to single spaces,
 * any run of three or more hyphens is shortened, the result is length-capped, and
 * slashes/quotes are escaped (preserving the existing front-matter format).
 *
 * @param string $title The raw feed item title.
 * @return string A single-line, length-capped, escaped title safe for front matter.
 */
function sanitize_feed_title(string $title): string
{
    $title = (string) preg_replace('/\s+/', ' ', $title);
    $title = (string) preg_replace('/-{3,}/', '--', $title);
    $title = trim($title);
    if (mb_strlen($title) > 200) {
        $title = rtrim(mb_substr($title, 0, 200));
    }

    return addslashes($title);
}

/**
 * Returns the description of a feed item formatted as a quoted block,
 * along with a citation to the original source.
 *
 * @param SimplePieItem|JsonFeedItem $item The feed item from which to extract the description and URL.
 * @param string $name The name to use in the citation.
 * @return string The formatted description with a citation to the original source.
 */
function attributed_content(SimplePieItem|JsonFeedItem $item, string $name): string
{
    $contents = strip_tags($item->get_description() ?? '');
    $lines = explode(PHP_EOL, $contents);
    $lines = array_slice($lines, 0, 5); // Get only the first 5 lines
    foreach ($lines as &$line) {
        $line = "> $line";
    }
    unset($line);
    $contents = implode(PHP_EOL, $lines);
    $url = $item->get_permalink();
    return "Originally written on [$name]($url): " . PHP_EOL . PHP_EOL . $contents;
}

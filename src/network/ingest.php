<?php

namespace Lamb\Network;

use RedBeanPHP\OODBBean;
use RedBeanPHP\R;
use RedBeanPHP\RedException\SQL;
use SimplePie\Item as SimplePieItem;

use function Lamb\Post\build_matter;
use function Lamb\Post\finalize_and_store_post;
use function Lamb\Post\finalize_slug;
use function Lamb\Post\populate_bean;

/**
 * Decides whether a single feed item is created, updated, or skipped, keyed on
 * its `feeditem_uuid` so an item that already has a post is never recreated.
 * Every date comparison is against something the feed said, never the clock:
 * a new item is created only when it is newer than the ingestion watermark, an
 * existing post re-synced only when the feed's copy is newer than ours and the
 * author has not taken it over (`feed_locked`). See network/README.md
 * ("The watermark model").
 *
 * @param SimplePieItem|JsonFeedItem $item      The feed item.
 * @param string        $name      Feed name from config.
 * @param int           $watermark Newest entry publication timestamp seen so far.
 * @return bool|null True when a post was created or updated (counts toward the
 *                   run total), false when there was nothing to do, and null
 *                   when an entry that should now exist does not because the
 *                   write failed — which the watermark has to know about.
 */
function ingest_item(SimplePieItem|JsonFeedItem $item, string $name, int $watermark): ?bool
{
    $uuid     = md5($name . $item->get_id());
    $existing = R::findOne('post', ' feeditem_uuid = ? ', [$uuid]);

    if (!$existing) {
        if ((int) $item->get_date('U') > $watermark) {
            // null, not true, when the store failed: creation is the only
            // decision the watermark gates, so a lost entry has to hold it back.
            return create_item($item, $name) ? true : null;
        }
        return false;
    }

    // The post's own `updated` is the copy we last took from the feed:
    // update_item() and populate_bean() both stamp it from the item. Comparing
    // against it makes the re-sync decision independent of when /_cron happens
    // to run, and stops a re-synced item from being re-synced on every crawl.
    $synced_at = (int) strtotime((string) $existing->updated);
    if (!$existing->feed_locked && (int) $item->get_updated_date('U') > $synced_at) {
        // A failed re-sync needs no special handling: `updated` was not stamped,
        // so the next crawl compares against the same value and tries again. It
        // just must not be counted as an entry this run took in.
        return update_item($item, $name);
    }

    return false;
}

/**
 * Runs a crawled feed's entries through ingest_item() against that feed's
 * ingestion watermark, and reports what the run should record. Shared by both
 * crawl paths so they cannot drift on which watermark they read. A run that lost
 * an entry to a failed write reports no new watermark, so the watermark is never
 * stepped over a lost entry. See network/README.md ("The watermark model").
 *
 * @param array<array-key, SimplePieItem|JsonFeedItem> $items  The feed's entries.
 * @param string   $name   Feed name from config.
 * @param OODBBean $status The feed's status bean from begin_crawl().
 * @return array{0: int, 1: int|null} Entries created or updated, and the newest
 *                                    entry date seen (null when none is dated,
 *                                    or when an entry was lost to a failed write).
 */
function ingest_items(array $items, string $name, OODBBean $status): array
{
    $watermark = (int) $status->last_item_date;
    $ingested  = 0;
    $newest    = null;
    $lost      = false;

    foreach ($items as $item) {
        $outcome = ingest_item($item, $name, $watermark);
        if ($outcome === null) {
            $lost = true;
            continue;
        }
        if ($outcome) {
            $ingested++;
        }
        $date = (int) $item->get_date('U');
        if ($date > 0 && ($newest === null || $date > $newest)) {
            $newest = $date;
        }
    }

    return [$ingested, $lost ? null : $newest];
}

/**
 * Re-syncs an already-ingested entry. Returns false when the row is gone or the
 * write failed, so the caller does not count a re-sync that did not happen.
 */
function update_item(SimplePieItem|JsonFeedItem $item, string $name): bool
{
    $uuid = md5($name . $item->get_id());
    $bean = R::findOne('post', ' feeditem_uuid = ?', [$uuid]);
    if (!$bean) {
        // Record not found
        return false;
    }
    $bean = prepare_item($item, $name, $bean);
    $bean->updated = $item->get_updated_date("Y-m-d H:i:s");
    finalize_slug($bean);

    try {
        R::store($bean);
    } catch (SQL) {
        return false;
    }

    return true;
}

function prepare_item(SimplePieItem|JsonFeedItem $item, string $name, ?OODBBean $bean = null): OODBBean
{
    $contents = get_structured_content($item, $name);

    return populate_bean($contents, $item, $name, $bean);
}

/**
 * Creates a post for a feed entry. Returns false when the write failed, so the
 * caller can hold the watermark back instead of stepping over a lost entry.
 */
function create_item(SimplePieItem|JsonFeedItem $item, string $name): bool
{
    $contents = get_structured_content($item, $name);
    $bean = populate_bean($contents, $item, $name);

    try {
        // Reserved-route and duplicate slugs (e.g. two same-titled items in
        // one feed) get an id suffix; the final slug is pinned into the
        // body's front matter so cron updates re-derive it unchanged.
        finalize_and_store_post($bean);
    } catch (SQL) {
        return false;
    }

    return true;
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
        // Dump via build_matter() (Yaml::dump), never string interpolation: a
        // feed could otherwise choose its title's YAML type and crash the run.
        // See network/README.md ("Untrusted content → front matter").
        $contents = build_matter(['title' => $title], "\n" . $contents);
    }
    return $contents;
}

/**
 * Sanitises a remote feed title before it is embedded in a post's YAML front
 * matter: collapses whitespace, shortens `---` runs, and length-caps it so an
 * untrusted title cannot inject keys or close the block early. Quoting is left to
 * Yaml::dump() in get_structured_content(). See network/README.md
 * ("Untrusted content → front matter").
 *
 * @param string $title The raw feed item title.
 * @return string A single-line, length-capped title safe for front matter.
 */
function sanitize_feed_title(string $title): string
{
    $title = (string) preg_replace('/\s+/', ' ', $title);
    $title = (string) preg_replace('/-{3,}/', '--', $title);
    $title = trim($title);
    if (mb_strlen($title) > 200) {
        $title = rtrim(mb_substr($title, 0, 200));
    }

    return $title;
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

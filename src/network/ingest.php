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
 * its `feeditem_uuid` rather than dates alone.
 *
 * Deduplication lives here: an item that already has a post is never recreated
 * (the source of the recreated-draft bug when a feed re-stamps an item's
 * publication date past the watermark).
 *
 * Both remaining date comparisons are against something the *feed* said, never
 * against the clock:
 *
 * - A brand-new item is created when its publication date is newer than the
 *   newest entry this feed has ever offered us (`feedstatus.last_item_date`).
 *   That mark exists only to stop an entry still sitting in the feed window
 *   from resurrecting a post the author trashed and /_cron later purged. It
 *   used to be the *crawl* timestamp, which quietly dropped items for good: any
 *   crawl that succeeded without seeing the entry — a cached or CDN-stale copy
 *   of the feed, a feed that publishes with a lag — still stamped
 *   `last_success = now`, and the entry's own publication date was by then
 *   older than that stamp, so it was never created and never would be.
 * - An already-ingested post is re-synced only when the item was modified after
 *   the copy we stored (its `updated` column, which update_item() stamps from
 *   the item) AND the author has not taken the post over via the edit form
 *   (`feed_locked`) — so a published, re-slugged post is left intact.
 *
 * @param SimplePieItem|JsonFeedItem $item      The feed item.
 * @param string        $name      Feed name from config.
 * @param int           $watermark Newest entry publication timestamp seen so far.
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

    // The post's own `updated` is the copy we last took from the feed:
    // update_item() and populate_bean() both stamp it from the item. Comparing
    // against it makes the re-sync decision independent of when /_cron happens
    // to run, and stops a re-synced item from being re-synced on every crawl.
    $synced_at = (int) strtotime((string) $existing->updated);
    if (!$existing->feed_locked && (int) $item->get_updated_date('U') > $synced_at) {
        update_item($item, $name);
        return true;
    }

    return false;
}

/**
 * Runs a crawled feed's entries through ingest_item() against that feed's
 * ingestion watermark, and reports what the run should record.
 *
 * Shared by the SimplePie and JSON Feed crawls so the two cannot drift on which
 * watermark they read — the divergence this pattern is prone to, and the reason
 * the pair already share begin_crawl()/record_crawl_*().
 *
 * @param array<array-key, SimplePieItem|JsonFeedItem> $items  The feed's entries.
 * @param string   $name   Feed name from config.
 * @param OODBBean $status The feed's status bean from begin_crawl().
 * @return array{0: int, 1: int|null} Entries created or updated, and the newest
 *                                    entry date seen (null when none is dated).
 */
function ingest_items(array $items, string $name, OODBBean $status): array
{
    $watermark = (int) $status->last_item_date;
    $ingested  = 0;
    $newest    = null;

    foreach ($items as $item) {
        if (ingest_item($item, $name, $watermark)) {
            $ingested++;
        }
        $date = (int) $item->get_date('U');
        if ($date > 0 && ($newest === null || $date > $newest)) {
            $newest = $date;
        }
    }

    return [$ingested, $newest];
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
        // build_matter() (i.e. Yaml::dump) rather than interpolating the title
        // into a heredoc. Interpolation let the remote feed choose the YAML
        // *type* of its own title: `[a, b]` arrived as a list and `2024-01-02`
        // as a date object, neither of which is a string, and the ingest run
        // died on the first such item. Dumping quotes the scalar so a title is
        // always read back as the text the feed sent.
        $contents = build_matter(['title' => $title], "\n" . $contents);
    }
    return $contents;
}

/**
 * Sanitises a remote feed title before it is embedded in a post's YAML front matter.
 *
 * Front matter is delimited by `---` and parsed as YAML, so an untrusted title
 * containing newlines could inject extra keys (e.g. `slug`, `created`) and a `---`
 * sequence could close the block early. Whitespace is collapsed to single spaces,
 * any run of three or more hyphens is shortened, and the result is length-capped.
 *
 * Quoting and escaping are no longer done here: get_structured_content() now
 * renders the block with Yaml::dump(), which quotes the scalar correctly for
 * whatever it contains. The previous addslashes() left a literal backslash in
 * front of every apostrophe in the stored title.
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

<?php

namespace Lamb\Network;

use JetBrains\PhpStorm\NoReturn;
use RedBeanPHP\OODBBean;
use RedBeanPHP\R;
use RedBeanPHP\RedException\SQL;
use SimplePie\Item as SimplePieItem;
use SimplePie\SimplePie;

use function Lamb\get_option;
use function Lamb\Route\register_route;
use function Lamb\Post\finalize_slug;
use function Lamb\Post\populate_bean;
use function Lamb\set_option;

// MINUTE_IN_SECONDS is defined in constants.php

register_route('_cron', __NAMESPACE__ . '\\process_feeds');

function get_feeds(): array
{
    global $config;

    return $config['feeds'] ?? [];
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
    $posts  = R::find('post', ' deleted = 1 AND deleted_at < ? ', [$cutoff]);
    foreach ($posts as $post) {
        R::trash($post);
    }
    return count($posts);
}

#[NoReturn] function process_feeds(): void
{
    header('Content-Type: text/plain');
    $feeds = get_feeds();

    $cron_last_updated = get_option('last_processed_date', 0);
    if ((time() - $cron_last_updated->value) < MINUTE_IN_SECONDS) {
        die('Too often, try again later.');
    }

    $purged = purge_deleted_posts();
    if ($purged > 0) {
        echo("Purged $purged deleted post(s)." . PHP_EOL);
    }

    echo("Updating feeds..." . PHP_EOL);
    foreach ($feeds as $name => $url) {
        flush();
        $last_updated = get_option('last_processed_date_' . md5($name . $url), 0);
        if ((time() - $last_updated->value) < MINUTE_IN_SECONDS * 30) {
            echo('Skipped ' . $url . PHP_EOL);
            continue;
        }

        $feed = new SimplePie();
        /** @noinspection PhpDeprecationInspection */
        $feed->set_cache_location('../data/cache/simplepie');
        $feed->set_feed_url($url);
        // Cap each fetch so a slow or hostile feed URL cannot stall the cron run.
        $feed->set_timeout(FEED_FETCH_TIMEOUT);
        $feed->init();
        echo PHP_EOL . "Processing " . $feed->get_title() . PHP_EOL;

        if ($feed->data) {
            /** @var SimplePieItem $item */
            foreach ($feed->get_items() as $item) {
                $pub_date = $item->get_date('U');
                $mod_date = $item->get_updated_date('U');

                // Compare the publication date of the item with the last processed date.
                if ($pub_date > $last_updated->value) {
                    create_item($item, $name);
                    printf("Created: %s - [%s] %s" . PHP_EOL, $name, $item->get_id(), $item->get_title());
                    continue;
                }
                if ($mod_date > $last_updated->value) {
                    update_item($item, $name);
                    printf("Updated: %s - [%s] %s" . PHP_EOL, $name, $item->get_id(), $item->get_title());
                }
            }
        }
        set_option($last_updated, (int)date('U'));
    }

    $sent = \Lamb\Webmention\process_outbound();
    if ($sent['sent'] || $sent['failed'] || $sent['skipped'] || $sent['cancelled']) {
        printf(
            "Webmentions sent: %d, failed: %d, skipped: %d, cancelled: %d" . PHP_EOL,
            $sent['sent'],
            $sent['failed'],
            $sent['skipped'],
            $sent['cancelled']
        );
    }

    set_option($cron_last_updated, (int)date('U'));
    exit('Done');
}

function update_item(SimplePieItem $item, string $name): void
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

function prepare_item(SimplePieItem $item, string $name, ?OODBBean $bean = null): OODBBean
{
    $contents = get_structured_content($item, $name);

    return populate_bean($contents, $item, $name, $bean);
}

function create_item(SimplePieItem $item, string $name)
{
    $contents = get_structured_content($item, $name);
    $bean = populate_bean($contents, $item, $name);

    try {
        R::store($bean);
        // Reserved-route and duplicate slugs (e.g. two same-titled items in
        // one feed) get an id suffix; the final slug is pinned into the
        // body's front matter so cron updates re-derive it unchanged.
        if (finalize_slug($bean)) {
            R::store($bean);
        }
    } catch (SQL) {
        // continue
    }
}

/**
 * @param SimplePieItem $item
 * @param string $name
 * @return string
 */
function get_structured_content(SimplePieItem $item, string $name): string
{
    $contents = attributed_content($item, $name);
    $title = sanitize_feed_title($item->get_title());
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
 * Returns the description of a SimplePie item formatted as a quoted block,
 * along with a citation to the original source.
 *
 * @param SimplePieItem $item The SimplePieItem instance from which to extract the description and URL.
 * @param string $name The name to use in the citation.
 * @return string The formatted description with a citation to the original source.
 */
function attributed_content(SimplePieItem $item, string $name): string
{
    $contents = strip_tags($item->get_description());
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

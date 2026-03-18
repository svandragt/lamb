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
use function Lamb\Route\is_reserved_route;
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
#[NoReturn] function process_feeds(): void
{
    header('Content-Type: text/plain');
    $feeds = get_feeds();

    $cron_last_updated = get_option('last_processed_date', 0);
    if ((time() - $cron_last_updated->value) < MINUTE_IN_SECONDS) {
        die('Too often, try again later.');
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
    if (!$bean) {
        // Record not found, should not happen as we already checked for existence.
        return;
    }
    $bean->updated = $item->get_updated_date("Y-m-d H:i:s");

    try {
        R::store($bean);
    } catch (SQL) {
        // continue
    }
}

function prepare_item(SimplePieItem $item, string $name, OODBBean $bean = null): ?OODBBean
{
    $contents = get_structured_content($item, $name);
    $bean = populate_bean($contents, $item, $name, $bean);
    if ($bean === null) {
        $_SESSION['flash'][] = 'Failed to save post';

        return null;
    }

    return $bean;
}

function create_item(SimplePieItem $item, string $name)
{
    $contents = get_structured_content($item, $name);
    $bean = populate_bean($contents, $item, $name);
    if ($bean === null) {
        $_SESSION['flash'][] = 'Failed to save post';

        return;
    }

    try {
        $id = R::store($bean);
        if (is_reserved_route($bean->slug)) {
            $bean->slug .= "-" . $id;
        }
        R::store($bean);
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
    $title = addslashes($item->get_title());
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

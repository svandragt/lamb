<?php

namespace Lamb\Network;

use JetBrains\PhpStorm\NoReturn;
use RedBeanPHP\OODBBean;
use RedBeanPHP\R;
use RedBeanPHP\RedException\SQL;
use SimplePie\Item as SimplePieItem;
use SimplePie\SimplePie;

use function Lamb\Route\register_route;
use function Lamb\Route\is_reserved_route;
use function Lamb\Post\populate_bean;

use const ROOT_DIR;

const MINUTE_IN_SECONDS = 60;

register_route('_cron', __NAMESPACE__ . '\\process_feeds');

function get_feeds(): array
{
    global $config;

    return $config['network_feeds'] ?? [];
}

/** @noinspection PhpUnused */
#[NoReturn] function process_feeds(): void
{
    header('Content-Type: text/plain');
    // FIXME: Missing permalink and title

    $feeds = get_feeds();

    $cron_last_updated = get_option('last_processed_date', 0);
    if ((time() - $cron_last_updated->value) < MINUTE_IN_SECONDS) {
        die('Too often, try again later.');
    }
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
    exit();
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
        // Record not found
        return;
    }
    $bean->updated = date("Y-m-d H:i:s");

    try {
        R::store($bean);
    } catch (SQL) {
        // continue
    }
}

function prepare_item(SimplePieItem $item, string $name, OODBBean $bean = null): ?OODBBean
{
    $contents = wrapped_contents($item, $name);
    $title = addslashes($item->get_title());
    if (!empty($title)) {
        $contents = <<<MATTER
---
title: {$title}
---

{$contents}
MATTER;
    }
    $bean = populate_bean($contents, $item, $name, $bean);
    if (is_null($bean)) {
        $_SESSION['flash'][] = 'Failed to save post';

        return null;
    }

    return $bean;
}

function create_item(SimplePieItem $item, string $name)
{
    $contents = wrapped_contents($item, $name);
    $title = addslashes($item->get_title());
    if (!empty($title)) {
        $contents = <<<MATTER
---
title: {$title}
---

{$contents}
MATTER;
    }
    $bean = populate_bean($contents, $item, $name);
    if (is_null($bean)) {
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

function get_option(string $key, $default_value): OODBBean
{
    $bean = R::findOneOrDispense('option', ' key = ? ', [$key]);
    $bean->key = $key;
    if ($bean->id === 0) {
        $bean->value = $default_value;
    }

    return $bean;
}

/**
 * Sets an option in the given OODBBean object and stores it.
 *
 * @param OODBBean $bean The OODBBean instance where the option will be set.
 * @param mixed $value The value to set in the OODBBean.
 * @return void No value is returned.
 * @throws SQL Exception when option can't be stored.
 */
function set_option(OODBBean $bean, $value): void
{
    $bean->value = $value;
    R::store($bean);
}

function wrapped_contents(SimplePieItem $item, string $name): string
{
    $contents = strip_tags($item->get_description());
    $lines = explode(PHP_EOL, $contents);
    foreach ($lines as &$line) {
        $line = "> $line";
    }
    unset($line);

    $contents = implode(PHP_EOL, $lines);
    $url = $item->get_permalink();
    return "Originally written on [$name]($url): " . PHP_EOL . PHP_EOL . $contents;
}

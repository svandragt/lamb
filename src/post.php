<?php

namespace Lamb\Post;

use RedBeanPHP\OODBBean;
use RedBeanPHP\R;
use SimplePie\Item;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

use function Lamb\parse_bean;

/**
 * Return an unsaved dispensed bean.
 *
 * @param string $text
 * @param Item|null $feed_item
 * @param string|null $feed_name
 *
 * @return OODBBean|null
 */
function populate_bean(string $text, Item $feed_item = null, string $feed_name = null, OODBBean $bean = null): ?OODBBean
{
    $matter = parse_matter($text);

    if (is_null($bean)) {
        $bean = R::dispense('post');
    }
    $bean->body = $text;
    $bean->slug = $matter['slug'] ?? '';
    $bean->created = date("Y-m-d H:i:s");
    if ($feed_item) {
        $bean->created = $feed_item->get_date("Y-m-d H:i:s");
        if ($feed_name) {
            if ($bean->slug) {
                // Prefix with feed name
                $bean->slug = slugify("$feed_name-" . $bean->slug);
            }
            $bean->feeditem_uuid = md5($feed_name . $feed_item->get_id());
            $bean->feed_name = $feed_name;
        }
    }
    $bean->updated = date("Y-m-d H:i:s");

    parse_bean($bean);

    return $bean;
}

/**
 * Parse YAML front matter from the given text and convert it into an associative array.
 *
 * @param string $text
 *
 * @return array
 */
function parse_matter(string $body): array
{
    $matter = null;
    $text = explode('---', $body);
    try {
        if (isset($text[1])) {
            $matter = Yaml::parse($text[1]);
        }
    } catch (ParseException) {
        // Invalid YAML
        return [];
    }

    // There is no matter.
    if (!is_array($matter)) {
        return [];
    }
    if (isset($matter['title'])) {
        $matter['slug'] = strtolower(preg_replace('/\W+/m', "-", $matter['title']));
    }

    return $matter;
}

function slugify(string $text): string
{
    return strtolower(preg_replace('/\W+/m', "-", $text));
}

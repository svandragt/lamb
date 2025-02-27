<?php

namespace Lamb\Post;

use RedBeanPHP\OODBBean;
use RedBeanPHP\R;
use SimplePie\Item;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

use function Lamb\parse_bean;

/**
 * Populates and returns an OODBBean instance with the given text and optional feed information.
 *
 * @param string $text The text content to be set in the bean.
 * @param Item|null $feed_item An optional feed item to extract creation date and ID from.
 * @param string|null $feed_name An optional feed name to prefix the slug and associate with the bean.
 * @param OODBBean|null $bean An optional existing bean to populate. If null, a new 'post' bean is dispensed.
 * @return OODBBean|null The populated bean instance, or null if input is insufficient.
 * @noinspection CallableParameterUseCaseInTypeContextInspection
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
	$bean->updated = date("Y-m-d H:i:s");
	if ($feed_item) {
		$bean->created = $feed_item->get_date("Y-m-d H:i:s");
		$bean->updated = $feed_item->get_updated_date("Y-m-d H:i:s");
		if ($feed_name) {
			if ($bean->slug) {
				// Prefix with feed name
				$bean->slug = slugify("$feed_name-" . $bean->slug);
			}
			$bean->feeditem_uuid = md5($feed_name . $feed_item->get_id());
			$bean->feed_name = $feed_name;
		}
	}

	parse_bean($bean);

	return $bean;
}

/**
 * Parses a string body for YAML front matter and returns an associative array of the extracted metadata.
 *
 * @param string $body The string containing the content with optional YAML front matter delimited by '---'.
 * @return array An associative array of parsed YAML metadata. Returns an empty array if the YAML is invalid or absent.
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
		$matter['slug'] = slugify($matter['title']);
	}

	return $matter;
}

function slugify(string $text): string
{
	return strtolower(preg_replace('/\W+/m', "-", $text));
}

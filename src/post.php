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
function populate_bean(string $text, ?Item $feed_item = null, ?string $feed_name = null, ?OODBBean $bean = null): ?OODBBean
{
    global $config;

    $matter = parse_matter($text);

    if ($bean === null) {
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
        $bean->source_url = $feed_item->get_permalink();
    }

    parse_bean($bean);
    $bean->version = 1;

    // Auto-draft new feed items when feeds_draft is enabled (applied after parse_bean
    // so frontmatter-driven draft:false cannot inadvertently publish a feed item).
    if ($feed_item && !$bean->id && filter_var($config['feeds_draft'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
        $bean->draft = 1;
    }

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
            // PARSE_DATETIME keeps absolute dates as DateTime objects carrying the
            // author's typed wall-clock time, instead of coercing them to UTC Unix
            // timestamps (which would shift the time by the server's timezone offset).
            $matter = Yaml::parse($text[1], Yaml::PARSE_DATETIME);
        }
    } catch (ParseException) {
        // Invalid YAML
        return [];
    }

    // There is no matter.
    if (!is_array($matter)) {
        return [];
    }
    if (isset($matter['title']) && !isset($matter['slug'])) {
        $matter['slug'] = slugify($matter['title']);
    }

    return $matter;
}

function slugify(string $text): string
{
    return strtolower(preg_replace('/\W+/m', "-", $text));
}

/**
 * Returns a broad SQL prefilter for posts whose body contains the given tag.
 *
 * The SQL is deliberately permissive (it also matches longer tags that share
 * this prefix, e.g. `#tildes` for `til`); callers must refine the result set
 * with body_has_tag() so the match honours the same delimiter rules as the
 * inline tag renderer (Lamb\parse_tags).
 *
 * @param string $tag The tag to match.
 * @return array{sql: string, params: array}
 */
function get_tag_search_conditions(string $tag): array
{
    return [
        'sql'    => 'body LIKE ?',
        'params' => ["%#$tag%"],
    ];
}

/**
 * Returns true if $body contains $tag as a hashtag, using the same boundary
 * rules as the inline tag renderer (Lamb\parse_tags): the tag must follow the
 * start of the string, whitespace, or `>`, and be followed by whitespace, the
 * end of the string, or one of the tag-terminating punctuation characters.
 *
 * @param string $tag  The tag to look for (without the leading `#`).
 * @param string $body The raw post body.
 * @return bool
 */
function body_has_tag(string $tag, string $body): bool
{
    $pattern = '/(^|[\s>])#' . preg_quote($tag, '/') . '(?=[\s#&.,!?;:()\[\]{}<]|$)/iu';
    return (bool) preg_match($pattern, $body);
}

/**
 * Retrieves posts that contain the specified tag within their body content.
 *
 * @param string $tag The tag to search for within post content.
 *
 * @return array An array of posts that match the specified tag.
 */
function posts_by_tag(string $tag): array
{
    $conditions = get_tag_search_conditions($tag);
    $visible = \Lamb\visible_clause();
    $posts = R::find(
        'post',
        '(' . $conditions['sql'] . ') AND' . $visible['sql'] . 'ORDER BY created DESC',
        array_merge($conditions['params'], $visible['params'])
    );

    return array_values(array_filter($posts, fn($post) => body_has_tag($tag, (string) $post->body)));
}

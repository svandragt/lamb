<?php

namespace Lamb\Post;

use RedBeanPHP\OODBBean;
use RedBeanPHP\R;
use SimplePie\Item;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

use function Lamb\parse_bean;
use function Lamb\Route\is_reserved_route;

/**
 * Populates and returns an OODBBean instance with the given text and optional feed information.
 *
 * @param string $text The text content to be set in the bean.
 * @param Item|null $feed_item An optional feed item to extract creation date and ID from.
 * @param string|null $feed_name An optional feed name to prefix the slug and associate with the bean.
 * @param OODBBean|null $bean An optional existing bean to populate. If null, a new 'post' bean is dispensed.
 * @return OODBBean The populated bean instance.
 * @noinspection CallableParameterUseCaseInTypeContextInspection
 */
function populate_bean(string $text, ?Item $feed_item = null, ?string $feed_name = null, ?OODBBean $bean = null): OODBBean
{
    global $config;

    $text = normalize_frontmatter_fence($text);
    $matter = parse_matter($text);

    if ($bean === null) {
        $bean = R::dispense('post');
    }
    // A published post's slug is locked (see parse_bean); capture the stored
    // state before it is overwritten so the feed-name prefix below cannot
    // change a slug the lock just preserved.
    $slug_mutable = empty($bean->id) || !empty($bean->draft);
    $bean->body = $text;
    if ($slug_mutable) {
        $bean->slug = $matter['slug'] ?? '';
    }
    $bean->created = date("Y-m-d H:i:s");
    $bean->updated = date("Y-m-d H:i:s");
    if ($feed_item) {
        $bean->created = $feed_item->get_date("Y-m-d H:i:s");
        $bean->updated = $feed_item->get_updated_date("Y-m-d H:i:s");
        if ($feed_name) {
            $bean->feeditem_uuid = md5($feed_name . $feed_item->get_id());
            $bean->feed_name = $feed_name;
        }
        $bean->source_url = $feed_item->get_permalink();
    }

    parse_bean($bean);
    // Prefix a title-derived feed-item slug with the feed name so same-titled
    // posts from different feeds don't collide. Applied after parse_bean(),
    // whose front-matter loop re-derives the slug from the title and would
    // otherwise clobber the prefix (issue #332). An explicit front-matter slug
    // (pinned by finalize_slug() on first save) is authoritative and must not
    // be prefixed again on cron updates.
    $derived = isset($matter['title']) && $bean->slug === slugify((string) $matter['title']);
    if ($feed_item && $feed_name && $bean->slug && $derived && $slug_mutable) {
        $bean->slug = slugify("$feed_name-" . $bean->slug);
    }
    $bean->version = POST_VERSION;

    // Auto-draft new feed items when feeds_draft is enabled (applied after parse_bean
    // so frontmatter-driven draft:false cannot inadvertently publish a feed item).
    if ($feed_item && !$bean->id && filter_var($config['feeds_draft'] ?? true, FILTER_VALIDATE_BOOLEAN)) {
        $bean->draft = 1;
    }

    return $bean;
}

/**
 * Restores a front-matter fence that iOS "Smart Punctuation" has rewritten.
 *
 * Typing `---` on iOS produces em/en dashes (commonly `—-`), which stops the
 * fence from being recognised as a front-matter delimiter. When the body opens
 * with a dash-only fence line and has a matching closing fence line, both are
 * normalised back to a literal `---`. Dashes anywhere else (post body, em-dash
 * punctuation, signatures) are left untouched, and the surrounding whitespace
 * and line endings are preserved.
 *
 * @param string $body The raw post body.
 * @return string The body with a normalised opening/closing front-matter fence.
 */
function normalize_frontmatter_fence(string $body): string
{
    // Dash-like fence characters: hyphen-minus, en dash (U+2013), em dash (U+2014).
    $pattern = '/\A([-\x{2013}\x{2014}]{2,})([ \t]*\R)(.*?)(\R)([-\x{2013}\x{2014}]{2,})([ \t]*)(\R|\z)/su';
    return preg_replace_callback($pattern, static function (array $m): string {
        return '---' . $m[2] . $m[3] . $m[4] . '---' . $m[6] . $m[7];
    }, $body, 1) ?? $body;
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

/**
 * Writes a title into a body's YAML front matter, creating the front matter
 * block when the body has none.
 *
 * Used when upgrading legacy posts (e.g. old feed items) whose title lives
 * only on the title column: parse_bean() clears titles absent from front
 * matter, so the stored title is migrated into the body before re-parsing.
 * The result matches the format modern feed ingestion writes.
 *
 * @param string $body The post body, with or without existing front matter.
 * @param string $title The title to write into the front matter.
 * @return string The body with the title present in its front matter.
 */
function inject_title_matter(string $body, string $title): string
{
    $title_line = rtrim(Yaml::dump(['title' => $title]), "\n");
    if (str_starts_with($body, "---\n")) {
        return "---\n" . $title_line . "\n" . substr($body, strlen("---\n"));
    }

    return "---\n" . $title_line . "\n---\n\n" . $body;
}

function slugify(string $text): string
{
    return strtolower(preg_replace('/\W+/m', "-", $text));
}

/**
 * Rewrites the `slug` value inside a body's leading YAML front-matter block to
 * the given actual slug, leaving all other front matter intact.
 *
 * This keeps the front matter in sync with the slug the post is actually
 * served under after adjustments (feed-name prefix, reserved-route or
 * duplicate suffix), so a later re-parse derives the same slug instead of the
 * original colliding one. An existing `slug:` line is updated in place; when
 * the block has none (slug derived from the title) an explicit line is
 * appended. Bodies without a front-matter block, or whose slug already equals
 * the actual value, are returned unchanged (no cosmetic churn).
 *
 * @param string $body The raw post body.
 * @param string $slug The slug the post is actually served under.
 * @return string The body with its front-matter `slug` pinned.
 */
function persist_slug(string $body, string $slug): string
{
    // Only touch a front-matter block at the very start of the body.
    if (!preg_match('/\A(\s*---\s*\n)(.*?\n)(---\s*\n?)/s', $body, $m)) {
        return $body;
    }

    $new_yaml = preg_replace_callback(
        '/^([ \t]*slug[ \t]*:)[ \t]*(.*?)[ \t]*$/mi',
        function (array $line) use ($slug): string {
            $current = trim($line[2], " \t'\"");
            if ($current === $slug) {
                return $line[0];
            }
            return $line[1] . ' ' . $slug;
        },
        $m[2],
        1,
        $count
    );

    if ($count === 0) {
        $new_yaml = $m[2] . "slug: $slug\n";
    }

    return $m[1] . $new_yaml . $m[3] . substr($body, strlen($m[0]));
}

/**
 * Finalises a stored post's slug: guarantees it is unique and not a reserved
 * route, and pins the result into the body's front matter.
 *
 * Duplicate and reserved slugs get the post's id appended (matching the
 * existing reserved-route convention), so two posts can never be served under
 * the same slug. The final slug is persisted into the front matter via
 * persist_slug() whenever it differs from what a re-parse would derive, so the
 * adjustment survives later edits and cron updates. Must be called after the
 * first R::store() (the id is part of the suffix); the caller re-stores when
 * this returns true.
 *
 * @param OODBBean $bean A stored post bean.
 * @return bool True when the slug or body changed and the bean needs re-storing.
 */
function finalize_slug(OODBBean $bean): bool
{
    $slug = (string) $bean->slug;
    if ($slug === '') {
        return false;
    }

    if (is_reserved_route($slug)) {
        $slug .= '-' . $bean->id;
    }
    while (R::findOne('post', ' slug = ? AND id != ? ', [$slug, $bean->id])) {
        $slug .= '-' . $bean->id;
    }

    $body = (string) $bean->body;
    $matter = parse_matter($body);
    if (($matter['slug'] ?? '') !== $slug) {
        $body = persist_slug($body, $slug);
    }

    $changed = $slug !== (string) $bean->slug || $body !== (string) $bean->body;
    $bean->slug = $slug;
    $bean->body = $body;

    return $changed;
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

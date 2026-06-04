<?php

namespace Lamb;

use RedBeanPHP\OODBBean;

// SQL fragments for common post visibility filters.
const SQL_NOT_DRAFT  = ' (draft IS NULL OR draft != 1) ';
const SQL_NOT_DELETED = ' (deleted IS NULL OR deleted != 1) ';
const SQL_PUBLISHED  = ' (draft IS NULL OR draft != 1) AND (deleted IS NULL OR deleted != 1) ';
const SQL_IS_DRAFT   = ' draft = 1 AND (deleted IS NULL OR deleted != 1) ';
const SQL_IS_DELETED = ' deleted = 1 ';
// SQL fragment selecting posts that are scheduled for the future (and not draft/deleted).
const SQL_IS_SCHEDULED = ' created > ? AND (draft IS NULL OR draft != 1) AND (deleted IS NULL OR deleted != 1) ';
use RedBeanPHP\R;
use RedBeanPHP\RedException\SQL;

use function Lamb\Post\parse_matter;

/**
 * Retrieves the tags from the given HTML.
 *
 * @param string $html The HTML content to search for tags.
 *
 * @return array An array of tags found in the HTML.
 */
function get_tags(string $html): array
{
    preg_match_all('/(^|[\s>])#([^\s#&.,!?;:()\[\]{}<]+)/u', $html, $matches);

    return $matches[2];
}

/**
 * Parses tags in the given HTML string and converts them into links.
 *
 * This method replaces all occurrences of the "#" symbol followed by an alphanumeric word with
 * a hyperlink to the corresponding tag page. The replacement is done using regular expressions.
 * The resulting HTML string is returned.
 *
 * @param string $html The HTML string to parse tags from.
 *
 * @return string The modified HTML string with tags converted into links.
 */
function parse_tags(string $html): string
{
    return preg_replace_callback('/(^|[\s>])#([^\s#&.,!?;:()\[\]{}<]+)/u', function ($matches) {
        return $matches[1] . '<a href="/tag/' . strtolower($matches[2]) . '">#' . $matches[2] . '</a>';
    }, $html);
}

/**
 * Generates a permalink for the given item.
 *
 * This method creates a permalink for the given item based on its slug or ID.
 * If the item has a slug, it appends it to the root URL. Otherwise, it appends
 * the item's ID to the root URL with the "status" path.
 *
 * @param OODBBean $bean The item for which the permalink is generated.
 *                    It should have the 'slug' and 'id' properties.
 *
 * @return string The generated permalink URL.
 */
function permalink(OODBBean $bean): string
{
    if ($bean->slug) {
        return ROOT_URL . "/$bean->slug";
    }

    return ROOT_URL . '/status/' . $bean->id;
}


/**
 * Parses the given bean to extract and transform its content.
 *
 * This method processes the content of an OODBBean object, typically containing
 * a body with markdown text separated by '---'. It processes the markdown, extracts
 * front matter, and updates the bean with the parsed and transformed content.
 *
 * @param OODBBean $bean The item whose body content is parsed and transformed.
 *                       It must have a 'body' property containing the text to be processed.
 *
 * @return void This method does not return any value. It modifies the input bean directly.
 */
function parse_bean(OODBBean $bean): void
{
    $parts = explode('---', $bean->body);
    $md_text = trim($parts[count($parts) - 1]);
    $parser = new LambDown();
    $parser->setSafeMode(true);
    $markdown = $parser->text($md_text);

    $front_matter = parse_matter($bean->body);
    $description = strtok(strip_tags($markdown), "\n");
    // Decode twice: feed-ingested bodies already contain HTML entities (e.g. `&#039;`),
    // which Parsedown then re-encodes (`&amp;#039;`). A single decode only undoes one layer.
    $description = html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $description = html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $front_matter['description'] = $description;

    $front_matter['transformed'] = (parse_tags($markdown));

    // Normalise the reply target. Accept either `in-reply-to` (IndieWeb/Micropub
    // spelling) or `in_reply_to`, and remove both from the blind copy below so the
    // hyphenated key is never written as an invalid column. Empty when absent, so
    // removing it from front matter on edit clears the stored value.
    $in_reply_to = $front_matter['in-reply-to'] ?? $front_matter['in_reply_to'] ?? null;
    unset($front_matter['in-reply-to'], $front_matter['in_reply_to']);
    if (is_array($in_reply_to)) {
        $in_reply_to = $in_reply_to[0] ?? null;
    }
    $bean->in_reply_to = is_string($in_reply_to) ? trim($in_reply_to) : '';

    // Preserve the existing created date (now for new posts, the stored value for
    // edits, the feed date for ingested items) so an unparseable front-matter date
    // falls back to it rather than leaving a non-date string in the column.
    $previous_created = $bean->created;

    foreach ($front_matter as $key => $value) {
        $bean->$key = $value;
    }

    // Normalise a front-matter `created` date into the canonical Y-m-d H:i:s string.
    // Accepts absolute dates (kept as the typed wall-clock) and relative strings such
    // as "next friday 3pm" (resolved in the server timezone). A future date schedules
    // the post; an unparseable value falls back to the previous date so the post still
    // publishes rather than staying hidden forever.
    if (isset($front_matter['created'])) {
        $bean->created = normalize_datetime($front_matter['created'])
            ?? ($previous_created ?: date('Y-m-d H:i:s'));
        // Pin the resolved date back into the body so relative phrases like
        // "next friday" don't drift to a new date on the next edit.
        $bean->body = persist_resolved_created($bean->body, $bean->created);
    }

    // Explicitly normalise draft: 1 if truthy in frontmatter, 0 otherwise.
    // This ensures removing "draft: true" from frontmatter publishes the post on next save.
    $bean->draft = !empty($front_matter['draft']) ? 1 : 0;
}

/**
 * Retrieves a named option bean from the database, dispensing a new one with the
 * given default value when the key does not yet exist.
 *
 * @param string $name          The option name (key).
 * @param mixed  $default_value Value to use when the option does not exist.
 * @return OODBBean             Existing or freshly dispensed (unsaved) bean.
 */
function get_option(string $name, mixed $default_value): OODBBean
{
    $bean = R::findOneOrDispense('option', ' name = ? ', [$name]);
    $bean->name = $name;
    if ($bean->id === 0) {
        $bean->value = $default_value;
    }

    return $bean;
}

/**
 * Persists the given value into an option bean.
 *
 * @param OODBBean $bean  The option bean to update.
 * @param mixed    $value New value to store.
 * @return void
 */
function set_option(OODBBean $bean, mixed $value): void
{
    $bean->value = $value;
    try {
        R::store($bean);
    } catch (SQL $e) {
        user_error($e->getMessage(), E_USER_ERROR);
    }
}

/**
 * Returns the SQL fragment and bound parameter that exclude posts scheduled for
 * the future. A post is publicly visible only once its `created` date has arrived.
 *
 * The current time is bound as a parameter so the comparison uses the same
 * timezone basis as the stored `created` values (both produced by PHP's date()).
 *
 * @return array{sql: string, params: array}
 */
function not_scheduled_clause(): array
{
    return [
        'sql'    => ' (created IS NULL OR created <= ?) ',
        'params' => [date('Y-m-d H:i:s')],
    ];
}

/**
 * Returns the SQL fragment and bound parameters selecting only posts that are
 * publicly visible to anonymous visitors: not draft, not deleted, and not
 * scheduled for the future.
 *
 * This is the single allow-list definition of "visible". Every public listing
 * query should use it instead of re-assembling SQL_PUBLISHED with
 * not_scheduled_clause(), so a new query cannot accidentally omit one of the
 * conditions (which is how scheduled posts leaked into related posts).
 *
 * @return array{sql: string, params: array}
 */
function visible_clause(): array
{
    $not_scheduled = not_scheduled_clause();
    return [
        'sql'    => SQL_PUBLISHED . 'AND' . $not_scheduled['sql'],
        'params' => $not_scheduled['params'],
    ];
}

/**
 * Rewrites the `created` value inside a body's leading YAML front-matter block to
 * the given resolved (absolute) datetime, leaving all other front-matter intact.
 *
 * This pins relative phrases (e.g. "next friday") to a fixed timestamp the first
 * time a post is saved, so later edits re-resolve a stable absolute date rather
 * than drifting to a new "next friday" relative to the edit time. Bodies without a
 * front-matter block, or whose `created` is already the resolved value, are
 * returned unchanged (no cosmetic churn).
 *
 * @param string $body     The raw post body.
 * @param string $resolved The canonical `Y-m-d H:i:s` value to persist.
 * @return string The body with its front-matter `created` pinned.
 */
function persist_resolved_created(string $body, string $resolved): string
{
    // Only touch a front-matter block at the very start of the body.
    if (!preg_match('/\A(\s*---\s*\n)(.*?\n)(---\s*\n?)/s', $body, $m)) {
        return $body;
    }

    $new_yaml = preg_replace_callback(
        '/^([ \t]*created[ \t]*:)[ \t]*(.*?)[ \t]*$/mi',
        function (array $line) use ($resolved): string {
            $current = trim($line[2], " \t'\"");
            if ($current === $resolved) {
                return $line[0];
            }
            return $line[1] . " '" . $resolved . "'";
        },
        $m[2],
        1,
        $count
    );

    if ($count === 0) {
        return $body;
    }

    return $m[1] . $new_yaml . $m[3] . substr($body, strlen($m[0]));
}

/**
 * Normalises a date value (YAML timestamp int, DateTime, or parseable string)
 * into the canonical `Y-m-d H:i:s` string used throughout the app.
 *
 * @param mixed $value The raw date value.
 * @return string|null The normalised string, or null when the value is unparseable.
 */
function normalize_datetime(mixed $value): ?string
{
    if ($value instanceof \DateTimeInterface) {
        return $value->format('Y-m-d H:i:s');
    }
    if (is_int($value)) {
        return date('Y-m-d H:i:s', $value);
    }
    if (is_string($value) && $value !== '') {
        $timestamp = strtotime($value);
        if ($timestamp !== false) {
            return date('Y-m-d H:i:s', $timestamp);
        }
    }
    return null;
}

/**
 * Returns true when the post's `created` date lies in the future (scheduled).
 *
 * @param OODBBean $post The post to inspect.
 * @return bool
 */
function is_scheduled(OODBBean $post): bool
{
    return !empty($post->created) && $post->created > date('Y-m-d H:i:s');
}

/**
 * Returns true when a post may be shown for a direct permalink request
 * (/status/<id> or a slug URL).
 *
 * Deleted posts are never visible. Drafts and posts scheduled for the future
 * are visible only to the logged-in author, so they can preview their own
 * work by permalink; anonymous visitors get a 404. This is the single-post
 * counterpart to visible_clause() (the SQL allow-list for listings), with the
 * added logged-in preview exception.
 *
 * @param OODBBean $post The post to inspect (an unsaved/missing bean has id 0).
 * @return bool
 */
function is_visible(OODBBean $post): bool
{
    if (empty($post->id) || $post->deleted == 1) {
        return false;
    }
    if (isset($_SESSION[SESSION_LOGIN])) {
        return true;
    }
    return $post->draft != 1 && !is_scheduled($post);
}

/**
 * Returns true when the supplied preview token grants access to an
 * unpublished post's permalink.
 *
 * Micropub createCallback appends ?preview=<token> to the Location header for
 * draft/scheduled posts so the creating client can show the post it just made
 * without a Lamb session (see issue #285). Tokens are random per-post secrets
 * with an expiry; deleted posts never match.
 *
 * @param OODBBean    $post  The post to inspect.
 * @param string|null $token The supplied preview token (e.g. $_GET['preview']).
 * @return bool
 */
function preview_token_valid(OODBBean $post, ?string $token): bool
{
    if (empty($post->id) || $post->deleted == 1) {
        return false;
    }
    if (empty($post->preview_token) || $token === null || $token === '') {
        return false;
    }
    if (empty($post->preview_token_expires) || strtotime($post->preview_token_expires) < time()) {
        return false;
    }

    return hash_equals((string) $post->preview_token, $token);
}

/**
 * Checks if a post with the given slug exists in the database.
 *
 * @param string $lookup The slug of the post to look up.
 *
 * @return string|null The slug of the post if it exists, otherwise null.
 */
function post_has_slug(string $lookup): string|null
{
    $post = R::findOne('post', ' slug = ? ', [$lookup]);
    if ($post === null || $post->id === 0) {
        return '';
    }
    $unpublished = $post->draft == 1 || is_scheduled($post);
    if ($unpublished && !preview_token_valid($post, $_GET['preview'] ?? null)) {
        return '';
    }

    return $post->slug;
}

/**
 * Deletes any redirect stored in the DB for the given slug.
 *
 * @param string $slug The from_slug to remove.
 * @return void
 */
function delete_redirect_for_slug(string $slug): void
{
    $existing = R::findOne('redirect', ' from_slug = ? ', [$slug]);
    if ($existing !== null) {
        R::trash($existing);
    }
}

/**
 * Returns the redirect destination for a given slug, or null if none exists.
 *
 * Checks manual redirections from config first, then automatic redirects stored in the DB.
 *
 * @param string $slug The URL path segment to look up.
 * @return string|null The destination URL, or null if no redirect is configured.
 */
function find_redirect(string $slug): ?string
{
    global $config;

    $redirections = $config['redirections'] ?? [];
    if (isset($redirections[$slug])) {
        $dest = $redirections[$slug];
        if (str_starts_with($dest, 'http') || str_starts_with($dest, '/')) {
            return $dest;
        }
        return '/' . $dest;
    }

    $redirect = R::findOne('redirect', ' from_slug = ? ', [$slug]);
    if ($redirect !== null) {
        return $redirect->to_url;
    }

    return null;
}

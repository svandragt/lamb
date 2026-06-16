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

use function Lamb\Post\consume_leading_heading;
use function Lamb\Post\normalize_frontmatter_fence;
use function Lamb\Post\parse_matter;
use function Lamb\Post\set_matter;
use function Lamb\Post\split_frontmatter;

/**
 * Returns the current time in the canonical `Y-m-d H:i:s` format used for every
 * datetime column in the app, so the format (and timezone basis) lives in one place.
 *
 * @return string The current datetime string.
 */
function now(): string
{
    return date('Y-m-d H:i:s');
}

// Matches a #hashtag preceded by start-of-string, whitespace, or a closing tag
// bracket. Capture 1 is the preceding character, capture 2 the tag name.
const TAG_PATTERN = '/(^|[\s>])#([^\s#&.,!?;:()\[\]{}<]+)/u';

/**
 * Retrieves the tags from the given HTML.
 *
 * @param string $html The HTML content to search for tags.
 *
 * @return list<string> An array of tags found in the HTML.
 */
function get_tags(string $html): array
{
    preg_match_all(TAG_PATTERN, $html, $matches);

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
    return preg_replace_callback(TAG_PATTERN, function ($matches) {
        return $matches[1] . '<a href="/tag/' . strtolower($matches[2]) . '">#' . $matches[2] . '</a>';
    }, $html) ?? $html;
}

/**
 * Appends the given tags to a body as trailing hashtags, skipping ones the
 * body already carries. Returns the body unchanged when nothing is missing.
 *
 * Counterpart of get_tags(): used by Micropub category `add` updates, where
 * categories live in the body as hashtags rather than in a column.
 *
 * @param string       $body The raw post body.
 * @param list<string> $tags Tag names (without `#`).
 * @return string The body with missing tags appended.
 */
function add_body_tags(string $body, array $tags): string
{
    $to_add = array_diff($tags, get_tags($body));
    if (empty($to_add)) {
        return $body;
    }
    $hashtags = implode(' ', array_map(fn($tag) => '#' . $tag, $to_add));

    return rtrim($body) . ' ' . $hashtags;
}

/**
 * Removes the run of trailing hashtags from a body, leaving inline tags alone.
 *
 * Used by Micropub category delete-property updates (drop all categories).
 *
 * @param string $body The raw post body.
 * @return string The body without its trailing hashtag run.
 */
function strip_trailing_body_tags(string $body): string
{
    return rtrim(preg_replace('/(\s+#[^\s#.,!?;:()\[\]{}<]+)+$/u', '', $body) ?? $body);
}

/**
 * Removes the named hashtags (and their preceding whitespace) from a body,
 * wherever they appear. Used by Micropub category delete-values updates.
 *
 * @param string       $body The raw post body.
 * @param list<string> $tags Tag names (without `#`) to remove.
 * @return string The body without the named tags.
 */
function remove_body_tags(string $body, array $tags): string
{
    foreach ($tags as $tag) {
        $body = preg_replace('/(\s+)#' . preg_quote($tag, '/') . '(?=\s|$)/u', '', $body) ?? $body;
    }

    return $body;
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
    // Restore an iOS Smart-Punctuation front-matter fence (e.g. a single em
    // dash for a typed `---`) before parsing, and persist the normalised body.
    // This is the single choke point every save path runs through — web
    // create/edit, Micropub create/update, feed ingestion, upgrade re-parse —
    // so the recovery is not limited to populate_bean(). Idempotent for bodies
    // already using a literal `---` fence.
    $bean->body = normalize_frontmatter_fence($bean->body);

    // Promote a leading `# Heading` to the post title when no front-matter title
    // exists, so the body's document title isn't rendered as a duplicate heading
    // inside the content. Runs at the same choke point as the fence recovery, so
    // every save path benefits, and is idempotent once the title is in front
    // matter.
    $bean->body = consume_leading_heading($bean->body);

    $markdown = render_body($bean->body);

    $front_matter = parse_matter($bean->body);
    // A hand-written `summary` (or `description`) in front matter wins over the
    // auto-extracted first line; both feed the post's `description` column, which
    // drives the feeds and the OpenGraph/meta tags.
    $front_matter['description'] = front_matter_summary($front_matter) ?? extract_description($markdown);
    // The summary is stored as the description, so drop the raw `summary` key
    // before apply_frontmatter()'s blind copy persists it as a stray column.
    unset($front_matter['summary']);
    $front_matter['transformed'] = highlight_and_link($markdown);

    // Capture the existing created date before apply_frontmatter() blind-copies the
    // raw front-matter `created` value over it, so apply_scheduling() can fall back
    // to the prior date when the front-matter value is unparseable.
    $previous_created = $bean->created;
    apply_frontmatter($bean, $front_matter);
    apply_scheduling($bean, $front_matter, $previous_created);
}

/**
 * Renders the Markdown body (everything after the leading front-matter block)
 * to HTML via LambDown with safe mode enabled. A `---` in the body itself — a
 * horizontal rule, a diff line, or `---` inside a fenced code block — is left
 * for the Markdown parser to handle rather than being mistaken for a fence.
 *
 * @param string $body The raw post body, optionally prefixed by front matter.
 * @return string The rendered HTML.
 *
 * @internal Decomposed step of parse_bean(); not part of the public API.
 */
function render_body(string $body): string
{
    [, $content] = split_frontmatter($body);
    $parser = new LambDown();
    $parser->setSafeMode(true);

    return $parser->text(trim($content));
}

/**
 * Extracts a plain-text, single-line description from rendered post HTML.
 *
 * @param string $markdown The rendered post HTML.
 * @return string The first line of stripped, entity-decoded text.
 *
 * @internal Decomposed step of parse_bean(); not part of the public API.
 */
function extract_description(string $markdown): string
{
    $description = strtok(strip_tags($markdown), "\n") ?: '';
    // Decode twice: feed-ingested bodies already contain HTML entities (e.g. `&#039;`),
    // which Parsedown then re-encodes (`&amp;#039;`). A single decode only undoes one layer.
    $description = html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8');

    return html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8');
}

/**
 * Returns the author's hand-written summary from front matter, or null when none.
 *
 * Accepts `summary` (the canonical key) and `description` as an alias;
 * parse_matter() has already lower-cased the keys and converted underscores to
 * dashes. A whitespace-only value is treated as absent, so an empty line falls
 * back to the auto-generated description.
 *
 * @param array<int|string, mixed> $front_matter The parsed front matter.
 * @return string|null The trimmed manual summary, or null to fall back to auto.
 *
 * @internal Decomposed step of parse_bean(); not part of the public API.
 */
function front_matter_summary(array $front_matter): ?string
{
    foreach (['summary', 'description'] as $key) {
        $value = $front_matter[$key] ?? null;
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
    }

    return null;
}

/**
 * Hashtag-links the rendered HTML while server-side highlighting fenced code.
 *
 * Code blocks are pulled out before hashtag linking so a `#comment` or a
 * highlighter colour like `style="color: #005cc5"` can never become a /tag/
 * link, then highlighted and restored so visitors get pre-rendered markup.
 * The extract/restore order is load-bearing: linking must run on the
 * code-free HTML, and the highlighted blocks are spliced back afterwards.
 *
 * @param string $markdown The rendered post HTML.
 * @return string The HTML with hashtag links and highlighted code blocks.
 *
 * @internal Decomposed step of parse_bean(); not part of the public API.
 */
function highlight_and_link(string $markdown): string
{
    [$markdown_without_code, $code_blocks] = Highlight\extract_code_blocks($markdown);
    $code_blocks = array_map('Lamb\Highlight\highlight_code_blocks', $code_blocks);

    return Highlight\restore_code_blocks(parse_tags($markdown_without_code), $code_blocks);
}

/**
 * Normalises the reply target from front matter into a single string.
 *
 * Reads the `in-reply-to` key (parse_matter() has already canonicalised the
 * `in_reply_to` spelling onto it), collapsing a YAML list to its first entry.
 * The key is removed from the passed-by-reference front matter so the
 * hyphenated key is never written as an invalid column by the blind copy in
 * apply_frontmatter().
 *
 * @param array<int|string, mixed> $front_matter The parsed front matter, modified in place.
 * @return string The normalised reply target, or '' when absent.
 *
 * @internal Decomposed step of parse_bean(); not part of the public API.
 */
function normalize_in_reply_to(array &$front_matter): string
{
    $in_reply_to = $front_matter['in-reply-to'] ?? null;
    unset($front_matter['in-reply-to']);
    if (is_array($in_reply_to)) {
        $in_reply_to = $in_reply_to[0] ?? null;
    }

    return is_string($in_reply_to) ? trim($in_reply_to) : '';
}

/**
 * Applies non-date front-matter fields onto the bean.
 *
 * Resets `in_reply_to`, `title`, and `draft` to their defaults when absent so
 * removing a line on edit clears the stored value, then copies the remaining
 * front-matter keys. Keys that are not valid bean column names (e.g. a
 * normalised multi-word key like `reading-time`) are skipped rather than
 * written, since RedBean rejects them — only the recognised single-word fields
 * map to columns. Date normalisation is handled separately by
 * apply_scheduling().
 *
 * @param OODBBean                  $bean         The bean to mutate.
 * @param array<int|string, mixed>  $front_matter The parsed front matter (including derived keys).
 * @return void
 *
 * @internal Decomposed step of parse_bean(); not part of the public API.
 */
function apply_frontmatter(OODBBean $bean, array $front_matter): void
{
    // Normalise the reply target. Empty when absent, so removing it from front
    // matter on edit clears the stored value.
    $bean->in_reply_to = normalize_in_reply_to($front_matter);

    // Reset the title to empty when it is absent from front matter, so removing
    // the `title:` line (or all front matter) on an edit clears a previously
    // stored title. The additive loop below only ever sets keys that are
    // present, so without this an old title would survive every save.
    $bean->title = isset($front_matter['title']) ? (string) $front_matter['title'] : '';

    foreach ($front_matter as $key => $value) {
        // RedBean only accepts word-character property names. Skip anything
        // else (e.g. a normalised `reading-time`) so it never reaches a store.
        if (!is_string($key) || !preg_match('/\A\w+\z/', $key)) {
            continue;
        }
        $bean->$key = $value;
    }

    // Explicitly normalise draft: 1 if truthy in frontmatter, 0 otherwise.
    // This ensures removing "draft: true" from frontmatter publishes the post on next save.
    $bean->draft = !empty($front_matter['draft']) ? 1 : 0;
}

/**
 * Normalises a front-matter `created` date onto the bean and schedules accordingly.
 *
 * Accepts absolute dates (kept as the typed wall-clock) and relative strings such
 * as "next friday 3pm" (resolved in the server timezone). A future date schedules
 * the post; an unparseable value falls back to the date already on the bean so the
 * post still publishes rather than staying hidden forever. The resolved date is
 * pinned back into the body so relative phrases don't drift on the next edit.
 *
 * Must run after apply_frontmatter(), which has already blind-copied the raw
 * `created` value onto the bean. The previous (pre-blind-copy) created date is
 * passed in by parse_bean() so the unparseable-date fallback uses the genuine
 * prior value rather than the raw front-matter string now sitting on the bean.
 *
 * @param OODBBean                 $bean             The bean to mutate.
 * @param array<int|string, mixed> $front_matter     The parsed front matter.
 * @param mixed                    $previous_created The created date held before apply_frontmatter().
 * @return void
 *
 * @internal Decomposed step of parse_bean(); not part of the public API.
 */
function apply_scheduling(OODBBean $bean, array $front_matter, mixed $previous_created): void
{
    if (!isset($front_matter['created'])) {
        return;
    }

    // Preserve the existing created date (now for new posts, the stored value for
    // edits, the feed date for ingested items) so an unparseable front-matter date
    // falls back to it rather than leaving a non-date string in the column.
    $bean->created = normalize_datetime($front_matter['created'])
        ?? ($previous_created ?: now());
    // Pin the resolved date back into the body so relative phrases like
    // "next friday" don't drift to a new date on the next edit.
    $bean->body = persist_resolved_created($bean->body, $bean->created);
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
 * @return array{sql: string, params: array<int, string>}
 */
function not_scheduled_clause(): array
{
    return [
        'sql'    => ' (created IS NULL OR created <= ?) ',
        'params' => [now()],
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
 * @return array{sql: string, params: array<int, string>}
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
    return set_matter($body, 'created', $resolved, quote: true, append: false);
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
    return !empty($post->created) && $post->created > now();
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
function is_viewable(OODBBean $post): bool
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
 * Issues a preview token on a draft or scheduled post when it has none, or
 * when the existing one has expired. Published posts are left untouched.
 *
 * Shared by every way a draft can be made — Micropub createCallback and the
 * web editor's create/edit handlers — so all unpublished posts get a working
 * ?preview= link (see issues #285 and #373). The caller stores the bean.
 *
 * @param OODBBean $post The post to (maybe) stamp with a token.
 * @return void
 */
function ensure_preview_token(OODBBean $post): void
{
    if ($post->draft != 1 && !is_scheduled($post)) {
        return;
    }
    $expires = $post->preview_token_expires ?? '';
    if (!empty($post->preview_token) && !empty($expires) && strtotime($expires) >= time()) {
        return;
    }
    $post->preview_token         = bin2hex(random_bytes(16));
    $post->preview_token_expires = date('Y-m-d H:i:s', time() + 86400);
}

/**
 * Fans out publish notifications for a freshly stored post.
 *
 * Both the web form and Micropub run the same two steps after saving: queue
 * outbound webmentions for the post's external links and ping the WebSub hubs.
 * Each callee already skips ineligible posts (drafts, feed items, future-dated
 * scheduled posts), so this is safe to call unconditionally from every save path.
 *
 * @param OODBBean $bean A stored post bean.
 * @return void
 */
function notify_post_subscribers(OODBBean $bean): void
{
    Webmention\enqueue_for_post($bean);
    Websub\ping_for_post($bean);
}

/**
 * Checks if a post with the given slug exists in the database and may be
 * routed for the current request: published posts for everyone, drafts and
 * scheduled posts for the logged-in author (matching is_viewable()) or via a
 * valid preview token.
 *
 * @param string $lookup The slug of the post to look up.
 *
 * @return string|null The slug of the post if it exists, otherwise null.
 */
function post_has_slug(string $lookup): string|null
{
    $post = R::findOne('post', ' slug = ? ', [$lookup]);
    if ($post === null || $post->id === 0) {
        return null;
    }
    if (!is_viewable($post) && !preview_token_valid($post, $_GET['preview'] ?? null)) {
        return null;
    }

    return $post->slug;
}

/**
 * Resolves a permalink path to the post it points at.
 *
 * Recognises the two permalink shapes the app mints: `/status/<id>` for
 * status posts and `/<slug>` for page-like posts. Shared by the Micropub and
 * Webmention endpoints, which both map externally supplied URLs back to posts
 * (each applies its own host policy before/after calling this).
 *
 * @param string $path The URL path (e.g. from parse_url(..., PHP_URL_PATH)).
 * @return OODBBean|null The matching post bean, or null when none exists.
 */
function find_post_by_path(string $path): ?OODBBean
{
    if (preg_match('#^/status/(\d+)$#', $path, $matches)) {
        $bean = R::load('post', (int) $matches[1]);
        return $bean->id ? $bean : null;
    }

    $slug = trim($path, '/');
    if ($slug !== '') {
        return R::findOne('post', ' slug = ? ', [$slug]);
    }

    return null;
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

/**
 * Returns the bare slug a redirect target points at when it can chain to another
 * redirect's `from_slug`, or null when the target is terminal.
 *
 * Only an internal, single-segment target (`/slug`) can chain. External URLs,
 * multi-segment paths (`/a/b`), and the root (`/`) are terminal destinations.
 *
 * @param string $to_url The redirect's stored destination.
 * @return string|null The chainable target slug, or null when terminal.
 */
function redirect_target_slug(string $to_url): ?string
{
    if (!str_starts_with($to_url, '/')) {
        return null;
    }
    $slug = ltrim($to_url, '/');
    if ($slug === '' || str_contains($slug, '/')) {
        return null;
    }

    return $slug;
}

/**
 * Flattens stored redirect chains so each hop points straight at its final
 * destination, breaks loops, and drops redirects whose destination no longer
 * resolves to a post. Intended to run as periodic maintenance from `/_cron`.
 *
 * - A chain `a → /b`, `b → /c` is rewritten so `a → /c` (one 301 instead of two).
 * - A loop (`a → /b`, `b → /a`) cannot resolve, so its rows are deleted.
 * - A redirect whose final single-segment destination has no post is deleted,
 *   unless a soft-deleted (trashed) post still holds that slug — it may be
 *   restored, so the redirect is kept.
 *
 * @return int The number of redirect rows rewritten or deleted.
 */
function flatten_redirects(): int
{
    $redirects = R::findAll('redirect');

    /** @var array<string, \RedBeanPHP\OODBBean> $by_from */
    $by_from = [];
    foreach ($redirects as $redirect) {
        if (is_string($redirect->from_slug) && $redirect->from_slug !== '') {
            $by_from[$redirect->from_slug] = $redirect;
        }
    }

    $delete  = [];
    $changes = 0;

    // 1. Resolve each redirect to its final destination, collecting loop members.
    foreach ($by_from as $from => $redirect) {
        $path  = [];
        $index = [];
        $node  = $from;
        $final = $redirect->to_url;
        $loop  = null;

        while (true) {
            $index[$node] = count($path);
            $path[]       = $node;
            $final        = $by_from[$node]->to_url;

            $target = redirect_target_slug((string) $by_from[$node]->to_url);
            if ($target === null || !isset($by_from[$target])) {
                break;
            }
            if (isset($index[$target])) {
                $loop = $target;
                break;
            }
            $node = $target;
        }

        if ($loop !== null) {
            for ($i = $index[$loop]; $i < count($path); $i++) {
                $delete[$path[$i]] = $by_from[$path[$i]];
            }
            continue;
        }

        if ($final !== $redirect->to_url) {
            $redirect->to_url = $final;
            R::store($redirect);
            $changes++;
        }
    }

    // 2. Drop redirects whose final destination resolves to no post, keeping
    //    those whose slug is still held by a trashed (restorable) post.
    foreach ($by_from as $from => $redirect) {
        if (isset($delete[$from])) {
            continue;
        }
        $target = redirect_target_slug((string) $redirect->to_url);
        if ($target === null) {
            continue;
        }
        if (R::findOne('post', ' slug = ? ', [$target]) === null) {
            $delete[$from] = $redirect;
        }
    }

    foreach ($delete as $redirect) {
        R::trash($redirect);
        $changes++;
    }

    return $changes;
}

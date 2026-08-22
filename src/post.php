<?php

namespace Lamb\Post;

use Lamb\Network\JsonFeedItem;
use RedBeanPHP\OODBBean;
use RedBeanPHP\R;
use SimplePie\Item;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

use function Lamb\Http\is_valid_http_url;
use function Lamb\parse_bean;
use function Lamb\Route\is_reserved_route;

/**
 * Populates and returns an OODBBean instance with the given text and optional feed information.
 *
 * @param string $text The text content to be set in the bean.
 * @param Item|JsonFeedItem|null $feed_item An optional feed item to extract creation date and ID from.
 * @param string|null $feed_name An optional feed name to prefix the slug and associate with the bean.
 * @param OODBBean|null $bean An optional existing bean to populate. If null, a new 'post' bean is dispensed.
 * @return OODBBean The populated bean instance.
 * @noinspection CallableParameterUseCaseInTypeContextInspection
 */
function populate_bean(string $text, Item|JsonFeedItem|null $feed_item = null, ?string $feed_name = null, ?OODBBean $bean = null): OODBBean
{
    global $config;

    $text = normalize_frontmatter_fence($text);
    $matter = parse_matter($text);

    if ($bean === null) {
        $bean = R::dispense('post');
    }
    $bean->body = $text;
    $bean->slug = $matter['slug'] ?? '';
    // Stamp `created` only when the row is new. A re-sync (feed cron update, or a
    // manual edit) keeps the stored date, so an upstream entry being renamed —
    // which bumps its feed date and trips update_item() — no longer re-dates the
    // post to now and reorders created-sorted listings. `updated` always tracks
    // the current write. Front matter can still override created via apply_scheduling().
    $is_new = empty($bean->id);
    if ($is_new) {
        $bean->created = \Lamb\now();
    }
    $bean->updated = \Lamb\now();
    if ($feed_item) {
        if ($is_new) {
            $bean->created = $feed_item->get_date("Y-m-d H:i:s");
        }
        $bean->updated = $feed_item->get_updated_date("Y-m-d H:i:s");
        if ($feed_name) {
            $bean->feeditem_uuid = md5($feed_name . $feed_item->get_id());
            $bean->feed_name = $feed_name;
        }
        // A feed item's permalink is attacker-influenced (any feed the site
        // subscribes to can supply it) and is later rendered into an <a href>
        // by Theme\link_source() — reject anything that isn't a well-formed
        // http(s) URL so a `javascript:`/other-scheme permalink can't reach
        // that attribute unescaped for scheme.
        $permalink = (string) $feed_item->get_permalink();
        $bean->source_url = is_valid_http_url($permalink) ? $permalink : null;
    }

    parse_bean($bean);
    // Prefix a title-derived feed-item slug with the feed name so same-titled
    // posts from different feeds don't collide. Applied after parse_bean(),
    // whose front-matter loop re-derives the slug from the title and would
    // otherwise clobber the prefix (issue #332). An explicit front-matter slug
    // (pinned by finalize_slug() on first save) is authoritative and must not
    // be prefixed again on cron updates.
    $derived = isset($matter['title']) && $bean->slug === slugify((string) $matter['title']);
    if ($feed_item && $feed_name && $bean->slug && $derived) {
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
 * with a dash-only fence line (two or more dash-like characters) and has a
 * matching closing fence line, both are normalised back to a literal `---`. A
 * single dash-like character is never treated as a fence: a lone em/en dash is
 * ordinary punctuation (or a thematic break) far more often than a mangled
 * fence, and a lone hyphen could swallow body content. Dashes anywhere else
 * (post body, em-dash punctuation, signatures) are left untouched, and the
 * surrounding whitespace and line endings are preserved.
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
 * Splits a body into its leading YAML front-matter block and the remaining
 * content.
 *
 * Front matter is recognised only as a *leading* fenced block: a `---` line at
 * the very start of the body, the YAML up to the next `---` line on its own,
 * and everything after that as content. A `---` anywhere else — a Markdown
 * horizontal rule, a `--- a/file` diff line, or `---` inside a fenced code
 * block — is body and is preserved verbatim. Bodies without a leading fence
 * return an empty YAML string and the body unchanged.
 *
 * @param string $body The raw post body.
 * @return array{0: string, 1: string} The YAML block (without fences) and the content.
 */
function split_frontmatter(string $body): array
{
    if (preg_match('/\A---[ \t]*\R(.*?)\R?---[ \t]*(?:\R|\z)/s', $body, $m)) {
        return [$m[1], substr($body, strlen($m[0]))];
    }

    return ['', $body];
}

/**
 * Parses a string body for YAML front matter and returns an associative array of the extracted metadata.
 *
 * @param string $body The string containing the content with optional YAML front matter delimited by '---'.
 * @return array<int|string, mixed> An associative array of parsed YAML metadata. Returns an empty array if the YAML is invalid or absent.
 */
function parse_matter(string $body): array
{
    [$yaml] = split_frontmatter($body);
    if ($yaml === '') {
        return [];
    }

    try {
        // PARSE_DATETIME keeps absolute dates as DateTime objects carrying the
        // author's typed wall-clock time, instead of coercing them to UTC Unix
        // timestamps (which would shift the time by the server's timezone offset).
        $matter = Yaml::parse($yaml, Yaml::PARSE_DATETIME);
    } catch (ParseException) {
        // Invalid YAML
        return [];
    }

    // There is no matter.
    if (!is_array($matter)) {
        return [];
    }

    $matter = normalize_matter_keys($matter);
    $matter = normalize_matter_values($matter);

    // normalize_matter_values() has already made both of these a string when
    // present; the casts keep the static analyser's `mixed` narrowing happy.
    if (isset($matter['slug'])) {
        $matter['slug'] = sanitize_explicit_slug((string) $matter['slug']);
    }

    if (isset($matter['title']) && !isset($matter['slug'])) {
        $matter['slug'] = slugify((string) $matter['title']);
    }

    return $matter;
}

/**
 * The front-matter keys the rest of the codebase reads as plain text.
 *
 * These are the values normalize_matter_values() coerces to a string (or drops
 * as absent). Keys outside this list — `created`, `draft` — carry their own
 * type handling downstream and are left as YAML parsed them.
 */
const MATTER_TEXT_KEYS = ['title', 'slug', 'summary', 'description', 'in-reply-to', 'syndicated-to'];

/**
 * Coerces a front-matter value to the string its readers assume, or null when
 * it has no faithful textual form.
 *
 * YAML is a typed format and the author picks the type by how they write the
 * line: `title: [a, b]` is a list, `title: 2024-01-02` is a date object (front
 * matter is parsed with PARSE_DATETIME), `title: yes` is a boolean. Everything
 * downstream — slugify(), the `(string)` casts in apply_frontmatter(), the
 * `?string` parameters in the Micropub adapter — expects a string, and PHP 8
 * turns that mismatch into a fatal rather than a warning, so one mistyped line
 * took down the whole save. Where it did not, an array cast to the literal
 * string "Array" and was stored as the post's title or slug.
 *
 * The coercion:
 *  - strings, integers and floats become their textual form;
 *  - a list collapses to its first entry — the shape `in-reply-to` already
 *    accepted, generalised, so `title: [a, b]` reads as `a`;
 *  - dates are formatted back to the wall-clock text the author typed;
 *  - a map, a nested list, a boolean or null have no faithful text (neither
 *    "1" nor "true" is what `title: yes` meant), so they are reported as
 *    absent and the caller's own fallback applies.
 *
 * @param mixed $value The raw front-matter value.
 * @return string|null The value as text, or null when it has none.
 */
function matter_string(mixed $value): ?string
{
    if (is_array($value)) {
        // One level only: the first entry of a list of lists is still not text.
        $value = array_is_list($value) ? ($value[0] ?? null) : null;
    }
    if (is_string($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return (string) $value;
    }
    if ($value instanceof \DateTimeInterface) {
        // A bare `2024-01-02` parses to midnight; render it back without the
        // time the author never typed.
        return $value->format($value->format('H:i:s') === '00:00:00' ? 'Y-m-d' : 'Y-m-d H:i:s');
    }

    return null;
}

/**
 * Normalises the textual front-matter values to strings before they are matched.
 *
 * The companion to normalize_matter_keys(): that one canonicalises how a key is
 * spelled, this one canonicalises what its value is. Running both inside
 * parse_matter() makes it the single place the rest of the codebase has to
 * trust — every consumer of MATTER_TEXT_KEYS gets a string or nothing, and a
 * key whose value has no textual form is removed so `isset()` reports it as
 * absent rather than handing on an array.
 *
 * @param array<int|string, mixed> $matter The parsed front matter.
 * @return array<int|string, mixed> The front matter with textual values coerced.
 */
function normalize_matter_values(array $matter): array
{
    foreach (MATTER_TEXT_KEYS as $key) {
        if (!array_key_exists($key, $matter)) {
            continue;
        }
        $text = matter_string($matter[$key]);
        if ($text === null) {
            unset($matter[$key]);
            continue;
        }
        $matter[$key] = $text;
    }

    return $matter;
}

/**
 * Reduces an explicit front-matter `slug:` value to the single URL path
 * segment a post is served under.
 *
 * An explicit slug (unlike a title-derived one, which goes through slugify())
 * is stored close to verbatim, and two things then read it as a path:
 *
 * - It feeds an automatic redirect's `to_url` on a subsequent slug change
 *   (`'/' . $slug`, in redirect_edited()). A slug beginning with `/` (or `\`,
 *   which some browsers normalise to `/` in a URL) would make that
 *   concatenation a protocol-relative `//host/...` (or `/\host/...`) URL — an
 *   open redirect off the site. Hence the leading separators go first.
 * - permalink() serves the post at `/<slug>`, and the router matches a post
 *   against the request's *first* path segment. A slug with a separator inside
 *   it (`archive/2024`) therefore names a URL that can never route back to the
 *   post: it saves, every link on the site points at it, and it 404s. The
 *   remaining separators become hyphens so the stored slug is the URL the post
 *   actually answers on.
 *
 * Sanitised rather than rejected outright so a post with a stray slash in its
 * slug still saves.
 *
 * @param string $slug
 * @return string
 */
function sanitize_explicit_slug(string $slug): string
{
    return str_replace(['/', '\\'], '-', ltrim($slug, "/\\"));
}

/**
 * Normalises front-matter keys to a canonical form before they are matched.
 *
 * String keys are lower-cased and underscores are converted to dashes, so
 * `Title`, `TITLE`, `in_reply_to` and `In-Reply-To` all collapse onto the
 * canonical `title` / `in-reply-to` keys the rest of the codebase matches on.
 * This smooths over mobile keyboards that auto-capitalise the first letter of a
 * line and over the underscore/dash spelling ambiguity. Non-string keys (YAML
 * sequence indices) are left untouched. When two source keys normalise to the
 * same canonical key, the later one wins.
 *
 * @param array<int|string, mixed> $matter The parsed front matter.
 * @return array<int|string, mixed> The front matter with canonicalised keys.
 */
function normalize_matter_keys(array $matter): array
{
    $normalized = [];
    foreach ($matter as $key => $value) {
        if (is_string($key)) {
            $key = str_replace('_', '-', strtolower($key));
        }
        $normalized[$key] = $value;
    }

    return $normalized;
}

/**
 * Consumes a leading ATX heading as the post title.
 *
 * When a body has no front-matter `title` but its content opens with an ATX
 * heading (the Markdown document-title convention), that line is removed from
 * the content and its text written into the body's front matter as `title:`
 * via inject_title_matter() (which creates a front-matter block when the body
 * has none, or inserts the title into an existing block). The derived slug then
 * flows through the normal parse_matter()/finalize_slug() path, so slug pinning
 * and collision handling need no special casing.
 *
 * Any leading heading level is recognised (`#` through `######`): the level the
 * author types for the title is immaterial, the first heading is the title. A
 * heading that is not the first content is left in place. Idempotent — once the
 * title is in front matter, a second call is a no-op.
 *
 * @param string $body The raw post body.
 * @return string The body with a leading heading promoted to the title, or the
 *                body unchanged when no leading heading is present.
 */
function consume_leading_heading(string $body): string
{
    if (isset(parse_matter($body)['title'])) {
        return $body;
    }

    [, $content] = split_frontmatter($body);
    if (!preg_match('/\A\s*#{1,6}[ \t]+(.+?)[ \t]*(?:\R|\z)/', $content, $m)) {
        return $body;
    }

    $title = $m[1];
    $prefix = substr($body, 0, strlen($body) - strlen($content));
    $remaining = ltrim(substr($content, strlen($m[0])), "\r\n");

    return inject_title_matter($prefix . $remaining, $title);
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
 * An existing block is recognised by the same fence split_frontmatter() reads,
 * not by a literal `---\n`: a browser normalises a <textarea> to CRLF on
 * submit, so every body saved from the edit form arrives with `---\r\n`
 * fences. Matching only the LF spelling took those bodies down the
 * create-a-block branch and prepended a *second* front-matter block — the
 * author's real one (with its `draft:`, `slug:` and `created:` lines) became
 * body text, so parse_bean() saw no `draft` key and published the post.
 * The block's own line ending is reused for the inserted line.
 *
 * @param string $body The post body, with or without existing front matter.
 * @param string $title The title to write into the front matter.
 * @return string The body with the title present in its front matter.
 */
function inject_title_matter(string $body, string $title): string
{
    $title_line = rtrim(Yaml::dump(['title' => $title]), "\n");
    // has_frontmatter(), not a non-empty YAML block: `---\n---\n` is an empty
    // block, and adding a second one above it is the bug this guards against.
    if (has_frontmatter($body) && preg_match('/\A---[ \t]*(\R)/', $body, $m) === 1) {
        return $m[0] . $title_line . $m[1] . substr($body, strlen($m[0]));
    }

    return "---\n" . $title_line . "\n---\n\n" . $body;
}

/**
 * Whether the body opens with a complete front-matter block.
 *
 * split_frontmatter() cannot answer this on its own: it reports an empty YAML
 * string both for a body with no block at all and for one whose block is empty
 * (`---\n---\n`). The content it returns is the discriminator — it is the body
 * itself only when nothing was split off.
 *
 * @param string $body The raw post body.
 */
function has_frontmatter(string $body): bool
{
    return split_frontmatter($body)[1] !== $body;
}

function slugify(string $text): string
{
    return strtolower(preg_replace('/\W+/m', "-", $text) ?? $text);
}

/**
 * Renders a body from a `key => value` front-matter map plus content.
 *
 * Each pair becomes a `key: value` line inside a leading `---` fence; an empty
 * map returns the content verbatim (no fence). Key order is preserved. This is
 * the single place that assembles a fresh front-matter block from scratch
 * (used by Micropub create/update).
 *
 * @param array<string, string> $matter Ordered front-matter key/value pairs.
 * @param string $content The post content to place after the fence.
 * @return string The assembled body.
 */
function build_matter(array $matter, string $content): string
{
    if ($matter === []) {
        return $content;
    }

    // Yaml::dump() rather than "$key: $value": the values come from Micropub
    // request properties, and an unescaped newline in one (a `name` of
    // "Title\nid: 1") injected extra front-matter keys, which apply_frontmatter()
    // then applied to the bean. It also fixes the mirror-image bug — a title
    // containing a colon produced invalid YAML, so parse_matter() returned
    // nothing and the title was silently dropped.
    return "---\n" . Yaml::dump($matter) . "---\n$content";
}

/**
 * Sets a single key in a body's leading YAML front-matter block, without
 * creating a block and without churning an unchanged save.
 *
 * A thin no-churn/no-create wrapper over set_frontmatter_key(), which is the one
 * engine that mutates a block (splitting and rebuilding through the YAML writer).
 * set_matter() adds two guarantees the every-save callers (persist_slug(),
 * persist_resolved_created()) rely on and that the engine deliberately does not
 * make:
 *
 * - A body with no leading front-matter block is returned unchanged, rather than
 *   gaining one. set_frontmatter_key() would add a block; a status update or a
 *   feed item without front matter must not sprout a `slug:`/`created:` fence.
 * - When the key's line already holds this value, the body is returned
 *   byte-for-byte — including its CRLF line endings. A browser submits a
 *   <textarea> with CRLF, so most bodies reaching here carry them; the `\r` must
 *   not make an unchanged value differ and re-store the post on every save.
 *
 * The value only reaches set_frontmatter_key() when it actually changes, so the
 * engine's rebuild (which quotes anything YAML needs, and cannot leave a stale
 * list behind under a scalar) happens once per real change, never as cosmetic
 * churn. finalize_slug() reaches this by pinning an id-suffixed slug that
 * collides or names a reserved route; apply_scheduling() by pinning a resolved
 * `created` date.
 *
 * @param string $body The raw post body.
 * @param string $key The front-matter key to set.
 * @param string $value The value to write.
 * @param bool $append When true, the key is appended if absent (otherwise the
 *                     body is returned unchanged when the key is missing).
 * @return string The body with the front-matter key set.
 */
function set_matter(string $body, string $key, string $value, bool $append = true): string
{
    // Only touch a front-matter block at the very start of the body.
    [$yaml] = split_frontmatter($body);
    if ($yaml === '') {
        return $body;
    }

    // Read-only probe for the key's column-zero line, in either spelling
    // (parse_matter() folds hyphen/underscore together). This decides no-churn
    // and, for $append === false, whether the key is present — matching the
    // engine's own column-zero, quote-trimming view of the value so an unchanged
    // save returns the body verbatim.
    $pattern = '/^' . str_replace('\-', '[-_]', preg_quote($key, '/')) . '[ \t]*:[ \t]*(.*?)[ \t]*\r?$/mi';
    if (preg_match($pattern, $yaml, $m) === 1) {
        if (trim($m[1], " \t'\"") === $value) {
            return $body;
        }
    } elseif (!$append) {
        return $body;
    }

    return set_frontmatter_key($body, $key, $value);
}

/**
 * Sets (or clears) a single key in a body's leading YAML front-matter block.
 *
 * Surgical on purpose: every other front-matter key — including ones this
 * codebase does not recognise — is carried over verbatim, because the Micropub
 * update path that calls this must not silently drop a hand-written `slug:` or
 * `draft:` while changing one value. A body with no front matter gains a block;
 * a block left with nothing but this key loses its fence again.
 *
 * Both the hyphen and underscore spellings of the key are removed before the
 * new value is appended (parse_matter() normalises them onto one key, so two
 * lines would race for it), and the value is dumped through the YAML writer
 * rather than interpolated, so a newline in it cannot inject further keys.
 *
 * Unlike set_matter(), which rewrites a value in place, this replaces the key
 * outright — so it can also remove one, and cannot leave a stale YAML list
 * behind under a key whose new value is a scalar.
 *
 * @param string $body The raw post body.
 * @param string $key The front-matter key to set, in its hyphenated spelling.
 * @param string $value The value to write, or '' to remove the key.
 * @return string The body with its front-matter key set.
 */
function set_frontmatter_key(string $body, string $key, string $value): string
{
    $body  = normalize_frontmatter_fence($body);
    $value = trim($value);
    [$yaml, $content] = split_frontmatter($body);

    if ($yaml === '') {
        return $value === '' ? $body : build_matter([$key => $value], $body);
    }

    // Matches whichever spelling the author used, the way normalize_matter_keys()
    // folds them together.
    $pattern = '/^' . str_replace('\-', '[-_]', preg_quote($key, '/')) . '[ \t]*:/i';

    $kept = [];
    $dropping = false;
    foreach (preg_split('/\R/', $yaml) ?: [] as $line) {
        // Anchored to column zero: an indented key belongs to whatever block
        // encloses it, and claiming it here took that block's remaining lines
        // with it as "continuations" of a key that was never ours.
        if (preg_match($pattern, $line) === 1) {
            $dropping = true;
            continue;
        }
        // An indented line after the key is its YAML list/continuation: dropping
        // the key but keeping `  - https://…` leaves the block unparseable.
        if ($dropping && preg_match('/^[ \t]+\S/', $line) === 1) {
            continue;
        }
        $dropping = false;
        $kept[] = $line;
    }

    $new_yaml = rtrim(implode("\n", $kept), "\n");
    if ($value !== '') {
        $new_yaml = ($new_yaml === '' ? '' : $new_yaml . "\n") . trim(Yaml::dump([$key => $value]));
    }

    if (trim($new_yaml) === '') {
        return $content;
    }

    return "---\n" . $new_yaml . "\n---\n" . $content;
}

/**
 * Sets (or clears) the reply target in a body's leading YAML front-matter block.
 *
 * @param string $body The raw post body.
 * @param string $value The reply target URL, or '' to remove it.
 * @return string The body with its front-matter reply target set.
 */
function set_reply_to(string $body, string $value): string
{
    return set_frontmatter_key($body, 'in-reply-to', $value);
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
    return set_matter($body, 'slug', $slug);
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
 * Stores a freshly populated post and pins its finalized slug.
 *
 * Every create path follows the same idiom: store once to mint an id (the id is
 * part of any dedup suffix), then finalize_slug() — which reserves/suffixes the
 * slug and pins it into the body's front matter — and re-store only when that
 * changed something. Centralising it keeps the create paths (web form, Micropub,
 * feed ingestion) from drifting apart.
 *
 * @param OODBBean $bean An unsaved or partially-saved post bean.
 * @return void
 */
function finalize_and_store_post(OODBBean $bean): void
{
    R::store($bean);
    if (finalize_slug($bean)) {
        R::store($bean);
    }
}

/**
 * Matches a line that could carry a task-list marker: optional blockquote
 * markers, an optional bullet or ordered list marker, then `[ ]`/`[x]`/`[X]`
 * followed by whitespace and a non-empty label.
 *
 * Whether such a marker actually renders as a checkbox depends on the block it
 * lands in, which only the renderer knows — see toggle_rendered_checkbox().
 *
 * Capture 2 is the state character, so its byte offset locates the single
 * character a toggle rewrites.
 */
const TASK_MARKER_PATTERN = '/^([ \t]*(?:>[ \t]?)*[ \t]*(?:(?:[-*+]|\d{1,9}[.)])[ \t]+)?\[)([ xX])(\][ \t]+\S)/';

/**
 * Every byte offset in $body that could be the state character of a rendered
 * checkbox — a deliberately permissive superset.
 *
 * This is only a candidate list, not an answer: which markers actually become
 * checkboxes is decided by Parsedown's block structure, and that is not
 * something a line scanner can be trusted to reproduce. So this scan skips
 * front matter (never rendered) and otherwise matches every task marker it
 * sees, leaving toggle_rendered_checkbox() to identify the real one by asking
 * the renderer.
 *
 * @param string $body The raw post body (front matter included).
 * @return list<int> Byte offsets of each candidate marker's state character.
 */
function candidate_marker_offsets(string $body): array
{
    [, $content] = split_frontmatter($body);
    $position = strlen($body) - strlen($content);

    $offsets = [];
    foreach (preg_split('/(\R)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $index => $piece) {
        if ($index % 2 === 0 && preg_match(TASK_MARKER_PATTERN, $piece, $m, PREG_OFFSET_CAPTURE) === 1) {
            $offsets[] = $position + (int) $m[2][1];
        }
        $position += strlen($piece);
    }

    return $offsets;
}

/**
 * The checked state of every task checkbox the renderer emits for a body, in
 * the order the toggle endpoint's `data-checkbox-index` counts them.
 *
 * The renderer is the authority on which markers become checkboxes, so this is
 * what toggle_rendered_checkbox() resolves a `data-checkbox-index` against.
 *
 * @param string $body The raw post body (front matter included).
 * @return list<bool> True for each checked box, in document order.
 */
function rendered_checkbox_states(string $body): array
{
    [, $content] = split_frontmatter($body);
    $parser = new \Lamb\LambDown();
    $parser->setSafeMode(true);
    $parser->text(trim($content));

    return $parser->renderedCheckboxStates();
}

/**
 * Rewrites the source marker behind the Nth *rendered* checkbox.
 *
 * The toggle endpoint addresses a checkbox by its rendered position
 * (`data-checkbox-index`, numbered in document order by LambDown::text()), so
 * the marker to rewrite is the one whose flip moves that box and no other. That
 * is a property of the renderer, not of the source text: `    - [ ] x` on the
 * first line is a checkbox (render_body() trims the body, so the indent is
 * gone), the same line inside a list item is a code block, and a scanner that
 * guesses either way flips somebody else's box — or, when the requested state
 * already holds, silently rewrites a `[ ]` inside a code block.
 *
 * So the renderer decides. Each candidate marker is rewritten in turn and the
 * result re-rendered; the marker that produces exactly the requested change,
 * with every other box untouched, is the right one. At most one candidate can
 * satisfy that, because only the marker behind box $index can move it.
 *
 * @param string $body    The raw post body (front matter included).
 * @param int    $index   Zero-based index of the rendered checkbox to toggle.
 * @param bool   $checked True to check the box, false to uncheck it.
 * @return string|null The body with that box toggled, or null when $index names
 *                     no rendered checkbox or no marker accounts for it.
 */
function toggle_rendered_checkbox(string $body, int $index, bool $checked): ?string
{
    if ($index < 0) {
        return null;
    }

    $before = rendered_checkbox_states($body);
    if (!isset($before[$index])) {
        return null;
    }
    // Already in the requested state: nothing to rewrite. Skipping the search
    // matters, because with no state change to look for every candidate would
    // satisfy the check — including one inside a code block.
    if ($before[$index] === $checked) {
        return $body;
    }

    $expected = $before;
    $expected[$index] = $checked;

    foreach (candidate_marker_offsets($body) as $offset) {
        $candidate = substr_replace($body, $checked ? 'x' : ' ', $offset, 1);
        if (rendered_checkbox_states($candidate) === $expected) {
            return $candidate;
        }
    }

    return null;
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
 * @return array{sql: string, params: array<int, string>}
 */
function get_tag_search_conditions(string $tag): array
{
    return [
        'sql'    => "body LIKE ? ESCAPE '\\'",
        'params' => ['%#' . \Lamb\like_escape($tag) . '%'],
    ];
}

/**
 * Returns true if $body contains $tag as a hashtag, using the same boundary
 * rules as the inline tag renderer (Lamb\parse_tags): the tag must follow the
 * start of the string, whitespace, or `>`, and be followed by whitespace, the
 * end of the string, or one of the tag-terminating punctuation characters.
 *
 * The terminator set is Lamb\TAG_TERMINATORS, the same class TAG_PATTERN
 * excludes from a tag name, rather than a second copy of it. The copy had
 * drifted: it was missing `>`, `"`, `'`, a backtick, `=` and both slashes, so a
 * body reading `#php/8` rendered a link to /tag/php — TAG_PATTERN ends the tag
 * at the slash — while this said the post carried no such tag. post_ids_by_tag()
 * and Theme\get_posts_by_tags() both filter on it, so the tag page and the
 * related-posts list left out the very post whose link led there.
 *
 * @param string $tag  The tag to look for (without the leading `#`).
 * @param string $body The raw post body.
 * @return bool
 */
function body_has_tag(string $tag, string $body): bool
{
    $pattern = '/(^|[\s>])#' . preg_quote($tag, '/') . '(?=[' . \Lamb\TAG_TERMINATORS . ']|$)/iu';
    return (bool) preg_match($pattern, $body);
}

/**
 * How many rows a tag scan reads per query.
 *
 * Only ids survive a page, so the page can be generous: it is the number of
 * bodies held at once, and one query per 500 matches keeps the query count
 * reasonable for a tag that really does cover the archive.
 */
const TAG_SCAN_PAGE = 500;

/**
 * The ids of every publicly visible post that really carries $tag, newest first.
 *
 * The LIKE condition get_tag_search_conditions() builds is a superset of a real
 * tag match — `#photographylover` matches `%#photography%`, and body_has_tag()
 * is what decides — so the rows cannot be narrowed to the wanted ones in SQL.
 * They are read a page at a time and only the surviving ids are kept, so one
 * page of bodies is all that is in memory at once.
 *
 * Holding a full bean per match instead made both /tag/<tag> and
 * /tag/<tag>/feed die on a tag that covers a large archive: at 20,000 posts
 * under one tag, "Allowed memory size of 134217728 bytes exhausted" on each,
 * against the default 128M limit both images inherit. Neither endpoint needs
 * more than a page of posts to render — the tag page paginates and the feed
 * takes 20 — so nothing but the id list has to grow with the tag.
 *
 * @param string $tag            The tag to search for within post content.
 * @param bool   $by_updated     Order by `updated` rather than `created` (what
 *                               the tag feeds sort on). The column is chosen
 *                               here, never interpolated from a caller.
 * @param int    $limit          Stop once this many ids have been collected;
 *                               0 collects every match.
 * @return list<int>
 */
function post_ids_by_tag(string $tag, bool $by_updated = false, int $limit = 0): array
{
    if ($limit === 0) {
        return all_post_ids_by_tag($tag, $by_updated ? 'updated' : 'created');
    }

    $conditions = get_tag_search_conditions($tag);
    $public = \Lamb\Response\public_posts_clause();
    $order = $by_updated ? 'updated' : 'created';
    $sql = 'SELECT id, body FROM post WHERE (' . $conditions['sql'] . ') AND' . $public['sql']
        . 'ORDER BY ' . $order . ' DESC LIMIT ? OFFSET ?';
    $params = array_merge($conditions['params'], $public['params']);

    // Past the early return $limit is never 0, so the loop no longer has to
    // carry the "0 means everything" case: it stops on the count alone.
    $ids = [];
    $offset = 0;
    while (count($ids) < $limit) {
        $rows = R::getAll($sql, array_merge($params, [TAG_SCAN_PAGE, $offset]));
        if ($rows === []) {
            break;
        }
        $offset += count($rows);
        foreach ($rows as $row) {
            if (!body_has_tag($tag, (string) ($row['body'] ?? ''))) {
                continue;
            }
            $ids[] = (int) $row['id'];
            if (count($ids) >= $limit) {
                break;
            }
        }
        if (count($rows) < TAG_SCAN_PAGE) {
            break;
        }
    }

    return $ids;
}

/**
 * The ids of *every* publicly visible post carrying $tag, ordered by $column
 * descending — the exhaustive half of post_ids_by_tag().
 *
 * Ordering the scan in SQL is what the limited scan above needs, because it
 * stops as soon as it has its ids and so wants the newest rows in its first
 * page. This scan has to look at every match whatever the order, and asking
 * SQL for one costs it dearly: nothing indexes `created`, so each `ORDER BY
 * created DESC LIMIT ? OFFSET ?` page re-scans and re-sorts the whole table.
 * A tag covering 4,000 of 30,000 posts took eight such pages — 57 ms to
 * produce an id list.
 *
 * So it pages on the rowid instead (`id > ?`, which SQLite seeks straight to)
 * and orders the survivors here. The table is walked once end to end, 57 ms
 * became 12 ms, and one page of bodies is still all that is held at a time.
 * The sort is on ids that survived, not on rows read, so it grows with the tag
 * rather than the archive.
 *
 * `arsort()` is stable (PHP 8), so posts sharing a timestamp come back in
 * ascending id order rather than in whatever order SQLite happened to emit
 * them — the page a reader sees no longer depends on that.
 *
 * @param string $tag    The tag to search for.
 * @param string $column The ordering column, chosen by the caller from a fixed
 *                       pair — never interpolated from request input.
 * @return list<int>
 */
function all_post_ids_by_tag(string $tag, string $column): array
{
    $conditions = get_tag_search_conditions($tag);
    $public = \Lamb\Response\public_posts_clause();
    $sql = 'SELECT id, body, ' . $column . ' AS sort_key FROM post WHERE id > ? AND ('
        . $conditions['sql'] . ') AND' . $public['sql'] . 'ORDER BY id LIMIT ?';
    $params = array_merge($conditions['params'], $public['params']);

    $sort_keys = [];
    $after = 0;
    while (true) {
        $rows = R::getAll($sql, array_merge([$after], $params, [TAG_SCAN_PAGE]));
        if ($rows === []) {
            break;
        }
        $after = (int) $rows[count($rows) - 1]['id'];
        foreach ($rows as $row) {
            if (!body_has_tag($tag, (string) ($row['body'] ?? ''))) {
                continue;
            }
            $sort_keys[(int) $row['id']] = (string) ($row['sort_key'] ?? '');
        }
        if (count($rows) < TAG_SCAN_PAGE) {
            break;
        }
    }
    arsort($sort_keys, SORT_STRING);

    return array_keys($sort_keys);
}

/**
 * Loads posts for the given ids, in the order the ids are given.
 *
 * R::loadAll() returns a map keyed by id, so the caller's ordering — which for
 * a tag listing is the whole point — has to be reapplied.
 *
 * @param list<int> $ids
 * @return list<\RedBeanPHP\OODBBean>
 */
function load_posts_in_order(array $ids): array
{
    if ($ids === []) {
        return [];
    }
    $beans = R::loadAll('post', $ids);

    $ordered = [];
    foreach ($ids as $id) {
        if (isset($beans[$id]) && (int) $beans[$id]->id === $id) {
            $ordered[] = $beans[$id];
        }
    }

    return $ordered;
}

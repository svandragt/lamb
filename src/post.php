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
    $bean->created = \Lamb\now();
    $bean->updated = \Lamb\now();
    if ($feed_item) {
        $bean->created = $feed_item->get_date("Y-m-d H:i:s");
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
 * Sets a single key in a body's leading YAML front-matter block, leaving all
 * other front matter intact.
 *
 * An existing `key:` line is updated in place (preserving the original key text
 * and indentation, rewriting only the value with a single separating space).
 * When the block has no such line and $append is true, an explicit line is
 * appended to the block. Bodies without a leading front-matter block, or whose
 * key already holds the target value, are returned unchanged (no cosmetic
 * churn).
 *
 * @param string $body The raw post body.
 * @param string $key The front-matter key to set.
 * @param string $value The value to write.
 * @param bool $quote When true, the value is wrapped in single quotes.
 * @param bool $append When true, the key is appended if absent (otherwise the
 *                     body is returned unchanged when the key is missing).
 * @return string The body with the front-matter key set.
 */
function set_matter(string $body, string $key, string $value, bool $quote = false, bool $append = true): string
{
    // Only touch a front-matter block at the very start of the body.
    if (!preg_match('/\A(\s*---\s*\n)(.*?\n)(---\s*\n?)/s', $body, $m)) {
        return $body;
    }

    $rendered = $quote ? "'" . $value . "'" : $value;

    $new_yaml = preg_replace_callback(
        '/^([ \t]*' . preg_quote($key, '/') . '[ \t]*:)[ \t]*(.*?)[ \t]*$/mi',
        function (array $line) use ($value, $rendered): string {
            $current = trim($line[2], " \t'\"");
            if ($current === $value) {
                return $line[0];
            }
            return $line[1] . ' ' . $rendered;
        },
        $m[2],
        1,
        $count
    );

    if ($count === 0) {
        if (!$append) {
            return $body;
        }
        $new_yaml = $m[2] . "$key: $rendered\n";
    }

    return $m[1] . $new_yaml . $m[3] . substr($body, strlen($m[0]));
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
 * Matches a task-list marker the renderer turns into a checkbox: optional
 * blockquote markers, an optional bullet or ordered list marker, then
 * `[ ]`/`[x]`/`[X]` followed by whitespace and a non-empty label.
 *
 * Capture 2 is the state character, so its byte offset locates the single
 * character a toggle rewrites.
 */
const TASK_MARKER_PATTERN = '/^([ \t]*(?:>[ \t]?)*[ \t]*(?:(?:[-*+]|\d{1,9}[.)])[ \t]+)?\[)([ xX])(\][ \t]+\S)/';

/**
 * Matches any list item line (task or not), used to track whether the scanner
 * is inside a list — where an indented line is nested content rather than an
 * indented code block.
 */
const LIST_ITEM_PATTERN = '/^[ \t]*(?:>[ \t]?)*[ \t]*(?:[-*+]|\d{1,9}[.)])[ \t]+/';

/**
 * Matches the opening line of a fenced code block, capturing the fence itself
 * so a closing fence can be required to use the same character and be at least
 * as long — the rule Parsedown applies, so a longer fence can quote a shorter
 * one inside it.
 */
const CODE_FENCE_PATTERN = '/^[ \t]*(?:>[ \t]?)*[ \t]*(`{3,}|~{3,})/';

/**
 * The width of a line's leading indentation, with tabs expanded to the next
 * multiple of four (the tab stop Markdown's four-space code indent assumes).
 *
 * @param string $line A single source line.
 * @return int The indentation width in columns.
 */
function indent_width(string $line): int
{
    $width = 0;
    for ($i = 0, $len = strlen($line); $i < $len; $i++) {
        if ($line[$i] === ' ') {
            $width++;
        } elseif ($line[$i] === "\t") {
            $width += 4 - ($width % 4);
        } else {
            break;
        }
    }

    return $width;
}

/**
 * The byte offsets, within $body, of the state character of every task marker
 * the renderer turns into a checkbox — in the order the checkboxes appear.
 *
 * The toggle endpoint addresses a checkbox by its rendered position
 * (`data-checkbox-index`, numbered in document order by LambDown::text()), so
 * this has to recognise a marker exactly where the renderer does, or a click
 * flips somebody else's box. That means mirroring LambDown/Parsedown, not just
 * matching `- [ ] ` lines:
 *
 * - a marker needs no list bullet at all (`[ ] task` is a checkbox block on its
 *   own, which is why LambDown registers one), and an ordered bullet
 *   (`1. [ ] task`) counts as much as `-`/`*`/`+`;
 * - blockquoted markers render too, which every feed-ingested post is made of
 *   (Network\attributed_content() quotes the source with `> `);
 * - a marker inside a fenced or indented code block renders as code, not a
 *   checkbox, so it must not be counted;
 * - a marker with no label after it (`- [ ] `) is not a checkbox either;
 * - front matter is not rendered at all, so markers there are invisible.
 *
 * @param string $body The raw post body (front matter included).
 * @return list<int> Byte offsets of each rendered marker's state character.
 */
function checkbox_marker_offsets(string $body): array
{
    [, $content] = split_frontmatter($body);
    $position = strlen($body) - strlen($content);

    $offsets = [];
    /** @var string|null $fence The opening fence (e.g. '```') while inside a code block. */
    $fence   = null;
    $in_list = false;
    $blank   = true;

    foreach (preg_split('/(\R)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [] as $index => $piece) {
        // Odd indices are the captured line endings.
        if ($index % 2 === 1) {
            $position += strlen($piece);
            continue;
        }

        $line = $piece;
        $advance = strlen($line);

        if ($fence !== null) {
            $closing = '/^[ \t]*(?:>[ \t]?)*[ \t]*' . $fence[0] . '{' . strlen($fence) . ',}[ \t]*$/';
            if (preg_match($closing, $line) === 1) {
                $fence = null;
            }
            $position += $advance;
            continue;
        }
        if (trim($line) === '') {
            $blank = true;
            $position += $advance;
            continue;
        }
        // An indented code block, but only outside a list: inside one the same
        // indentation marks nested list content, which does render checkboxes.
        if (indent_width($line) >= 4 && !$in_list) {
            $blank = false;
            $position += $advance;
            continue;
        }
        if (preg_match(CODE_FENCE_PATTERN, $line, $m) === 1) {
            $fence = $m[1];
            $blank = false;
            $position += $advance;
            continue;
        }

        if (preg_match(LIST_ITEM_PATTERN, $line) === 1) {
            $in_list = true;
        } elseif ($blank && indent_width($line) === 0) {
            // A fresh, unindented block after a blank line closes the list.
            $in_list = false;
        }
        $blank = false;

        if (preg_match(TASK_MARKER_PATTERN, $line, $m, PREG_OFFSET_CAPTURE) === 1) {
            $offsets[] = $position + (int) $m[2][1];
        }

        $position += $advance;
    }

    return $offsets;
}

/**
 * The checked state of every task checkbox the renderer emits for a body, in
 * the order the toggle endpoint's `data-checkbox-index` counts them.
 *
 * The renderer is the authority on which markers become checkboxes, so this is
 * what checkbox_marker_offsets()'s reading of the source is checked against
 * before a toggle is stored (see Response\apply_checkbox_toggle).
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
 * Toggles the checked state of the Nth GitHub-style task-list marker in a body.
 *
 * Markers are counted exactly as the renderer numbers the checkboxes it emits
 * (see checkbox_marker_offsets()), so the Nth rendered checkbox maps to the Nth
 * source marker. Only the target marker's state character is rewritten; every
 * other marker and all surrounding text is preserved verbatim. An index past
 * the last marker returns the body unchanged.
 *
 * @param string $body    The raw post body.
 * @param int    $index   Zero-based index of the marker to toggle.
 * @param bool   $checked True to check the marker, false to uncheck it.
 * @return string The body with the target marker toggled.
 */
function toggle_checkbox(string $body, int $index, bool $checked): string
{
    $offsets = checkbox_marker_offsets($body);
    if ($index < 0 || !isset($offsets[$index])) {
        return $body;
    }

    return substr_replace($body, $checked ? 'x' : ' ', $offsets[$index], 1);
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
 * @return list<\RedBeanPHP\OODBBean> An array of posts that match the specified tag.
 */
function posts_by_tag(string $tag): array
{
    $conditions = get_tag_search_conditions($tag);
    $public = \Lamb\Response\public_posts_clause();
    $posts = R::find(
        'post',
        '(' . $conditions['sql'] . ') AND' . $public['sql'] . 'ORDER BY created DESC',
        array_merge($conditions['params'], $public['params'])
    );

    return array_values(array_filter($posts, fn($post) => body_has_tag($tag, (string) $post->body)));
}

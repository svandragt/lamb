<?php

/** @noinspection PhpUnused */

namespace Lamb\Theme;

/**
 * Escapes a string for safe HTML5 output using htmlspecialchars.
 *
 * @param string $html The raw string to escape.
 * @return string HTML-safe escaped string.
 */
function escape(string $html): string
{
    return htmlspecialchars($html, ENT_HTML5 | ENT_QUOTES | ENT_SUBSTITUTE);
}

/**
 * Escapes a string for safe XML output (Atom feed, sitemap).
 *
 * Uses ENT_XML1 rather than ENT_HTML5 so a single quote becomes `&apos;` (a
 * defined XML entity) and no HTML-only named entities are emitted. ENT_SUBSTITUTE
 * keeps a malformed UTF-8 byte from making htmlspecialchars() return '' for the
 * whole string — it degrades to U+FFFD instead, so one bad byte cannot empty an
 * entire <loc> or <title>.
 *
 * htmlspecialchars() only ever rewrites `& < > " '` — it has no idea that XML
 * 1.0 also forbids raw control characters (everything below U+0020 except tab,
 * LF and CR) and the two U+FFFE/U+FFFF noncharacters, control-character or not.
 * Those pass through htmlspecialchars() untouched, "escaped" or not, and a
 * document containing one is not well-formed XML: DOMDocument::loadXML() fails
 * the whole document on it (`PCDATA invalid Char value`), and
 * SimpleXMLElement::addChild() (the Atom feed's path) instead silently drops
 * the value, emitting an empty element. A title containing such a byte is
 * reachable input, not theoretical: Micropub's JSON create form accepts a
 * unicode-escaped control character in a string property (valid JSON), and
 * json_decode() turns it into a literal control byte, with nothing between
 * there and here stripping it. They are stripped before escaping so one bad
 * character corrupts only itself, not the surrounding XML document, matching
 * the "one bad byte" resilience the malformed-UTF8 handling above already
 * gives this function.
 *
 * @param string $xml The raw string to escape.
 * @return string XML-safe escaped string.
 */
function escape_xml(string $xml): string
{
    // preg_replace() with /u requires valid UTF-8; on malformed input it
    // returns null; fall back to $xml unchanged so htmlspecialchars() below
    // still gets a chance to degrade the bad byte to U+FFFD as before.
    $xml = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x{FFFE}\x{FFFF}]/u', '', $xml) ?? $xml;

    return htmlspecialchars($xml, ENT_XML1 | ENT_QUOTES | ENT_SUBSTITUTE);
}

/**
 * Re-levels a post body's headings so its highest heading sits at $top, keeping
 * the levels relative to each other. Open and close tags shift identically,
 * attributes are preserved, and a body with no headings is returned unchanged.
 * See theme/README.md ("Heading re-levelling") for why the shift is signed and
 * why no level is skipped at the top of the body.
 *
 * @param string $html The post body HTML, at the author's literal levels.
 * @param int    $top  The level the body's highest heading should occupy.
 * @return string The HTML with headings re-levelled to start at $top.
 */
function anchor_headings(string $html, int $top): string
{
    preg_match_all('#<h([1-6])\b#i', $html, $m);
    $levels = array_map('intval', $m[1]);
    if ($levels === []) {
        return $html;
    }

    $by = $top - min($levels);
    if ($by === 0) {
        return $html;
    }

    return preg_replace_callback(
        '#<(/?)h([1-6])\b([^>]*)>#i',
        static function (array $m) use ($by): string {
            $level = max(1, min(6, (int) $m[2] + $by));
            return '<' . $m[1] . 'h' . $level . $m[3] . '>';
        },
        $html
    ) ?? $html;
}


/**
 * Renders the reply-context line for a post that is a reply to another URL or
 * an RSVP to an event, or '' when it is neither. The link carries the
 * `u-in-reply-to` microformats2 class so Webmention receivers categorise the
 * mention as a reply, and an RSVP reply is emitted as the `p-rsvp` property the
 * IndieWeb vocabulary defines — on the same line, because an RSVP *is* a reply
 * to the event ("RSVP yes to example.com"), and two stacked lines would say so
 * twice.
 *
 * `<data value>` rather than plain text: the machine-readable value stays the
 * bare `yes`/`no`/`maybe`/`interested` a parser expects however the line is
 * worded for readers.
 *
 * Neither field is author-only (a Micropub client holding just `create` scope
 * can set both) and this markup is also served inside the Atom/JSON feeds'
 * content_html, so a target that isn't a genuine http(s) URL is shown as text
 * rather than linked, and a stored reply outside RSVP_VALUES is dropped rather
 * than emitted as a `p-rsvp` no consumer can read. See theme/README.md
 * ("Escaping is per-context, not per-file").
 *
 * @param \RedBeanPHP\OODBBean $bean
 * @return string
 */
function the_reply_context(\RedBeanPHP\OODBBean $bean): string
{
    $targets = \Lamb\Post\split_reply_targets((string) ($bean->in_reply_to ?? ''));
    $rsvp = strtolower(trim((string) ($bean->rsvp ?? '')));
    if (!in_array($rsvp, \Lamb\RSVP_VALUES, true)) {
        $rsvp = '';
    }
    if ($targets === [] && $rsvp === '') {
        return '';
    }

    // No rel="in-reply-to": the rel-in-reply-to proposal is superseded by the
    // u-in-reply-to h-entry property (which this already carries), the value is
    // not a registered HTML link type, and nothing consumes it that does not
    // already read u-in-reply-to. See #585.
    $parts = [];
    foreach ($targets as $url) {
        if (!\Lamb\Http\is_valid_http_url($url)) {
            $parts[] = escape($url);
            continue;
        }
        $label = parse_url($url, PHP_URL_HOST) ?: $url;
        $parts[] = '<a class="u-in-reply-to" href="'
            . escape($url) . '">' . escape($label) . '</a>';
    }

    $links = implode(', ', $parts);
    if ($rsvp === '') {
        return '<p class="reply-context">In reply to ' . $links . '</p>';
    }

    $reply = 'RSVP <data class="p-rsvp" value="' . $rsvp . '">' . $rsvp . '</data>';

    return '<p class="reply-context">'
        . ($links === '' ? $reply : $reply . ' to ' . $links)
        . '</p>';
}

/**
 * Escapes a string for use in OpenGraph meta attribute values.
 * Decodes any existing HTML entities first to avoid double-encoding.
 *
 * @param string $html The raw or partially-encoded string to escape.
 * @return string Escaped string safe for use in HTML attribute values.
 */
function og_escape(string $html): string
{
    return htmlspecialchars(htmlspecialchars_decode($html), ENT_COMPAT | ENT_HTML5);
}

/**
 * Returns the HTML-escaped body the entry form's textarea is pre-filled with.
 *
 * `?text=` supplies the content. `?in-reply-to=` and `?rsvp=` supply the two
 * front-matter keys that turn that content into a reply or an RSVP, so a
 * bookmarklet on an event page can open the compose box with the post already
 * addressed — the one-click path that spares the author typing a front-matter
 * block by hand (see docs/rsvp.md). Nothing is published: this is a draft in a
 * textarea the author still reviews and submits.
 *
 * Both keys are validated here rather than trusted: the block is assembled
 * through build_matter()'s YAML writer, so a newline in a value cannot inject
 * further keys, and a target that is not an http(s) URL — or a reply outside
 * the mf2 vocabulary — is left out rather than pre-filled for
 * \Lamb\normalize_rsvp() to discard on save.
 *
 * @return string Escaped body text, or '' when no parameter is present.
 */
function preload_text(): string
{
    $text = \Lamb\Http\request_string($_GET['text'] ?? null) ?? '';

    return htmlspecialchars(
        \Lamb\Post\build_matter(preload_matter(), $text),
        ENT_QUOTES,
        'UTF-8'
    );
}

/**
 * Builds the front matter the entry form is pre-filled with from the query
 * string, or [] when the request asks for none.
 *
 * Accepts either spelling of the reply target (`in-reply-to`, `in_reply_to`),
 * the way \Lamb\Post\normalize_matter_keys() folds them together for a
 * hand-written block — a query string is the one place an author (or their
 * bookmarklet) types the key themselves.
 *
 * @return array<string, string> Front matter keyed as parse_matter() reads it.
 */
function preload_matter(): array
{
    $matter = [];

    $target = \Lamb\Http\request_string($_GET['in-reply-to'] ?? $_GET['in_reply_to'] ?? null) ?? '';
    if (\Lamb\Http\is_valid_http_url(trim($target))) {
        $matter['in-reply-to'] = trim($target);
    }

    $rsvp = strtolower(trim(\Lamb\Http\request_string($_GET['rsvp'] ?? null) ?? ''));
    if (in_array($rsvp, \Lamb\RSVP_VALUES, true)) {
        $matter['rsvp'] = $rsvp;
    }

    return $matter;
}

/**
 * Returns the sanitised value of the ?redirect_to= query parameter, or '' if absent.
 *
 * @return string Sanitised URL string from the query parameter.
 */
function redirect_to(): string
{
    return (string)filter_input(INPUT_GET, 'redirect_to', FILTER_SANITIZE_URL);
}

/**
 * Returns a human-readable date string for a past timestamp (j > 2).
 *
 * @param int $j          Period index after the time-difference loop.
 * @param int $difference Rounded number of periods elapsed.
 * @param int $timestamp  Original Unix timestamp.
 * @return string
 */
function format_past_date(int $j, int $difference, int $timestamp): string
{
    switch (true) {
        case $j === 3 && $difference === 1:
            return "Yesterday at " . date("g:i a", $timestamp);
        case $j === 3:
            return date("l \a\\t g:i a", $timestamp);
        case $j < 6 && !($j === 5 && $difference === 12):
            $format = date('Y', $timestamp) !== date('Y') ? "F j, Y \a\\t g:i a" : "F j \a\\t g:i a";
            return date($format, $timestamp);
        default:
            return date("F j, Y \a\\t g:i a", $timestamp);
    }
}

/**
 * Returns a human-readable relative time string for the given Unix timestamp.
 * Thanks to Rose Perrone.
 *
 * @param int $timestamp Unix timestamp to format.
 * @return string Relative string such as "3 hours ago", "Yesterday at 2:15 pm", or an absolute date.
 * @link https://stackoverflow.com/a/11813996
 */
function human_time($timestamp): string
{
    $difference = time() - $timestamp;
    $periods = ["second", "minute", "hour", "day", "week", "month", "years"];
    $lengths = ["60", "60", "24", "7", "4.35", "12"];

    if ($difference >= 0) {
        $ending = "ago";
    } else {
        $difference = -$difference;
        $ending = "to go";
    }

    $arr_len = count($lengths);
    for ($j = 0; $j < $arr_len && $difference >= $lengths[$j]; $j++) {
        $difference /= $lengths[$j];
    }

    $difference = (int)round($difference);

    if ($difference !== 1) {
        $periods[$j] .= "s";
    }

    if ($j <= 2) {
        return "$difference $periods[$j] $ending";
    }

    if ($ending === "to go") {
        if ($j === 3 && $difference === 1) {
            return "Tomorrow at " . date("g:i a", $timestamp);
        }
        return date("F j, Y \a\\t g:i a", $timestamp);
    }

    return format_past_date($j, $difference, $timestamp);
}

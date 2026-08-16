<?php

/** @noinspection PhpUnused */

namespace Lamb\Theme;

use RedBeanPHP\OODBBean;

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
 * Re-levels a post body's headings so its highest heading sits at $top, keeping
 * the levels relative to each other.
 *
 * Post bodies are stored at the author's literal heading levels (theme-neutral),
 * so each theme fits them into its own outline at render time. A theme that
 * renders the post title at h2 passes $top = 3, so the body's highest heading
 * becomes h3 (directly under the title) and the rest shift by the same amount —
 * the level the author actually typed is immaterial, and no level is skipped at
 * the top of the body, which keeps the document outline in order for screen
 * readers (WCAG heading-order). The shift is signed: a body written deeper than
 * $top is pulled up just as one written shallower is pushed down. Results clamp
 * at h6. Open and close tags shift identically, attributes are preserved, and a
 * body with no headings is returned unchanged.
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
 * Renders a post's body HTML for on-site display.
 *
 * The one seam every theme puts a post body through, so anything that has to
 * happen to stored content on its way to the page happens once. Today that is
 * two things: the heading re-levelling a theme asks for, and prefixing the
 * install's base path onto root-relative links.
 *
 * Post bodies store asset links root-relative (`/assets/…` from asset_url()) so
 * the content survives a domain change and works from the CLI importer. That
 * resolves against the domain root, which is outside an install served from a
 * subdirectory (issue #580) — so the base goes on at render time and what is
 * stored stays portable. Feeds do their own, absolute, version of this via
 * Lamb\absolute_urls() with ROOT_URL.
 *
 * @param OODBBean    $bean The post being rendered.
 * @param int|null    $top  The level the body's highest heading should occupy,
 *                          or null to leave headings alone.
 * @param string|null $base The install's base path; defaults to Http\base_path().
 *                          Passed explicitly by tests, which cannot redefine a constant.
 * @return string The body HTML, ready to echo.
 */
function the_content(OODBBean $bean, ?int $top = null, ?string $base = null): string
{
    $html = (string) ($bean->transformed ?? '');
    if ($top !== null) {
        $html = anchor_headings($html, $top);
    }

    return \Lamb\absolute_urls($html, $base ?? \Lamb\Http\base_path());
}


/**
 * Render the reply-context line for a post that is a reply to another URL.
 *
 * Returns an empty string when the post has no `in_reply_to` target. The link
 * carries the `u-in-reply-to` microformats2 class so Webmention receivers
 * categorise the mention as a reply.
 *
 * @param \RedBeanPHP\OODBBean $bean
 * @return string
 */
function the_reply_context(\RedBeanPHP\OODBBean $bean): string
{
    $url = trim((string) ($bean->in_reply_to ?? ''));
    if ($url === '') {
        return '';
    }

    $label = parse_url($url, PHP_URL_HOST) ?: $url;

    return '<p class="reply-context">In reply to <a class="u-in-reply-to" rel="in-reply-to" href="'
        . escape($url) . '">' . escape($label) . '</a></p>';
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
 * Returns the HTML-escaped value of the ?text= query parameter, used to pre-fill the entry form textarea.
 *
 * @return string Escaped text string, or '' when the parameter is absent.
 */
function preload_text(): string
{
    return htmlspecialchars($_GET['text'] ?? '', ENT_QUOTES, 'UTF-8');
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

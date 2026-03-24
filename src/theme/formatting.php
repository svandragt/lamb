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

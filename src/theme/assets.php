<?php

/** @noinspection PhpUnused */

namespace Lamb\Theme;

use Generator;

use const ROOT_URL;
use const SESSION_LOGIN;

/**
 * Emits the active theme's stylesheet, inlined when small enough (see styles_markup()).
 *
 * @return void
 */
function the_styles(): void
{
    $css_url  = ROOT_URL . '/' . THEME_URL . 'styles/styles.css';
    $css_path = (defined('ROOT_DIR') ? ROOT_DIR : '') . '/' . THEME_URL . 'styles/styles.css';
    $base_url = ROOT_URL . '/' . THEME_URL . 'styles/';

    echo styles_markup($css_path, $css_url, $base_url);
}

/**
 * Builds the markup that loads the active theme's stylesheet: inlined as a
 * <style> tag when small enough, otherwise an external <link> with a
 * content-hash cache-buster. See theme/README.md ("Why CSS gets minified and
 * inlined, but JS never does").
 *
 * @param string $css_path  Absolute filesystem path to the stylesheet.
 * @param string $css_url   Public URL of the stylesheet (fallback <link> href).
 * @param string $base_url  Absolute URL of the directory the stylesheet lives in.
 * @param int    $max_bytes Inline only when the minified CSS is at most this size.
 * @return string The <style> or <link> markup, including a trailing newline.
 */
function styles_markup(string $css_path, string $css_url, string $base_url, int $max_bytes = 20480): string
{
    if (is_file($css_path) && is_readable($css_path)) {
        $css = file_get_contents($css_path);
        if ($css !== false) {
            $inline = minify_css(rewrite_css_urls($css, $base_url));
            if (strlen($inline) <= $max_bytes) {
                return sprintf('<style id="%s">%s</style>', md5($inline), $inline) . PHP_EOL;
            }
        }
    }

    $ver = asset_version($css_path, $css_url);
    return sprintf('<link rel="stylesheet" id="%1$s" href="%2$s?ver=%1$s">', $ver, $css_url) . PHP_EOL;
}

/**
 * Rewrites relative url() references in CSS to absolute URLs against $base_url.
 * Absolute (http(s):, //, /), data: and fragment (#) URLs are left untouched.
 * See theme/README.md ("Why CSS gets minified and inlined, but JS never
 * does") for why this matters once the CSS is inlined into the HTML document.
 *
 * @param string $css      The stylesheet contents.
 * @param string $base_url Absolute URL of the stylesheet's directory (trailing slash).
 * @return string The CSS with relative url() references made absolute.
 */
function rewrite_css_urls(string $css, string $base_url): string
{
    return preg_replace_callback(
        '/url\(\s*([\'"]?)([^\'")]+)\1\s*\)/i',
        static function (array $m) use ($base_url): string {
            $url = trim($m[2]);
            if (preg_match('~^(?:https?:)?//|^/|^data:|^#~i', $url)) {
                return $m[0];
            }
            return "url('" . $base_url . $url . "')";
        },
        $css
    ) ?? $css;
}

/**
 * Minifies CSS for inlining: strips comments and collapses insignificant
 * whitespace. Deliberately conservative — string literals and url() tokens
 * are split out and copied through untouched, since whitespace inside them
 * is content rather than syntax. See theme/README.md ("Why CSS gets
 * minified and inlined, but JS never does") for the concrete cases this
 * protects.
 *
 * @param string $css The stylesheet contents.
 * @return string Minified CSS.
 */
function minify_css(string $css): string
{
    $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;

    // Capturing split, so the delimiters (the literals) are kept and land on the
    // odd indices. url() is included so an unquoted data URI is protected too.
    $parts = preg_split(
        '/("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'|url\([^)]*\))/',
        $css,
        -1,
        PREG_SPLIT_DELIM_CAPTURE
    );
    if ($parts === false) {
        return trim($css);
    }

    $out = '';
    foreach ($parts as $index => $part) {
        if ($index % 2 === 1) {
            // A literal or url() token: content, not syntax.
            $out .= $part;
            continue;
        }
        $part = preg_replace('/\s+/', ' ', $part) ?? $part;
        $part = preg_replace('/\s*([{}:;,>])\s*/', '$1', $part) ?? $part;
        // A `;` that CSS syntax owns is always in the same segment as the `}`
        // that follows it — `;"foo"}` is not valid CSS — so this is safe here
        // and cannot reach a literal ending in `;}`.
        $out .= str_replace(';}', '}', $part);
    }

    return trim($out);
}

/**
 * Emits <script defer> tags for shorthand.js and, when logged in, the admin-only JS files.
 *
 * @return void
 */
function the_scripts(): void
{
    $scripts = [
        '' => ['shorthand.js', 'image-modal.js'],
        'logged_in' => ['growing-input.js', 'confirm-delete.js', 'link-edit-buttons.js', 'upload-image.js', 'paste-link.js', 'toggle-checkbox.js'],
        'search' => ['search-highlight.js'],
    ];
    $assets = asset_loader($scripts, 'scripts');
    foreach ($assets as $id => $href) {
        printf('<script id="%1$s" defer src="%2$s?ver=%1$s"></script>', $id, $href);
    }
}

/**
 * Computes a content-addressed cache-busting version for an asset: hashes the
 * file contents, falling back to hashing the URL when the file can't be read
 * (e.g. a remote asset). See theme/README.md ("Cache-busting is
 * content-addressed").
 *
 * @param string $local_path Absolute filesystem path to the asset.
 * @param string $href       The public URL of the asset (used as a fallback).
 * @return string 32-character hex hash.
 */
function asset_version(string $local_path, string $href): string
{
    if (is_file($local_path)) {
        return md5_file($local_path) ?: md5($href);
    }
    return md5($href);
}

/**
 * Loads and yields asset URLs for the application.
 *
 * The array key controls when each group of files is emitted:
 * - ''          always loaded
 * - 'logged_in' loaded only when the user is authenticated
 * - any other string is matched against the current $template
 *
 * See theme/README.md ("asset_loader()'s three-way key") for why the loaders
 * are split this way.
 *
 * @param array<string, list<string>> $assets Associative array: key = subdirectory condition, value = array of filenames.
 * @param string $asset_dir Base directory for the assets.
 * @return Generator<string, string> Yields asset_version($contents) => $href for each asset to load.
 */
function asset_loader(array $assets, string $asset_dir): Generator
{
    global $template;

    foreach ($assets as $dir => $files) {
        $load = match (true) {
            empty($dir)                                          => true,
            $dir === SESSION_LOGIN && isset($_SESSION[SESSION_LOGIN]) => true,
            $dir === $template                                   => true,
            default                                              => false,
        };
        if (!$load) {
            continue;
        }


        foreach ($files as $file) {
            $path = $dir ? "$asset_dir/$dir/$file" : "$asset_dir/$file";
            $href = ROOT_URL . '/' . ltrim($path, '/');
            $local_path = (defined('ROOT_DIR') ? ROOT_DIR : '') . '/' . ltrim($path, '/');
            $hash = asset_version($local_path, $href);
            yield $hash => $href;
        }
    }
}

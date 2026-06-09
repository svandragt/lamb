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
 * Builds the markup that loads the active theme's stylesheet.
 *
 * Small stylesheets are inlined as a <style> tag to remove the render-blocking
 * round-trip on first paint (the single biggest mobile PageSpeed win for a
 * one-file theme). Relative url() references are rewritten to absolute so they
 * still resolve once the CSS lives in the HTML rather than at styles/styles.css.
 * Anything larger than $max_bytes, or an unreadable file, falls back to an
 * external <link> with a content-hash cache-buster.
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
 *
 * Needed when CSS is inlined into the HTML document: a relative url('fonts/x.woff2')
 * would otherwise resolve against the page URL instead of the stylesheet's directory.
 * Absolute (http(s):, //, /), data: and fragment (#) URLs are left untouched.
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
 * Minifies CSS for inlining: strips comments and collapses insignificant whitespace.
 *
 * Conservative by design — it only touches whitespace and comments, so it is safe
 * for the controlled, hand-written theme stylesheets Lamb ships.
 *
 * @param string $css The stylesheet contents.
 * @return string Minified CSS.
 */
function minify_css(string $css): string
{
    $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;
    $css = preg_replace('/\s+/', ' ', $css) ?? $css;
    $css = preg_replace('/\s*([{}:;,>])\s*/', '$1', $css) ?? $css;
    $css = str_replace(';}', '}', $css);

    return trim($css);
}

/**
 * Emits <script defer> tags for shorthand.js and, when logged in, the admin-only JS files.
 *
 * @return void
 */
function the_scripts(): void
{
    $scripts = [
        '' => ['shorthand.js'],
        'logged_in' => ['growing-input.js', 'confirm-delete.js', 'link-edit-buttons.js', 'upload-image.js', 'paste-link.js', 'toggle-checkbox.js'],
        'search' => ['search-highlight.js'],
    ];
    $assets = asset_loader($scripts, 'scripts');
    foreach ($assets as $id => $href) {
        printf('<script id="%1$s" defer src="%2$s?ver=%1$s"></script>', $id, $href);
    }
}

/**
 * Computes a content-addressed cache-busting version for an asset.
 *
 * Hashing the file contents (not the URL) means the version only changes when the
 * file actually changes, so a deploy invalidates stale browser/CDN copies while
 * returning visitors keep their cache across deploys that leave the file untouched.
 * Falls back to hashing the URL when the file cannot be read (e.g. a remote asset).
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

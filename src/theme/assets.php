<?php

/** @noinspection PhpUnused */

namespace Lamb\Theme;

use Generator;

use const ROOT_URL;
use const SESSION_LOGIN;

/**
 * Emits a <link rel="stylesheet"> tag for the active theme's styles/styles.css with a cache-busting hash.
 *
 * @return void
 */
function the_styles(): void
{
    $styles = [
        '' => ['styles.css'],
    ];
    $assets = asset_loader($styles, THEME_URL . 'styles');
    foreach ($assets as $id => $href) {
        printf('<link rel="stylesheet" id="%1$s" href="%2$s?ver=%1$s">' . PHP_EOL, $id, $href);
    }
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
        'logged_in' => ['growing-input.js', 'confirm-delete.js', 'link-edit-buttons.js', 'upload-image.js', 'paste-link.js'],
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
 * @param array  $assets    Associative array: key = subdirectory condition, value = array of filenames.
 * @param string $asset_dir Base directory for the assets.
 * @return Generator Yields asset_version($contents) => $href for each asset to load.
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

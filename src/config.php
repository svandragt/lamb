<?php

namespace Lamb\Config;

use RedBeanPHP\R;

use function Lamb\get_option;
use function Lamb\set_option;

/**
 * Returns the default INI configuration text.
 *
 * @return string The default INI configuration text.
 */
function get_default_ini_text(): string
{
    return <<<INI
;; Theme used to render the site. Bundled themes: 2026 (default, "Notes"), 2024, base.
;; Custom themes live in src/themes/<name>/ and only need to override the parts they change.
theme = 2026

;; Author email in feed
;author_email = joe.sheeple@example.com

;; Author name in feed
;author_name = Joe Sheeple

;; Title of the site, in html and feed views
;site_title = My Microblog

;; Your timezone, used for post dates and scheduling (the server is often UTC).
;; Use a name from https://www.php.net/manual/en/timezones.php. Defaults to UTC.
;timezone = Europe/London

;; When content is not found, instead of a 404 by setting the following value the user is redirected to the same
;; relative path on another site.
;; Useful where old content is archived to an archive site, or the lamb blog is still under construction but public.
;404_fallback = https://my.oldsite.com

[menu_items]
;; Add <label>=<url> entries here where URL is either:
;;   - Slugs of an existing post, which is then hidden in the feed and the timeline (
;About Me = about
;;   - Root relative links which can be used to signpost important functionality such as tags, feeds etc.
;Subscribe = /feed
;;   - Full URLs to external sites
;Source = https://github.com/svandragt/lamb

[feeds]
;; Add feeds whose content gets published into the blog.
;; Each item is in the format of <name>=<url>  where URL is a link to an RSS or Atom feed.
;; Feeds can be tested for compatibility here: https://simplepie.org/demo/
;lamb-releases=https://github.com/svandragt/lamb/releases.atom

;; Feed-ingested posts are saved as drafts by default for editorial review before publishing.
;; Set to false to publish feed items directly without review.
;feeds_draft = false

[preconnect]
;; List external origins to preconnect to, improving load time for external resources.
;; Each item is in the format of <label>=<origin>.
;google-fonts = https://fonts.googleapis.com
;google-fonts-static = https://fonts.gstatic.com

;; IndieAuth endpoints used for Micropub discovery.
;; Override to use your own IndieAuth server.
;authorization_endpoint = https://indieauth.com/auth
;token_endpoint = https://tokens.indieauth.com/token

[me]
;; Add rel="me" identity links for IndieAuth verification.
;; Each entry is <label>=<url>. Links appear as <link rel="me"> in the HTML head.
;Github = https://github.com/yourusername
;Email = mailto:you@example.com

[redirections]
;; Add 301 redirects for old URL path segments.
;; Format: <old-slug> = <destination>
;; Destination can be a root-relative URL, a bare slug, or a full external URL.
;old-post = /new-post
INI;
}

/**
 * Resolves a configured theme name to the directory that should be rendered.
 *
 * Falls back to the bundled `base` theme (the part-fallback library) when no
 * theme is configured, and aliases the legacy name `default` to `base` so
 * installs that explicitly set `theme = default` keep working after the rename.
 *
 * @param string|null $configured The theme name from config, or null when unset.
 * @param string $fallback The theme to use when none is configured.
 * @return string The theme directory name to render.
 */
function resolve_theme(?string $configured, string $fallback = 'base'): string
{
    if (empty($configured)) {
        return $fallback;
    }
    if ($configured === 'default') {
        return 'base';
    }
    return $configured;
}

/**
 * Ensures the INI text records an explicit top-level `theme` key.
 *
 * Older installs never stored a theme and relied on a PHP fallback. Stamping an
 * explicit value lets that fallback be removed later. Idempotent: returns the
 * text unchanged when a top-level `theme` key is already present.
 *
 * @param string $ini_text The raw INI configuration text.
 * @param string $default_theme The theme to record when none is set.
 * @return string The INI text, with a `theme` line prepended when it was absent.
 */
function ensure_explicit_theme(string $ini_text, string $default_theme = 'base'): string
{
    $parsed = @parse_ini_string($ini_text, true, INI_SCANNER_RAW);
    if (is_array($parsed) && array_key_exists('theme', $parsed)) {
        return $ini_text;
    }

    return "theme = {$default_theme}\n\n" . $ini_text;
}

/**
 * Loads the configuration settings.
 *
 * @return array The configuration settings.
 */
function load(): array
{
    $ini_text = get_ini_text();
    $config = @parse_ini_string($ini_text, true, INI_SCANNER_RAW);

    // Hardcoded defaults as fallback for missing keys
    $defaults = [
        'author_email'           => 'joe.sheeple@example.com',
        'author_name'            => 'Joe Sheeple',
        'site_title'             => 'My Microblog',
        'authorization_endpoint' => 'https://indieauth.com/auth',
        'token_endpoint'         => 'https://tokens.indieauth.com/token',
        'timezone'               => 'UTC',
    ];

    return array_merge($defaults, $config ?: []);
}

/**
 * Applies the author's configured timezone as the default for all date handling.
 *
 * The server clock is often UTC while the author lives elsewhere; setting the
 * timezone here makes scheduling, "now", post dates, and human times all use the
 * author's wall clock. Falls back to UTC when the configured value is missing or
 * not a recognised timezone identifier.
 *
 * @param array $config The loaded configuration.
 * @return string The timezone identifier that was applied.
 */
function apply_timezone(array $config): string
{
    $timezone = $config['timezone'] ?? 'UTC';
    if (!in_array($timezone, \DateTimeZone::listIdentifiers(), true)) {
        $timezone = 'UTC';
    }
    date_default_timezone_set($timezone);

    return $timezone;
}

/**
 * Retrieves the raw INI text from the database or bootstraps it if not present.
 *
 * @return string The raw INI configuration text.
 */
function get_ini_text(): string
{
    $option = get_option('site_config_ini', '');
    if ($option->id > 0) {
        // Migrate themeless installs to an explicit theme so the PHP fallback
        // can eventually be removed. Only rewrites (and bumps the cache
        // validator) on the first request after upgrade.
        $ini_text = ensure_explicit_theme($option->value);
        if ($ini_text !== $option->value) {
            save_ini_text($ini_text);
        }
        return $ini_text;
    }

    // Bootstrap
    $ini_text = '';
    if (file_exists('config.ini')) {
        $ini_text = file_get_contents('config.ini');
    }

    if (empty($ini_text)) {
        $ini_text = get_default_ini_text();
    }

    $ini_text = ensure_explicit_theme($ini_text);
    save_ini_text($ini_text);

    return $ini_text;
}

/**
 * Saves the raw INI configuration text to the database.
 *
 * @param string $ini_text The INI configuration text to save.
 *
 * @return void
 */
function save_ini_text(string $ini_text): void
{
    $option = get_option('site_config_ini', '');
    // Stamp the edit time so conditional-GET validators invalidate cached pages
    // immediately on a settings change (see Response\latest_content_timestamp).
    // Advance the timestamp monotonically: two saves landing in the same
    // wall-clock second must still move it forward, otherwise the composite
    // ETag is unchanged and anonymous clients are served a stale 304 (#279).
    $previous = !empty($option->updated) ? (strtotime($option->updated) ?: 0) : 0;
    $option->updated = date('Y-m-d H:i:s', max(time(), $previous + 1));
    set_option($option, $ini_text);
}

/**
 * Returns the Unix timestamp of the last config edit, or 0 if config was never saved.
 *
 * Used to fold config changes into the cache validator so editing settings
 * (title, menu, theme, …) invalidates anonymous cached pages right away.
 *
 * @return int
 */
function config_modified_timestamp(): int
{
    $option = get_option('site_config_ini', '');
    if ($option->id === 0 || empty($option->updated)) {
        return 0;
    }
    return strtotime($option->updated) ?: 0;
}

/**
 * Validates the syntax of the given INI text.
 *
 * @param string $ini_text The INI configuration text to validate.
 *
 * @return array{valid: bool, error: ?string} The validation result.
 */
function validate_ini(string $ini_text): array
{
    set_error_handler(function ($errno, $errstr) {
        throw new \Exception($errstr);
    });

    try {
        $parsed = parse_ini_string($ini_text, true, INI_SCANNER_RAW);
        if ($parsed === false) {
            return ['valid' => false, 'error' => 'Invalid INI syntax.'];
        }
        return ['valid' => true, 'error' => null];
    } catch (\Exception $e) {
        return ['valid' => false, 'error' => $e->getMessage()];
    } finally {
        restore_error_handler();
    }
}

/**
 * Retrieves a list of slugs derived from menu items that should be excluded from the timeline.
 *
 * @return array An array of slugs to exclude.
 */
function get_menu_slugs(): array
{
    global $config;

    $menu_items = $config['menu_items'] ?? [];
    $slugs = [];

    foreach ($menu_items as $value) {
        // "The home link must never match slugs."
        if ($value === '/') {
            continue;
        }

        // If it starts with a slash, we take the part after it.
        if (str_starts_with($value, '/')) {
            $slug = trim($value, '/');
            if ($slug !== '') {
                $slugs[] = $slug;
            }
            continue;
        }

        // Otherwise, if it's not a full URL, we treat it as a slug.
        if (!filter_var($value, FILTER_VALIDATE_URL)) {
            $slugs[] = $value;
        }
    }

    return array_unique($slugs);
}

/**
 * Checks if a given menu item exists in the configuration array.
 *
 * @param string $slug The menu item slug to check.
 *
 * @return bool Returns true if the menu item exists in the configuration array, false otherwise.
 */
function is_menu_item(string $slug): bool
{
    return in_array($slug, get_menu_slugs(), true);
}

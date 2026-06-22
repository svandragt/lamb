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
site_title = My Microblog

;; Short description of the site, used as the meta description on listing pages.
;site_description = A personal microblog

;; Your timezone, used for post dates and scheduling (the server is often UTC).
;; Use a name from https://www.php.net/manual/en/timezones.php.
timezone = UTC

;; Number of posts shown per page in lists and feeds.
posts_per_page = 10

;; Feed-ingested posts are saved as drafts by default for editorial review before
;; publishing. Set to false to publish feed items directly without review.
feeds_draft = true

;; IndieAuth endpoints used for Micropub discovery. Override to use your own server.
authorization_endpoint = https://indieauth.com/auth
token_endpoint = https://tokens.indieauth.com/token

;; Diagnostic logging for the Micropub endpoint. Off by default. Set to true only to
;; debug a misbehaving client — it writes request/token-verification details (never the
;; token itself) to data/micropub.log. Turn it back off when you are done.
;micropub_debug = false

;; When content is not found, instead of a 404 by setting the following value the user is redirected to the same
;; relative path on another site.
;; Useful where old content is archived to an archive site, or the lamb blog is still under construction but public.
;404_fallback = https://my.oldsite.com

;; WebSub hubs used to push new posts to feed subscribers in real time.
;; Hubs are advertised in the Atom and JSON feeds, and pinged when you publish.
;; Separate multiple hubs with commas.
;websub_hubs = https://hub.example.com/

[menu_items]
;; Add <label>=<url> entries here where URL is either:
;;   - Slugs of an existing post, which is then hidden in the feed and the timeline (
;About Me = about
;;   - Root relative links which can be used to signpost important functionality such as tags, feeds etc.
;Subscribe = /feed
;;   - Full URLs to external sites
;Source = https://github.com/svandragt/lamb
Home = /
Feed = /feed

[feeds]
;; Add feeds whose content gets published into the blog.
;; Each item is in the format of <name>=<url>  where URL is a link to an RSS or Atom feed.
;; Feeds can be tested for compatibility here: https://simplepie.org/demo/
;lamb-releases=https://github.com/svandragt/lamb/releases.atom

[preconnect]
;; List external origins to preconnect to, improving load time for external resources.
;; Each item is in the format of <label>=<origin>.
;google-fonts = https://fonts.googleapis.com
;google-fonts-static = https://fonts.gstatic.com

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

[syndicate_to]
;; Add POSSE syndication targets shown to Micropub clients (e.g. Quill).
;; Format: <uid>=<name>  where uid is the profile URL of the target silo.
;https://bsky.app/profile/yourusername = Bluesky
;https://mastodon.social/@yourusername = Mastodon
INI;
}

/**
 * Parses INI text with warnings suppressed, returning [] when unparseable.
 *
 * The tolerant counterpart to validate_ini(): readers treat broken INI as
 * empty config rather than surfacing errors, so a bad edit can't take the
 * site down between save and fix.
 *
 * @param string $ini_text The raw INI text.
 * @return array<string, mixed> The parsed sections/keys, or [] on failure.
 */
function parse_ini_safe(string $ini_text): array
{
    return @parse_ini_string($ini_text, true, INI_SCANNER_RAW) ?: [];
}

/**
 * Ensures the stored INI records an explicit, renderable top-level `theme` key.
 *
 * This is the single place that normalises the legacy theme shapes, so the
 * render path (`index.php`) can read `$config['theme']` directly without a
 * runtime fallback or alias (see #291):
 *  - no `theme` key at all (older installs relied on a PHP fallback): an
 *    explicit line is prepended;
 *  - an empty value, or the pre-rename name `default`: the line is rewritten to
 *    the bundled `base` theme (the part-fallback library).
 *
 * Idempotent: text that already names a real theme is returned unchanged.
 *
 * @param string $ini_text The raw INI configuration text.
 * @param string $default_theme The theme to record when none is usable.
 * @return string The INI text with an explicit, renderable `theme` value.
 */
function ensure_explicit_theme(string $ini_text, string $default_theme = 'base'): string
{
    $parsed = parse_ini_safe($ini_text);

    if (!array_key_exists('theme', $parsed)) {
        return "theme = {$default_theme}\n\n" . $ini_text;
    }

    $current = trim((string) $parsed['theme']);
    if ($current === '' || $current === 'default') {
        return (string) preg_replace(
            '/^(\h*theme\h*=).*$/mi',
            '${1} ' . $default_theme,
            $ini_text,
            1
        );
    }

    return $ini_text;
}

/**
 * Loads the configuration settings.
 *
 * @return array<string, mixed> The configuration settings.
 */
function load(): array
{
    return compose_config(get_ini_text(), get_default_ini_text());
}

/**
 * Merges stored config over the seeded defaults to produce the effective config.
 *
 * The seeded INI (`get_default_ini_text()`) is the single source of truth for the
 * real defaults (timezone, posts_per_page, feeds_draft, IndieAuth endpoints), so
 * they no longer live in a parallel hardcoded array. Precedence (lowest to
 * highest): identity placeholders → seeded defaults → stored config.
 *
 * @param string $stored_ini  The raw stored INI text.
 * @param string $default_ini The seeded default INI text.
 * @return array<string, mixed> The effective configuration.
 */
function compose_config(string $stored_ini, string $default_ini): array
{
    $config = parse_ini_safe($stored_ini);
    $defaults = parse_ini_safe($default_ini);

    // Theme is intentionally not defaulted here. An install without an explicit
    // theme is migrated per-install on read (see ensure_explicit_theme /
    // get_ini_text), so inheriting the seeded theme would silently re-theme
    // existing sites.
    unset($defaults['theme']);

    // Personal-identity values are kept commented in the seeded INI, so supply
    // them as a last-resort fallback for consumers without an inline default
    // (e.g. feed.php reads author_name directly).
    $fallback = [
        'author_email' => 'joe.sheeple@example.com',
        'author_name'  => 'Joe Sheeple',
    ];

    return array_merge($fallback, $defaults, $config);
}

/**
 * Applies the author's configured timezone as the default for all date handling.
 *
 * The server clock is often UTC while the author lives elsewhere; setting the
 * timezone here makes scheduling, "now", post dates, and human times all use the
 * author's wall clock. Falls back to UTC when the configured value is missing or
 * not a recognised timezone identifier.
 *
 * @param array<string, mixed> $config The loaded configuration.
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
 * @return list<string> An array of slugs to exclude.
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
            $slugs[] = (string) $value;
        }
    }

    return array_values(array_unique($slugs));
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

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
;; Theme used to render the site. Bundled themes: 2026 (default, "Notes"), base.
;; Custom themes live in src/themes/<name>/ and only need to override the parts they change.
theme = 2026

;; Author email in feed
;author_email = joe.sheeple@example.com

;; Author name in feed
;author_name = Joe Sheeple

;; Title of the site, in html and feed views
site_title = My Microblog

;; The canonical URL of your site, e.g. https://example.com (no trailing slash).
;; Set this if you use Micropub: it is how Lamb tells that an IndieAuth token was
;; issued for your identity rather than someone else's, so the endpoint refuses
;; every token while it is unset. It also pins the absolute URLs in feeds and
;; social embeds to your real domain instead of whatever host the request claimed.
;; The LAMB_SITE_URL environment variable overrides this value.
;site_url = https://example.com

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

;; A public OPML list of the feeds you follow, e.g. the one your feed reader publishes.
;; Lamb copies it during scheduled tasks (/_cron) and serves the copy at
;; /.well-known/following.opml for SocialSpec apps (https://followspec.org/).
;following_url = https://reader.example/following/your-token.opml

;; Gates features still gathering real-world testing before general release
;; (currently: the WordPress, Known, and Lamb-export import CLI scripts).
;; Off by default, and reset to false automatically whenever an upgrade
;; changes what this gates — see docs for what is currently covered before
;; turning it back on.
experimental_features = false

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

[footer_items]
;; Add <label>=<url> entries here for the site footer. Same format as [menu_items].
;; Useful for secondary navigation: privacy policy, colophon, social links, etc.
;Privacy = /privacy

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
    $defaults = parse_ini_safe($default_ini);
    $config = normalize_config(parse_ini_safe($stored_ini), $defaults);

    // Theme is intentionally not defaulted here. An install without an explicit
    // theme falls back to the base theme in Theme\resolve_theme() (index.php);
    // inheriting the seeded theme here instead would silently re-theme
    // existing sites.
    unset($defaults['theme']);

    // Personal-identity values are kept commented in the seeded INI, so supply
    // them as a last-resort fallback for consumers without an inline default
    // (e.g. Response\render_atom_feed() reads author_name directly).
    return array_merge(IDENTITY_FALLBACK, $defaults, $config);
}

/**
 * Placeholder identity compose_config() supplies while author_email and
 * author_name are unset. Readers that must not publish a placeholder as if it
 * were the author's (SocialSpec\contact_vcard()) compare against it.
 */
const IDENTITY_FALLBACK = [
    'author_email' => 'joe.sheeple@example.com',
    'author_name'  => 'Joe Sheeple',
];

/**
 * Themes that no longer ship, each mapped to the theme that renders in its place.
 *
 * Read by retired_theme_replacement().
 */
const RETIRED_THEMES = ['2024' => '2026'];

/**
 * The config keys written as `[section]` blocks of `label = value` entries.
 *
 * Everything else is a single-value setting. This is the shape schema
 * normalize_config() and shape_warnings() work from. It is spelled out rather
 * than inferred from the seeded defaults because the defaults keep the
 * personal-identity and opt-in settings (`author_name`, `site_description`,
 * `404_fallback`, …) commented out, so they are absent from the parsed
 * defaults and inference could not tell they are scalars.
 */
const CONFIG_SECTIONS = [
    'menu_items',
    'footer_items',
    'feeds',
    'preconnect',
    'me',
    'redirections',
    'syndicate_to',
];

/**
 * Returns true when the named config key holds a section rather than a value.
 *
 * The seeded defaults are consulted as well as CONFIG_SECTIONS, so a section
 * added to get_default_ini_text() is classified correctly even if this list is
 * not updated with it.
 *
 * @param string $key The config key.
 * @param array<string, mixed> $defaults The parsed seeded defaults.
 * @return bool
 */
function is_config_section(string $key, array $defaults): bool
{
    return in_array($key, CONFIG_SECTIONS, true) || is_array($defaults[$key] ?? null);
}

/**
 * Coerces the parsed INI into the shapes its readers assume.
 *
 * `parse_ini_string()` is called with sections enabled, so the author picks
 * each key's PHP type by how they write it, and the two spellings are only one
 * bracket apart:
 *
 *   [site_title]        a scalar setting arrives as an array
 *   feeds = <url>       a section arrives as a string
 *   Home[] = /          a section entry arrives as a nested array
 *
 * None of the ~50 consumers expect the other shape. Most of them fatal on it —
 * `escape()`, `wrap_title()` and `str_starts_with()` all take a `string`, so a
 * `[site_title]` header 500s every HTML page, including `/settings`, which is
 * the page the author would use to undo it. The quieter cases are worse: a
 * wrongly-shaped `feeds_draft` made `filter_var()` return false and silently
 * published third-party feed items that should have been held for review, and a
 * wrongly-shaped `posts_per_page` cast to 1.
 *
 * Rather than guard 50 call sites, normalise once here, on the way out of the
 * parser. The seeded defaults declare each documented key's shape, so they are
 * also the schema: a key the defaults hold as an array is a section, anything
 * else is a scalar setting. A value of the wrong shape is dropped, which in
 * compose_config() means the seeded default applies — the same
 * fall-back-to-something-renderable stance parse_ini_safe() takes for INI that
 * does not parse at all. Keys the defaults do not mention (the author's own)
 * keep their value when scalar and are filtered to scalars when not.
 *
 * @param array<string, mixed> $config   The parsed stored config.
 * @param array<string, mixed> $defaults The parsed seeded defaults, used as the shape schema.
 * @return array<string, mixed> The config with every value in its expected shape.
 */
function normalize_config(array $config, array $defaults): array
{
    $normalized = [];

    foreach ($config as $key => $value) {
        if (is_config_section((string) $key, $defaults)) {
            $normalized[$key] = normalize_config_section($value);
            continue;
        }

        if (is_array($value)) {
            // A single-value setting written as a section, or a section the
            // author invented that nothing reads. Neither has a meaning to any
            // consumer, so drop it and let the default stand.
            continue;
        }

        $normalized[$key] = (string) $value;
    }

    return $normalized;
}

/**
 * Reduces a config section to the `label => string` map its readers iterate.
 *
 * A section is only ever consumed as flat label/value pairs — menu links, feed
 * URLs, redirect targets, rel="me" links. A repeated key (`Home[] = /`) nests
 * an array under the label, which then reaches `str_starts_with()` or
 * `escape()` as an array and throws; there is no sensible single value to
 * recover from it, so the entry is dropped and its siblings still render.
 *
 * @param mixed $section The raw parsed section value.
 * @return array<string, string> The section as flat label/value pairs.
 */
function normalize_config_section(mixed $section): array
{
    if (!is_array($section)) {
        return [];
    }

    $normalized = [];
    foreach ($section as $label => $value) {
        if (!is_scalar($value)) {
            continue;
        }
        $normalized[(string) $label] = (string) $value;
    }

    return $normalized;
}

/**
 * Returns the site's explicitly configured canonical URL, or null when unset.
 *
 * Everything else the app knows about its own address is derived from the
 * client-supplied Host header, which an attacker can set at will (see
 * Lamb\Http\request_root_url()). This is the one value the author states
 * deliberately, so it is the only one safe to make a security decision against —
 * notably the IndieAuth `me` comparison that decides whether a Micropub bearer
 * token was issued for *this* site.
 *
 * Read from LAMB_SITE_URL in the environment first (so a deployment can pin it
 * without touching the database), then the `site_url` key in the settings INI.
 * The value is normalised to `scheme://host[:port]` with no trailing slash, and
 * rejected unless it is an absolute http(s) URL with a host.
 *
 * @param array<string, mixed> $config The loaded configuration.
 * @return string|null The canonical site URL, or null when not configured or unusable.
 */
function canonical_site_url(array $config): ?string
{
    return normalize_site_url((string) (getenv('LAMB_SITE_URL') ?: ($config['site_url'] ?? '')));
}

/**
 * Normalises a site URL to `scheme://host[:port]`, or null when it is unusable.
 *
 * The check canonical_site_url() applies to the configured value, kept apart
 * from the environment lookup so the settings form can validate what the
 * author typed even when LAMB_SITE_URL overrides it.
 *
 * @param string $candidate The URL as written.
 * @return string|null The normalised URL, or null unless it is an absolute http(s) URL with a host.
 */
function normalize_site_url(string $candidate): ?string
{
    $candidate = trim($candidate);
    if ($candidate === '') {
        return null;
    }

    $parts = parse_url($candidate);
    if ($parts === false || empty($parts['host'])) {
        return null;
    }
    $scheme = strtolower($parts['scheme'] ?? '');
    if (!in_array($scheme, ['http', 'https'], true)) {
        return null;
    }

    $port = isset($parts['port']) ? ':' . $parts['port'] : '';

    return $scheme . '://' . strtolower($parts['host']) . $port;
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
 * Whether experimental features are enabled for this install — currently the
 * WordPress, Known, and Lamb-export import CLI scripts.
 *
 * @param array<string, mixed> $config The loaded configuration.
 * @return bool
 */
function experimental_features_enabled(array $config): bool
{
    return filter_var($config['experimental_features'] ?? false, FILTER_VALIDATE_BOOLEAN);
}

/**
 * Bumped only when the set of features gated behind `experimental_features`
 * changes (one graduates out, or a new one joins) — not on every release.
 * reset_stale_experimental_flag() uses this to force a re-opt-in when that
 * happens, so enabling the flag once cannot silently keep covering whatever
 * gets added to the gate later.
 */
const EXPERIMENTAL_GATE_VERSION = 1;

/**
 * Forces `experimental_features` back to `false` when the gated feature set
 * has changed since this install last read config, so opting in once cannot
 * silently keep covering something added to the gate later.
 *
 * Tracked via a hidden `experimental_gate_version` option row — not part of
 * the user-edited INI — compared against EXPERIMENTAL_GATE_VERSION. The
 * stamp advances every time this runs behind, even when the flag is already
 * `false`, so a stale stamp cannot cause a rewrite (and a save_ini_text()
 * call) on every single read.
 *
 * @param string $ini_text The raw INI configuration text.
 * @return string The INI text, with `experimental_features` reset if stale.
 */
function reset_stale_experimental_flag(string $ini_text): string
{
    $stamp = get_option('experimental_gate_version', 0);
    if ((int) $stamp->value >= EXPERIMENTAL_GATE_VERSION) {
        return $ini_text;
    }

    set_option($stamp, EXPERIMENTAL_GATE_VERSION);

    if (!experimental_features_enabled(parse_ini_safe($ini_text))) {
        return $ini_text;
    }

    return (string) preg_replace(
        '/^(\h*experimental_features\h*=).*$/mi',
        '${1} false',
        $ini_text,
        1
    );
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
        // Force a re-opt-in to the experimental-features gate whenever the
        // set of features it covers has changed since this install last saw it.
        $ini_text = reset_stale_experimental_flag($option->value);
        if ($ini_text !== $option->value) {
            save_ini_text($ini_text);
        }
        return $ini_text;
    }

    // Bootstrap from the built-in defaults; the author edits from there at
    // /settings.
    $ini_text = get_default_ini_text();

    $ini_text = reset_stale_experimental_flag($ini_text);
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
    // Valid UTF-8, like a post body (see Lamb\normalize_utf8): a stray byte
    // pasted into a setting reaches json_encode() through the JSON feed and the
    // export manifest, and json_encode() refuses the whole document over it.
    $ini_text = \Lamb\normalize_utf8($ini_text);
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
 * Describes the settings normalize_config() will ignore, in the author's terms.
 *
 * The normalisation is deliberately quiet at render time — a wrongly-shaped
 * value falls back to its default rather than taking the site down. But quiet
 * at *save* time would mean typing a setting, being told "Settings saved
 * successfully", and watching it have no effect with nothing to explain why.
 * This is the "communicate failure" half: the settings handler flashes these
 * alongside the success message.
 *
 * Only shape is reported. Whether a value is a usable timezone, URL or theme
 * name is each reader's business and each already has its own fallback.
 *
 * The one exception is a path in `site_url` (see site_url_warning), because that
 * value has no fallback the author can observe — it is silently narrowed and the
 * damage surfaces somewhere else entirely, as a Micropub endpoint refusing every
 * token.
 *
 * Notices about deprecated settings (see deprecation_warnings) are appended, so
 * both save paths report them.
 *
 * @param string $ini_text The raw INI text as the author submitted it.
 * @return list<string> Human-readable warnings, empty when every value fits.
 */
function shape_warnings(string $ini_text): array
{
    $defaults = parse_ini_safe(get_default_ini_text());
    $warnings = [];

    $parsed = parse_ini_safe($ini_text);
    $site_url = $parsed['site_url'] ?? null;
    if (is_scalar($site_url)) {
        $site_url_warning = site_url_warning((string) $site_url);
        if ($site_url_warning !== null) {
            $warnings[] = $site_url_warning;
        }
    }

    foreach (parse_ini_safe($ini_text) as $key => $value) {
        $expects_section = is_config_section((string) $key, $defaults);

        if ($expects_section && !is_array($value)) {
            $warnings[] = "`{$key}` is a list of entries: write it as a `[{$key}]` section. "
                . 'The single value was ignored.';
            continue;
        }

        if (!$expects_section && is_array($value)) {
            $warnings[] = "`{$key}` takes one value: write it as `{$key} = ...`, not a "
                . "`[{$key}]` section. The section was ignored.";
            continue;
        }

        if (!is_array($value)) {
            continue;
        }

        foreach ($value as $label => $entry) {
            if (!is_scalar($entry)) {
                $warnings[] = "`{$label}` appears more than once in `[{$key}]`, "
                    . 'which makes it a list. The entry was ignored.';
            }
        }
    }

    return array_merge($warnings, deprecation_warnings($ini_text));
}

/**
 * Describes the deprecated settings the stored INI still uses, and what replaces them.
 *
 * Each notice names the release that removes the setting (0.17). A setting is
 * only reported when it changes something: the seeded defaults hold
 * `posts_per_page = 10`, `experimental_features = false` and an empty
 * `[preconnect]`, so every install has those keys, and warning about them
 * would show a notice on every save to authors who never set them.
 *
 * @param string $ini_text The raw INI text.
 * @return list<string> Human-readable notices, empty when no deprecated setting is in use.
 */
function deprecation_warnings(string $ini_text): array
{
    $parsed = parse_ini_safe($ini_text);
    $notice = fn (string $key, string $advice): string => "`{$key}` is deprecated and will be removed in 0.17. {$advice}";
    $text = fn (string $key): string => is_scalar($parsed[$key] ?? null) ? trim((string) $parsed[$key]) : '';
    $warnings = [];

    if ($text('posts_per_page') !== '' && $text('posts_per_page') !== '10') {
        $warnings[] = $notice('posts_per_page', 'Lamb will use 10.');
    }
    if ($text('404_fallback') !== '') {
        $warnings[] = $notice('404_fallback', 'Add a `[redirections]` entry for each old address you still want to forward.');
    }
    if (!empty($parsed['preconnect']) && is_array($parsed['preconnect'])) {
        $warnings[] = $notice(
            '[preconnect]',
            'Lamb now adds preconnect hints for the sites your pages load from, so you can delete this section.'
        );
    }
    if (filter_var($text('micropub_debug'), FILTER_VALIDATE_BOOLEAN)) {
        $warnings[] = $notice('micropub_debug', 'Use the LAMB_MICROPUB_DEBUG environment variable instead.');
    }
    if (experimental_features_enabled($parsed)) {
        $warnings[] = $notice('experimental_features', 'Use `bin/lamb import --experimental` instead.');
    }

    $retired_theme = retired_theme_warning($ini_text);
    if ($retired_theme !== null) {
        $warnings[] = $retired_theme;
    }

    return $warnings;
}

/**
 * Returns the theme that renders in place of a retired one, or null when $name is not retired.
 *
 * A site that added its own directory under `src/themes/` with a retired name
 * keeps it, so that directory also returns null.
 *
 * @param string $name The configured theme name.
 * @return string|null The replacement theme, or null when $name renders as itself.
 */
function retired_theme_replacement(string $name): ?string
{
    $replacement = RETIRED_THEMES[$name] ?? null;
    if ($replacement === null || (defined('ROOT_DIR') && is_dir(ROOT_DIR . '/themes/' . $name))) {
        return null;
    }

    return $replacement;
}

/**
 * Warns when the stored `theme` names a theme that no longer ships, or null when it does not.
 *
 * Theme\resolve_theme() renders the replacement, so the site keeps working. The
 * settings form then shows the replacement as selected, and saving writes it, so
 * this notice only appears until the author saves.
 *
 * @param string $ini_text The raw INI text.
 * @return string|null The warning, or null when the theme is not retired.
 */
function retired_theme_warning(string $ini_text): ?string
{
    $theme = parse_ini_safe($ini_text)['theme'] ?? null;
    $theme = is_scalar($theme) ? trim((string) $theme) : '';
    $replacement = retired_theme_replacement($theme);
    if ($replacement === null) {
        return null;
    }

    return "The `{$theme}` theme was removed in 0.17. Lamb renders your site with the `{$replacement}` theme "
        . "instead. Save the settings to keep `{$replacement}`.";
}

/**
 * Warns when a configured site URL carries a path, or null when it is fine.
 *
 * Lamb serves from the root of a domain. canonical_site_url() narrows the
 * configured value to `scheme://host[:port]`, so a path is dropped — and because
 * that value is the author's IndieAuth identity, an install at
 * `https://example.com/blog` ends up comparing `https://example.com` against a
 * token issued for `https://example.com/blog`. Every Micropub token is refused
 * with `me_mismatch`, and nothing on the settings page suggests why (issue #580).
 *
 * A value canonical_site_url() cannot read at all is left alone: it already has
 * its own handling and its own message, and a second one here is noise.
 *
 * @param string $site_url The `site_url` value as the author wrote it.
 * @return string|null The warning, or null when the value has no path.
 */
function site_url_warning(string $site_url): ?string
{
    $site_url = trim($site_url);
    if ($site_url === '' || canonical_site_url(['site_url' => $site_url]) === null) {
        return null;
    }

    $path = trim((string) (parse_url($site_url, PHP_URL_PATH) ?? ''), '/');
    if ($path === '') {
        return null;
    }

    return "`site_url` has a path (`/{$path}`), and Lamb serves from the root of a domain. "
        . 'The path is ignored, so Micropub refuses every token: your identity is the whole '
        . "address including `/{$path}`, and Lamb compares tokens against the domain alone. "
        . 'Serve the site from its own domain or subdomain.';
}

/**
 * The single-value settings the /settings form owns, in the order they are written.
 *
 * `feeds_draft` is also form-owned but is a checkbox, so form_to_ini() handles
 * it apart from these text fields.
 */
const SETTINGS_FORM_FIELDS = [
    'site_title',
    'site_url',
    'site_description',
    'timezone',
    'theme',
    'author_name',
    'author_email',
    'following_url',
    'authorization_endpoint',
    'token_endpoint',
    'websub_hubs',
];

/**
 * The sections the /settings form owns, each posted as one textarea of `label = value` lines.
 *
 * Every other section in the stored INI (`[preconnect]`, an author's own) is
 * carried over untouched by form_to_ini().
 */
const SETTINGS_FORM_SECTIONS = [
    'menu_items',
    'footer_items',
    'feeds',
    'me',
    'redirections',
    'syndicate_to',
];

/**
 * Builds the stored INI text from the settings form fields.
 *
 * Storage stays one INI blob, so existing installs need no migration. The form
 * owns the keys in SETTINGS_FORM_FIELDS, `feeds_draft` and SETTINGS_FORM_SECTIONS:
 * they are rebuilt from the post. Every other key and section in the current
 * INI (deprecated settings, an author's own keys) is carried over unchanged, so
 * saving the form cannot drop a setting it does not show. Comments in the
 * current INI are not kept. An empty text field is left out so the default
 * applies; an owned section is always written, because an absent `[menu_items]`
 * brings back the default menu while an empty one means no menu.
 *
 * Call settings_form_errors() first: lines it reports as malformed are skipped here.
 *
 * @param array<string, mixed> $post        The submitted form fields.
 * @param string               $current_ini The INI text currently stored.
 * @return string The INI text to store.
 * @throws \InvalidArgumentException When a posted value spans lines (settings_form_errors() reports it).
 */
function form_to_ini(array $post, string $current_ini): string
{
    $values = [];
    foreach (SETTINGS_FORM_FIELDS as $key) {
        $value = settings_form_value($post, $key);
        if ($value !== '') {
            $values[$key] = $value;
        }
    }
    $values['feeds_draft'] = array_key_exists('feeds_draft', $post) ? 'true' : 'false';

    $sections = [];
    foreach (SETTINGS_FORM_SECTIONS as $name) {
        $sections[$name] = parse_section_text(settings_form_value($post, $name, false))['entries'];
    }

    $owned = [...SETTINGS_FORM_FIELDS, 'feeds_draft', ...SETTINGS_FORM_SECTIONS];
    foreach (parse_ini_safe($current_ini) as $key => $value) {
        if (in_array((string) $key, $owned, true)) {
            continue;
        }
        if (is_array($value)) {
            // A repeated label (`Home[] = /`) nests an array; readers drop it
            // anyway (see normalize_config_section), so it is not carried over.
            $sections[$key] = array_filter($value, 'is_string');
            continue;
        }
        $values[$key] = (string) $value;
    }

    return write_ini($values, $sections);
}

/**
 * Lists what stops the settings form being saved, in the author's terms.
 *
 * Site title, site URL and author name are required: an unset author name
 * publishes the placeholder identity in feeds. The site URL must pass the check
 * canonical_site_url() applies. A section line that cannot be read, or a value
 * that would not survive the INI file, is reported with the section and line.
 *
 * The stored INI is checked too: form_to_ini() carries over what it holds, and
 * INI that does not parse has nothing to carry, so saving over it would silently
 * drop every setting the form does not show. Comments-only or empty INI parses
 * fine and is not refused.
 *
 * @param array<string, mixed> $post        The submitted form fields.
 * @param string               $current_ini The INI text currently stored.
 * @return list<string> Human-readable errors, empty when the form can be saved.
 */
function settings_form_errors(array $post, string $current_ini = ''): array
{
    $errors = [];

    if (@parse_ini_string($current_ini, true, INI_SCANNER_RAW) === false) {
        $errors[] = "The settings stored on this site can't be read, so saving this form would lose them. "
            . 'Fix the stored settings first, or reset them to the defaults.';
    }

    $required = ['site_title' => 'Site title', 'site_url' => 'Site URL', 'author_name' => 'Author name'];
    foreach ($required as $key => $label) {
        if (settings_form_value($post, $key) === '') {
            $errors[] = "{$label} is required.";
        }
    }

    $site_url = settings_form_value($post, 'site_url');
    if ($site_url !== '' && normalize_site_url($site_url) === null) {
        $errors[] = 'Site URL must be a full web address, such as https://example.com.';
    }

    foreach (SETTINGS_FORM_FIELDS as $key) {
        if (preg_match('/[\r\n\0]/', settings_form_value($post, $key)) === 1) {
            $errors[] = "`{$key}` must fit on one line.";
        }
    }

    foreach (SETTINGS_FORM_SECTIONS as $name) {
        foreach (parse_section_text(settings_form_value($post, $name, false))['errors'] as $error) {
            $errors[] = "`[{$name}]` {$error}";
        }
    }

    return $errors;
}

/**
 * Returns the values the /settings form shows.
 *
 * They come from the stored INI, not from load(): the effective config fills an
 * unset author with IDENTITY_FALLBACK, and a form prefilled with that
 * placeholder would save it as the author's own. A field the stored INI lacks is
 * empty, except `timezone` (UTC, as apply_timezone() falls back to) and the
 * `feeds_draft` checkbox (on, as its reader defaults to). `theme` is returned as
 * stored: the template resolves an unset one to the theme in use. Deprecated
 * keys are not offered.
 *
 * Fields in $posted, a refused submission (see respond_settings()), replace the
 * stored ones, even when posted empty. A submission without `feeds_draft` means
 * the box was unchecked.
 *
 * @param string               $stored_ini The INI text currently stored.
 * @param array<string, mixed> $posted     The refused submission, or [] to show the stored settings.
 * @return array{fields: array<string, string>, feeds_draft: bool} The text of every field and
 *     section textarea (one `label = value` line per entry), and whether the checkbox is checked.
 */
function settings_form_prefill(string $stored_ini, array $posted = []): array
{
    $stored = parse_ini_safe($stored_ini);
    $fields = [];

    foreach (SETTINGS_FORM_FIELDS as $key) {
        $fields[$key] = array_key_exists($key, $posted)
            ? settings_form_value($posted, $key)
            : (is_scalar($stored[$key] ?? null) ? trim((string) $stored[$key]) : '');
    }
    if (!in_array($fields['timezone'], \DateTimeZone::listIdentifiers(), true)) {
        $fields['timezone'] = 'UTC';
    }

    foreach (SETTINGS_FORM_SECTIONS as $name) {
        if (array_key_exists($name, $posted)) {
            $fields[$name] = settings_form_value($posted, $name, false);
            continue;
        }
        $lines = [];
        foreach (normalize_config_section($stored[$name] ?? null) as $label => $value) {
            $lines[] = "{$label} = {$value}";
        }
        $fields[$name] = implode("\n", $lines);
    }

    $feeds_draft = is_scalar($stored['feeds_draft'] ?? null) ? $stored['feeds_draft'] : true;

    return [
        'fields' => $fields,
        'feeds_draft' => $posted === []
            ? filter_var($feeds_draft, FILTER_VALIDATE_BOOLEAN)
            : array_key_exists('feeds_draft', $posted),
    ];
}

/**
 * Reads a posted form field as text, treating a missing or array value as empty.
 *
 * A section textarea is read untrimmed, so the line numbers in its errors
 * count from the first line the author sees.
 *
 * @param array<string, mixed> $post The submitted form fields.
 * @param string               $key  The field name.
 * @param bool                 $trim Whether to trim surrounding whitespace.
 * @return string The value.
 */
function settings_form_value(array $post, string $key, bool $trim = true): string
{
    $value = \Lamb\Http\request_string($post[$key] ?? null) ?? '';

    return $trim ? trim($value) : $value;
}

/**
 * Parses a section textarea of `label = value` lines into ordered entries.
 *
 * Blank lines and `;` comment lines are skipped, and the line splits at its
 * first `=`, so a value may contain more of them (`/search?q=a`). A line is
 * reported, and left out of the entries, when it has no `=`, no label, a label
 * already used above it, a label the INI file cannot hold (a `[`, `"`, `&`
 * and so on, which would fail to parse or turn the entry into a list), or a
 * NUL byte.
 *
 * @param string $text The textarea content.
 * @return array{entries: array<string, string>, errors: list<string>} The entries in order, and one
 *     error per rejected line, each starting with its 1-based line number.
 */
function parse_section_text(string $text): array
{
    $entries = [];
    $errors = [];

    foreach (preg_split('/\R/', $text) ?: [] as $index => $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === ';') {
            continue;
        }

        $number = $index + 1;
        if (str_contains($line, "\0")) {
            $errors[] = "line {$number}: it contains a NUL byte, which cannot be saved.";
            continue;
        }

        $split = strpos($line, '=');
        if ($split === false) {
            $errors[] = "line {$number}: `{$line}` needs a label, an `=` and a value, like `label = value`.";
            continue;
        }

        $label = trim(substr($line, 0, $split));
        if ($label === '') {
            $errors[] = "line {$number}: there is no label before the `=`.";
        } elseif (parse_ini_safe("[s]\n{$label} = x\n") !== ['s' => [$label => 'x']]) {
            $errors[] = "line {$number}: `{$label}` cannot be used as a label.";
        } elseif (array_key_exists($label, $entries)) {
            $errors[] = "line {$number}: `{$label}` appears more than once.";
        } else {
            $entries[$label] = trim(substr($line, $split + 1));
        }
    }

    return ['entries' => $entries, 'errors' => $errors];
}

/**
 * Writes settings as INI text that parse_ini_safe() reads back exactly.
 *
 * Single values come first, then one `[section]` block per section, empty
 * ones included. A value is wrapped in double quotes when the INI_SCANNER_RAW
 * parser would otherwise misread it: leading or trailing space, an empty value,
 * or a `;`, `=`, `"`, `[` or `]` in it. The raw parser has no escape for a line
 * break, so a value or label containing one is refused.
 *
 * @param array<string, string>                $values   The single-value settings.
 * @param array<string, array<string, string>> $sections The sections, as label => value entries.
 * @return string The INI text.
 * @throws \InvalidArgumentException When a key, label or value contains a line break or NUL.
 */
function write_ini(array $values, array $sections): string
{
    $lines = [];
    foreach ($values as $key => $value) {
        $lines[] = ini_line((string) $key, $value);
    }
    foreach ($sections as $name => $entries) {
        if ($lines !== []) {
            $lines[] = '';
        }
        $lines[] = "[{$name}]";
        foreach ($entries as $label => $value) {
            $lines[] = ini_line((string) $label, $value);
        }
    }

    return implode("\n", $lines) . "\n";
}

/**
 * Writes one `key = value` INI line, quoting the value when the raw parser needs it.
 *
 * @param string $key   The key or label.
 * @param string $value The value.
 * @return string The line, without a line break.
 * @throws \InvalidArgumentException When the key or value contains a line break or NUL.
 */
function ini_line(string $key, string $value): string
{
    if (preg_match('/[\r\n\0]/', $key . $value) === 1) {
        throw new \InvalidArgumentException("The setting `{$key}` cannot be saved: it contains a line break.");
    }

    $needs_quotes = $value === '' || $value !== trim($value) || preg_match('/[;"=\[\]]/', $value) === 1;

    return $key . ' = ' . ($needs_quotes ? '"' . $value . '"' : $value);
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

<?php

namespace Lamb\WordPress;

use RedBeanPHP\OODBBean;
use SimpleXMLElement;

use function Lamb\Import\html_to_markdown as import_html_to_markdown;
use function Lamb\Import\import_item as shared_import_item;
use function Lamb\Import\import_uuid;
use function Lamb\Import\parse_rss_file;
use function Lamb\Import\parse_rss_string;
use function Lamb\Import\skip_reason as shared_skip_reason;
use function Lamb\Import\should_import as shared_should_import;
use function Lamb\Import\store_redirect;

const WXR_NS = 'http://wordpress.org/export/1.2/';
const CONTENT_NS = 'http://purl.org/rss/1.0/modules/content/';

// WordPress's WXR export carries top-level posts and standalone pages — see
// should_import().
const ALLOWED_TYPES = ['post', 'page'];

/**
 * Stable dedup key for a WordPress post, stored on the post's `import_uuid`
 * column, so re-running an import never recreates a row. Imported posts are
 * the author's own content, so they deliberately do NOT get feed identity
 * (`feed_name`/`feeditem_uuid`) — see DECISIONS.md.
 */
function wordpress_uuid(string $guid): string
{
    return import_uuid('wordpress-', $guid);
}

/**
 * Loads a WXR XML string into a SimpleXMLElement.
 */
function parse_wxr_string(string $xml): SimpleXMLElement
{
    return parse_rss_string($xml);
}

/**
 * Loads a WXR XML file from disk.
 */
function parse_wxr_file(string $path): SimpleXMLElement
{
    return parse_rss_file($path);
}

/**
 * Extracts every <item> from the channel as a normalised assoc array.
 * Non-namespaced fields (title, link, guid, pubDate) sit alongside the WP
 * namespace fields (post_type, status, post_date, post_id) and the content
 * namespace field (content:encoded), so downstream code never has to think
 * about XML namespaces again.
 *
 * @return list<array{
 *   title:string, guid:string, link:string, content:string,
 *   post_type:string, status:string, post_id:string, slug:string,
 *   created:string, updated:string, tags:list<string>
 * }>
 */
function extract_items(SimpleXMLElement $rss): array
{
    $items = [];
    foreach ($rss->channel->item ?? [] as $item) {
        $wp = $item->children(WXR_NS);
        $content = $item->children(CONTENT_NS);

        $pub_date = trim((string) ($item->pubDate ?? ''));
        $created = wxr_local_datetime(
            trim((string) ($wp->post_date_gmt ?? '')),
            trim((string) ($wp->post_date ?? '')),
            $pub_date,
        );
        $updated = wxr_local_datetime(
            trim((string) ($wp->post_modified_gmt ?? '')),
            trim((string) ($wp->post_modified ?? '')),
            '',
        );
        if ($updated === '') {
            $updated = $created;
        }

        $tags = [];
        foreach ($item->category ?? [] as $cat) {
            $domain = (string) $cat['domain'];
            if ($domain !== 'category' && $domain !== 'post_tag') {
                continue;
            }
            $nicename = trim((string) $cat['nicename']);
            if ($nicename === '') {
                $nicename = \Lamb\Post\slugify(trim((string) $cat));
            }
            if ($nicename !== '') {
                $tags[] = $nicename;
            }
        }

        $items[] = [
            'title'     => trim((string) ($item->title ?? '')),
            'guid'      => trim((string) ($item->guid ?? '')),
            'link'      => trim((string) ($item->link ?? '')),
            'content'   => (string) ($content->encoded ?? ''),
            'post_type' => trim((string) ($wp->post_type ?? '')),
            'status'    => trim((string) ($wp->status ?? '')),
            'post_id'   => trim((string) ($wp->post_id ?? '')),
            'slug'      => trim((string) ($wp->post_name ?? '')),
            'created'   => $created,
            'updated'   => $updated,
            'tags'      => array_values(array_unique($tags)),
        ];
    }
    return $items;
}

/**
 * Resolves a WP date triple (`*_gmt`, `*`, RSS `pubDate`) to a local
 * `Y-m-d H:i:s` string in the site's configured timezone.
 *
 * Prefers the GMT field because it carries an unambiguous UTC timestamp.
 * Falls back to the timezone-less local field (best-effort: WP doesn't
 * emit its own offset, so the string is treated as already-local) and
 * finally the RFC822 `pubDate` (which does carry an offset). WP uses the
 * zero sentinel `0000-00-00 00:00:00` for drafts; both date fields are
 * skipped when they match it. Returns '' when nothing parses.
 */
function wxr_local_datetime(string $gmt, string $local, string $pub_date): string
{
    if ($gmt !== '' && $gmt !== '0000-00-00 00:00:00') {
        $ts = strtotime($gmt . ' UTC');
        if ($ts !== false) {
            return date('Y-m-d H:i:s', $ts);
        }
    }
    if ($local !== '' && $local !== '0000-00-00 00:00:00') {
        return $local;
    }
    if ($pub_date !== '') {
        $ts = strtotime($pub_date);
        if ($ts !== false) {
            return date('Y-m-d H:i:s', $ts);
        }
    }
    return '';
}

/**
 * First-pass scope: published posts and pages only. Drafts, private posts,
 * custom post types, attachments and revisions are skipped.
 *
 * @param array<string, mixed> $item
 * @return bool
 * @throws void
 */
function should_import(array $item): bool
{
    return shared_should_import($item, ALLOWED_TYPES);
}

/**
 * Explains why an item falls outside import scope, or null when it should be
 * imported. Single source of truth for should_import(); the importer uses the
 * reason string to break down its skipped tally.
 *
 * @param array<string, mixed> $item
 * @return ?string
 * @throws void
 */
function skip_reason(array $item): ?string
{
    return shared_skip_reason($item, ALLOWED_TYPES);
}

/**
 * Converts the already-sanitised WordPress HTML body to Markdown.
 *
 * Delegates to the shared converter with the `wp-block-` unwrap prefix, so
 * WordPress's block-editor wrapper divs/sections (`wp-block-buttons`,
 * `wp-block-image`, …) are unwrapped the same way they always have been.
 */
function html_to_markdown(string $html): string
{
    return import_html_to_markdown($html, ['wp-block-']);
}

/**
 * Runs a single WXR item through the standard post pipeline.
 *
 * Returns null when the item is out of scope (drafts, custom post types). When
 * an item with the same `wordpress_uuid()` already exists the existing bean is
 * returned untouched — re-running an import is therefore safe and idempotent.
 *
 * Passing $bean (run_import() does so under `--replace`) re-imports into that
 * row instead: everything the WXR describes is written over it, in place, so
 * the row keeps its id and its import_uuid and any local edit since the
 * original import is lost.
 *
 * No outbound webmentions or WebSub pings are emitted: the call path stops at
 * save() without `notify`, so no post.published event fires.
 *
 * @param array<string, mixed>            $item       Item from extract_items().
 * @param callable(string,string):?string $downloader Image downloader.
 * @param OODBBean|null                   $bean       Existing row to overwrite.
 * @throws \RedBeanPHP\RedException\SQL When the store fails.
 */
function import_item(array $item, callable $downloader, bool $dry_run = false, ?OODBBean $bean = null): ?OODBBean
{
    return shared_import_item(
        $item,
        [
            'uuid' => wordpress_uuid(...),
            'allowed_types' => ALLOWED_TYPES,
            'title' => static fn(array $item): string => (string) $item['title'],
            'slug' => wordpress_slug(...),
            'dom_pass' => null,
            'markdown' => html_to_markdown(...),
            'tags' => static fn(array $item, string $markdown): array => array_values(
                array_map(static fn($t): string => (string) $t, (array) ($item['tags'] ?? []))
            ),
            'finalize_markdown' => static fn(array $item, string $markdown): string => $markdown,
            'store_redirects' => store_source_redirect(...),
        ],
        $downloader,
        $dry_run,
        $bean,
    );
}

/**
 * Drops the slug for a purely numeric `<wp:post_name>` — see
 * {@see is_numeric_wordpress_slug} — so the post falls through to Lamb's
 * native `/status/<id>` permalink instead of pinning a bare-number URL.
 *
 * @param array<string, mixed> $item
 * @return string
 * @throws void
 */
function wordpress_slug(array $item): string
{
    return is_numeric_wordpress_slug($item) ? '' : (string) ($item['slug'] ?? '');
}

/**
 * True when `<wp:post_name>` is purely numeric — e.g. `26`, `633`. WordPress
 * hands these out when a post has no title (the post id leaks into the
 * slug column), regardless of the site's permalink structure: even a site
 * using the default "Plain" structure (`<link>` shaped as a bare `?p=59`
 * query string, no path at all) still records this numeric post_name
 * internally. Pinning such a slug locally produces a Lamb URL like `/26`,
 * which visually collides with the canonical `/status/<id>` shape and can
 * shadow it on lookup.
 *
 * Deliberately independent of whether {@see wordpress_status_path} can also
 * derive an old redirect path from `<link>` — those are two different
 * questions. A "Plain"-permalink site has no old path worth redirecting
 * from, but the slug still needs dropping.
 *
 * @param array<string, mixed> $item
 */
function is_numeric_wordpress_slug(array $item): bool
{
    $slug = trim((string) ($item['slug'] ?? ''));
    return $slug !== '' && ctype_digit($slug);
}

/**
 * Returns the original WP path (slashes trimmed) when `<wp:post_name>` is
 * purely numeric (see {@see is_numeric_wordpress_slug}) and `<link>` carries
 * a usable path to redirect from.
 *
 * The slug is always dropped for a numeric post_name — see
 * is_numeric_wordpress_slug() — but a redirect can only be captured when the
 * source site's permalink structure put the old path somewhere ("Plain"
 * permalinks don't; the post falls through to /status/<id> with no old path
 * to redirect away from).
 *
 * @param array<string, mixed> $item
 */
function wordpress_status_path(array $item): ?string
{
    if (!is_numeric_wordpress_slug($item)) {
        return null;
    }
    $path = parse_url((string) ($item['link'] ?? ''), PHP_URL_PATH);
    if (!is_string($path) || $path === '' || $path === '/') {
        return null;
    }
    return trim($path, '/');
}

/**
 * Stores an automatic redirect from an imported WordPress URL path to the
 * local Lamb path. An old path that already matches Lamb's freshly minted
 * permalink is dropped by store_redirect(), which every importer shares.
 *
 * @param array<string, mixed> $item
 */
function store_source_redirect(array $item, OODBBean $bean): void
{
    $from = wordpress_status_path($item);
    if ($from === null) {
        return;
    }

    store_redirect($from, \Lamb\permalink_path($bean));
}

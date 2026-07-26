<?php

namespace Lamb\WordPress;

use RedBeanPHP\OODBBean;
use RedBeanPHP\R;
use SimpleXMLElement;

use function Lamb\Import\build_post_body;
use function Lamb\Import\html_to_markdown as import_html_to_markdown;
use function Lamb\Import\import_uuid;
use function Lamb\Import\parse_rss_file;
use function Lamb\Import\parse_rss_string;
use function Lamb\Import\prepare_imported_html;
use function Lamb\Import\store_redirect;
use function Lamb\Post\finalize_and_store_post;
use function Lamb\Post\populate_bean;

const WXR_NS = 'http://wordpress.org/export/1.2/';
const CONTENT_NS = 'http://purl.org/rss/1.0/modules/content/';

/**
 * Stable dedup key for a WordPress post. Mirrors the feed-ingest convention
 * (`feeditem_uuid = md5($feed_name . $id)`), so re-running an import never
 * recreates a row.
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
 */
function should_import(array $item): bool
{
    return skip_reason($item) === null;
}

/**
 * Explains why an item falls outside import scope, or null when it should be
 * imported. Single source of truth for should_import(); the importer uses the
 * reason string to break down its skipped tally.
 *
 * @param array<string, mixed> $item
 */
function skip_reason(array $item): ?string
{
    $type   = (string) ($item['post_type'] ?? '');
    $status = (string) ($item['status'] ?? '');
    if (!in_array($type, ['post', 'page'], true)) {
        return "unsupported post_type '" . ($type === '' ? '(none)' : $type) . "'";
    }
    if ($status !== 'publish') {
        return "non-published status '" . ($status === '' ? '(none)' : $status) . "'";
    }
    return null;
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
 * No outbound webmentions or WebSub pings are emitted: the call path stops at
 * finalize_and_store_post(), which never invokes notify_post_subscribers().
 *
 * @param array<string, mixed>            $item       Item from extract_items().
 * @param callable(string,string):?string $downloader Image downloader.
 */
function import_item(array $item, callable $downloader, bool $dry_run = false): ?OODBBean
{
    if (!should_import($item)) {
        return null;
    }

    $uuid = wordpress_uuid((string) $item['guid']);
    $existing = R::findOne('post', ' feeditem_uuid = ? ', [$uuid]);
    if ($existing) {
        return $existing;
    }

    // Sanitize and image-rewrite share one DOM so the body is parsed and
    // serialised once for these two passes (a third parse happens inside
    // html_to_markdown for normalize_html, which has to stay on its own DOM
    // because of its placeholder-string substitution dance).
    $body_html = (string) ($item['content'] ?? '');
    $prepared = $body_html === ''
        ? ''
        : prepare_imported_html($body_html, (string) $item['created'], $downloader);
    $markdown = html_to_markdown($prepared);
    $tags = array_values(array_map(static fn($t): string => (string) $t, (array) ($item['tags'] ?? [])));
    $slug = wordpress_status_path($item) !== null ? '' : (string) ($item['slug'] ?? '');
    $body = build_post_body((string) $item['title'], $markdown, $tags, $slug);

    $bean = populate_bean($body);
    if (!empty($item['created'])) {
        $bean->created = (string) $item['created'];
    }
    if (!empty($item['updated'])) {
        $bean->updated = (string) $item['updated'];
    }
    $bean->feeditem_uuid = $uuid;
    $bean->feed_name = 'wordpress';

    if ($dry_run) {
        return $bean;
    }

    finalize_and_store_post($bean);
    store_source_redirect($item, $bean);
    return $bean;
}

/**
 * Returns the original WP path (slashes trimmed) when `<wp:post_name>` is
 * purely numeric — e.g. `26`, `633`. WordPress hands these out when a post
 * has no title (the post id leaks into the URL). Pinning such a slug locally
 * produces a Lamb URL like `/26`, which visually collides with the canonical
 * `/status/<id>` shape and can shadow it on lookup.
 *
 * Instead we drop the slug, let the post fall through to its `/status/<id>`
 * permalink, and capture the original WP path as a redirect.
 *
 * @param array<string, mixed> $item
 */
function wordpress_status_path(array $item): ?string
{
    $slug = trim((string) ($item['slug'] ?? ''));
    if ($slug === '' || !ctype_digit($slug)) {
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
 * local Lamb path. The importer only creates redirects for old paths that do
 * not naturally match Lamb's freshly minted permalink.
 *
 * @param array<string, mixed> $item
 */
function store_source_redirect(array $item, OODBBean $bean): void
{
    $from = wordpress_status_path($item);
    if ($from === null) {
        return;
    }

    $to = $bean->slug ? '/' . $bean->slug : '/status/' . $bean->id;
    if ($from === ltrim($to, '/')) {
        return;
    }

    store_redirect($from, $to);
}

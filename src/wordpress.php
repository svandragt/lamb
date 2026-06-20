<?php

namespace Lamb\WordPress;

use DOMDocument;
use DOMElement;
use DOMXPath;
use League\HTMLToMarkdown\HtmlConverter;
use RedBeanPHP\OODBBean;
use RedBeanPHP\R;
use RuntimeException;
use SimpleXMLElement;
use Symfony\Component\Yaml\Yaml;

use function Lamb\Http\fetch;
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
    return md5('wordpress-' . $guid);
}

/**
 * Loads a WXR XML string into a SimpleXMLElement.
 */
function parse_wxr_string(string $xml): SimpleXMLElement
{
    $previous = libxml_use_internal_errors(true);
    $rss = simplexml_load_string($xml);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if ($rss === false) {
        throw new RuntimeException('Could not parse WXR XML.');
    }
    return $rss;
}

/**
 * Loads a WXR XML file from disk.
 */
function parse_wxr_file(string $path): SimpleXMLElement
{
    $xml = @file_get_contents($path);
    if ($xml === false) {
        throw new RuntimeException("Could not read WXR file: $path");
    }
    return parse_wxr_string($xml);
}

/**
 * Returns the WP site's host (from <wp:base_blog_url> or <wp:base_site_url>),
 * used to restrict image downloads to the source site. Null when neither tag is
 * present or parseable.
 */
function extract_site_host(SimpleXMLElement $rss): ?string
{
    $channel = $rss->channel ?? null;
    if (!$channel) {
        return null;
    }
    foreach (['base_blog_url', 'base_site_url'] as $tag) {
        $node = $channel->children(WXR_NS)->$tag ?? null;
        $url = $node ? trim((string) $node) : '';
        if ($url !== '') {
            $host = parse_url($url, PHP_URL_HOST);
            if (is_string($host) && $host !== '') {
                return $host;
            }
        }
    }
    return null;
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
    return ($item['status'] ?? '') === 'publish'
        && in_array($item['post_type'] ?? '', ['post', 'page'], true);
}

/**
 * Removes executable / styling tags and any `on*` event-handler attributes.
 *
 * WP body HTML is untrusted enough to warrant a defensive scrub before it
 * touches the Markdown converter. Tag removal is done at DOM level so nested
 * markup doesn't break the strip; event-handler attributes are walked off
 * every element regardless of case.
 */
function sanitize_html(string $html): string
{
    if (trim($html) === '') {
        return '';
    }
    $dom = load_html_fragment($html);
    $xpath = new DOMXPath($dom);

    $strip = $xpath->query('//script | //style | //iframe') ?: [];
    foreach ($strip as $node) {
        if ($node instanceof \DOMNode) {
            $node->parentNode?->removeChild($node);
        }
    }

    $elements = $xpath->query('//*') ?: [];
    foreach ($elements as $el) {
        if (!$el instanceof DOMElement) {
            continue;
        }
        $remove = [];
        foreach ($el->attributes as $attr) {
            if (stripos($attr->nodeName, 'on') === 0) {
                $remove[] = $attr->nodeName;
            }
        }
        foreach ($remove as $name) {
            $el->removeAttribute($name);
        }
    }

    return dump_html_fragment($dom);
}

/**
 * Converts the already-sanitised HTML body to Markdown.
 *
 * The HtmlConverter is configured to keep unknown HTML so a malformed snippet
 * doesn't silently disappear; hard_break = true preserves <br> as a visible
 * line break in source.
 */
function html_to_markdown(string $html): string
{
    $placeholders = [];
    $html = normalize_wordpress_html($html, $placeholders);
    $converter = new HtmlConverter([
        'header_style'    => 'atx',
        'strip_tags'      => false,
        'remove_nodes'    => '',
        'hard_break'      => true,
        'use_autolinks'   => true,
        'preserve_comments' => false,
    ]);
    $markdown = strtr(trim($converter->convert($html)), $placeholders);
    $markdown = html_entity_decode($markdown, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    return separate_images_from_following_text($markdown);
}

function separate_images_from_following_text(string $markdown): string
{
    return preg_replace('/(!\[[^\]]*]\([^)]+\))(?=\S)/', "$1\n\n", $markdown) ?? $markdown;
}

/**
 * Normalises WordPress editor HTML before Markdown conversion.
 *
 * The Markdown converter preserves unknown HTML by design. WordPress exports
 * often contain presentational block wrappers, tables and video tags that would
 * otherwise survive into Lamb's safe Markdown renderer as visible escaped tags.
 */
/**
 * @param array<string, string> $placeholders
 */
function normalize_wordpress_html(string $html, array &$placeholders = []): string
{
    if (trim($html) === '') {
        return '';
    }

    $dom = load_html_fragment($html);
    $xpath = new DOMXPath($dom);

    $tables = $xpath->query('//table') ?: [];
    foreach ($tables as $table) {
        if (!$table instanceof DOMElement || $table->parentNode === null) {
            continue;
        }
        $key = 'LAMBTABLEPLACEHOLDER' . count($placeholders);
        $placeholders[$key] = markdown_table($table);
        $table->parentNode->replaceChild($dom->createTextNode("\n\n$key\n\n"), $table);
    }

    $videos = $xpath->query('//video[@src]') ?: [];
    foreach ($videos as $video) {
        if (!$video instanceof DOMElement || $video->parentNode === null) {
            continue;
        }
        $src = $video->getAttribute('src');
        $link = $dom->createElement('a');
        $link->setAttribute('href', $src);
        $link->appendChild($dom->createTextNode($src));
        $p = $dom->createElement('p');
        $p->appendChild($link);
        $video->parentNode->replaceChild($p, $video);
    }

    do {
        $changed = false;
        $wrappers = $xpath->query(
            '//*[self::figure or self::figcaption or self::cite or self::span'
            . ' or ((self::div or self::section)'
            . ' and contains(concat(" ", normalize-space(@class), " "), " wp-block-"))]'
        ) ?: [];
        foreach ($wrappers as $wrapper) {
            if (!$wrapper instanceof DOMElement || $wrapper->parentNode === null) {
                continue;
            }
            unwrap_element($dom, $wrapper, in_array($wrapper->tagName, ['div', 'section', 'figure', 'figcaption'], true));
            $changed = true;
        }
    } while ($changed);

    return dump_html_fragment($dom);
}

function unwrap_element(DOMDocument $dom, DOMElement $element, bool $block): void
{
    $parent = $element->parentNode;
    if ($parent === null) {
        return;
    }
    if ($block) {
        $parent->insertBefore($dom->createTextNode("\n\n"), $element);
    }
    while ($element->firstChild !== null) {
        $parent->insertBefore($element->firstChild, $element);
    }
    if ($block) {
        $parent->insertBefore($dom->createTextNode("\n\n"), $element);
    }
    $parent->removeChild($element);
}

function markdown_table(DOMElement $table): string
{
    $xpath = new DOMXPath($table->ownerDocument);
    $rows = [];
    foreach ($xpath->query('.//tr', $table) ?: [] as $tr) {
        if (!$tr instanceof DOMElement) {
            continue;
        }
        $cells = [];
        foreach ($xpath->query('./th|./td', $tr) ?: [] as $cell) {
            if (!$cell instanceof DOMElement) {
                continue;
            }
            $cells[] = markdown_table_cell($cell);
        }
        if ($cells !== []) {
            $rows[] = ['cells' => $cells];
        }
    }
    if ($rows === []) {
        return '';
    }

    $columnCount = max(array_map(static fn(array $row): int => count($row['cells']), $rows));
    $lines = [];
    $first = array_shift($rows);
    $lines[] = markdown_table_row($first['cells'], $columnCount);
    $lines[] = markdown_table_row(array_fill(0, $columnCount, '---'), $columnCount);
    foreach ($rows as $row) {
        $lines[] = markdown_table_row($row['cells'], $columnCount);
    }

    return "\n\n" . implode("\n", $lines) . "\n\n";
}

function markdown_table_cell(DOMElement $cell): string
{
    $html = '';
    foreach ($cell->childNodes as $child) {
        $html .= $cell->ownerDocument->saveHTML($child);
    }
    $markdown = html_to_markdown($html);
    $markdown = preg_replace('/\s+/', ' ', $markdown) ?? $markdown;
    return str_replace('|', '\\|', trim($markdown));
}

/**
 * @param list<string> $cells
 */
function markdown_table_row(array $cells, int $columnCount): string
{
    $cells = array_pad($cells, $columnCount, '');
    return '| ' . implode(' | ', $cells) . ' |';
}

/**
 * Renders a post body with a YAML front-matter block (title, slug) and inline
 * #hashtags.
 *
 * Tags are appended to the body as #hashtag tokens because Lamb's tag index
 * scans the body for `#tag` rather than reading a separate column.
 *
 * An explicit `slug:` line — sourced from the WP `<wp:post_name>` — pins the
 * post's URL to its original WordPress permalink leaf. parse_matter() only
 * derives a slug from the title when none is set, so the WP slug survives the
 * normal save pipeline. finalize_slug() still suffixes `-id` on a reserved-
 * route or collision, so a duplicate WP slug can't override an existing post.
 *
 * @param list<string> $tags
 */
function build_post_body(string $title, string $markdown_body, array $tags, string $slug = ''): string
{
    $title = trim($title);
    $slug = trim($slug);
    $tagLine = '';
    if ($tags !== []) {
        $tagLine = "\n\n" . implode(' ', array_map(static fn(string $t): string => '#' . $t, $tags));
    }

    $body = trim($markdown_body) . $tagLine . "\n";

    $front = [];
    if ($title !== '') {
        $front['title'] = $title;
    }
    if ($slug !== '') {
        $front['slug'] = $slug;
    }
    if ($front === []) {
        return $body;
    }
    $matter = rtrim(Yaml::dump($front), "\n");
    return "---\n" . $matter . "\n---\n\n" . $body;
}

/**
 * Returns the YYYY/MM subpath under src/assets/ for a post's created date.
 * Falls back to the current month when the date can't be parsed.
 */
function asset_dir_for_date(string $created): string
{
    $ts = $created ? strtotime($created) : false;
    if ($ts === false) {
        $ts = time();
    }
    return date('Y/m', $ts);
}

/**
 * Walks an HTML fragment, finds `<img src>` URLs whose host matches $site_host,
 * hands each URL to $downloader, and rewrites the src to a root-relative
 * `assets/YYYY/MM/<filename>` URL on success. Off-site images and failed
 * downloads are left untouched.
 *
 * @param callable(string,string):?string $downloader  ($url, $dest_dir) → saved filename or null.
 */
function rewrite_image_links(string $html, string $site_host, string $created, callable $downloader): string
{
    if (trim($html) === '') {
        return '';
    }
    $dom = load_html_fragment($html);
    $xpath = new DOMXPath($dom);
    $sub_path = asset_dir_for_date($created);

    $imgs = $xpath->query('//img[@src]') ?: [];
    foreach ($imgs as $img) {
        if (!$img instanceof DOMElement) {
            continue;
        }
        $src = $img->getAttribute('src');
        $host = parse_url($src, PHP_URL_HOST);
        if (!is_string($host) || strcasecmp($host, $site_host) !== 0) {
            continue;
        }
        $filename = $downloader($src, $sub_path);
        if (!is_string($filename) || $filename === '') {
            continue;
        }
        $img->setAttribute('src', "assets/$sub_path/$filename");
    }

    return dump_html_fragment($dom);
}

/**
 * Default downloader used by the CLI script: fetches $url over HTTP and writes
 * it under ROOT_DIR/assets/$sub_path with a content-hash filename. JPEG/PNG
 * are re-encoded to WebP via the shared upload pipeline (store_webp_copy).
 * Returns the filename relative to the subdirectory, or null on any failure.
 */
function default_image_downloader(string $url, string $sub_path): ?string
{
    if (!defined('ROOT_DIR')) {
        return null;
    }
    $response = fetch($url);
    if ($response === null || $response['body'] === '') {
        return null;
    }
    if ($response['status'] < 200 || $response['status'] >= 300) {
        return null;
    }
    if (!response_is_image($response['headers'])) {
        return null;
    }
    $path = parse_url($url, PHP_URL_PATH) ?: '';
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === '' || !in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif'], true)) {
        return null;
    }
    $dest_dir = ROOT_DIR . '/assets/' . $sub_path;
    if (!is_dir($dest_dir) && !mkdir($dest_dir, 0777, true) && !is_dir($dest_dir)) {
        return null;
    }
    $seed = sha1($url);
    $tmp = tempnam(sys_get_temp_dir(), 'wpimport_');
    if ($tmp === false) {
        return null;
    }
    if (file_put_contents($tmp, $response['body']) === false) {
        @unlink($tmp);
        return null;
    }
    $filename = \Lamb\Response\store_webp_copy($tmp, $ext, $dest_dir, $seed);
    if ($filename === null) {
        $filename = "$seed.$ext";
        if (!rename($tmp, "$dest_dir/$filename")) {
            @unlink($tmp);
            return null;
        }
    } else {
        @unlink($tmp);
    }
    return $filename;
}

/**
 * Scans raw HTTP response headers (as returned by Lamb\Http\fetch) for an
 * image/* Content-Type. Lets the downloader reject 200-OK HTML error pages,
 * CDN block pages, or login-required HTML returned for unauthenticated asset
 * URLs — situations the URL extension and status code alone don't catch.
 *
 * @param string[] $headers Raw response header lines.
 */
function response_is_image(array $headers): bool
{
    foreach ($headers as $header) {
        if (preg_match('/^content-type\s*:\s*image\//i', $header)) {
            return true;
        }
    }
    return false;
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
function import_item(array $item, string $site_host, callable $downloader, bool $dry_run = false): ?OODBBean
{
    if (!should_import($item)) {
        return null;
    }

    $uuid = wordpress_uuid((string) $item['guid']);
    $existing = R::findOne('post', ' feeditem_uuid = ? ', [$uuid]);
    if ($existing) {
        return $existing;
    }

    $sanitized = sanitize_html((string) ($item['content'] ?? ''));
    $rewritten = rewrite_image_links($sanitized, $site_host, (string) $item['created'], $downloader);
    $markdown = html_to_markdown($rewritten);
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

    $redirect = R::findOneOrDispense('redirect', ' from_slug = ? ', [$from]);
    $redirect->from_slug = $from;
    $redirect->to_url = $to;
    R::store($redirect);
}

/**
 * Loads an HTML fragment into DOMDocument with UTF-8 preserved.
 *
 * Wraps the input in a full <!doctype html><html><body> tree rather than using
 * LIBXML_HTML_NOIMPLIED, because libxml's handling of top-level fragment
 * elements changed between 2.9 and 2.12: the older NOIMPLIED + sibling-div
 * trick lost the wrapper on the newer parser and the round-trip collapsed to
 * just the meta hint. The full wrap parses identically on every libxml
 * version we support. The `<?xml encoding="utf-8" ?>` processing instruction
 * forces UTF-8; without it loadHTML defaults to ISO-8859-1 and mangles
 * multibyte input.
 */
function load_html_fragment(string $html): DOMDocument
{
    $dom = new DOMDocument();
    $previous = libxml_use_internal_errors(true);
    $dom->loadHTML(
        '<?xml encoding="utf-8" ?><!DOCTYPE html><html><body>' . $html . '</body></html>'
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    return $dom;
}

/**
 * Renders the contents of the synthesised <body> back out as an HTML fragment.
 * The original input lives as body's direct children, so we serialise each in
 * order and skip the wrapper.
 */
function dump_html_fragment(DOMDocument $dom): string
{
    $body = $dom->getElementsByTagName('body')->item(0);
    if (!$body instanceof DOMElement) {
        return trim((string) $dom->saveHTML());
    }
    $out = '';
    foreach ($body->childNodes as $child) {
        $out .= $dom->saveHTML($child);
    }
    return $out;
}

<?php

namespace Lamb\Known;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RedBeanPHP\OODBBean;
use RedBeanPHP\R;
use SimpleXMLElement;

use function Lamb\Import\build_post_body;
use function Lamb\Import\html_to_markdown;
use function Lamb\Import\import_uuid;
use function Lamb\Import\local_datetime_from_rfc822;
use function Lamb\Import\prepare_imported_html;
use function Lamb\Import\store_redirect;
use function Lamb\Import\unwrap_element;
use function Lamb\Post\body_has_tag;
use function Lamb\Post\finalize_and_store_post;
use function Lamb\Post\populate_bean;

// Known's RSS export borrows two fields from the WordPress WXR namespace
// (wp:post_type, wp:status) without adopting the rest of WXR. Known has its
// own copy of the namespace URI so this file never depends on Lamb\WordPress.
const WP_NS = 'http://wordpress.org/export/1.2/';

// Known tags that describe the post's shape rather than its subject, dropped
// on import: 'status' marks a titleless status update (Lamb models that as a
// post without a title, not a tag) and 'uncategorized' means no category at
// all.
const STRUCTURAL_TAGS = ['status', 'uncategorized'];

/**
 * Whether a tag is one of Known's structural tags ({@see STRUCTURAL_TAGS}).
 * Accepts raw tag text as it appears in the export — with or without the
 * leading `#`, in any case.
 */
function is_structural_tag(string $tag): bool
{
    $tag = strtolower(trim(ltrim(trim($tag), '#')));
    return in_array($tag, STRUCTURAL_TAGS, true);
}

/**
 * Strips standalone structural hashtags (`#status`, `#uncategorized`) from
 * converted Markdown.
 *
 * Runs on the final text form rather than the DOM because the hashtags reach
 * it by several routes: authors typed them inline (Known's inline tagging),
 * repair_known_content() surfaces them out of the malformed tag-line
 * paragraph, and a handful of source-damaged posts only reveal them after
 * html_to_markdown()'s entity decoding. Lamb's tag index scans the body for
 * `#tag` tokens, so any survivor would re-import the tag through the back
 * door.
 */
function strip_structural_hashtags(string $markdown): string
{
    $pattern = '/[ \t]?(?<![\w#])#(?:' . implode('|', STRUCTURAL_TAGS) . ')\b/i';
    return preg_replace($pattern, '', $markdown) ?? $markdown;
}

/**
 * Stable dedup key for a Known post. Mirrors the feed-ingest convention
 * (`feeditem_uuid = md5($feed_name . $id)`), so re-running an import never
 * recreates a row.
 */
function known_uuid(string $guid): string
{
    return import_uuid('known-', $guid);
}

/**
 * Repairs Known's malformed trailing tag-line paragraph.
 *
 * Known appends the post's tags as a `<p>` of plain `#hashtag` text and
 * `p-category` anchors, but its RSS export double-escapes that paragraph's
 * own `<p>`/`</p>` (they arrive as `&lt;p&gt;` entities). Left alone, the
 * entities decode into literal `<p>` text in the converted Markdown body.
 * Un-escaping is limited to exactly this shape — an escaped `<p>` wrapping
 * nothing but hashtags and tag anchors — so genuinely escaped markup that an
 * author wrote about (or that Known damaged elsewhere in old posts) is
 * passed through untouched.
 */
function repair_known_content(string $html): string
{
    $pattern = '/&lt;p&gt;\s*((?:#[\w-]+\s*|<a\b[^>]*class="[^"]*p-category[^"]*"[^>]*>.*?<\/a>\s*)+)&lt;\/p&gt;/is';
    return preg_replace($pattern, '<p>$1</p>', $html) ?? $html;
}

/**
 * Extracts every <item> from the channel as a normalised assoc array.
 *
 * Known's RSS export is a partial WXR veneer: content lives in <description>
 * (no content:encoded), there's no <wp:post_name>, so the slug has to come
 * from the <link> path leaf — but only when <link> is on the same host as
 * the channel's own <link> (the other 25/445 items in the source export are
 * bookmarks whose <link> points at the bookmarked page itself). Tags appear
 * as plain `<category>#tag</category>` elements (no domain/nicename
 * attributes). Dates are RFC822 `<pubDate>` only, so created and updated are
 * always identical.
 *
 * @return list<array{
 *   title:string, guid:string, link:string, content:string,
 *   post_type:string, status:string, created:string, updated:string,
 *   tags:list<string>, slug:string, bookmark_url:string,
 *   title_is_synthetic:bool
 * }>
 */
function extract_items(SimpleXMLElement $rss): array
{
    $channel_host = strtolower(
        (string) (parse_url(trim((string) ($rss->channel->link ?? '')), PHP_URL_HOST) ?: '')
    );

    $items = [];
    foreach ($rss->channel->item ?? [] as $item) {
        $wp = $item->children(WP_NS);

        $title = trim((string) ($item->title ?? ''));
        $link = trim((string) ($item->link ?? ''));
        $content = repair_known_content((string) ($item->description ?? ''));
        $created = local_datetime_from_rfc822(trim((string) ($item->pubDate ?? '')));

        $link_host = strtolower((string) (parse_url($link, PHP_URL_HOST) ?: ''));
        $on_host = $link_host !== '' && $link_host === $channel_host;

        $slug = '';
        $bookmark_url = '';
        if ($on_host) {
            $path = parse_url($link, PHP_URL_PATH);
            if (is_string($path) && trim($path, '/') !== '') {
                $segments = explode('/', trim($path, '/'));
                $slug = (string) end($segments);
            }
        } else {
            $bookmark_url = $link;
        }

        $tags = [];
        foreach ($item->category ?? [] as $cat) {
            $tag = trim((string) $cat);
            if (str_starts_with($tag, '#')) {
                $tag = substr($tag, 1);
            }
            $tag = strtolower(trim($tag));
            if ($tag !== '' && !is_structural_tag($tag)) {
                $tags[] = $tag;
            }
        }

        $title_is_synthetic = str_ends_with(rtrim($title), '...')
            || (bool) preg_match('/class="[^"]*\bp-name\b/', $content);

        $items[] = [
            'title'              => $title,
            'guid'               => trim((string) ($item->guid ?? '')),
            'link'               => $link,
            'content'            => $content,
            'post_type'          => trim((string) ($wp->post_type ?? '')),
            'status'             => trim((string) ($wp->status ?? '')),
            'created'            => $created,
            'updated'            => $created,
            'tags'               => array_values(array_unique($tags)),
            'slug'               => $slug,
            'bookmark_url'       => $bookmark_url,
            'title_is_synthetic' => $title_is_synthetic,
        ];
    }
    return $items;
}

/**
 * First-pass scope: published posts only. The real export has zero items
 * outside this scope, but the check is kept for defensiveness (and parity
 * with the WordPress importer) should a future export carry drafts.
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
    if ($type !== 'post') {
        return "unsupported post_type '" . ($type === '' ? '(none)' : $type) . "'";
    }
    if ($status !== 'publish') {
        return "non-published status '" . ($status === '' ? '(none)' : $status) . "'";
    }
    return null;
}

/**
 * Known-specific DOM surgery, run as the `$dom_pass` callback inside
 * {@see \Lamb\Import\prepare_imported_html} (after sanitisation, before the
 * image rewrite):
 *
 *  - `div.unfurl-block` (Known's hidden link-preview UI, including its
 *    nested `unfurl`/`unfurl-edit` children) is removed entirely.
 *  - `a.p-category[rel=tag]` anchors (pointing at the dead old host's tag
 *    archive) are replaced with a plain text node of their own text, so the
 *    inline `#tag` survives as ordinary body text instead of a dead link.
 *    Structural-tag anchors ({@see STRUCTURAL_TAGS}) are removed outright
 *    instead; their plain-text form is handled later, in
 *    {@see strip_structural_hashtags} on the converted Markdown.
 *  - `a[data-gallery]` anchors (Known's photo-post wrapper around the image)
 *    are unwrapped to a bare `<img>`.
 *  - Every remaining div is loop-unwrapped. This covers Known's own
 *    structural wrappers (`e-content`, `entry-content`, `known-bookmark`,
 *    `photo-view`, which can nest each other) and any legacy authored divs
 *    carried over from earlier platforms (bare `<div>`s, Windows Live
 *    Writer wrappers, …). A div holds no meaning in Markdown, and one that
 *    survives conversion renders as visibly escaped HTML in Lamb's safe
 *    renderer — unwrapping to paragraph breaks is strictly better. The
 *    unfurl-block removal above runs first, so junk subtrees are gone
 *    before this pass keeps their content.
 */
function normalize_known_html_in_dom(DOMDocument $dom): void
{
    $xpath = new DOMXPath($dom);

    $unfurls = $xpath->query(
        '//div[contains(concat(" ", normalize-space(@class), " "), " unfurl-block ")]'
    ) ?: [];
    foreach ($unfurls as $node) {
        if ($node instanceof DOMElement && $node->parentNode !== null) {
            $node->parentNode->removeChild($node);
        }
    }

    $tag_anchors = $xpath->query(
        '//a[@rel="tag" and contains(concat(" ", normalize-space(@class), " "), " p-category ")]'
    ) ?: [];
    foreach ($tag_anchors as $a) {
        if (!$a instanceof DOMElement || $a->parentNode === null) {
            continue;
        }
        // Structural tags are dropped outright — leaving them as text would
        // put e.g. `#status` back into Lamb's body-scanning tag index.
        if (is_structural_tag($a->textContent)) {
            $a->parentNode->removeChild($a);
            continue;
        }
        $a->parentNode->replaceChild($dom->createTextNode($a->textContent), $a);
    }


    $gallery_anchors = $xpath->query('//a[@data-gallery]') ?: [];
    foreach ($gallery_anchors as $a) {
        if ($a instanceof DOMElement) {
            unwrap_element($dom, $a, false);
        }
    }

    do {
        $changed = false;
        $wrappers = $xpath->query('//div') ?: [];
        foreach ($wrappers as $wrapper) {
            if (!$wrapper instanceof DOMElement || $wrapper->parentNode === null) {
                continue;
            }
            unwrap_element($dom, $wrapper, true);
            $changed = true;
        }
    } while ($changed);
}

/**
 * Runs a single Known RSS item through the standard post pipeline.
 *
 * Returns null when the item is out of scope. When an item with the same
 * `known_uuid()` already exists the existing bean is returned untouched —
 * re-running an import is therefore safe and idempotent.
 *
 * Synthetic-title items (Known-generated status updates — title ends `...`
 * or the body carries microformats2 `p-name`) are imported titleless, so
 * they fall through to Lamb's native `/status/<id>` permalink instead of
 * pinning a Known-derived slug. Bookmark items (an offsite `<link>`) get a
 * `[title](url)` markdown line prepended to the body, mirroring how Known
 * rendered them. Extracted `<category>` tags already present as inline
 * hashtags in the converted body (case-insensitively) are dropped so they
 * aren't duplicated.
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

    $uuid = known_uuid((string) $item['guid']);
    $existing = R::findOne('post', ' feeditem_uuid = ? ', [$uuid]);
    if ($existing) {
        return $existing;
    }

    $body_html = (string) ($item['content'] ?? '');
    $prepared = $body_html === ''
        ? ''
        : prepare_imported_html(
            $body_html,
            (string) $item['created'],
            $downloader,
            normalize_known_html_in_dom(...),
        );
    $markdown = strip_structural_hashtags(html_to_markdown($prepared));

    $extracted_tags = array_values(array_map(static fn($t): string => (string) $t, (array) ($item['tags'] ?? [])));
    $tags = array_values(array_filter(
        $extracted_tags,
        static fn(string $tag): bool => !body_has_tag($tag, $markdown)
    ));

    $title_is_synthetic = (bool) ($item['title_is_synthetic'] ?? false);
    $title = $title_is_synthetic ? '' : (string) ($item['title'] ?? '');
    $slug = $title_is_synthetic ? '' : (string) ($item['slug'] ?? '');

    $bookmark_url = trim((string) ($item['bookmark_url'] ?? ''));
    if ($bookmark_url !== '') {
        $markdown = '[' . (string) $item['title'] . '](' . $bookmark_url . ')' . "\n\n" . $markdown;
    }

    $body = build_post_body($title, $markdown, $tags, $slug);

    $bean = populate_bean($body);
    if (!empty($item['created'])) {
        $bean->created = (string) $item['created'];
    }
    if (!empty($item['updated'])) {
        $bean->updated = (string) $item['updated'];
    }
    $bean->feeditem_uuid = $uuid;
    $bean->feed_name = 'known';

    if ($dry_run) {
        return $bean;
    }

    finalize_and_store_post($bean);
    store_source_redirects($item, $bean);
    return $bean;
}

/**
 * Stores automatic redirects from an imported Known item's old URLs to its
 * new local Lamb path: the on-host `<link>` path (`2020/<slug>`, skipped for
 * offsite bookmarks and when it already equals the new path) and the `<guid>`
 * path (`view/<hash>`) — Known's own permalinks resolved either way.
 *
 * @param array<string, mixed> $item
 */
function store_source_redirects(array $item, OODBBean $bean): void
{
    $to = $bean->slug ? '/' . $bean->slug : '/status/' . $bean->id;

    $bookmark_url = trim((string) ($item['bookmark_url'] ?? ''));
    if ($bookmark_url === '') {
        $link_path = parse_url((string) ($item['link'] ?? ''), PHP_URL_PATH);
        if (is_string($link_path) && $link_path !== '') {
            $from = trim($link_path, '/');
            if ($from !== '' && $from !== ltrim($to, '/')) {
                store_redirect($from, $to);
            }
        }
    }

    $guid_path = parse_url((string) ($item['guid'] ?? ''), PHP_URL_PATH);
    if (is_string($guid_path) && $guid_path !== '') {
        $from = trim($guid_path, '/');
        if ($from !== '') {
            store_redirect($from, $to);
        }
    }
}

<?php

namespace Lamb\Import;

use DOMDocument;
use DOMElement;
use DOMXPath;
use League\HTMLToMarkdown\HtmlConverter;
use RedBeanPHP\R;
use RuntimeException;
use SimpleXMLElement;
use Symfony\Component\Yaml\Yaml;

use function Lamb\Http\fetch;
use function Lamb\Response\asset_url;

/**
 * Stable dedup key for an imported feed item. Mirrors the feed-ingest
 * convention (`feeditem_uuid = md5($feed_name . $id)`), so re-running an
 * import never recreates a row. Called with a per-CMS prefix ('wordpress-',
 * 'known-', …) so dedup identity can never collide across importers.
 */
function import_uuid(string $prefix, string $guid): string
{
    return md5($prefix . $guid);
}

/**
 * Loads an RSS/WXR XML string into a SimpleXMLElement.
 */
function parse_rss_string(string $xml): SimpleXMLElement
{
    $previous = libxml_use_internal_errors(true);
    $rss = simplexml_load_string($xml);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if ($rss === false) {
        throw new RuntimeException('Could not parse RSS XML.');
    }
    return $rss;
}

/**
 * Loads an RSS/WXR XML file from disk.
 */
function parse_rss_file(string $path): SimpleXMLElement
{
    $xml = @file_get_contents($path);
    if ($xml === false) {
        throw new RuntimeException("Could not read RSS file: $path");
    }
    return parse_rss_string($xml);
}

/**
 * Resolves an RFC822 `pubDate` (the only date field Known's RSS export
 * carries) to a local `Y-m-d H:i:s` string. Extracted from wxr_local_datetime's
 * final fallback so both importers share one RFC822 parse path.
 */
function local_datetime_from_rfc822(string $pub_date): string
{
    if ($pub_date !== '') {
        $ts = strtotime($pub_date);
        if ($ts !== false) {
            return date('Y-m-d H:i:s', $ts);
        }
    }
    return '';
}

/**
 * Removes executable / styling tags and any `on*` event-handler attributes.
 *
 * Imported body HTML is untrusted enough to warrant a defensive scrub before
 * it touches the Markdown converter. Tag removal is done at DOM level so
 * nested markup doesn't break the strip; event-handler attributes are walked
 * off every element regardless of case.
 */
function sanitize_html(string $html): string
{
    if (trim($html) === '') {
        return '';
    }
    $dom = load_html_fragment($html);
    sanitize_html_in_dom($dom);
    return dump_html_fragment($dom);
}

/**
 * In-place sanitisation pass: removes script/style/iframe nodes and any
 * `on*` event-handler attributes from every element. Extracted so {@see
 * prepare_imported_html} can chain it onto the same DOM as the image rewrite
 * pass, avoiding a parse/serialise round-trip between the two stages.
 */
function sanitize_html_in_dom(DOMDocument $dom): void
{
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
}

/**
 * Converts the already-sanitised HTML body to Markdown.
 *
 * The HtmlConverter is configured to keep unknown HTML so a malformed snippet
 * doesn't silently disappear; hard_break = true preserves <br> as a visible
 * line break in source.
 *
 * @param list<string> $unwrap_class_prefixes Div/section class prefixes (in
 *                     addition to figure/figcaption/cite/span) to loop-unwrap
 *                     before conversion — e.g. WordPress's `wp-block-` blocks.
 */
function html_to_markdown(string $html, array $unwrap_class_prefixes = []): string
{
    $placeholders = [];
    $html = normalize_html($html, $placeholders, $unwrap_class_prefixes);
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
 * Normalises imported editor HTML before Markdown conversion.
 *
 * The Markdown converter preserves unknown HTML by design. CMS exports often
 * contain presentational block wrappers, tables and video tags that would
 * otherwise survive into Lamb's safe Markdown renderer as visible escaped tags.
 *
 * @param array<string, string> $placeholders
 * @param list<string> $unwrap_class_prefixes Div/section class prefixes (in
 *                     addition to figure/figcaption/cite/span) to loop-unwrap.
 *                     An empty list unwraps only figure/figcaption/cite/span.
 */
function normalize_html(string $html, array &$placeholders = [], array $unwrap_class_prefixes = []): string
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
        $placeholders[$key] = markdown_table($table, $unwrap_class_prefixes);
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

    $class_condition = '';
    foreach ($unwrap_class_prefixes as $prefix) {
        $class_condition .= ' or contains(concat(" ", normalize-space(@class), " "), " ' . $prefix . '")';
    }

    do {
        $changed = false;
        $wrappers = $xpath->query(
            '//*[self::figure or self::figcaption or self::cite or self::span'
            . ' or ((self::div or self::section)'
            . ' and (false()' . $class_condition . '))]'
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

/**
 * @param list<string> $unwrap_class_prefixes Threaded through to the nested
 *                     html_to_markdown() call for each cell so table cells
 *                     containing CMS-specific block wrappers unwrap correctly.
 */
function markdown_table(DOMElement $table, array $unwrap_class_prefixes = []): string
{
    $doc = $table->ownerDocument;
    if ($doc === null) {
        return '';
    }
    $xpath = new DOMXPath($doc);
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
            $cells[] = markdown_table_cell($cell, $unwrap_class_prefixes);
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

/**
 * @param list<string> $unwrap_class_prefixes
 */
function markdown_table_cell(DOMElement $cell, array $unwrap_class_prefixes = []): string
{
    $doc = $cell->ownerDocument;
    if ($doc === null) {
        return '';
    }
    $html = '';
    foreach ($cell->childNodes as $child) {
        $html .= $doc->saveHTML($child);
    }
    $markdown = html_to_markdown($html, $unwrap_class_prefixes);
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
 * An explicit `slug:` line — sourced from the CMS's own permalink leaf — pins
 * the post's URL to its original permalink. parse_matter() only derives a
 * slug from the title when none is set, so the imported slug survives the
 * normal save pipeline. finalize_slug() still suffixes `-id` on a reserved-
 * route or collision, so a duplicate imported slug can't override an existing
 * post.
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
 * Walks an HTML fragment and downloads every referenced image:
 *  - `<img>` elements: prefers `data-full-url` (WordPress gallery blocks put
 *    the original full-resolution URL there and a smaller `…-473x1024.jpg`
 *    variant in `src`; pulling the full original lets convert_to_webp() do the
 *    1600px resize from a higher-quality source). Falls back to `src`, then to
 *    `data-src` for lazy-loaded markup.
 *  - `<a>` elements whose `href` points at an image extension: thumbnails
 *    wrapped in "view full size" links would otherwise leave dead off-site
 *    links in the body once the source site is decommissioned.
 *
 * Protocol-relative URLs (`//host/path.jpg`) are treated as `https://`.
 * Multi-resolution `srcset`/`sizes` attributes are stripped once we have a
 * local copy — the WebP we wrote is already the canonical single-resolution
 * image. data-* URL hints are also dropped on success so stale absolute URLs
 * pointing at the old host don't leak back in. Failed downloads, data: URIs
 * and relative URLs are left untouched.
 *
 * @param callable(string,string):?string $downloader  ($url, $sub_path) → saved filename or null.
 */
function rewrite_image_links(string $html, string $created, callable $downloader): string
{
    if (trim($html) === '') {
        return '';
    }
    $dom = load_html_fragment($html);
    rewrite_image_links_in_dom($dom, $created, $downloader);
    return dump_html_fragment($dom);
}

/**
 * In-place version of {@see rewrite_image_links}, chained on the same DOM as
 * {@see sanitize_html_in_dom} from {@see prepare_imported_html} so the two
 * passes don't each parse and serialise the body separately.
 *
 * @param callable(string,string):?string $downloader  ($url, $sub_path) → saved filename or null.
 */
function rewrite_image_links_in_dom(DOMDocument $dom, string $created, callable $downloader): void
{
    $xpath = new DOMXPath($dom);
    $sub_path = asset_dir_for_date($created);

    unwrap_picture_elements($dom, $xpath);

    $imgs = $xpath->query('//img[@src or @data-src or @data-full-url]') ?: [];
    foreach ($imgs as $img) {
        if (!$img instanceof DOMElement) {
            continue;
        }
        $src = pick_image_url($img);
        $url = normalize_image_url($src);
        if ($url === null) {
            continue;
        }
        $filename = $downloader($url, $sub_path);
        if (!is_string($filename) || $filename === '') {
            continue;
        }
        $img->setAttribute('src', asset_url($sub_path, $filename));
        foreach (['srcset', 'sizes', 'data-src', 'data-full-url', 'data-link', 'data-orig-file'] as $stale) {
            $img->removeAttribute($stale);
        }
    }

    $anchors = $xpath->query('//a[@href]') ?: [];
    foreach ($anchors as $a) {
        if (!$a instanceof DOMElement) {
            continue;
        }
        $href = $a->getAttribute('href');
        if (!href_looks_like_image($href)) {
            continue;
        }
        $url = normalize_image_url($href);
        if ($url === null) {
            continue;
        }
        $filename = $downloader($url, $sub_path);
        if (!is_string($filename) || $filename === '') {
            continue;
        }
        $a->setAttribute('href', asset_url($sub_path, $filename));
    }
}

/**
 * Replaces every `<picture>` in the document with its descendant `<img>`
 * fallback (or a synthesised `<img>` derived from the first `<source srcset>`
 * URL when there is no `<img>` child).
 *
 * `<picture>` doesn't survive the Markdown converter — unknown HTML is kept
 * verbatim, so the post body would otherwise display raw `<source>` tags. The
 * downstream image walk only knows about `<img>`, and `<source>` URLs in
 * srcset would leak the old host into the body. Unwrapping here means the
 * single canonical `<img>` flows through the normal rewrite path.
 */
function unwrap_picture_elements(DOMDocument $dom, DOMXPath $xpath): void
{
    $pictures = $xpath->query('//picture') ?: [];
    foreach ($pictures as $picture) {
        if (!$picture instanceof DOMElement || $picture->parentNode === null) {
            continue;
        }
        $img = first_descendant_img($xpath, $picture)
            ?? synthesise_img_from_source($xpath, $picture, $dom);
        if ($img === null) {
            $picture->parentNode->removeChild($picture);
            continue;
        }
        // Detach the img from wherever it lives inside the picture before
        // splicing the picture out — otherwise the `replaceChild` below
        // disposes the still-attached node along with the rest of the tree.
        $img->parentNode?->removeChild($img);
        $picture->parentNode->replaceChild($img, $picture);
    }
}

function first_descendant_img(DOMXPath $xpath, DOMElement $picture): ?DOMElement
{
    $found = $xpath->query('.//img', $picture);
    if ($found === false) {
        return null;
    }
    foreach ($found as $node) {
        if ($node instanceof DOMElement) {
            return $node;
        }
    }
    return null;
}

function synthesise_img_from_source(DOMXPath $xpath, DOMElement $picture, DOMDocument $dom): ?DOMElement
{
    $sources = $xpath->query('.//source[@srcset]', $picture);
    if ($sources === false) {
        return null;
    }
    foreach ($sources as $source) {
        if (!$source instanceof DOMElement) {
            continue;
        }
        $first = first_srcset_url($source->getAttribute('srcset'));
        if ($first === null) {
            continue;
        }
        $img = $dom->createElement('img');
        $img->setAttribute('src', $first);
        return $img;
    }
    return null;
}

/**
 * Pulls the first URL out of an HTML5 `srcset` attribute. The grammar is
 * comma-separated entries of "URL [descriptor]"; we only need the URL of the
 * first entry, with everything after the first whitespace dropped.
 */
function first_srcset_url(string $srcset): ?string
{
    $first = trim(explode(',', $srcset, 2)[0]);
    if ($first === '') {
        return null;
    }
    $parts = preg_split('/\s+/', $first, 2);
    $url = $parts === false ? '' : $parts[0];
    return $url === '' ? null : $url;
}

/**
 * Picks the best source URL for an `<img>` element: full-res variant first,
 * then the regular `src`, then lazy-load `data-src`.
 */
function pick_image_url(DOMElement $img): string
{
    foreach (['data-full-url', 'src', 'data-src'] as $attr) {
        $value = trim($img->getAttribute($attr));
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

/**
 * Normalises a candidate image URL to an absolute http(s) form, or returns
 * null when it isn't fetchable (data: URI, relative path, empty, etc.).
 * Protocol-relative `//host/path` URLs are promoted to https.
 */
function normalize_image_url(string $url): ?string
{
    $url = trim($url);
    if ($url === '') {
        return null;
    }
    if (str_starts_with($url, '//')) {
        return 'https:' . $url;
    }
    $scheme = parse_url($url, PHP_URL_SCHEME);
    if (!is_string($scheme) || !in_array(strtolower($scheme), ['http', 'https'], true)) {
        return null;
    }
    return $url;
}

/**
 * Whether an anchor href appears to point at an image — used to decide if a
 * "view full size" link should also be downloaded. Conservative: only known
 * upload-pipeline extensions are accepted, query strings and fragments are
 * tolerated.
 */
function href_looks_like_image(string $href): bool
{
    $path = parse_url($href, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        return false;
    }
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    return $ext !== '' && in_array($ext, IMAGE_UPLOAD_EXTENSIONS, true);
}

/**
 * Cap for how many bytes a single image download is allowed to consume.
 * A misbehaving server returning a multi-GB body with image/* Content-Type
 * would otherwise OOM the importer; truncated reads fail the WebP decode
 * cleanly, which is the right outcome.
 */
const IMAGE_DOWNLOAD_MAX_BYTES = 20_000_000;

/**
 * Default downloader used by the CLI scripts: fetches $url over HTTP and
 * writes it under ROOT_DIR/assets/$sub_path with a content-hash filename.
 * JPEG/PNG are re-encoded to WebP via the shared persistence helper. Returns
 * the filename relative to the subdirectory, or null on any failure.
 *
 * Idempotent: when the destination file already exists from an earlier post in
 * the same month — or from a previous interrupted run — the existing filename
 * is returned without re-fetching. The seed is sha1($url), so the same URL
 * always maps to the same on-disk name.
 */
function default_image_downloader(string $url, string $sub_path): ?string
{
    if (!defined('ROOT_DIR')) {
        return null;
    }
    $path = parse_url($url, PHP_URL_PATH) ?: '';
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext === '' || !in_array($ext, IMAGE_UPLOAD_EXTENSIONS, true)) {
        return null;
    }
    $dest_dir = ROOT_DIR . '/assets/' . $sub_path;
    $seed = sha1($url);
    foreach (["$seed.webp", "$seed.$ext"] as $existing) {
        if (is_file("$dest_dir/$existing")) {
            return $existing;
        }
    }
    if (!is_dir($dest_dir) && !mkdir($dest_dir, 0777, true) && !is_dir($dest_dir)) {
        return null;
    }
    $response = fetch($url, ['max_bytes' => IMAGE_DOWNLOAD_MAX_BYTES]);
    if ($response === null || $response['body'] === '') {
        return null;
    }
    if ($response['status'] < 200 || $response['status'] >= 300) {
        return null;
    }
    if (!response_is_image($response['headers'])) {
        return null;
    }
    return \Lamb\Response\persist_image_bytes($response['body'], $ext, $dest_dir, $seed);
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
 * Bundles the sanitise + image-rewrite passes (and an optional CMS-specific
 * DOM pass in between) onto a single DOM. Used by each importer's
 * `import_item()` to halve the parse/serialise work versus calling the two
 * public string functions back to back, and to remove a layer of round-trip
 * artifacts (entity normalisation, attribute reordering) between the stages.
 *
 * @param callable(string,string):?string $downloader ($url, $sub_path) → saved filename or null.
 * @param ?callable(DOMDocument):void     $dom_pass    Optional CMS-specific DOM
 *                     surgery run after sanitisation and before the image
 *                     rewrite (e.g. Known's unfurl-block removal).
 */
function prepare_imported_html(string $html, string $created, callable $downloader, ?callable $dom_pass = null): string
{
    if (trim($html) === '') {
        return '';
    }
    $dom = load_html_fragment($html);
    sanitize_html_in_dom($dom);
    if ($dom_pass !== null) {
        $dom_pass($dom);
    }
    rewrite_image_links_in_dom($dom, $created, $downloader);
    return dump_html_fragment($dom);
}

/**
 * Stores an automatic redirect from an imported source URL path to a local
 * Lamb path. Importers only create redirects for old paths that do not
 * naturally match Lamb's freshly minted permalink.
 */
function store_redirect(string $from, string $to): void
{
    $redirect = R::findOneOrDispense('redirect', ' from_slug = ? ', [$from]);
    $redirect->from_slug = $from;
    $redirect->to_url = $to;
    R::store($redirect);
}

/**
 * Parses argv into [path, dry_run]. Returns [null, false] when the path is
 * missing.
 *
 * @param array<int,string> $argv
 * @return array{0: ?string, 1: bool}
 */
function parse_import_args(array $argv): array
{
    $path = null;
    $dry_run = false;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--dry-run') {
            $dry_run = true;
        } elseif ($arg === '--help' || $arg === '-h') {
            return [null, false];
        } elseif ($path === null) {
            $path = $arg;
        }
    }
    return [$path, $dry_run];
}

/**
 * Shared CLI import loop: walks $items, skips out-of-scope ones (tallying a
 * skip-reason breakdown), dedups already-imported items by uuid, imports the
 * rest through $import, and prints a per-item progress line plus a final
 * summary. Used by both import-wordpress.php and import-known.php so the two
 * scripts' output stays byte-identical in shape.
 *
 * @param list<array<string, mixed>>      $items      Items from extract_items().
 * @param callable(array<string,mixed>):?string $skip_reason Explains why an item is out of scope, or null.
 * @param callable(array<string,mixed>):string  $uuid       Computes the item's dedup uuid.
 * @param callable(array<string,mixed>,callable,bool):?\RedBeanPHP\OODBBean $import Imports a single item.
 */
function run_import(array $items, callable $skip_reason, callable $uuid, callable $import, bool $dry_run): void
{
    $downloader = $dry_run
        ? static fn(): ?string => null
        : 'Lamb\\Import\\default_image_downloader';

    $created = 0;
    $existed = 0;
    $skipped = 0;
    /** @var array<string, int> $skip_reasons */
    $skip_reasons = [];
    $total = count($items);

    foreach ($items as $i => $item) {
        $reason = $skip_reason($item);
        if ($reason !== null) {
            $skipped++;
            $skip_reasons[$reason] = ($skip_reasons[$reason] ?? 0) + 1;
            continue;
        }
        $item_uuid = $uuid($item);
        if (R::findOne('post', ' feeditem_uuid = ? ', [$item_uuid])) {
            $existed++;
            continue;
        }

        $bean = $import($item, $downloader, $dry_run);
        if ($bean === null) {
            $skipped++;
            $skip_reasons['conversion failed'] = ($skip_reasons['conversion failed'] ?? 0) + 1;
            echo "[" . ($i + 1) . "/$total] skipped (conversion failed): "
                . trim((string) $item['title']) . "\n";
            continue;
        }
        $created++;
        $verb = $dry_run ? 'would import' : 'imported';
        echo "[" . ($i + 1) . "/$total] $verb: " . trim((string) $item['title']) . "\n";
    }

    $prefix = $dry_run ? '[dry-run] ' : '';
    echo "\n{$prefix}Done. created=$created existed=$existed skipped=$skipped total=$total\n";
    if ($skip_reasons) {
        arsort($skip_reasons);
        echo "Skipped breakdown:\n";
        foreach ($skip_reasons as $reason => $count) {
            echo "  $count\t$reason\n";
        }
    }
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

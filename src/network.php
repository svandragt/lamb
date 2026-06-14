<?php

namespace Lamb\Network;

use JetBrains\PhpStorm\NoReturn;
use RedBeanPHP\OODBBean;
use RedBeanPHP\R;
use RedBeanPHP\RedException\SQL;
use SimplePie\Item as SimplePieItem;
use SimplePie\SimplePie;

use function Lamb\get_option;
use function Lamb\Http\fetch;
use function Lamb\Http\is_valid_http_url;
use function Lamb\Route\register_route;
use function Lamb\Post\finalize_slug;
use function Lamb\Post\populate_bean;
use function Lamb\set_option;

// MINUTE_IN_SECONDS is defined in constants.php

register_route('_cron', __NAMESPACE__ . '\\process_feeds');

/**
 * @return array<array-key, mixed> Configured feed URLs keyed by feed name.
 */
function get_feeds(): array
{
    global $config;

    // A setting accidentally placed under [feeds] (e.g. `feeds_draft = false`)
    // would otherwise be fetched as a feed URL. Only keep http(s) URLs.
    return array_filter($config['feeds'] ?? [], fn($url) => is_valid_http_url((string) $url));
}

/**
 * Ensures the SimplePie cache directory exists, creating it when missing.
 *
 * SimplePie warns loudly (HTML in the text/plain cron output) when the cache
 * location is not writable, so create it up front and disable caching when
 * that fails.
 *
 * @param string $dir The cache directory path.
 * @return string|false The directory when usable, false otherwise.
 */
function ensure_feed_cache(string $dir): string|false
{
    if (!is_dir($dir) && !mkdir($dir, 0775, true)) {
        return false;
    }
    return is_writable($dir) ? $dir : false;
}

/** @noinspection PhpUnused */
/**
 * Hard-delete posts that were soft-deleted more than 30 days ago.
 *
 * @return int Number of posts permanently deleted.
 */
function purge_deleted_posts(): int
{
    $cutoff = date('Y-m-d H:i:s', strtotime('-30 days'));
    $posts  = R::find('post', ' deleted = 1 AND deleted_at < ? ', [$cutoff]);
    foreach ($posts as $post) {
        R::trash($post);
    }
    return count($posts);
}

#[NoReturn] function process_feeds(): void
{
    header('Content-Type: text/plain');
    $feeds = get_feeds();

    $cron_last_updated = get_option('last_processed_date', 0);
    if ((time() - $cron_last_updated->value) < MINUTE_IN_SECONDS) {
        die('Too often, try again later.');
    }

    $purged = purge_deleted_posts();
    if ($purged > 0) {
        echo("Purged $purged deleted post(s)." . PHP_EOL);
    }

    $pruned = prune_feed_status();
    if ($pruned > 0) {
        echo("Pruned $pruned stale feed status row(s)." . PHP_EOL);
    }

    echo("Updating feeds..." . PHP_EOL);
    foreach ($feeds as $name => $url) {
        flush();
        $status = feed_status_bean($name, $url);
        // The 30-minute fetch-frequency skip is gated on the last *attempt*, not the
        // last success, so a failing feed is retried on schedule rather than locked
        // out (and a healthy feed is not re-fetched within the window).
        if ((time() - (int)$status->last_attempt) < MINUTE_IN_SECONDS * 30) {
            echo('Skipped ' . $url . PHP_EOL);
            continue;
        }

        // JSON Feed sources are not XML, so SimplePie cannot parse them. Route
        // .json URLs through a small JSON parser instead; RSS/Atom is unchanged.
        if (is_json_feed_url($url)) {
            echo PHP_EOL . "Processing " . $url . PHP_EOL;
            $result = record_json_feed_crawl($name, $url);
            if ($result['ok']) {
                printf("OK: %s - %d item(s) ingested" . PHP_EOL, $name, $result['items']);
            } else {
                printf("FAILED: %s - %s" . PHP_EOL, $name, $result['error']);
            }
            continue;
        }

        $feed = new SimplePie();
        $cache_dir = ensure_feed_cache('../data/cache/simplepie');
        if ($cache_dir === false) {
            $feed->enable_cache(false);
        } else {
            /** @noinspection PhpDeprecationInspection */
            $feed->set_cache_location($cache_dir);
        }
        $feed->set_feed_url($url);
        // Cap each fetch so a slow or hostile feed URL cannot stall the cron run.
        $feed->set_timeout(FEED_FETCH_TIMEOUT);
        $feed->init();
        echo PHP_EOL . "Processing " . $feed->get_title() . PHP_EOL;

        $result = record_feed_crawl($name, $url, $feed);
        if ($result['ok']) {
            printf("OK: %s - %d item(s) ingested" . PHP_EOL, $name, $result['items']);
        } else {
            printf("FAILED: %s - %s" . PHP_EOL, $name, $result['error']);
        }
    }

    $sent = \Lamb\Webmention\process_outbound();
    if ($sent['sent'] || $sent['failed'] || $sent['skipped'] || $sent['cancelled']) {
        printf(
            "Webmentions sent: %d, failed: %d, skipped: %d, cancelled: %d" . PHP_EOL,
            $sent['sent'],
            $sent['failed'],
            $sent['skipped'],
            $sent['cancelled']
        );
    }

    set_option($cron_last_updated, (int)date('U'));
    exit('Done');
}

/**
 * Returns (creating if needed) the per-feed status bean keyed by md5(name . url) —
 * the same key the legacy `last_processed_date_*` option used.
 *
 * The bean records crawl *health*; config remains the source of truth for which
 * feeds exist. A freshly dispensed bean seeds its success watermark from any legacy
 * `last_processed_date_<key>` option so existing installs do not re-ingest (and
 * duplicate) every item on the first run after upgrade.
 *
 * @param string $name Feed name from config.
 * @param string $url  Feed URL from config.
 * @return OODBBean    Existing or freshly dispensed (unsaved) feedstatus bean.
 */
function feed_status_bean(string $name, string $url): OODBBean
{
    $key  = md5($name . $url);
    $bean = R::findOneOrDispense('feedstatus', ' feedkey = ? ', [$key]);
    $bean->feedkey = $key;
    if ((int)$bean->id === 0) {
        $bean->name         = $name;
        $bean->url          = $url;
        $legacy             = R::findOne('option', ' name = ? ', ['last_processed_date_' . $key]);
        $bean->last_success = $legacy ? (int)$legacy->value : 0;
        $bean->last_attempt = 0;
        $bean->last_error   = 0;
        $bean->item_count   = 0;
        $bean->error_message = '';
    }

    return $bean;
}

/**
 * Crawls a single initialised feed and records the outcome on its feedstatus bean.
 *
 * A failed fetch (`!$feed->data` or a non-empty `$feed->error()`) does NOT advance the
 * success watermark — it only stamps `last_attempt` and records the error so the
 * Logs tab can surface it. On success, items newer than the watermark are created or
 * updated, the watermark advances, the item count is recorded and any prior error is
 * cleared.
 *
 * @param string    $name Feed name from config.
 * @param string    $url  Feed URL from config.
 * @param SimplePie $feed The initialised SimplePie instance.
 * @return array{ok: bool, items: int, error: ?string}
 */
function record_feed_crawl(string $name, string $url, SimplePie $feed): array
{
    $status = feed_status_bean($name, $url);
    $now    = (int)date('U');
    $status->last_attempt = $now;

    $error = $feed->error();
    if (is_array($error)) {
        $error = implode('; ', array_filter($error));
    }

    if (!$feed->data || $error) {
        $message = (string)($error ?: 'Feed fetch failed: no data returned.');
        $status->last_error    = $now;
        $status->error_message = $message;
        R::store($status);
        return ['ok' => false, 'items' => 0, 'error' => $message];
    }

    $watermark = (int)$status->last_success;
    $items     = 0;
    /** @var SimplePieItem $item */
    foreach ($feed->get_items() as $item) {
        if (ingest_item($item, $name, $watermark)) {
            $items++;
        }
    }

    $status->last_success  = $now;
    $status->item_count    = $items;
    $status->error_message = '';
    R::store($status);

    return ['ok' => true, 'items' => $items, 'error' => null];
}

/**
 * True when a configured feed URL should be parsed as JSON Feed (jsonfeed.org)
 * rather than handed to SimplePie. JSON Feed files conventionally use a `.json`
 * URL, which keeps RSS/Atom detection a no-op (no extra fetch).
 *
 * @param string $url The configured feed URL.
 * @return bool Whether the source is a JSON Feed.
 */
function is_json_feed_url(string $url): bool
{
    $path = (string) parse_url($url, PHP_URL_PATH);

    return str_ends_with(strtolower($path), '.json');
}

/**
 * Parses a JSON Feed document into feed items wrapped as JsonFeedItem adapters,
 * so the existing ingest pipeline (dedup, draft-on-ingest, create_item) is reused.
 *
 * @param string $json The raw JSON Feed body.
 * @return array{title: string, items: list<JsonFeedItem>}|null
 *               The parsed feed, or null when the body is not a JSON Feed.
 */
function parse_json_feed(string $json): ?array
{
    $data = json_decode($json, true);
    if (!is_array($data) || !str_contains((string) ($data['version'] ?? ''), 'jsonfeed.org')) {
        return null;
    }

    $items = [];
    foreach ($data['items'] ?? [] as $raw) {
        if (is_array($raw)) {
            $items[] = new JsonFeedItem($raw);
        }
    }

    return ['title' => (string) ($data['title'] ?? ''), 'items' => $items];
}

/**
 * Fetches and ingests a JSON Feed source, recording the outcome on its
 * feedstatus bean — the JSON Feed counterpart of record_feed_crawl().
 *
 * Mirrors the SimplePie path: a failed fetch or a body that is not a JSON Feed
 * stamps the error without advancing the success watermark; on success, items
 * newer than the watermark are created/updated and the watermark advances.
 *
 * @param string $name Feed name from config.
 * @param string $url  Feed URL from config.
 * @return array{ok: bool, items: int, error: ?string}
 */
function record_json_feed_crawl(string $name, string $url): array
{
    $status = feed_status_bean($name, $url);
    $now    = (int) date('U');
    $status->last_attempt = $now;

    $response = fetch($url, ['timeout' => FEED_FETCH_TIMEOUT]);
    $feed     = $response === null ? null : parse_json_feed($response['body']);

    if ($feed === null) {
        $message = $response === null
            ? 'Feed fetch failed: no data returned.'
            : 'Not a valid JSON Feed (missing jsonfeed.org version).';
        $status->last_error    = $now;
        $status->error_message = $message;
        R::store($status);
        return ['ok' => false, 'items' => 0, 'error' => $message];
    }

    $watermark = (int) $status->last_success;
    $items     = 0;
    foreach ($feed['items'] as $item) {
        if (ingest_item($item, $name, $watermark)) {
            $items++;
        }
    }

    $status->last_success  = $now;
    $status->item_count    = $items;
    $status->error_message = '';
    R::store($status);

    return ['ok' => true, 'items' => $items, 'error' => null];
}

/**
 * Returns the persisted crawl status for every configured feed, in config order.
 *
 * Feeds with no stored health yet (never crawled) get a zeroed row so the Logs tab
 * lists them too. Config is the source of truth for which feeds exist.
 *
 * @return array<int, array{name:string, url:string, last_attempt:int, last_success:int, last_error:int, error_message:string, item_count:int}>
 */
function get_feed_statuses(): array
{
    $out = [];
    foreach (get_feeds() as $name => $url) {
        $bean = R::findOne('feedstatus', ' feedkey = ? ', [md5($name . $url)]);
        $out[] = [
            'name'          => (string)$name,
            'url'           => (string)$url,
            'last_attempt'  => $bean ? (int)$bean->last_attempt : 0,
            'last_success'  => $bean ? (int)$bean->last_success : 0,
            'last_error'    => $bean ? (int)$bean->last_error : 0,
            'error_message' => $bean ? (string)$bean->error_message : '',
            'item_count'    => $bean ? (int)$bean->item_count : 0,
        ];
    }

    return $out;
}

/**
 * Deletes feedstatus beans for feeds that are no longer present in config.
 *
 * @return int Number of stale status rows removed.
 */
function prune_feed_status(): int
{
    $keys = [];
    foreach (get_feeds() as $name => $url) {
        $keys[] = md5($name . $url);
    }

    $removed = 0;
    foreach (R::findAll('feedstatus') as $bean) {
        if (!in_array($bean->feedkey, $keys, true)) {
            R::trash($bean);
            $removed++;
        }
    }

    return $removed;
}

/**
 * Decides whether a single feed item is created, updated, or skipped, keyed on
 * its `feeditem_uuid` rather than dates alone.
 *
 * Deduplication lives here: an item that already has a post is never recreated
 * (the source of the recreated-draft bug when a feed re-stamps an item's
 * publication date past the watermark). A brand-new item is created only when
 * its publication date is newer than the watermark. An already-ingested post is
 * re-synced from the source only when the item was modified after the watermark
 * AND the author has not taken the post over via the edit form
 * (`feed_locked`) — so a published, re-slugged post is left intact.
 *
 * @param SimplePieItem $item      The feed item.
 * @param string        $name      Feed name from config.
 * @param int           $watermark The feed's last-success timestamp.
 * @return bool True when a post was created or updated (counts toward the run total).
 */
function ingest_item(SimplePieItem|JsonFeedItem $item, string $name, int $watermark): bool
{
    $uuid     = md5($name . $item->get_id());
    $existing = R::findOne('post', ' feeditem_uuid = ? ', [$uuid]);

    if (!$existing) {
        if ((int) $item->get_date('U') > $watermark) {
            create_item($item, $name);
            return true;
        }
        return false;
    }

    if (!$existing->feed_locked && (int) $item->get_updated_date('U') > $watermark) {
        update_item($item, $name);
        return true;
    }

    return false;
}

function update_item(SimplePieItem|JsonFeedItem $item, string $name): void
{
    $uuid = md5($name . $item->get_id());
    $bean = R::findOne('post', ' feeditem_uuid = ?', [$uuid]);
    if (!$bean) {
        // Record not found
        return;
    }
    $bean = prepare_item($item, $name, $bean);
    $bean->updated = $item->get_updated_date("Y-m-d H:i:s");
    finalize_slug($bean);

    try {
        R::store($bean);
    } catch (SQL) {
        // continue
    }
}

function prepare_item(SimplePieItem|JsonFeedItem $item, string $name, ?OODBBean $bean = null): OODBBean
{
    $contents = get_structured_content($item, $name);

    return populate_bean($contents, $item, $name, $bean);
}

function create_item(SimplePieItem|JsonFeedItem $item, string $name): void
{
    $contents = get_structured_content($item, $name);
    $bean = populate_bean($contents, $item, $name);

    try {
        R::store($bean);
        // Reserved-route and duplicate slugs (e.g. two same-titled items in
        // one feed) get an id suffix; the final slug is pinned into the
        // body's front matter so cron updates re-derive it unchanged.
        if (finalize_slug($bean)) {
            R::store($bean);
        }
    } catch (SQL) {
        // continue
    }
}

/**
 * @param SimplePieItem|JsonFeedItem $item
 * @param string $name
 * @return string
 */
function get_structured_content(SimplePieItem|JsonFeedItem $item, string $name): string
{
    $contents = attributed_content($item, $name);
    $title = sanitize_feed_title($item->get_title() ?? '');
    if (!empty($title)) {
        $contents = <<<MATTER
---
title: {$title}
---

{$contents}
MATTER;
    }
    return $contents;
}

/**
 * Sanitises a remote feed title before it is embedded in a post's YAML front matter.
 *
 * Front matter is delimited by `---` and parsed as YAML, so an untrusted title
 * containing newlines could inject extra keys (e.g. `slug`, `created`) and a `---`
 * sequence could close the block early. Whitespace is collapsed to single spaces,
 * any run of three or more hyphens is shortened, the result is length-capped, and
 * slashes/quotes are escaped (preserving the existing front-matter format).
 *
 * @param string $title The raw feed item title.
 * @return string A single-line, length-capped, escaped title safe for front matter.
 */
function sanitize_feed_title(string $title): string
{
    $title = (string) preg_replace('/\s+/', ' ', $title);
    $title = (string) preg_replace('/-{3,}/', '--', $title);
    $title = trim($title);
    if (mb_strlen($title) > 200) {
        $title = rtrim(mb_substr($title, 0, 200));
    }

    return addslashes($title);
}

/**
 * Returns the description of a SimplePie item formatted as a quoted block,
 * along with a citation to the original source.
 *
 * @param SimplePieItem|JsonFeedItem $item The feed item from which to extract the description and URL.
 * @param string $name The name to use in the citation.
 * @return string The formatted description with a citation to the original source.
 */
function attributed_content(SimplePieItem|JsonFeedItem $item, string $name): string
{
    $contents = strip_tags($item->get_description() ?? '');
    $lines = explode(PHP_EOL, $contents);
    $lines = array_slice($lines, 0, 5); // Get only the first 5 lines
    foreach ($lines as &$line) {
        $line = "> $line";
    }
    unset($line);
    $contents = implode(PHP_EOL, $lines);
    $url = $item->get_permalink();
    return "Originally written on [$name]($url): " . PHP_EOL . PHP_EOL . $contents;
}

/**
 * Adapts a JSON Feed (jsonfeed.org) item to the subset of the SimplePie\Item
 * interface the ingest pipeline relies on, so JSON Feed items flow through
 * ingest_item()/create_item()/populate_bean() unchanged.
 *
 * Dates absent from the item return null (as SimplePie does for dateless
 * entries), so an item with no `date_published` is not ingested — matching the
 * RSS/Atom behaviour rather than inventing a date.
 */
class JsonFeedItem
{
    /** @var array<string, mixed> */
    private array $item;
    private ?int $published;
    private ?int $modified;

    /**
     * @param array<string, mixed> $item A single decoded JSON Feed item.
     */
    public function __construct(array $item)
    {
        $this->item      = $item;
        $this->published = $this->toTimestamp($item['date_published'] ?? null);
        $this->modified  = $this->toTimestamp($item['date_modified'] ?? null);
    }

    // These accessors deliberately mirror SimplePie\Item's snake_case API so a
    // JsonFeedItem is a drop-in for the ingest pipeline's union type.
    // phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

    /**
     * The item's stable identifier — JSON Feed requires `id`; fall back to `url`.
     */
    public function get_id(): string
    {
        return (string) ($this->item['id'] ?? $this->item['url'] ?? '');
    }

    public function get_title(): ?string
    {
        return is_string($this->item['title'] ?? null) ? $this->item['title'] : null;
    }

    /**
     * The item content. Prefers the plain-text `content_text`; falls back to the
     * raw `content_html` (the ingest pipeline strips tags downstream).
     */
    public function get_description(): ?string
    {
        foreach (['content_text', 'content_html'] as $key) {
            $value = $this->item[$key] ?? null;
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }

    public function get_permalink(): ?string
    {
        return is_string($this->item['url'] ?? null) ? $this->item['url'] : null;
    }

    /**
     * @return string|int|null `U` returns the Unix timestamp; any other format
     *                         is passed to date(); null when no publish date.
     */
    public function get_date(string $date_format = 'U'): string|int|null
    {
        return $this->formatTimestamp($this->published, $date_format);
    }

    /**
     * The modified date, falling back to the publish date when `date_modified`
     * is absent (so updates don't churn against a missing value).
     *
     * @return string|int|null
     */
    public function get_updated_date(string $date_format = 'U'): string|int|null
    {
        return $this->formatTimestamp($this->modified ?? $this->published, $date_format);
    }

    // phpcs:enable PSR1.Methods.CamelCapsMethodName.NotCamelCaps

    private function toTimestamp(mixed $value): ?int
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        $timestamp = strtotime($value);

        return $timestamp === false ? null : $timestamp;
    }

    private function formatTimestamp(?int $timestamp, string $format): string|int|null
    {
        if ($timestamp === null) {
            return null;
        }

        return $format === 'U' ? $timestamp : date($format, $timestamp);
    }
}

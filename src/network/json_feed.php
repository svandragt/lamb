<?php

namespace Lamb\Network;

use RedBeanPHP\R;

use function Lamb\Http\fetch_guarded;

// FEED_FETCH_TIMEOUT is defined in constants.php

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

    // A configured feed URL is admin-trusted, but nothing pins where it (or a
    // redirect from it) actually points — fetch_guarded() re-checks the
    // destination is a public, non-internal address on every hop.
    $response = fetch_guarded($url, ['timeout' => FEED_FETCH_TIMEOUT]);
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

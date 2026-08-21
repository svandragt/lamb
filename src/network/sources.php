<?php

namespace Lamb\Network;

use SimplePie\File as SimplePieFile;
use SimplePie\SimplePie;

use function Lamb\Http\is_valid_http_url;
use function Lamb\Http\resolve_validated_ip;

// FEED_FETCH_TIMEOUT and FEED_FETCH_MAX_BYTES are defined in constants.php

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
 * SimplePie's remote-fetch class, subclassed to harden feed fetches against
 * SSRF and oversized bodies. SimplePie follows a redirect by recursively
 * re-entering this constructor (PHP dispatches `$this->__construct()`
 * virtually), so the guards below run on every hop, not just the initial URL.
 *
 * See network/README.md ("Fetch hardening") for the SSRF-pinning and body-cap
 * model this implements.
 */
class SafeFile extends SimplePieFile
{
    /**
     * @param array<int, mixed> $curl_options
     */
    public function __construct(
        string $url,
        int $timeout = 10,
        int $redirects = 5,
        ?array $headers = null,
        ?string $useragent = null,
        bool $force_fsockopen = false,
        array $curl_options = []
    ) {
        $pinned = self::buildPinnedCurlOptions($url, $curl_options, $force_fsockopen);
        if ($pinned === false) {
            $this->success = false;
            $this->error = 'Blocked: URL does not resolve to a public, routable address';
            return;
        }

        parent::__construct(
            $url,
            $timeout,
            $redirects,
            $headers,
            $useragent,
            false,
            self::capBodyCurlOptions($pinned, FEED_FETCH_MAX_BYTES)
        );
    }

    /**
     * Caps a feed fetch at $max_bytes without changing how SimplePie receives
     * the body. SimplePie reads the response from curl_exec()'s return value, so
     * a CURLOPT_WRITEFUNCTION cap (the fetch_guarded() approach) would lose it;
     * these three options bound the transfer instead. Each guards its own line:
     *
     * - `CURLOPT_ENCODING: identity` forces an uncompressed body, so the cap
     *   bounds real bytes — over a compressed transfer it would bound only the
     *   *compressed* size and a gzip bomb would expand past it unseen.
     * - `CURLOPT_MAXFILESIZE` refuses an over-cap declared Content-Length up front.
     * - the progress callback catches a chunked/undeclared-length body; aborting
     *   surfaces as a fetch error, so the success watermark is left alone.
     *
     * See network/README.md ("Fetch hardening"). Applied on every redirect hop
     * (see the class docblock).
     *
     * @param array<int, mixed> $curl_options
     * @return array<int, mixed>
     */
    public static function capBodyCurlOptions(array $curl_options, int $max_bytes): array
    {
        $curl_options[CURLOPT_ENCODING] = 'identity';
        $curl_options[CURLOPT_MAXFILESIZE] = $max_bytes;
        $curl_options[CURLOPT_NOPROGRESS] = false;
        $curl_options[CURLOPT_PROGRESSFUNCTION] = static function (
            mixed $ch,
            int $download_size,
            int $downloaded,
            int $upload_size,
            int $uploaded
        ) use ($max_bytes): int {
            return ($downloaded > $max_bytes || $download_size > $max_bytes) ? 1 : 0;
        };

        return $curl_options;
    }

    /**
     * Validates the URL's destination and, if safe, merges a `CURLOPT_RESOLVE`
     * entry into `$curl_options` pinning the connection to it. Pure logic
     * split out of the constructor so it can be unit-tested without making a
     * real request (the constructor fetches immediately via SimplePie).
     *
     * @param array<int, mixed> $curl_options
     * @return array<int, mixed>|false Merged curl options, or false when the
     *                                  URL is unsafe or pinning isn't possible.
     */
    public static function buildPinnedCurlOptions(
        string $url,
        array $curl_options,
        bool $force_fsockopen,
        ?callable $resolver = null
    ): array|false {
        // fsockopen has no CURLOPT_RESOLVE equivalent, so a forced fsockopen
        // request would re-resolve the host itself and reopen the TOCTOU.
        if ($force_fsockopen || !is_valid_http_url($url)) {
            return false;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        $ip = resolve_validated_ip($host, $resolver);
        if ($ip === false) {
            return false;
        }

        $port = parse_url($url, PHP_URL_PORT)
            ?? (strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' ? 443 : 80);

        $curl_options[CURLOPT_RESOLVE] = array_merge(
            $curl_options[CURLOPT_RESOLVE] ?? [],
            ["$host:$port:$ip"]
        );

        return $curl_options;
    }
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

/**
 * Wires a SimplePie instance up for a feed URL: the SSRF-guarded fetch class, the
 * shared cache directory (disabling caching when it is not writable), the cache
 * lifetime, and the per-fetch timeout that stops a slow or hostile feed stalling
 * the cron run. Split from init_simplepie_feed() so the wiring is testable without
 * a network fetch.
 *
 * @param SimplePie $feed The instance to configure.
 * @param string    $url  The RSS/Atom feed URL.
 * @return void
 */
function configure_simplepie_feed(SimplePie $feed, string $url): void
{
    $feed->get_registry()->register(SimplePieFile::class, SafeFile::class, true);
    $cache_dir = ensure_feed_cache(\Lamb\Bootstrap\data_dir() . '/cache/simplepie');
    if ($cache_dir === false) {
        $feed->enable_cache(false);
    } else {
        /** @noinspection PhpDeprecationInspection */
        $feed->set_cache_location($cache_dir);
    }
    // SimplePie keeps a feed for an hour by default, while /_cron re-fetches every
    // half hour: every other crawl then read an up-to-an-hour-old copy of the feed
    // and still recorded a success. Align the two so a crawl always revalidates
    // (the cache stays useful — it is what carries the ETag/Last-Modified).
    $feed->set_cache_duration(FEED_FETCH_INTERVAL);
    $feed->set_feed_url($url);
    // Cap each fetch so a slow or hostile feed URL cannot stall the cron run.
    $feed->set_timeout(FEED_FETCH_TIMEOUT);
}

/**
 * Builds and initialises a SimplePie instance for a feed URL.
 *
 * @param string $url The RSS/Atom feed URL.
 * @return SimplePie The initialised SimplePie instance.
 */
function init_simplepie_feed(string $url): SimplePie
{
    $feed = new SimplePie();
    configure_simplepie_feed($feed, $url);
    $feed->init();

    return $feed;
}

/**
 * Crawls a single initialised feed and records the outcome on its feedstatus bean:
 * a failed fetch (`!$feed->data` or a non-empty `$feed->error()`) records the error
 * without advancing the success watermark; a success ingests and raises it. See
 * network/README.md ("The watermark model").
 *
 * @param string    $name Feed name from config.
 * @param string    $url  Feed URL from config.
 * @param SimplePie $feed The initialised SimplePie instance.
 * @return array{ok: bool, items: int, error: ?string}
 */
function record_feed_crawl(string $name, string $url, SimplePie $feed): array
{
    [$status, $now] = begin_crawl($name, $url);

    $error = $feed->error();
    if (is_array($error)) {
        $error = implode('; ', array_filter($error));
    }

    if (!$feed->data || $error) {
        return record_crawl_failure($status, $now, (string)($error ?: 'Feed fetch failed: no data returned.'));
    }

    [$items, $newest] = ingest_items($feed->get_items(), $name, $status);

    return record_crawl_success($status, $now, $items, $newest);
}

<?php

namespace Lamb\Network;

use SimplePie\File as SimplePieFile;
use SimplePie\SimplePie;

use function Lamb\Http\is_valid_http_url;
use function Lamb\Http\resolve_validated_ip;

// FEED_FETCH_TIMEOUT is defined in constants.php

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
 * SimplePie's remote-fetch class, hardened against SSRF: refuses to make a
 * request when the destination doesn't resolve to a public address, and pins
 * the curl connection to the exact address that was validated.
 *
 * A feed URL is admin-configured (trusted at add-time), but nothing pins its
 * *eventual* destination — if the feed host is later compromised, or simply
 * issues a redirect, the cron job would otherwise fetch wherever it points,
 * including internal/loopback addresses. SimplePie\File follows redirects by
 * recursively calling `$this->__construct()` on each hop (see its
 * constructor), which — since PHP dispatches `$this->__construct()`
 * virtually — re-enters *this* subclass's override on every hop, so each
 * redirect target is checked, not just the initial URL.
 *
 * Checking the URL and then letting curl make its own, independent DNS
 * lookup is itself a DNS-rebinding TOCTOU — the address curl connects to
 * could differ from the one just validated. `CURLOPT_RESOLVE` closes that:
 * it pins curl to a chosen address for a given host:port while still using
 * the original hostname for the `Host:` header, SNI, and certificate
 * verification. That only works through curl, so a request forced through
 * `fsockopen` (which has no equivalent) is refused rather than left
 * unpinned.
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

        parent::__construct($url, $timeout, $redirects, $headers, $useragent, false, $pinned);
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
    $cache_dir = ensure_feed_cache('../data/cache/simplepie');
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
 * Crawls a single initialised feed and records the outcome on its feedstatus bean.
 *
 * A failed fetch (`!$feed->data` or a non-empty `$feed->error()`) does NOT advance the
 * success watermark — it only stamps `last_attempt` and records the error so the
 * Logs tab can surface it. On success, entries newer than the newest one this feed
 * has offered before are created or updated, that ingestion watermark is raised, the
 * item count is recorded and any prior error is cleared.
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

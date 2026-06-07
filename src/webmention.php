<?php

/** @noinspection PhpUnused */

namespace Lamb\Webmention;

use JetBrains\PhpStorm\NoReturn;
use RedBeanPHP\OODBBean;
use RedBeanPHP\R;

use function Lamb\is_scheduled;
use function Lamb\permalink;

use const ROOT_URL;

/**
 * Seconds before fetching a webmention source page is abandoned.
 */
const WEBMENTION_FETCH_TIMEOUT = 10;

/**
 * Route handler for POST /webmention.
 *
 * Accepts `source` and `target` form parameters per the Webmention spec,
 * verifies them, and persists a verified mention. Always terminates the
 * request with a plain-text response.
 *
 * @param array $_args Unused route arguments.
 * @return void
 */
#[NoReturn]
function respond_webmention(array $_args): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        header('Content-Type: text/plain; charset=utf-8');
        echo 'Method Not Allowed';
        die();
    }

    $result = verify_and_store((string) ($_POST['source'] ?? ''), (string) ($_POST['target'] ?? ''));

    http_response_code($result['status']);
    header('Content-Type: text/plain; charset=utf-8');
    echo $result['body'];
    die();
}

/**
 * Verify a source→target webmention and persist (or remove) it.
 *
 * The fetcher is injectable so the verification flow can be tested without
 * network access; in production it defaults to {@see fetch_source}.
 *
 * @param string        $source  The page that supposedly links to us.
 * @param string        $target  The Lamb post URL being mentioned.
 * @param callable|null $fetcher fn(string $url): ?string — returns source HTML or null.
 * @return array{status:int, body:string}
 */
function verify_and_store(string $source, string $target, ?callable $fetcher = null): array
{
    $source = trim($source);
    $target = trim($target);

    if (!is_valid_http_url($source) || !is_valid_http_url($target)) {
        return response(400, 'source and target are required and must be http(s) URLs');
    }
    if (rtrim($source, '/') === rtrim($target, '/')) {
        return response(400, 'source and target must differ');
    }

    $post_id = target_post_id($target);
    if ($post_id === null) {
        return response(400, 'target is not a valid post on this site');
    }

    $fetcher ??= __NAMESPACE__ . '\\fetch_source';
    $html = $fetcher($source);

    $existing = R::findOne('webmention', ' source = ? AND target = ? ', [$source, $target]);

    if (!is_string($html) || !source_mentions_target($html, $target)) {
        // The source no longer links to us: drop any mention we already held.
        if ($existing) {
            R::trash($existing);
            return response(200, 'mention removed: source no longer links to target');
        }
        return response(400, 'source does not link to target');
    }

    $meta = extract_meta($html);
    $now = \Lamb\now();

    $mention = $existing ?: R::dispense('webmention');
    $mention->source = $source;
    $mention->target = $target;
    $mention->post_id = $post_id;
    $mention->type = 'mention';
    $mention->author = $meta['author'];
    $mention->content = $meta['content'];
    $mention->status = $mention->status ?: 'pending';
    $mention->created = $mention->created ?: $now;
    $mention->verified_at = $now;
    R::store($mention);

    return response(202, 'accepted');
}

/**
 * Resolve a target URL to the id of the Lamb post it points at.
 *
 * Returns null when the host is not ours, the path is not a recognised post
 * URL, or no matching post exists.
 *
 * @param string $target
 * @return int|null
 */
function target_post_id(string $target): ?int
{
    $root_host = parse_url(ROOT_URL, PHP_URL_HOST);
    $target_host = parse_url($target, PHP_URL_HOST);
    if (!is_string($target_host) || !is_string($root_host) || strcasecmp($target_host, $root_host) !== 0) {
        return null;
    }

    $path = parse_url($target, PHP_URL_PATH) ?: '';

    if (preg_match('#^/status/(\d+)$#', $path, $matches)) {
        $bean = R::load('post', (int) $matches[1]);
        return $bean->id ? (int) $bean->id : null;
    }

    $slug = trim($path, '/');
    if ($slug !== '') {
        $bean = R::findOne('post', ' slug = ? ', [$slug]);
        return $bean ? (int) $bean->id : null;
    }

    return null;
}

/**
 * Determine whether the fetched source HTML links to the target URL.
 *
 * Checks href/src attributes (the spec's primary requirement) and falls back
 * to a raw substring match so plain-text or unusual markup still counts.
 *
 * @param string $html
 * @param string $target
 * @return bool
 */
function source_mentions_target(string $html, string $target): bool
{
    if ($target === '' || $html === '') {
        return false;
    }

    $needle = rtrim($target, '/');
    if (preg_match_all('/(?:href|src)\s*=\s*["\']([^"\']+)["\']/i', $html, $matches)) {
        foreach ($matches[1] as $url) {
            if (rtrim(html_entity_decode($url), '/') === $needle) {
                return true;
            }
        }
    }

    return str_contains($html, $target);
}

/**
 * Return verified webmentions for a post, oldest first.
 *
 * @param int $post_id
 * @return OODBBean[]
 */
function webmentions_for_post(int $post_id): array
{
    return array_values(
        R::find('webmention', ' post_id = ? AND verified_at IS NOT NULL ORDER BY created ASC ', [$post_id])
    );
}

/**
 * Best-effort extraction of author name and a short content snippet from a
 * source page, without pulling in a full microformats2 parser.
 *
 * @param string $html
 * @return array{author:?string, content:?string}
 */
function extract_meta(string $html): array
{
    $author = null;
    if (preg_match('/<meta\s+name=["\']author["\']\s+content=["\']([^"\']*)["\']/i', $html, $m)) {
        $author = trim($m[1]) ?: null;
    }
    if ($author === null && preg_match('/rel=["\']author["\'][^>]*>([^<]+)</i', $html, $m)) {
        $author = trim(strip_tags($m[1])) ?: null;
    }

    $content = null;
    if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $html, $m)) {
        $content = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5)) ?: null;
    }

    return ['author' => $author, 'content' => $content];
}

/**
 * Fetch the raw HTML of a webmention source page.
 *
 * @param string $url
 * @return string|null
 */
function fetch_source(string $url): ?string
{
    $result = \Lamb\Http\fetch($url, [
        'headers' => ['Accept: text/html, */*', 'User-Agent: Lamb-Webmention'],
        'timeout' => WEBMENTION_FETCH_TIMEOUT,
    ]);

    return $result === null ? null : $result['body'];
}

/**
 * Whether a string is an absolute http(s) URL with a host.
 *
 * @param string $url
 * @return bool
 */
function is_valid_http_url(string $url): bool
{
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true) && parse_url($url, PHP_URL_HOST) !== null;
}

/**
 * Build a {status, body} response array.
 *
 * @param int    $status
 * @param string $body
 * @return array{status:int, body:string}
 */
function response(int $status, string $body): array
{
    return ['status' => $status, 'body' => $body];
}

// ---------------------------------------------------------------------------
// Sending webmentions on publish (#248)
// ---------------------------------------------------------------------------

/**
 * Extract distinct external http(s) link targets from rendered post HTML.
 *
 * Only absolute links to other hosts are returned — links back to this site,
 * relative links, and non-http(s) schemes (mailto:, etc.) are ignored.
 *
 * @param string $html
 * @return string[]
 */
function extract_outbound_links(string $html): array
{
    $targets = [];

    if (preg_match_all('/<a\b[^>]*\bhref\s*=\s*["\']([^"\']+)["\']/i', $html, $matches)) {
        foreach ($matches[1] as $href) {
            $href = html_entity_decode(trim($href), ENT_QUOTES | ENT_HTML5);
            if (is_external_http_url($href) && !in_array($href, $targets, true)) {
                $targets[] = $href;
            }
        }
    }

    return $targets;
}

/**
 * Whether a URL is an absolute http(s) URL pointing at a host other than ours.
 *
 * @param string $url
 * @return bool
 */
function is_external_http_url(string $url): bool
{
    if (!is_valid_http_url($url)) {
        return false;
    }
    $own_host = strtolower((string) parse_url(ROOT_URL, PHP_URL_HOST));
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    return $host !== '' && $host !== $own_host;
}

/**
 * Discover a target's webmention endpoint.
 *
 * Resolution order follows the spec: HTTP `Link` headers first, then the first
 * `<link>`/`<a>` with `rel="webmention"` in document order. Relative endpoints
 * are resolved against the target URL. Returns null when none is found.
 *
 * @param string   $html         The fetched target HTML.
 * @param string[] $link_headers Raw `Link` header values (without the "Link:" prefix).
 * @param string   $target_url   The URL the headers/HTML were fetched from.
 * @return string|null
 */
function discover_endpoint(string $html, array $link_headers, string $target_url): ?string
{
    foreach ($link_headers as $header) {
        foreach (explode(',', $header) as $part) {
            if (!preg_match('/<([^>]+)>\s*;\s*(.*)/s', trim($part), $m)) {
                continue;
            }
            if (preg_match('/\brel\s*=\s*"?([^";]+)"?/i', $m[2], $rel) && rel_has_webmention($rel[1])) {
                return resolve_url($target_url, trim($m[1]));
            }
        }
    }

    if (preg_match_all('/<(?:link|a)\b[^>]*>/i', $html, $tags)) {
        foreach ($tags[0] as $tag) {
            if (!preg_match('/\brel\s*=\s*["\']([^"\']*)["\']/i', $tag, $rel) || !rel_has_webmention($rel[1])) {
                continue;
            }
            if (preg_match('/\bhref\s*=\s*["\']([^"\']+)["\']/i', $tag, $href)) {
                return resolve_url($target_url, html_entity_decode(trim($href[1]), ENT_QUOTES | ENT_HTML5));
            }
        }
    }

    return null;
}

/**
 * Whether a rel attribute's space-separated token list contains "webmention".
 *
 * @param string $rel
 * @return bool
 */
function rel_has_webmention(string $rel): bool
{
    foreach (preg_split('/\s+/', strtolower($rel)) as $token) {
        if (trim($token, " \t\"'") === 'webmention') {
            return true;
        }
    }
    return false;
}

/**
 * Resolve a possibly-relative URL against a base URL.
 *
 * @param string $base
 * @param string $rel
 * @return string
 */
function resolve_url(string $base, string $rel): string
{
    if ($rel === '') {
        return $base;
    }
    if (parse_url($rel, PHP_URL_SCHEME) !== null) {
        return $rel;
    }

    $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
    $host = parse_url($base, PHP_URL_HOST) ?: '';
    $port = parse_url($base, PHP_URL_PORT);
    $authority = $scheme . '://' . $host . ($port ? ':' . $port : '');

    if (str_starts_with($rel, '//')) {
        return $scheme . ':' . $rel;
    }
    if (str_starts_with($rel, '/')) {
        return $authority . $rel;
    }

    $path = parse_url($base, PHP_URL_PATH) ?: '/';
    $dir = rtrim(substr($path, 0, strrpos($path, '/') + 1), '/');
    return $authority . $dir . '/' . $rel;
}

/**
 * Queue outbound webmentions for a freshly saved post, if it is eligible.
 *
 * Skips ingested feed items (third-party content) and drafts — neither should
 * notify external targets. Future-dated scheduled posts ARE queued: their rows
 * wait in the outbox until publication, because process_outbound() only sends
 * once the source post is publicly visible (#302).
 *
 * @param OODBBean $bean A stored post bean.
 * @return void
 */
function enqueue_for_post(OODBBean $bean): void
{
    if (!$bean->id || !empty($bean->feed_name) || !empty($bean->draft)) {
        return;
    }

    enqueue_outbound(
        (int) $bean->id,
        permalink($bean),
        (string) ($bean->transformed ?? ''),
        (string) ($bean->in_reply_to ?? '')
    );
}

/**
 * Queue outbound webmentions for every external link in a post.
 *
 * Idempotent across edits: an existing pending/sent row for the same
 * source+target is left untouched (so receivers are not spammed), while a
 * previously failed or cancelled row is reset to pending for another attempt.
 * Pending rows whose target no longer appears in the post are cancelled, so a
 * scheduled post edited to remove a link before publication does not notify it.
 *
 * The reply target (from a post's `in-reply-to` front matter) is treated as an
 * outbound link even when it does not appear in the body, so replies notify the
 * parent. It is subject to the same external-host filter as body links.
 *
 * @param int    $post_id
 * @param string $source   This post's permalink.
 * @param string $html     The post's rendered HTML.
 * @param string $reply_to Optional `in-reply-to` target URL.
 * @return int Number of newly created queue rows.
 */
function enqueue_outbound(int $post_id, string $source, string $html, string $reply_to = ''): int
{
    $targets = extract_outbound_links($html);

    $reply_to = trim($reply_to);
    if ($reply_to !== '' && is_external_http_url($reply_to) && !in_array($reply_to, $targets, true)) {
        $targets[] = $reply_to;
    }

    // Cancel pending rows for links the post no longer contains.
    $stale = R::find('webmentionoutbox', ' source = ? AND status = ? ', [$source, 'pending']);
    foreach ($stale as $row) {
        if (!in_array((string) $row->target, $targets, true)) {
            $row->status = 'cancelled';
            R::store($row);
        }
    }

    $created = 0;
    foreach ($targets as $target) {
        $row = R::findOne('webmentionoutbox', ' source = ? AND target = ? ', [$source, $target]);
        if ($row) {
            if ($row->status === 'failed' || $row->status === 'cancelled') {
                $row->status = 'pending';
                R::store($row);
            }
            continue;
        }

        $row = R::dispense('webmentionoutbox');
        $row->post_id = $post_id;
        $row->source = $source;
        $row->target = $target;
        $row->status = 'pending';
        $row->attempts = 0;
        $row->created = \Lamb\now();
        $row->processed_at = null;
        R::store($row);
        $created++;
    }

    return $created;
}

/**
 * Process queued outbound webmentions: discover each target's endpoint and
 * POST the mention. Intended to be driven by the `/_cron` route.
 *
 * Each row's source post is re-checked before sending: rows for deleted,
 * draft, or missing posts are cancelled, and rows for still-scheduled posts
 * are left pending (untouched, no attempt counted) until publication. This
 * deliberately does not use is_viewable(), which trusts logged-in sessions —
 * a logged-in author hitting /_cron must not leak drafts. Deferred scheduled
 * rows still occupy part of the LIMIT window; with the default of 20 that is
 * harmless.
 *
 * Both network operations are injectable for testing:
 *  - $fetcher fn(string $url): ?array{headers: string[], body: string}
 *  - $sender  fn(string $endpoint, string $source, string $target): int (HTTP status)
 *
 * @param callable|null $fetcher
 * @param callable|null $sender
 * @param int           $limit Maximum rows to process per run.
 * @return array{sent:int, failed:int, skipped:int, cancelled:int}
 */
function process_outbound(?callable $fetcher = null, ?callable $sender = null, int $limit = 20): array
{
    $fetcher ??= __NAMESPACE__ . '\\fetch_target';
    $sender ??= __NAMESPACE__ . '\\send_webmention';

    $stats = ['sent' => 0, 'failed' => 0, 'skipped' => 0, 'cancelled' => 0];
    $rows = R::find('webmentionoutbox', ' status = ? ORDER BY created ASC LIMIT ? ', ['pending', $limit]);

    foreach ($rows as $row) {
        $outcome = process_outbound_row($row, $fetcher, $sender);
        if ($outcome !== null) {
            $stats[$outcome]++;
        }
    }

    return $stats;
}

/**
 * Process a single outbox row: re-check its source post, discover the target's
 * endpoint, and POST the mention. Mutates and stores `$row` as a side effect.
 *
 * Returns the stat bucket the row falls into ('cancelled', 'skipped', 'sent' or
 * 'failed'), or null when the row is deferred — its source post is still
 * scheduled, so it is left pending and untouched (no attempt counted, no store)
 * until publication.
 *
 * @param OODBBean $row
 * @param callable $fetcher fn(string $url): ?array{headers: string[], body: string}
 * @param callable $sender  fn(string $endpoint, string $source, string $target): int
 * @return string|null Stat bucket name, or null when the row is deferred.
 */
function process_outbound_row(OODBBean $row, callable $fetcher, callable $sender): ?string
{
    $post = R::load('post', (int) $row->post_id);
    if (!$post->id || $post->deleted == 1 || $post->draft == 1) {
        $row->status = 'cancelled';
        $row->processed_at = \Lamb\now();
        R::store($row);
        return 'cancelled';
    }
    if (is_scheduled($post)) {
        return null;
    }

    $row->attempts = (int) $row->attempts + 1;
    $row->processed_at = \Lamb\now();

    $fetched = $fetcher($row->target);
    $endpoint = is_array($fetched)
        ? discover_endpoint($fetched['body'] ?? '', $fetched['headers'] ?? [], $row->target)
        : null;

    // A target may advertise any URL as its endpoint — only POST to http(s).
    if ($endpoint !== null && !is_valid_http_url($endpoint)) {
        $endpoint = null;
    }

    if ($endpoint === null) {
        $row->status = 'skipped';
        R::store($row);
        return 'skipped';
    }

    $row->endpoint = $endpoint;
    $code = (int) $sender($endpoint, $row->source, $row->target);
    if ($code >= 200 && $code < 300) {
        $row->status = 'sent';
        $outcome = 'sent';
    } else {
        $row->status = 'failed';
        $outcome = 'failed';
    }
    R::store($row);
    return $outcome;
}

/**
 * Fetch a target page, returning its `Link` headers and body.
 *
 * @param string $url
 * @return array{headers: string[], body: string}|null
 */
function fetch_target(string $url): ?array
{
    $result = \Lamb\Http\fetch($url, [
        'headers' => ['Accept: text/html, */*', 'User-Agent: Lamb-Webmention'],
        'timeout' => WEBMENTION_FETCH_TIMEOUT,
    ]);

    if ($result === null) {
        return null;
    }

    $link_headers = [];
    foreach ($result['headers'] as $header) {
        if (preg_match('/^link:\s*(.*)$/i', $header, $m)) {
            $link_headers[] = trim($m[1]);
        }
    }

    return ['headers' => $link_headers, 'body' => $result['body']];
}

/**
 * POST a webmention to a discovered endpoint and return the HTTP status code.
 *
 * @param string $endpoint
 * @param string $source
 * @param string $target
 * @return int HTTP status code, or 0 on transport failure.
 */
function send_webmention(string $endpoint, string $source, string $target): int
{
    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", [
                'Content-Type: application/x-www-form-urlencoded',
                'User-Agent: Lamb-Webmention',
            ]),
            'content' => http_build_query(['source' => $source, 'target' => $target]),
            'timeout' => WEBMENTION_FETCH_TIMEOUT,
            'ignore_errors' => true,
        ],
    ]);

    $response = @file_get_contents($endpoint, false, $context);
    if ($response === false && empty($http_response_header)) {
        return 0;
    }

    return \Lamb\Http\parse_status_line($http_response_header[0] ?? '');
}

<?php

/** @noinspection PhpUnused */

namespace Lamb\Webmention;

use JetBrains\PhpStorm\NoReturn;
use RedBeanPHP\OODBBean;
use RedBeanPHP\R;

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
    $now = date('Y-m-d H:i:s');

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
    if ($target_host === null || $root_host === null || strcasecmp($target_host, $root_host) !== 0) {
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
    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", [
                'Accept: text/html, */*',
                'User-Agent: Lamb-Webmention',
            ]),
            'timeout' => WEBMENTION_FETCH_TIMEOUT,
            'follow_location' => 1,
            'max_redirects' => 5,
            'ignore_errors' => true,
        ],
    ]);

    $body = @file_get_contents($url, false, $context);
    return $body === false ? null : $body;
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

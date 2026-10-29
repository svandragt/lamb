<?php

/**
 * SocialSpec (https://socialspec.org/) well-known files.
 *
 * Each file is derived from data Lamb already holds, and is absent (404) when
 * that data is, so nothing is published until the author has set it:
 *  - about.md       the published post slugged `about`
 *  - contact.vcf    author_name / author_email / site_url / [me]
 *  - following.opml a copy of the OPML at `following_url` (e.g. a feed
 *                   reader's public list), refreshed by /_cron
 *
 * User docs: docs/socialspec.md. Decision: DECISIONS.md (2026-10-29).
 */

namespace Lamb\SocialSpec;

use RedBeanPHP\OODBBean;
use RedBeanPHP\R;
use Symfony\Component\Yaml\Yaml;

use function Lamb\Http\fetch_guarded;
use function Lamb\Http\is_valid_http_url;
use function Lamb\Network\feed_fetch_due;
use function Lamb\Post\parse_matter;
use function Lamb\Post\split_frontmatter;

use const Lamb\Config\IDENTITY_FALLBACK;

/** Slug of the post served as /.well-known/about.md. */
const ABOUT_SLUG = 'about';

/** Largest following list we keep; a few thousand subscriptions fit well under this. */
const FOLLOWING_MAX_BYTES = 2_000_000;

/**
 * The published, non-scheduled post slugged `about`, or null.
 *
 * Visibility is checked in SQL rather than with is_viewable(), so a logged-in
 * author requesting the file sees what everyone else does.
 *
 * @return OODBBean|null
 */
function about_post(): ?OODBBean
{
    $visible = \Lamb\visible_clause();
    return R::findOne('post', ' slug = ? AND ' . $visible['sql'], [ABOUT_SLUG, ...$visible['params']]);
}

/**
 * Renders the about post as AboutSpec Markdown.
 *
 * Lamb's own front matter is replaced by a `summary` (the post's summary or
 * description, else site_description) and the title becomes a heading.
 * Root-relative link and image targets are made absolute, because the file is
 * read from other origins.
 *
 * @param OODBBean             $post
 * @param array<string, mixed> $config
 * @param string               $root_url Site root without trailing slash.
 * @return string
 */
function about_markdown(OODBBean $post, array $config, string $root_url): string
{
    $body = (string) $post->body;
    $matter = parse_matter($body);
    [, $content] = split_frontmatter($body);

    $summary = $matter['summary'] ?? $matter['description'] ?? $config['site_description'] ?? '';
    $out = '';
    if (is_string($summary) && trim($summary) !== '') {
        $out .= "---\n" . Yaml::dump(['summary' => trim($summary)]) . "---\n\n";
    }
    $title = $matter['title'] ?? '';
    if (is_string($title) && trim($title) !== '') {
        $out .= '# ' . trim($title) . "\n\n";
    }

    $root = rtrim($root_url, '/') . '/';
    // Inline `](/x)` targets, and reference definitions `[label]: /x`.
    $content = (string) preg_replace('~(\]\()/(?!/)~', '$1' . $root, trim($content));
    $content = (string) preg_replace('~^( {0,3}\[[^\]\n]+\]:[ \t]*<?)/(?!/)~m', '$1' . $root, $content);
    return $out . $content . "\n";
}

/**
 * Escapes a vCard 4.0 text value (RFC 6350 §3.4).
 */
function vcard_escape(string $value): string
{
    return strtr($value, ['\\' => '\\\\', ',' => '\\,', ';' => '\\;', "\r\n" => '\\n', "\n" => '\\n', "\r" => '\\n']);
}

/**
 * Folds a content line at 75 octets without splitting a UTF-8 character (RFC 6350 §3.2).
 */
function vcard_fold(string $line): string
{
    $out = '';
    $limit = 75;
    while (strlen($line) > $limit) {
        $cut = $limit;
        // Back up off UTF-8 continuation bytes (10xxxxxx).
        while ($cut > 0 && (ord($line[$cut]) & 0xC0) === 0x80) {
            $cut--;
        }
        $out .= substr($line, 0, $cut) . "\r\n ";
        $line = substr($line, $cut);
        $limit = 74; // the leading space counts towards the next line
    }
    return $out . $line . "\r\n";
}

/**
 * Builds the ContactSpec vCard, or null when no valid author_email is set.
 *
 * The email is the opt-in: without one there is nothing to contact. The
 * placeholder identity a fresh install falls back to counts as unset.
 *
 * @param array<string, mixed> $config
 * @param string               $root_url Site root without trailing slash.
 * @return string|null
 */
function contact_vcard(array $config, string $root_url): ?string
{
    $email = trim((string) ($config['author_email'] ?? ''));
    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || $email === IDENTITY_FALLBACK['author_email']) {
        return null;
    }
    $name = trim((string) ($config['author_name'] ?? ''));
    if ($name === '' || $name === IDENTITY_FALLBACK['author_name']) {
        $name = trim((string) ($config['site_title'] ?? ''));
    }

    $lines = ['BEGIN:VCARD', 'VERSION:4.0', 'FN:' . vcard_escape($name), 'EMAIL;PREF=1:' . $email, 'URL:' . rtrim($root_url, '/') . '/'];
    foreach ((array) ($config['me'] ?? []) as $url) {
        if (is_string($url) && is_valid_http_url($url)) {
            $lines[] = 'URL:' . $url;
        }
    }
    $lines[] = 'END:VCARD';

    return implode('', array_map(__NAMESPACE__ . '\\vcard_fold', $lines));
}

/**
 * Whether a body is an OPML document safe to republish.
 *
 * A DOCTYPE is refused outright: OPML defines none, and internal entities are
 * an expansion-bomb vector.
 */
function is_valid_opml(string $body): bool
{
    if ($body === '' || stripos($body, '<!DOCTYPE') !== false) {
        return false;
    }
    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);

    return $xml !== false && $xml->getName() === 'opml' && isset($xml->body);
}

/**
 * Where the following list copy lives.
 */
function following_cache_path(): string
{
    return \Lamb\Bootstrap\data_dir() . '/following.opml';
}

/**
 * Refreshes the local copy of the following list from following_url.
 *
 * Runs from /_cron, at most once per 30 minutes (the feed window), as a
 * conditional GET. The last good copy is kept when the source fails or returns
 * something that isn't OPML, so a reader outage never empties the list.
 * Unsetting following_url removes the copy.
 *
 * Bookkeeping (ETag, last attempt) lives in a `<path>.json` sidecar next to
 * the copy so the two can never disagree.
 *
 * @param string|null   $url     The configured following_url.
 * @param string        $path    Local copy path.
 * @param callable|null $fetcher fn(string $url, array $opts): ?array — defaults to fetch_guarded().
 * @param int|null      $now     Current Unix timestamp.
 * @return string Cron output line ('' when unconfigured or not due).
 */
function refresh_following(?string $url, string $path, ?callable $fetcher = null, ?int $now = null): string
{
    $meta_path = $path . '.json';
    if ($url === null || trim($url) === '') {
        @unlink($path);
        @unlink($meta_path);
        return '';
    }
    $url = trim($url);
    $now ??= time();
    $fetcher ??= static fn(string $u, array $o): ?array => fetch_guarded($u, $o);

    $meta = following_meta($path);
    if (($meta['url'] ?? null) !== $url) {
        // A new source: the old list is no longer the author's.
        @unlink($path);
        $meta = [];
    }
    if (isset($meta['attempt']) && !feed_fetch_due((int) $meta['attempt'], $now)) {
        return '';
    }

    $headers = ['Accept: text/x-opml, application/xml;q=0.9, */*;q=0.1'];
    if (is_file($path) && isset($meta['etag'])) {
        $headers[] = 'If-None-Match: ' . $meta['etag'];
    }
    $result = $fetcher($url, ['headers' => $headers, 'timeout' => FEED_FETCH_TIMEOUT, 'max_bytes' => FOLLOWING_MAX_BYTES]);

    $meta = ['url' => $url, 'attempt' => $now] + $meta;
    // /_cron is unauthenticated, so its output never names the URL: a
    // reader's public-list address usually carries a secret token.
    $line = 'Following list: fetch failed.' . PHP_EOL;
    if ($result !== null && $result['status'] === 304 && is_file($path)) {
        $line = 'Following list: unchanged.' . PHP_EOL;
    } elseif ($result !== null && $result['status'] === 200 && is_valid_opml($result['body'])) {
        $tmp = $path . '.tmp';
        if (file_put_contents($tmp, $result['body']) !== false && rename($tmp, $path)) {
            $meta['etag'] = response_etag($result['headers']);
            $line = 'Following list: updated.' . PHP_EOL;
        }
    }
    file_put_contents($meta_path, json_encode(array_filter($meta, static fn($v) => $v !== null)));

    return $line;
}

/**
 * The bookkeeping sidecar of a following-list copy: its source `url`, last
 * `attempt` and `etag`. Empty when missing or unreadable.
 *
 * @return array<string, mixed>
 */
function following_meta(string $path): array
{
    $meta = is_file($path . '.json') ? json_decode((string) file_get_contents($path . '.json'), true) : null;
    return is_array($meta) ? $meta : [];
}

/**
 * The ETag header value from raw response header lines, or null.
 *
 * @param string[] $headers
 */
function response_etag(array $headers): ?string
{
    foreach ($headers as $header) {
        if (preg_match('/^etag:\s*(.+)$/i', $header, $m)) {
            return trim($m[1]);
        }
    }
    return null;
}

/**
 * The body, content type and last-change time of a well-known file, or null
 * when the file isn't published.
 *
 * @param string               $name     Requested file name under /.well-known/.
 * @param array<string, mixed> $config
 * @param string               $root_url Site root without trailing slash.
 * @param string               $following_path Local following-list copy.
 * @return array{body: string, type: string, modified: int}|null
 */
function well_known_document(string $name, array $config, string $root_url, string $following_path): ?array
{
    switch ($name) {
        case 'about.md':
            $post = about_post();
            if ($post === null) {
                return null;
            }
            return [
                'body' => about_markdown($post, $config, $root_url),
                'type' => 'text/markdown; charset=utf-8',
                'modified' => strtotime((string) $post->updated) ?: 0,
            ];
        case 'contact.vcf':
            $vcf = contact_vcard($config, $root_url);
            return $vcf === null ? null : ['body' => $vcf, 'type' => 'text/vcard; charset=utf-8', 'modified' => 0];
        case 'following.opml':
            // Only a copy of the URL configured now: after an edit, the old
            // list stays hidden until /_cron fetches the new one.
            $url = trim((string) ($config['following_url'] ?? ''));
            if ($url === '' || !is_file($following_path) || (following_meta($following_path)['url'] ?? null) !== $url) {
                return null;
            }
            return [
                'body' => (string) file_get_contents($following_path),
                'type' => 'text/x-opml; charset=utf-8',
                'modified' => (int) filemtime($following_path),
            ];
    }
    return null;
}

/**
 * Responds to /.well-known/<name> for the SocialSpec files.
 *
 * Readable cross-origin (CORS `*`), cached like the feeds, and answered with
 * 304 when the client's validators are current.
 *
 * @param array<int, string> $args Route arguments; the first is the file name.
 * @return array{title: string, intro: string, action: string, requested: string} View data
 *         for the 404 page when the file isn't published.
 */
function respond_well_known(array $args): array
{
    global $config;

    $doc = well_known_document((string) ($args[0] ?? ''), $config, ROOT_URL, following_cache_path());
    if ($doc === null) {
        return \Lamb\Response\respond_404();
    }

    // The ETag hashes the body itself: each file has a different source (a
    // post, config, the cron-refreshed copy), and the body is small.
    $etag = '"' . hash('xxh128', $doc['body']) . '"';
    $modified = max($doc['modified'], \Lamb\Config\config_modified_timestamp());
    header('Content-Type: ' . $doc['type']);
    header('Access-Control-Allow-Origin: *');
    header('Cache-Control: max-age=1800');
    header('ETag: ' . $etag);
    if ($modified > 0) {
        header('Last-Modified: ' . \Lamb\Bootstrap\http_date($modified));
    }
    if (\Lamb\Bootstrap\client_has_current_version($_SERVER, $etag, $modified)) {
        http_response_code(304);
        die();
    }
    echo $doc['body'];
    die();
}

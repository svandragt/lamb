<?php

namespace Lamb\Http;

/**
 * Retrieves the current request URI.
 *
 * This method uses the PHP superglobal variable $_SERVER to retrieve the request URI.
 * The request URI is the string representation of the current URL path.
 * The URI does not include any query parameters that may be present in the URL.
 *
 * @return string|false The current request URI as a string. If the URI is '/',
 *                     the method returns the string '/home'. If the URI cannot be determined,
 *                     the method returns false.
 */
function get_request_uri(): string|false
{
    $request_uri = strtok($_SERVER['REQUEST_URI'], '?');
    if ($request_uri === '/') {
        return '/home';
    }

    return $request_uri;
}

/**
 * Default User-Agent sent by {@see fetch} when no header overrides it.
 */
const DEFAULT_USER_AGENT = 'Lamb-Webmention';

/**
 * Build the `http` stream-context option array for {@see fetch}.
 *
 * Factored out so the option assembly can be unit-tested without opening a
 * socket. Defaults mirror the long-standing hand-rolled callers: GET method,
 * follow up to 5 redirects, ignore non-2xx so the body/status is still
 * readable, and a Lamb-Webmention User-Agent.
 *
 * Recognised `$opts` keys:
 *  - `method`          string  HTTP method (default 'GET').
 *  - `headers`         string[] Raw header lines; when none include a
 *                      `User-Agent:` the default UA is appended.
 *  - `content`         string  Request body (e.g. for POST).
 *  - `timeout`         int     Socket timeout in seconds.
 *  - `follow_location` int|null follow_location flag; pass null to omit it
 *                      (so PHP's stream default applies).
 *  - `max_redirects`   int|null redirect cap; pass null to omit it.
 *
 * @param array<string, mixed> $opts
 * @return array<string, mixed>
 */
function build_http_context_options(array $opts): array
{
    $headers = $opts['headers'] ?? [];
    $hasUserAgent = false;
    foreach ($headers as $line) {
        if (stripos((string) $line, 'user-agent:') === 0) {
            $hasUserAgent = true;
            break;
        }
    }
    if (!$hasUserAgent) {
        $headers[] = 'User-Agent: ' . DEFAULT_USER_AGENT;
    }

    $context = [
        'method' => $opts['method'] ?? 'GET',
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
    ];

    if (array_key_exists('content', $opts)) {
        $context['content'] = $opts['content'];
    }
    if (array_key_exists('timeout', $opts)) {
        $context['timeout'] = $opts['timeout'];
    }

    // follow_location / max_redirects default to the webmention behaviour, but
    // callers may pass null to omit them entirely (introspectToken's original
    // behaviour, which relied on PHP's stream defaults).
    $follow = array_key_exists('follow_location', $opts) ? $opts['follow_location'] : 1;
    if ($follow !== null) {
        $context['follow_location'] = $follow;
    }
    $maxRedirects = array_key_exists('max_redirects', $opts) ? $opts['max_redirects'] : 5;
    if ($maxRedirects !== null) {
        $context['max_redirects'] = $maxRedirects;
    }

    return $context;
}

/**
 * Extract the status code from an HTTP status line.
 *
 * Accepts status lines with or without a reason phrase ("HTTP/1.1 200 OK"
 * and "HTTP/1.1 200" are both valid — the reason phrase may be empty).
 *
 * @param string $line The raw status line.
 * @return int The status code, or 0 when none can be parsed.
 */
function parse_status_line(string $line): int
{
    return preg_match('#\s(\d{3})(?:\s|$)#', $line, $m) ? (int) $m[1] : 0;
}

/**
 * Perform a single HTTP request via the streams wrapper.
 *
 * This is the shared low-level fetch used by webmention discovery/verification
 * and Micropub token introspection. It owns stream-context construction so the
 * timeout / redirect / ignore_errors / User-Agent conventions live in one
 * place. See {@see build_http_context_options} for the recognised `$opts`.
 *
 * @param string               $url
 * @param array<string, mixed> $opts
 * @return array{status:int, headers:string[], body:string}|null
 *               Null on transport failure (file_get_contents === false).
 */
function fetch(string $url, array $opts = []): ?array
{
    $context = stream_context_create(['http' => build_http_context_options($opts)]);

    $http_response_header = [];
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        return null;
    }

    $headers = $http_response_header;

    return ['status' => parse_status_line($headers[0] ?? ''), 'headers' => $headers, 'body' => $body];
}

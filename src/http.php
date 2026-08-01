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
 * The current request's path, without its leading or trailing slashes.
 *
 * The plain "what did the visitor ask for?" reading of the request, for callers
 * that want to show it back to them (the 404 page's search suggestion). Distinct
 * from {@see get_request_uri}, which is the router's view: that one keeps the
 * leading slash and rewrites `/` to `/home`, neither of which is something to
 * put in front of a visitor.
 *
 * Returns '' when there is no usable path (the site root, or a malformed
 * REQUEST_URI), so callers can drop the UI that depends on it rather than
 * render an empty one.
 *
 * @return string The path, e.g. `tag/php` for `/tag/php?utm=1`.
 */
function requested_path(): string
{
    $path = parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);

    return is_string($path) ? trim($path, '/') : '';
}

/**
 * Splits a trailing `/page/<N>` pagination segment off a request path.
 *
 * Clean pagination URLs append `/page/N` to whatever list path they paginate
 * (`/page/2`, `/tag/foo/page/2`, `/search/foo/page/2`, …). Stripping it here,
 * before the router segments the path, lets every list responder keep routing
 * on its base path while the page number is fed into the normal `$_GET['page']`
 * pagination path. A bare `/page/N` collapses to `/home`.
 *
 * @param string $uri The request path (no query string).
 * @return array{0: string, 1: int|null} The page-stripped path and the page
 *                                        number, or the original path and null
 *                                        when no numeric page segment is present.
 */
function extract_page_segment(string $uri): array
{
    if (preg_match('#^(.*)/page/(\d+)/?$#', $uri, $matches) === 1) {
        $path = $matches[1] === '' ? '/home' : $matches[1];
        return [$path, max(1, (int)$matches[2])];
    }

    return [$uri, null];
}

/**
 * Builds the clean pagination URL for a list path at a given page.
 *
 * The inverse of extract_page_segment(): strips any existing trailing
 * `/page/N` off the path, then appends `/page/N` for page 2 onwards. Page 1
 * is the bare base path (the homepage collapses to `/`), so the first page has
 * a single canonical URL with no page segment.
 *
 * @param string $path The list path (may itself carry a `/page/N` suffix).
 * @param int    $page The target page number.
 * @return string The clean pagination URL.
 */
function page_path(string $path, int $page): string
{
    $base = rtrim((string)preg_replace('#/page/\d+/?$#', '', $path), '/');
    if ($page <= 1) {
        return $base === '' ? '/' : $base;
    }

    return $base . '/page/' . $page;
}

/**
 * Builds the request's own root URL (`scheme://host[:port]`) from the Host header.
 *
 * The Host header is client-supplied and a front-end catch-all vhost (the bundled
 * Caddy config binds `:80` with no host matcher) forwards whatever it is given, so
 * the value is validated to a strict host charset here: letters, digits, dots,
 * hyphens, a single port, and the brackets of an IPv6 literal. Anything else — a
 * quote, an angle bracket, whitespace, CR/LF — yields null so the caller can
 * refuse the request rather than reflect it into HTML or a response header.
 *
 * This is only the *request's* root URL. It says nothing about the site's real
 * identity, so it must not be used for a security decision; see
 * Lamb\Config\canonical_site_url() for the configured, trustworthy value.
 *
 * @param array<string, mixed> $server Typically $_SERVER.
 * @return string|null The request root URL, or null when the Host is missing or malformed.
 */
function request_root_url(array $server): ?string
{
    $host = $server['HTTP_HOST'] ?? '';
    if (!is_string($host) || $host === '' || strlen($host) > 253) {
        return null;
    }
    $is_ipv6_literal = preg_match('/^\[[0-9A-Fa-f:.]+](:\d{1,5})?$/', $host) === 1;
    $is_named_host = preg_match('/^[A-Za-z0-9.-]+(:\d{1,5})?$/', $host) === 1;
    if (!$is_ipv6_literal && !$is_named_host) {
        return null;
    }

    $scheme = (isset($server['HTTPS']) && $server['HTTPS'] === 'on') ? 'https' : 'http';

    return $scheme . '://' . $host;
}

/**
 * Sanitises a value bound for a `Location:` header.
 *
 * Any request-derived value interpolated into a redirect target (the request
 * URI, a search query, a fallback URL) is a header-injection surface: a CR or
 * LF would let a client smuggle extra response headers. They are stripped (along
 * with null bytes) before output. An empty result falls back to the site root so
 * callers never emit a bare `Location:`.
 *
 * This is the pure core of the redirect helpers — it takes its input as an
 * argument and returns the safe string, so it is unit-testable without the
 * surrounding `header()`/`die()` shell.
 *
 * @param string $location The proposed redirect target.
 * @return string The CR/LF-stripped target, or '/' when empty.
 */
function sanitize_location(string $location): string
{
    $location = str_replace(["\r", "\n", "\0"], '', $location);

    return $location === '' ? '/' : $location;
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
 *  - `max_bytes`       int     Cap the response body length, passed through
 *                      to {@see fetch}; not part of the stream context itself.
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
 * Whether a string is an absolute http(s) URL with a host.
 *
 * The single URL gate for everything that touches the network: webmention
 * source/target/endpoint checks, outbound link extraction, and feed-config
 * filtering all share this definition of "fetchable".
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
 * Whether an IP address is loopback, link-local, or a private/reserved range
 * (RFC 1918, RFC 4193, the IPv4 link-local block that also covers the common
 * cloud metadata address 169.254.169.254, etc.).
 *
 * @param string $ip
 * @return bool
 */
function is_private_ip(string $ip): bool
{
    return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
}

/**
 * Resolve a hostname to its IP addresses (A + AAAA records).
 *
 * Split out so {@see is_public_http_url} can be unit-tested with a fake
 * resolver instead of depending on live DNS.
 *
 * @param string $host
 * @return string[] Resolved IPs, or an empty array when resolution fails.
 */
function resolve_host_ips(string $host): array
{
    $records = @dns_get_record($host, DNS_A + DNS_AAAA);
    if (is_array($records) && $records !== []) {
        return array_values(array_filter(array_map(
            fn($record) => $record['ip'] ?? $record['ipv6'] ?? null,
            $records
        )));
    }

    // dns_get_record() can be disabled/restricted in some environments;
    // gethostbyname() is IPv4-only but covers that gap.
    $ipv4 = @gethostbyname($host);
    return $ipv4 !== $host ? [$ipv4] : [];
}

/**
 * Resolve a host to the single public IP a connection should be pinned to,
 * or false when it has no safe address.
 *
 * A host is only accepted when *every* address it resolves to is public —
 * a DNS-rebinding host that answers with a mix of public and private
 * addresses is rejected outright, since accepting it would mean the caller
 * picked one of possibly-several answers without knowing which one a second,
 * independent resolution (the TOCTOU this guards against) might return
 * instead. When accepted, the first resolved address is returned so the
 * caller can pin its connection to the exact address that was validated,
 * rather than trusting whatever a later, separate DNS lookup returns.
 *
 * @param string        $host
 * @param callable|null $resolver fn(string $host): string[] — defaults to {@see resolve_host_ips}.
 * @return string|false
 */
function resolve_validated_ip(string $host, ?callable $resolver = null): string|false
{
    if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
        return is_private_ip($host) ? false : $host;
    }

    $resolver ??= __NAMESPACE__ . '\\resolve_host_ips';
    $ips = $resolver($host);
    if ($ips === []) {
        return false;
    }

    foreach ($ips as $ip) {
        if (is_private_ip($ip)) {
            return false;
        }
    }

    return $ips[0];
}

/**
 * Whether a URL is safe to fetch: an absolute http(s) URL whose host resolves
 * only to public, routable addresses.
 *
 * This closes an SSRF hole in {@see is_valid_http_url}, which only checks
 * that a URL is well-formed http(s) — it accepts `http://127.0.0.1/`,
 * `http://169.254.169.254/`, `http://10.0.0.1/`, etc. Anywhere a URL is
 * attacker-influenced and this server will make a request to it on the
 * attacker's behalf (webmention source verification, discovered webmention
 * endpoints, feed fetches), the resolved destination — not just the URL's
 * syntax — must be checked, since it's the destination that reaches internal
 * services. Must be re-checked after every redirect hop for the same reason.
 *
 * This only answers "is it safe" — it does not pin a connection to the
 * address it checked, so a second, independent resolution (as `file_get_contents()`
 * or curl would perform) can still return a different, private address. See
 * {@see resolve_validated_ip}, which returns the address to connect to, and
 * {@see fetch_guarded}, which pins the connection to it.
 *
 * @param string        $url
 * @param callable|null $resolver fn(string $host): string[] — defaults to {@see resolve_host_ips}.
 * @return bool
 */
function is_public_http_url(string $url, ?callable $resolver = null): bool
{
    if (!is_valid_http_url($url)) {
        return false;
    }

    $host = (string) parse_url($url, PHP_URL_HOST);

    return resolve_validated_ip($host, $resolver) !== false;
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
 * POST a www-form-urlencoded body and return the response HTTP status code.
 *
 * The shared outbound notification primitive: webmention sending and WebSub
 * hub pings both POST a small form and only care about the status (WebSub
 * ignores even that — it is fire-and-forget). Built on {@see fetch};
 * follow_location/max_redirects are omitted so PHP's stream defaults apply,
 * matching the hand-rolled contexts this replaces.
 *
 * @param string               $url        The endpoint to POST to.
 * @param array<string, mixed> $fields     Form fields for the request body.
 * @param int                  $timeout    Socket timeout in seconds.
 * @param string               $user_agent User-Agent header value.
 * @return int HTTP status code, or 0 on transport failure.
 */
function post_form(string $url, array $fields, int $timeout, string $user_agent): int
{
    $result = fetch($url, [
        'method' => 'POST',
        'headers' => [
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: ' . $user_agent,
        ],
        'content' => http_build_query($fields),
        'timeout' => $timeout,
        'follow_location' => null,
        'max_redirects' => null,
    ]);

    return $result === null ? 0 : $result['status'];
}

/**
 * Perform a single HTTP request pinned to a specific IP via curl, so the
 * connection reaches the exact address {@see resolve_validated_ip} validated
 * instead of letting the transport re-resolve the host itself (the
 * DNS-rebinding TOCTOU {@see fetch_guarded} closes).
 *
 * `CURLOPT_RESOLVE` is used rather than rewriting the URL to the IP: it
 * makes curl connect to the given address for the given host:port while
 * still sending the original hostname in the `Host:` header, SNI, and
 * certificate verification — a plain URL rewrite would break all three.
 *
 * Response headers/status are collected via `CURLOPT_HEADERFUNCTION` (curl
 * does not populate `$http_response_header` the way streams do), and
 * `max_bytes` is enforced via `CURLOPT_WRITEFUNCTION` aborting the transfer
 * once the cap is exceeded, so an oversized body is never fully downloaded.
 *
 * @param string               $url
 * @param array<string, mixed> $opts Same keys as {@see fetch}.
 * @param array{host: string, ip: string} $pin
 * @return array{status:int, headers:string[], body:string}|null
 *               Null on transport failure or when the body exceeds `max_bytes`.
 */
function fetch_pinned(string $url, array $opts, array $pin): ?array
{
    $ch = curl_init();
    if ($ch === false) {
        return null;
    }

    $port = parse_url($url, PHP_URL_PORT);
    if ($port === null) {
        $port = strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https' ? 443 : 80;
    }

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

    $max_bytes = isset($opts['max_bytes']) ? max(0, (int) $opts['max_bytes']) : null;
    $body = '';
    $truncated = false;
    $responseHeaders = [];

    $options = [
        CURLOPT_URL => $url,
        CURLOPT_RESOLVE => ["{$pin['host']}:$port:{$pin['ip']}"],
        CURLOPT_CUSTOMREQUEST => $opts['method'] ?? 'GET',
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HEADERFUNCTION => function ($ch, string $line) use (&$responseHeaders): int {
            $trimmed = rtrim($line, "\r\n");
            if ($trimmed !== '') {
                $responseHeaders[] = $trimmed;
            }
            return strlen($line);
        },
        CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$body, &$truncated, $max_bytes): int {
            $body .= $chunk;
            if ($max_bytes !== null && strlen($body) > $max_bytes) {
                $truncated = true;
                return 0;
            }
            return strlen($chunk);
        },
    ];
    if (array_key_exists('timeout', $opts)) {
        $options[CURLOPT_TIMEOUT] = (int) $opts['timeout'];
    }
    if (array_key_exists('content', $opts)) {
        $options[CURLOPT_POSTFIELDS] = $opts['content'];
    }

    curl_setopt_array($ch, $options);
    $ok = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($ok === false || $truncated) {
        return null;
    }

    return ['status' => $status, 'headers' => $responseHeaders, 'body' => $body];
}

/**
 * Perform a single HTTP request via the streams wrapper.
 *
 * This is the shared low-level fetch used by webmention discovery/verification
 * and Micropub token introspection. It owns stream-context construction so the
 * timeout / redirect / ignore_errors / User-Agent conventions live in one
 * place. See {@see build_http_context_options} for the recognised `$opts`.
 *
 * Pass `pin => ['host' => string, 'ip' => string]` to route the request
 * through {@see fetch_pinned} instead — see its docblock for why. Every
 * other caller (`post_form()`, Micropub's `introspectToken`) is unaffected.
 *
 * @param string               $url
 * @param array<string, mixed> $opts
 * @return array{status:int, headers:string[], body:string}|null
 *               Null on transport failure (file_get_contents === false).
 */
function fetch(string $url, array $opts = []): ?array
{
    if (isset($opts['pin'])) {
        return fetch_pinned($url, $opts, $opts['pin']);
    }

    $context = stream_context_create(['http' => build_http_context_options($opts)]);

    $max_bytes = isset($opts['max_bytes']) ? max(0, (int) $opts['max_bytes']) : null;
    $http_response_header = [];
    // When max_bytes is set, request cap+1 so the caller can detect truncation
    // (a body that comes back > max_bytes means the source was at least one
    // byte larger than we're willing to keep). Without a cap, read to EOF.
    $body = $max_bytes === null
        ? @file_get_contents($url, false, $context)
        : @file_get_contents($url, false, $context, 0, $max_bytes + 1);
    if ($body === false) {
        return null;
    }
    if ($max_bytes !== null && strlen($body) > $max_bytes) {
        return null;
    }

    $headers = $http_response_header;

    return ['status' => parse_status_line($headers[0] ?? ''), 'headers' => $headers, 'body' => $body];
}

/**
 * Read the `Location` header from a response's raw header lines.
 *
 * @param string[] $headers
 * @return string|null
 */
function response_location(array $headers): ?string
{
    foreach ($headers as $header) {
        if (preg_match('/^location:\s*(.*)$/i', $header, $m)) {
            return trim($m[1]);
        }
    }

    return null;
}

/**
 * Resolve a possibly-relative redirect `Location` against the URL it came from.
 *
 * @param string $base
 * @param string $location
 * @return string
 */
function resolve_redirect_location(string $base, string $location): string
{
    if ($location === '' || parse_url($location, PHP_URL_SCHEME) !== null) {
        return $location;
    }

    $scheme = parse_url($base, PHP_URL_SCHEME) ?: 'https';
    $host = parse_url($base, PHP_URL_HOST) ?: '';
    $port = parse_url($base, PHP_URL_PORT);
    $authority = $scheme . '://' . $host . ($port ? ':' . $port : '');

    if (str_starts_with($location, '//')) {
        return $scheme . ':' . $location;
    }
    if (str_starts_with($location, '/')) {
        return $authority . $location;
    }

    $path = parse_url($base, PHP_URL_PATH) ?: '/';
    $dir = rtrim(substr($path, 0, strrpos($path, '/') + 1), '/');

    return $authority . $dir . '/' . $location;
}

/**
 * Fetch a URL like {@see fetch}, but only from public, non-internal addresses
 * (see {@see resolve_validated_ip}), re-resolving and pinning the connection
 * to the validated address on every redirect hop instead of trusting PHP's
 * automatic `follow_location`.
 *
 * A remote server that responds fine on the first request but redirects to
 * a loopback/private address is exactly the SSRF bypass this closes: without
 * per-hop validation, `follow_location` would transparently fetch the
 * internal address and only the (already-validated) first URL would ever be
 * checked. Used for every URL that is attacker-influenced and fetched on the
 * attacker's behalf (webmention source verification, discovered webmention
 * endpoints, feed sources).
 *
 * Resolving and then fetching are two separate operations, so a naive
 * "check the URL, then fetch the URL" would let the transport's own,
 * independent DNS lookup return a different (private) address than the one
 * just validated — a DNS-rebinding TOCTOU. Each hop resolves the host once
 * via {@see resolve_validated_ip} and passes that exact address to
 * {@see fetch} as `pin`, so the connection cannot land anywhere except the
 * address that was checked.
 *
 * @param string               $url
 * @param array<string, mixed> $opts         Same as {@see fetch}; `follow_location`/`max_redirects` are ignored.
 * @param int                  $max_redirects
 * @param callable|null        $resolver     Passed through to {@see resolve_validated_ip}.
 * @param callable|null        $fetcher      fn(string $url, array $opts): array|null — defaults to {@see fetch}. Overridable for testing.
 * @return array{status:int, headers:string[], body:string}|null Null when the URL (or any redirect hop) is unsafe or unreachable.
 */
function fetch_guarded(
    string $url,
    array $opts = [],
    int $max_redirects = 5,
    ?callable $resolver = null,
    ?callable $fetcher = null
): ?array {
    $opts['follow_location'] = 0;
    $opts['max_redirects'] = null;
    $fetcher ??= __NAMESPACE__ . '\\fetch';

    for ($hop = 0; $hop <= $max_redirects; $hop++) {
        if (!is_valid_http_url($url)) {
            return null;
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        $ip = resolve_validated_ip($host, $resolver);
        if ($ip === false) {
            return null;
        }

        $result = $fetcher($url, $opts + ['pin' => ['host' => $host, 'ip' => $ip]]);
        if ($result === null) {
            return null;
        }

        if ($result['status'] < 300 || $result['status'] >= 400) {
            return $result;
        }

        $location = response_location($result['headers']);
        if ($location === null) {
            return $result;
        }

        $url = resolve_redirect_location($url, $location);
    }

    return null;
}

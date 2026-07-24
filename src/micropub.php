<?php

namespace Lamb\Micropub;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\UploadedFileInterface;
use RedBeanPHP\OODBBean;
use RedBeanPHP\R;
use Psr\Log\AbstractLogger;
use Taproot\Micropub\MicropubAdapter;

use function Lamb\add_body_tags;
use function Lamb\get_tags;
use function Lamb\is_scheduled;
use function Lamb\notify_post_subscribers;
use function Lamb\parse_bean;
use function Lamb\permalink;
use function Lamb\remove_body_tags;
use function Lamb\strip_trailing_body_tags;
use function Lamb\Post\build_matter;
use function Lamb\Post\finalize_and_store_post;
use function Lamb\Post\parse_matter;
use function Lamb\Post\populate_bean;
use function Lamb\Post\split_frontmatter;

class LambMicropubAdapter extends MicropubAdapter
{
    /**
     * Return the source properties of a post identified by URL.
     *
     * @param string $url
     * @param list<string>|null $properties Specific properties to return; null means all.
     * @return array{type: list<string>, properties: array<string, mixed>}|false
     */
    public function sourceQueryCallback(string $url, ?array $properties = null)
    {
        $bean = $this->findPostByUrl($url);
        if ($bean === null) {
            return false;
        }

        $props = $this->beanToMf2Properties($bean);

        if ($properties !== null) {
            $props = array_intersect_key($props, array_flip($properties));
        }

        return ['type' => ['h-entry'], 'properties' => $props];
    }

    /**
     * Find a post bean by its permalink URL.
     *
     * @param string $url
     * @return OODBBean|null
     */
    private function findPostByUrl(string $url): ?OODBBean
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';

        return \Lamb\find_post_by_path($path);
    }

    /**
     * Convert a post bean to a flat MF2 properties array.
     *
     * @param OODBBean $bean
     * @return array<string, mixed>
     */
    private function beanToMf2Properties(OODBBean $bean): array
    {
        $body = $bean->body ?? '';
        [, $content] = split_frontmatter($body);
        $content = trim($content);

        // Strip trailing hashtags — categories appended by buildBody during creation.
        $content = rtrim(preg_replace('/([ \t]+#\S+)+$/', '', $content) ?? $content);

        $props = ['content' => [$content]];

        if (!empty($bean->title)) {
            $props['name'] = [$bean->title];
        }

        $tags = get_tags($body);
        if (!empty($tags)) {
            $props['category'] = $tags;
        }

        if (!empty($bean->syndicated_to)) {
            $props['syndication'] = preg_split('/\s+/', trim((string) $bean->syndicated_to));
        }

        return $props;
    }

    /**
     * Override handleRequest to allow unauthenticated ?q=config discovery queries,
     * and to accept bearer tokens sent in both the Authorization header and POST body
     * (common in real-world clients like Quill and micro.blog for backward compatibility).
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function handleRequest(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        // q=config is a discovery endpoint; return it without requiring a token.
        if (strtolower($request->getMethod()) === 'get' && ($request->getQueryParams()['q'] ?? '') === 'config') {
            $this->request = $request;
            $configResult = $this->configurationQueryCallback($request->getQueryParams());
            return new Response(200, ['content-type' => 'application/json'], json_encode($configResult) ?: '');
        }

        return parent::handleRequest($request);
    }

    /**
     * Verify the bearer token by introspecting it against the configured token endpoint.
     *
     * @param string $token
     * @return array{me: mixed, scope: list<string>}|false
     */
    public function verifyAccessTokenCallback(string $token)
    {
        global $config;
        $endpoint = $config['token_endpoint'] ?? 'https://tokens.indieauth.com/token';

        $data = $this->introspectToken($token, $endpoint);
        if ($data === null) {
            mp_log('token_verify', [
                'reason'   => 'introspection_failed',
                'endpoint' => $endpoint,
                'token'    => token_fingerprint($token),
            ]);
            return false;
        }
        if (empty($data['me'])) {
            mp_log('token_verify', [
                'reason' => 'no_me',
                'token'  => token_fingerprint($token),
            ]);
            return false;
        }

        if (rtrim($data['me'], '/') !== rtrim(ROOT_URL, '/')) {
            mp_log('token_verify', [
                'reason'   => 'me_mismatch',
                'me'       => $data['me'],
                'expected' => ROOT_URL,
                'token'    => token_fingerprint($token),
            ]);
            return false;
        }

        $scope = isset($data['scope']) ? explode(' ', $data['scope']) : [];

        mp_log('token_verify', [
            'reason' => 'ok',
            'me'     => $data['me'],
            'scope'  => $scope,
            'token'  => token_fingerprint($token),
        ]);

        return [
            'me'    => $data['me'],
            'scope' => $scope,
        ];
    }

    /**
     * Call the token endpoint to introspect a bearer token.
     * Returns the parsed JSON response or null on failure.
     *
     * @param string $token
     * @param string $endpoint
     * @return array<string, mixed>|null
     */
    protected function introspectToken(string $token, string $endpoint): ?array
    {
        $result = \Lamb\Http\fetch($endpoint, [
            'headers' => [
                'Authorization: Bearer ' . $token,
                'Accept: application/json',
            ],
            'timeout' => 5,
            // introspectToken historically did not follow redirects explicitly,
            // relying on PHP's stream defaults; preserve that by omitting them.
            'follow_location' => null,
            'max_redirects' => null,
        ]);

        if ($result === null) {
            mp_log('introspect', ['endpoint' => $endpoint, 'reason' => 'fetch_failed']);
            return null;
        }

        $statusLine = $result['headers'][0] ?? '';
        if (!str_contains($statusLine, ' 200 ')) {
            mp_log('introspect', ['endpoint' => $endpoint, 'reason' => 'non_200', 'status' => trim($statusLine)]);
            return null;
        }

        $data = json_decode($result['body'], true);
        if (!is_array($data)) {
            mp_log('introspect', ['endpoint' => $endpoint, 'reason' => 'bad_json']);
            return null;
        }

        // Log only the response's keys (e.g. me, scope, client_id) — never the values,
        // which can echo the token on some endpoints.
        mp_log('introspect', ['endpoint' => $endpoint, 'reason' => 'ok', 'keys' => array_keys($data)]);
        return $data;
    }

    /**
     * Build the 403 response for a token that lacks the scope an action requires.
     *
     * Both RFC 6750 §3.1 and the W3C Micropub error-response section map
     * `insufficient_scope` to HTTP 403 (a valid token was supplied, but it lacks
     * the privilege) — 401 is reserved for a missing or invalid token. The
     * taproot/micropub-adapter already returns 403, but without the RFC 6750 §3
     * `WWW-Authenticate: Bearer` challenge, so the callbacks return this response
     * directly to attach it. (micropub.rocks test 804 wants 401, contradicting the
     * spec — see aaronpk/micropub.rocks#101 — so we follow the spec, not the test.)
     *
     * @param string $requiredScope The scope the rejected action needs (e.g. 'create', 'update').
     * @return Response
     */
    private function insufficientScopeResponse(string $requiredScope): Response
    {
        return new Response(
            403,
            [
                'content-type'     => 'application/json',
                'www-authenticate' => bearer_challenge('insufficient_scope', $requiredScope),
            ],
            json_encode([
                'error'             => 'insufficient_scope',
                'error_description' => 'Your access token does not grant the scope required for this action.',
            ]) ?: ''
        );
    }

    /**
     * Handle a micropub create request.
     *
     * @param array<string, mixed> $data  Normalised microformats2 data.
     * @param array<string, mixed> $uploadedFiles
     * @return string|array<string, mixed>|\Psr\Http\Message\ResponseInterface
     */
    public function createCallback(array $data, array $uploadedFiles = [])
    {
        $scope = $this->user['scope'] ?? [];
        if ($this->user !== null && !in_array('create', $scope)) {
            return $this->insufficientScopeResponse('create');
        }

        $props = $data['properties'] ?? [];

        // Merge any uploaded photo files into the photo property as URLs.
        $uploadedPhotoUrls = $this->saveUploadedPhotos($uploadedFiles);
        if (!empty($uploadedPhotoUrls)) {
            $props['photo'] = array_merge($props['photo'] ?? [], $uploadedPhotoUrls);
        }

        ['content' => $content, 'is_html' => $isHtml] = $this->extractContent($props);
        if ($content === null) {
            return 'invalid_request';
        }

        $body = $this->buildBody($props, $isHtml ? strip_tags($content) : $content);

        $bean = populate_bean($body);

        if ($isHtml) {
            $bean->transformed = $this->sanitizeHtml($content);
        }

        $published = $props['published'][0] ?? null;
        if ($published) {
            $bean->created = date('Y-m-d H:i:s', strtotime($published));
        }

        $postStatus = $props['post-status'][0] ?? null;
        if ($postStatus === 'draft') {
            $bean->draft = 1;
        }
        // A "scheduled" post is a published-intent post with a future `published` date;
        // it is never a draft. Visibility is driven by the future `created` date set
        // above, so it stays hidden from public listings until that time arrives.

        // Unpublished posts 404 anonymously (#284), but clients GET the Location
        // URL we return to show the just-created post. Attach a short-lived
        // preview token so that URL works without a Lamb session (#285).
        $needs_preview = $bean->draft == 1 || is_scheduled($bean);
        \Lamb\ensure_preview_token($bean);

        // Stores and pins the final slug, which must be settled before the
        // Location permalink is computed below.
        finalize_and_store_post($bean);

        notify_post_subscribers($bean);

        $location = permalink($bean);
        if ($needs_preview) {
            $location .= '?preview=' . $bean->preview_token;
        }

        return $location;
    }

    /**
     * Return the configuration query response including configured syndicate-to targets.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function configurationQueryCallback(array $params): array
    {
        global $config;
        $targets = [];
        foreach ($config['syndicate_to'] ?? [] as $uid => $name) {
            $targets[] = ['uid' => (string) $uid, 'name' => (string) $name];
        }
        return [
            'q'              => ['config', 'source', 'syndicate-to'],
            'media-endpoint' => ROOT_URL . '/micropub-media',
            'syndicate-to'   => $targets,
        ];
    }

    /**
     * Handle a micropub delete request.
     *
     * @param string $url
     * @return true|string|\Psr\Http\Message\ResponseInterface
     */
    public function deleteCallback(string $url)
    {
        $bean = $this->findPostByUrl($url);
        if ($bean === null) {
            return 'invalid_request';
        }

        // Share the web delete path so a Micropub delete also stamps deleted_at
        // (for purging) and re-sends webmentions for the now-gone post (#331).
        \Lamb\Response\soft_delete_post($bean);

        return true;
    }

    /**
     * Handle a micropub undelete request.
     *
     * @param string $url
     * @return true|string|\Psr\Http\Message\ResponseInterface
     */
    public function undeleteCallback(string $url)
    {
        $bean = $this->findPostByUrl($url);
        if ($bean === null) {
            return 'invalid_request';
        }

        // Share the web restore path so a Micropub undelete also reconciles any
        // deletion webmention re-sends (#331).
        \Lamb\Response\restore_post($bean);

        return true;
    }

    /**
     * Handle a micropub update request (replace/add/delete operations).
     *
     * @param string $url
     * @param array<string, mixed>  $actions
     * @return true|string|array<string, mixed>|\Psr\Http\Message\ResponseInterface
     */
    public function updateCallback(string $url, array $actions)
    {
        $bean = $this->findPostByUrl($url);
        if ($bean === null) {
            return 'invalid_request';
        }

        $scope = $this->user['scope'] ?? [];
        if ($this->user !== null && !in_array('update', $scope)) {
            return $this->insufficientScopeResponse('update');
        }

        foreach ($actions['replace'] ?? [] as $property => $values) {
            $this->applyReplace($bean, $property, $values);
        }

        foreach ($actions['add'] ?? [] as $property => $values) {
            $this->applyAdd($bean, $property, $values);
        }

        $delete = $actions['delete'] ?? [];
        if (array_is_list($delete)) {
            // Indexed array: delete entire properties.
            foreach ($delete as $property) {
                $this->applyDeleteProperty($bean, $property);
            }
        } else {
            // Associative array: delete specific values from each property.
            foreach ($delete as $property => $values) {
                $this->applyDeleteValues($bean, $property, $values);
            }
        }

        parse_bean($bean);
        $bean->updated = \Lamb\now();
        R::store($bean);

        notify_post_subscribers($bean);

        return true;
    }

    /**
     * Apply an add operation for a single property to a post bean.
     *
     * @param OODBBean     $bean
     * @param string       $property
     * @param list<string> $values
     * @return void
     */
    private function applyAdd(OODBBean $bean, string $property, array $values): void
    {
        if ($property === 'category') {
            $bean->body = add_body_tags($bean->body ?? '', $values);
        }
    }

    /**
     * Apply a delete-property operation (remove all values) to a post bean.
     *
     * @param OODBBean $bean
     * @param string   $property
     * @return void
     */
    private function applyDeleteProperty(OODBBean $bean, string $property): void
    {
        if ($property === 'category') {
            $bean->body = strip_trailing_body_tags($bean->body ?? '');
        }
    }

    /**
     * Apply a delete-values operation for a single property to a post bean.
     *
     * @param OODBBean     $bean
     * @param string       $property
     * @param list<string> $values
     * @return void
     */
    private function applyDeleteValues(OODBBean $bean, string $property, array $values): void
    {
        if ($property === 'category') {
            $bean->body = remove_body_tags($bean->body ?? '', $values);
        }
    }

    /**
     * Apply a replace operation for a single property to a post bean.
     *
     * @param OODBBean    $bean
     * @param string      $property
     * @param list<mixed> $values
     * @return void
     */
    private function applyReplace(OODBBean $bean, string $property, array $values): void
    {
        if ($property === 'content') {
            $newContent = (string) ($values[0] ?? '');
            $bean->body = $this->rebuildBody($bean, $newContent);
        }
    }

    /**
     * Rebuild the post body with new content, preserving existing front matter and hashtags.
     *
     * @param OODBBean $bean
     * @param string   $newContent
     * @return string
     */
    private function rebuildBody(OODBBean $bean, string $newContent): string
    {
        $currentBody  = $bean->body ?? '';
        $matter       = parse_matter($currentBody);
        $title        = $matter['title'] ?? null;
        $replyTo      = $matter['in-reply-to'] ?? null;
        $syndicatedTo = $matter['syndicated-to'] ?? null;

        $tags       = get_tags($currentBody);
        $hashtagStr = empty($tags) ? '' : ' ' . implode(' ', array_map(fn($t) => '#' . $t, $tags));

        $content = $newContent . $hashtagStr;

        return build_matter(
            $this->assembleFrontMatter($title, $replyTo, $syndicatedTo),
            $content
        );
    }

    /**
     * Assemble the post front-matter array from individual fields.
     *
     * Central point for front-matter assembly so buildBody() and rebuildBody()
     * stay in sync when new fields are added.
     *
     * @param string|null $title
     * @param string|null $replyTo
     * @param string|null $syndicatedTo
     * @return array<string, string>
     */
    private function assembleFrontMatter(?string $title, ?string $replyTo, ?string $syndicatedTo): array
    {
        $matter = [];
        if ($title !== null) {
            $matter['title'] = $title;
        }
        if ($replyTo !== null && $replyTo !== '') {
            $matter['in-reply-to'] = $replyTo;
        }
        if ($syndicatedTo !== null && $syndicatedTo !== '') {
            $matter['syndicated-to'] = $syndicatedTo;
        }
        return $matter;
    }

    /**
     * Save uploaded photo files to the assets directory and return their public URLs.
     *
     * @param array<string, mixed> $uploadedFiles Associative array of field name → UploadedFileInterface (or array thereof).
     * @return list<string>
     */
    private function saveUploadedPhotos(array $uploadedFiles): array
    {
        $files = $uploadedFiles['photo'] ?? [];
        if ($files instanceof UploadedFileInterface) {
            $files = [$files];
        }

        $urls = [];
        foreach ($files as $file) {
            if (!($file instanceof UploadedFileInterface) || $file->getError() !== UPLOAD_ERR_OK) {
                continue;
            }

            $ext = \Lamb\Response\safe_upload_extension($file->getClientFilename() ?? '');
            if ($ext === null) {
                continue;
            }
            $sub_path  = \Lamb\Response\upload_subpath();
            $uploadDir = \Lamb\Response\get_upload_dir($sub_path);
            $seed      = sha1($file->getClientFilename() ?? uniqid('', true));

            $filename = \Lamb\Response\persist_image_bytes(
                (string) $file->getStream(),
                $ext,
                $uploadDir,
                $seed
            );
            if ($filename === null) {
                continue;
            }

            $urls[] = \Lamb\Response\asset_url($sub_path, $filename);
        }

        return $urls;
    }

    /**
     * Extract content from micropub properties.
     * Returns ['content' => string|null, 'is_html' => bool].
     *
     * @param array<string, mixed> $props
     * @return array{content: string|null, is_html: bool}
     */
    private function extractContent(array $props): array
    {
        if (empty($props['content'])) {
            return ['content' => null, 'is_html' => false];
        }

        $raw = $props['content'][0];

        if (is_array($raw)) {
            if (isset($raw['html'])) {
                return ['content' => $raw['html'], 'is_html' => true];
            }
            return ['content' => $raw['value'] ?? null, 'is_html' => false];
        }

        return ['content' => (string) $raw, 'is_html' => false];
    }

    /**
     * Build a Lamb post body (YAML front matter + markdown content).
     *
     * @param array<string, mixed> $props   Micropub properties.
     * @param string $content Plain-text body content.
     * @return string
     */
    private function buildBody(array $props, string $content): string
    {
        $title = $props['name'][0] ?? null;
        $replyTo = $props['in-reply-to'][0] ?? null;

        $photos = $this->buildPhotos($props['photo'] ?? []);
        if ($photos !== '') {
            $content = $content . "\n\n" . $photos;
        }

        $tags = $this->buildTags($props['category'] ?? []);
        if ($tags !== '') {
            $content = $content . ' ' . $tags;
        }

        $extra = $this->buildExtraProperties($props);
        if ($extra !== '') {
            $content = $content . "\n\n" . $extra;
        }

        $syndicateTo  = array_filter(array_values((array) ($props['mp-syndicate-to'] ?? [])));
        $syndicatedTo = !empty($syndicateTo) ? implode(' ', $syndicateTo) : null;

        return build_matter(
            $this->assembleFrontMatter($title, $replyTo, $syndicatedTo),
            $content
        );
    }

    /**
     * Serialize any extra nested MF2 properties (not content/name/category/photo/published)
     * as a JSON code block so they are preserved in storage.
     *
     * @param array<string, mixed> $props
     * @return string
     */
    private function buildExtraProperties(array $props): string
    {
        $known = ['content', 'name', 'category', 'photo', 'published', 'post-status', 'mp-syndicate-to'];
        $extra = array_diff_key($props, array_flip($known));

        if (empty($extra)) {
            return '';
        }

        return "```json\n" . json_encode($extra, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n```";
    }

    /**
     * Strip disallowed tags from HTML content, keeping safe formatting elements.
     *
     * @param string $html
     * @return string
     */
    private function sanitizeHtml(string $html): string
    {
        return strip_tags($html, [
            'a', 'abbr', 'b', 'blockquote', 'br', 'caption',
            'code', 'del', 'em', 'figcaption', 'figure', 'h1', 'h2', 'h3',
            'h4', 'h5', 'h6', 'hr', 'i', 'img', 'ins', 'li', 'ol', 'p',
            'pre', 'q', 's', 'small', 'strong', 'sub', 'sup',
            'table', 'tbody', 'td', 'th', 'thead', 'tr', 'u', 'ul',
        ]);
    }

    /**
     * Convert an array of photo URLs to newline-separated Markdown images.
     *
     * @param array<int, mixed> $photos
     * @return string
     */
    private function buildPhotos(array $photos): string
    {
        if (empty($photos)) {
            return '';
        }

        return implode("\n", array_map(function ($photo) {
            if (is_array($photo)) {
                $url = $photo['value'] ?? '';
                $alt = $photo['alt'] ?? '';
            } else {
                $url = $photo;
                $alt = '';
            }
            return '![' . $alt . '](' . $url . ')';
        }, $photos));
    }

    /**
     * Convert an array of category strings to space-separated hashtags.
     *
     * @param array<int, mixed> $categories
     * @return string
     */
    private function buildTags(array $categories): string
    {
        if (empty($categories)) {
            return '';
        }

        return implode(' ', array_map(fn($c) => '#' . $c, $categories));
    }
}

/**
 * A minimal PSR-3 logger that funnels the taproot adapter's own trace into mp_log().
 *
 * Wired into the adapter's $logger (only when micropub_debug is on), so its
 * info/warning/error messages — token verification, query handling, error
 * responses — land in the same file as Lamb's own diagnostic log points. An
 * anonymous class keeps micropub.php to a single named class (PSR1).
 */
function mp_adapter_logger(): \Psr\Log\LoggerInterface
{
    return new class extends AbstractLogger {
        /**
         * @param mixed              $level
         * @param string|\Stringable $message
         * @param array<mixed>       $context
         */
        public function log($level, string|\Stringable $message, array $context = []): void
        {
            mp_log('adapter', ['level' => (string) $level, 'message' => (string) $message] + $context);
        }
    };
}

/**
 * Whether opt-in Micropub diagnostic logging is enabled (config key `micropub_debug`).
 * Off by default and for any non-truthy value, so no token/PII is ever written unless
 * the operator explicitly turns it on at /settings to debug a client.
 */
function mp_debug_enabled(): bool
{
    global $config;
    $value = $config['micropub_debug'] ?? false;
    if (is_bool($value)) {
        return $value;
    }
    return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
}

/**
 * Path of the Micropub diagnostic log. Lives next to the SQLite DB in data/.
 * Overridable via $GLOBALS['lamb_mp_log_path'] for tests.
 */
function mp_log_path(): string
{
    if (!empty($GLOBALS['lamb_mp_log_path'])) {
        return (string) $GLOBALS['lamb_mp_log_path'];
    }
    return ROOT_DIR . '/../data/micropub.log';
}

/**
 * Append one diagnostic event to the Micropub log. No-op unless micropub_debug is on.
 *
 * The token itself is never passed in here — callers log token_fingerprint() instead.
 *
 * @param string       $event   Short event name (e.g. 'request', 'token_verify', 'response').
 * @param array<mixed> $context Structured fields to record alongside the event.
 */
function mp_log(string $event, array $context = []): void
{
    if (!mp_debug_enabled()) {
        return;
    }

    $line = json_encode(
        ['ts' => \Lamb\now(), 'event' => $event] + $context,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    ) ?: '';
    @file_put_contents(mp_log_path(), $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

/**
 * Build a `WWW-Authenticate: Bearer` challenge value per OAuth bearer-token RFC 6750 §3.
 *
 * With no error this returns a bare `Bearer` — RFC 6750 §3.1 says a request that
 * supplied no credentials at all SHOULD NOT carry an error code. When an error is
 * given, the optional `error_description` and `scope` attributes are appended in the
 * spec's listed order (error, error_description, scope).
 *
 * @param string|null $error       Error code (e.g. 'invalid_token', 'insufficient_scope').
 * @param string|null $scope       Scope the action requires (insufficient_scope only).
 * @param string|null $description Human-readable error description.
 */
/**
 * Whether a verified access-token user carries a given Micropub scope.
 *
 * Shared by every scope-gated Micropub action (post creation/update, and the
 * media endpoint) so a token issued without the scope an action needs is
 * rejected consistently.
 *
 * @param array{me?: mixed, scope?: list<string>}|false $user The result of verifyAccessTokenCallback().
 * @param string                                         $scope Required scope (e.g. 'create').
 * @return bool
 */
function has_micropub_scope(array|false $user, string $scope): bool
{
    return is_array($user) && in_array($scope, $user['scope'] ?? [], true);
}

function bearer_challenge(?string $error = null, ?string $scope = null, ?string $description = null): string
{
    $params = [];
    if ($error !== null) {
        $params[] = 'error="' . $error . '"';
    }
    if ($description !== null) {
        $params[] = 'error_description="' . $description . '"';
    }
    if ($scope !== null) {
        $params[] = 'scope="' . $scope . '"';
    }

    return $params === [] ? 'Bearer' : 'Bearer ' . implode(', ', $params);
}

/**
 * Non-reversible fingerprint of a bearer token: enough to correlate the same token
 * across log lines without ever writing the secret itself.
 */
function token_fingerprint(string $token): string
{
    if ($token === '') {
        return 'empty';
    }
    return 'len=' . strlen($token) . ' sha256=' . substr(hash('sha256', $token), 0, 12);
}

/**
 * Route handler for GET/POST /micropub.
 * Builds a PSR-7 request from globals, delegates to LambMicropubAdapter,
 * emits the response and exits — same pattern as respond_feed.
 */
function respond_micropub(): void
{
    $headers = getallheaders() ?: [];
    $rawBody = file_get_contents('php://input') ?: null;

    $request = new ServerRequest(
        $_SERVER['REQUEST_METHOD'] ?? 'GET',
        ROOT_URL . ($_SERVER['REQUEST_URI'] ?? '/micropub'),
        $headers,
        $rawBody,
        '1.1',
        $_SERVER
    );

    if (!empty($_POST)) {
        $request = $request->withParsedBody($_POST);
    }

    if (!empty($_FILES)) {
        $psr7Files = [];
        foreach ($_FILES as $field => $info) {
            if (is_array($info['tmp_name'])) {
                $fieldFiles = [];
                foreach ($info['tmp_name'] as $i => $tmpName) {
                    $fieldFiles[] = new UploadedFile(
                        $tmpName,
                        (int) $info['size'][$i],
                        (int) $info['error'][$i],
                        $info['name'][$i],
                        $info['type'][$i]
                    );
                }
                $psr7Files[$field] = $fieldFiles;
            } else {
                $psr7Files[$field] = new UploadedFile(
                    $info['tmp_name'],
                    (int) $info['size'],
                    (int) $info['error'],
                    $info['name'],
                    $info['type']
                );
            }
        }
        $request = $request->withUploadedFiles($psr7Files);
    }

    $lcHeaders = array_change_key_case($headers, CASE_LOWER);
    mp_log('request', [
        'method'          => $request->getMethod(),
        'uri'             => (string) $request->getUri(),
        'q'               => $request->getQueryParams()['q'] ?? null,
        'content_type'    => $lcHeaders['content-type'] ?? null,
        'user_agent'      => $lcHeaders['user-agent'] ?? null,
        'has_auth_header' => isset($lcHeaders['authorization']),
        'has_body_token'  => isset($_POST['access_token']),
        'body_len'        => $rawBody !== null ? strlen($rawBody) : 0,
    ]);

    $adapter = new LambMicropubAdapter();
    if (mp_debug_enabled()) {
        $adapter->logger = mp_adapter_logger();
    }
    $response = $adapter->handleRequest($request);

    // The adapter returns a bare 401 when no access token is supplied; RFC 6750 §3
    // requires a WWW-Authenticate: Bearer challenge on such responses. (Our own
    // insufficient_scope path is a 403 that already sets the header, so it is untouched.)
    $status = $response->getStatusCode();
    if ($status === 401 && !$response->hasHeader('WWW-Authenticate')) {
        $response = $response->withHeader('WWW-Authenticate', bearer_challenge());
    }

    mp_log('response', [
        'status' => $status,
        // Only echo the body into the log on failures — it carries the error reason.
        'body'   => $status >= 400 ? substr((string) $response->getBody(), 0, 300) : null,
    ]);

    http_response_code($status);
    foreach ($response->getHeaders() as $name => $values) {
        foreach ($values as $value) {
            header("$name: $value", false);
        }
    }
    echo $response->getBody();
    exit;
}

/**
 * Emit a JSON Micropub error response and terminate the request.
 *
 * Centralises the status code + Content-Type header + {error, error_description}
 * body that every guard in the media endpoint would otherwise repeat.
 *
 * @param int         $status          HTTP status code.
 * @param string      $error           Micropub error code (e.g. 'unauthorized', 'invalid_request').
 * @param string      $description     Human-readable error description.
 * @param string|null $wwwAuthenticate Optional RFC 6750 `WWW-Authenticate` challenge (see bearer_challenge()).
 * @return never
 */
function micropub_error(int $status, string $error, string $description, ?string $wwwAuthenticate = null): never
{
    mp_log('response', ['status' => $status, 'error' => $error, 'error_description' => $description]);
    http_response_code($status);
    header('Content-Type: application/json');
    if ($wwwAuthenticate !== null) {
        header('WWW-Authenticate: ' . $wwwAuthenticate);
    }
    echo json_encode(['error' => $error, 'error_description' => $description]);
    exit;
}

/**
 * Handles Micropub media endpoint requests (POST multipart/form-data with a 'file' field).
 * Validates the bearer token, saves the uploaded file, and returns HTTP 201 + Location.
 *
 * @return void
 */
function respond_micropub_media(): void
{
    $headers = getallheaders() ?: [];
    $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    $lcHeaders = array_change_key_case($headers, CASE_LOWER);

    mp_log('media_request', [
        'method'          => $_SERVER['REQUEST_METHOD'] ?? null,
        'content_type'    => $lcHeaders['content-type'] ?? null,
        'user_agent'      => $lcHeaders['user-agent'] ?? null,
        'has_auth_header' => $authHeader !== '',
        'has_file'        => !empty($_FILES['file']),
    ]);

    $token = null;
    if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
        $token = trim($m[1]);
    }

    if (!$token) {
        // No credentials supplied: bare Bearer challenge, no error code (RFC 6750 §3.1).
        micropub_error(401, 'unauthorized', 'Missing bearer token.', bearer_challenge());
    }

    $adapter = new LambMicropubAdapter();
    if (mp_debug_enabled()) {
        $adapter->logger = mp_adapter_logger();
    }
    $user = $adapter->verifyAccessTokenCallback($token);
    if (!$user) {
        micropub_error(
            401,
            'unauthorized',
            'Invalid or expired token.',
            bearer_challenge('invalid_token', null, 'The access token is invalid or expired.')
        );
    }

    // The base MicropubAdapter never enforces scope itself — it's left to the
    // implementing callbacks, and createCallback()/updateCallback() both do
    // (requiring 'create'/'update'). This endpoint is reached independently
    // of those callbacks, so without its own check here a token issued with
    // any scope at all (e.g. 'update'-only) could still upload files.
    if (!has_micropub_scope($user, 'create')) {
        micropub_error(
            403,
            'insufficient_scope',
            'Your access token does not grant the scope required for this action.',
            bearer_challenge('insufficient_scope', 'create')
        );
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || empty($_FILES['file'])) {
        micropub_error(400, 'invalid_request', 'Expected a multipart/form-data POST with a file field.');
    }

    $file = $_FILES['file'];
    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        micropub_error(400, 'invalid_request', 'File upload failed.');
    }

    $ext = \Lamb\Response\safe_upload_extension($file['name'] ?? '');
    if ($ext === null) {
        micropub_error(400, 'invalid_request', 'Unsupported file type.');
    }

    $sub_path  = \Lamb\Response\upload_subpath();
    $uploadDir = \Lamb\Response\get_upload_dir($sub_path);
    $seed      = sha1(($file['name'] ?? '') . uniqid('', true));

    // Re-encode JPEG/PNG to WebP, falling back to the original bytes on failure.
    $filename = \Lamb\Response\store_webp_copy($file['tmp_name'], $ext, $uploadDir, $seed);
    if ($filename === null) {
        $filename = $seed . ".$ext";
        move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $filename);
    }

    // The media endpoint hands this URL back to an external Micropub client, so
    // it must be absolute (resolvable off-site); content URLs stay root-relative.
    $url = ROOT_URL . \Lamb\Response\asset_url($sub_path, $filename);

    http_response_code(201);
    header('Location: ' . $url);
    exit;
}

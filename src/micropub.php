<?php

namespace Lamb\Micropub;

use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Nyholm\Psr7\UploadedFile;
use Psr\Http\Message\UploadedFileInterface;
use RedBeanPHP\OODBBean;
use RedBeanPHP\R;
use Taproot\Micropub\MicropubAdapter;

use function Lamb\get_tags;
use function Lamb\parse_bean;
use function Lamb\permalink;
use function Lamb\Post\parse_matter;
use function Lamb\Post\populate_bean;

class LambMicropubAdapter extends MicropubAdapter
{
    /**
     * Return the source properties of a post identified by URL.
     *
     * @param string $url
     * @param array|null $properties Specific properties to return; null means all.
     * @return array|false
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
        $path = parse_url($url, PHP_URL_PATH) ?? '';

        if (preg_match('#^/status/(\d+)$#', $path, $matches)) {
            $bean = R::load('post', (int) $matches[1]);
            return $bean->id ? $bean : null;
        }

        $slug = trim($path, '/');
        if ($slug !== '') {
            $bean = R::findOne('post', ' slug = ? ', [$slug]);
            return $bean ?: null;
        }

        return null;
    }

    /**
     * Convert a post bean to a flat MF2 properties array.
     *
     * @param OODBBean $bean
     * @return array
     */
    private function beanToMf2Properties(OODBBean $bean): array
    {
        $body = $bean->body ?? '';
        $parts = explode('---', $body, 3);
        $content = trim(count($parts) === 3 ? $parts[2] : $body);

        // Strip trailing hashtags — categories appended by buildBody during creation.
        $content = rtrim(preg_replace('/([ \t]+#\S+)+$/', '', $content));

        $props = ['content' => [$content]];

        if (!empty($bean->title)) {
            $props['name'] = [$bean->title];
        }

        $tags = get_tags($body);
        if (!empty($tags)) {
            $props['category'] = $tags;
        }

        return $props;
    }

    /**
     * Reject requests that present the bearer token via both the Authorization header
     * and the POST body, which is forbidden by RFC 6750 §2.
     *
     * taproot/micropub-adapter's getAccessToken() silently prefers the header when both
     * are present instead of rejecting the request. We override handleRequest() here to
     * enforce the spec before delegating to the parent.
     * TODO: report upstream to taproot/micropub-adapter that dual-method token requests
     *       should be rejected with HTTP 400 per RFC 6750 §2.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request
     * @return \Psr\Http\Message\ResponseInterface
     */
    public function handleRequest(\Psr\Http\Message\ServerRequestInterface $request): \Psr\Http\Message\ResponseInterface
    {
        $hasAuthHeader = $request->hasHeader('authorization') &&
            stripos($request->getHeaderLine('authorization'), 'bearer') === 0;

        $parsedBody = $request->getParsedBody();
        $hasBodyToken = is_array($parsedBody) && isset($parsedBody['access_token']);

        if ($hasAuthHeader && $hasBodyToken) {
            return new Response(400, ['content-type' => 'application/json'], json_encode([
                'error'             => 'invalid_request',
                'error_description' => 'The request must not contain more than one method of sending the bearer token (RFC 6750 §2).',
            ]));
        }

        // q=config is a discovery endpoint; return it without requiring a token.
        if (strtolower($request->getMethod()) === 'get' && ($request->getQueryParams()['q'] ?? '') === 'config') {
            $this->request = $request;
            $configResult = $this->configurationQueryCallback($request->getQueryParams());
            if ($configResult instanceof \Psr\Http\Message\ResponseInterface) {
                return $configResult;
            }
            return new Response(200, ['content-type' => 'application/json'], json_encode($configResult));
        }

        return parent::handleRequest($request);
    }

    /**
     * Verify the bearer token by introspecting it against the configured token endpoint.
     *
     * @param string $token
     * @return array|false
     */
    public function verifyAccessTokenCallback(string $token)
    {
        global $config;
        $endpoint = $config['token_endpoint'] ?? 'https://tokens.indieauth.com/token';

        $data = $this->introspectToken($token, $endpoint);
        if ($data === null || empty($data['me'])) {
            return false;
        }

        if (rtrim($data['me'], '/') !== rtrim(ROOT_URL, '/')) {
            return false;
        }

        $scope = isset($data['scope']) ? explode(' ', $data['scope']) : [];

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
     * @return array|null
     */
    protected function introspectToken(string $token, string $endpoint): ?array
    {
        $context = stream_context_create([
            'http' => [
                'method'  => 'GET',
                'header'  => implode("\r\n", [
                    'Authorization: Bearer ' . $token,
                    'Accept: application/json',
                ]),
                'timeout' => 5,
                'ignore_errors' => true,
            ],
        ]);

        $response = @file_get_contents($endpoint, false, $context);
        if ($response === false) {
            return null;
        }

        $statusLine = $http_response_header[0] ?? '';
        if (!str_contains($statusLine, ' 200 ')) {
            return null;
        }

        $data = json_decode($response, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Handle a micropub create request.
     *
     * @param array $data  Normalised microformats2 data.
     * @param array $uploadedFiles
     * @return string|array|\Psr\Http\Message\ResponseInterface
     */
    public function createCallback(array $data, array $uploadedFiles = [])
    {
        // W3C Micropub spec §error-response requires HTTP 401 for insufficient_scope.
        // taproot/micropub-adapter maps it to 403 instead, so we bypass toResponse() by
        // returning a ResponseInterface directly from this callback.
        // TODO: report upstream to taproot/micropub-adapter that insufficient_scope should
        //       map to HTTP 401 per the W3C Micropub spec.
        $scope = $this->user['scope'] ?? [];
        if ($this->user !== null && !in_array('create', $scope)) {
            return new Response(401, ['content-type' => 'application/json'], json_encode([
                'error' => 'insufficient_scope',
                'error_description' => 'Your access token does not grant the scope required for this action.',
            ]));
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

        if (($props['post-status'][0] ?? null) === 'draft') {
            $bean->draft = 1;
        }

        R::store($bean);

        return permalink($bean);
    }

    /**
     * Return the configuration query response including an empty syndicate-to list.
     *
     * @param array $params
     * @return array
     */
    public function configurationQueryCallback(array $params): array
    {
        return [
            'q'              => ['config', 'source', 'syndicate-to'],
            'media-endpoint' => ROOT_URL . '/micropub-media',
            'syndicate-to'   => [],
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

        $bean->deleted = 1;
        R::store($bean);

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

        $bean->deleted = null;
        R::store($bean);

        return true;
    }

    /**
     * Handle a micropub update request (replace/add/delete operations).
     *
     * @param string $url
     * @param array  $actions
     * @return true|string|array|\Psr\Http\Message\ResponseInterface
     */
    public function updateCallback(string $url, array $actions)
    {
        $bean = $this->findPostByUrl($url);
        if ($bean === null) {
            return 'invalid_request';
        }

        $scope = $this->user['scope'] ?? [];
        if ($this->user !== null && !in_array('update', $scope)) {
            return new Response(401, ['content-type' => 'application/json'], json_encode([
                'error'             => 'insufficient_scope',
                'error_description' => 'Your access token does not grant the scope required for this action.',
            ]));
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
        $bean->updated = date('Y-m-d H:i:s');
        R::store($bean);

        return true;
    }

    /**
     * Apply an add operation for a single property to a post bean.
     *
     * @param OODBBean $bean
     * @param string   $property
     * @param array    $values
     * @return void
     */
    private function applyAdd(OODBBean $bean, string $property, array $values): void
    {
        if ($property === 'category') {
            $existing = get_tags($bean->body ?? '');
            $toAdd    = array_diff($values, $existing);
            if (!empty($toAdd)) {
                $newTags = implode(' ', array_map(fn($t) => '#' . $t, $toAdd));
                $bean->body = rtrim($bean->body ?? '') . ' ' . $newTags;
            }
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
            $bean->body = rtrim(preg_replace('/(\s+#[^\s#.,!?;:()\[\]{}<]+)+$/u', '', $bean->body ?? ''));
        }
    }

    /**
     * Apply a delete-values operation for a single property to a post bean.
     *
     * @param OODBBean $bean
     * @param string   $property
     * @param array    $values
     * @return void
     */
    private function applyDeleteValues(OODBBean $bean, string $property, array $values): void
    {
        if ($property === 'category') {
            foreach ($values as $tag) {
                $bean->body = preg_replace('/(\s+)#' . preg_quote($tag, '/') . '(?=\s|$)/u', '', $bean->body ?? '');
            }
        }
    }

    /**
     * Apply a replace operation for a single property to a post bean.
     *
     * @param OODBBean $bean
     * @param string   $property
     * @param array    $values
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
        $currentBody = $bean->body ?? '';
        $matter      = parse_matter($currentBody);
        $title       = $matter['title'] ?? null;

        $tags      = get_tags($currentBody);
        $hashtagStr = empty($tags) ? '' : ' ' . implode(' ', array_map(fn($t) => '#' . $t, $tags));

        $content = $newContent . $hashtagStr;

        if ($title === null) {
            return $content;
        }

        return "---\ntitle: $title\n---\n$content";
    }

    /**
     * Save uploaded photo files to the assets directory and return their public URLs.
     *
     * @param array $uploadedFiles Associative array of field name → UploadedFileInterface (or array thereof).
     * @return string[]
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

            $uploadDir = \Lamb\Response\get_upload_dir();
            $ext       = pathinfo($file->getClientFilename() ?? 'upload', PATHINFO_EXTENSION);
            $filename  = sha1($file->getClientFilename() ?? uniqid('', true)) . ($ext ? ".$ext" : '');
            $file->moveTo($uploadDir . '/' . $filename);

            $urls[] = str_replace(ROOT_DIR, ROOT_URL, $uploadDir) . '/' . $filename;
        }

        return $urls;
    }

    /**
     * Extract content from micropub properties.
     * Returns ['content' => string|null, 'is_html' => bool].
     *
     * @param array $props
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
     * @param array  $props   Micropub properties.
     * @param string $content Plain-text body content.
     * @return string
     */
    private function buildBody(array $props, string $content): string
    {
        $title = $props['name'][0] ?? null;

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

        if ($title === null) {
            return $content;
        }

        return "---\ntitle: $title\n---\n$content";
    }

    /**
     * Serialize any extra nested MF2 properties (not content/name/category/photo/published)
     * as a JSON code block so they are preserved in storage.
     *
     * @param array $props
     * @return string
     */
    private function buildExtraProperties(array $props): string
    {
        $known = ['content', 'name', 'category', 'photo', 'published', 'post-status'];
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
     * @param array $photos
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
     * @param array $categories
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
                foreach ($info['tmp_name'] as $i => $tmpName) {
                    $psr7Files[$field][] = new UploadedFile(
                        $tmpName,
                        (int) $info['size'][$i],
                        (int) $info['error'][$i],
                        $info['name'][$i],
                        $info['type'][$i]
                    );
                }
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

    $adapter  = new LambMicropubAdapter();
    $response = $adapter->handleRequest($request);

    http_response_code($response->getStatusCode());
    foreach ($response->getHeaders() as $name => $values) {
        foreach ($values as $value) {
            header("$name: $value", false);
        }
    }
    echo $response->getBody();
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

    $token = null;
    if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) {
        $token = trim($m[1]);
    }

    if (!$token) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'unauthorized', 'error_description' => 'Missing bearer token.']);
        exit;
    }

    $adapter = new LambMicropubAdapter();
    $user = $adapter->verifyAccessTokenCallback($token);
    if (!$user) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'unauthorized', 'error_description' => 'Invalid or expired token.']);
        exit;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || empty($_FILES['file'])) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'invalid_request', 'error_description' => 'Expected a multipart/form-data POST with a file field.']);
        exit;
    }

    $file = $_FILES['file'];
    if ((int) $file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'invalid_request', 'error_description' => 'File upload failed.']);
        exit;
    }

    $uploadDir = \Lamb\Response\get_upload_dir();
    $ext       = pathinfo($file['name'] ?? 'upload', PATHINFO_EXTENSION);
    $filename  = sha1(($file['name'] ?? '') . uniqid('', true)) . ($ext ? ".$ext" : '');
    move_uploaded_file($file['tmp_name'], $uploadDir . '/' . $filename);

    $url = str_replace(ROOT_DIR, ROOT_URL . '/', $uploadDir) . '/' . $filename;

    http_response_code(201);
    header('Location: ' . $url);
    exit;
}

<?php

namespace Lamb\Micropub;

use Nyholm\Psr7\ServerRequest;
use RedBeanPHP\OODBBean;
use RedBeanPHP\R;
use Taproot\Micropub\MicropubAdapter;

use function Lamb\get_tags;
use function Lamb\permalink;
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
    public function sourceQueryCallback(string $url, array $properties = null)
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
     * @return string|'invalid_request'
     */
    public function createCallback(array $data, array $uploadedFiles = [])
    {
        $props = $data['properties'] ?? [];

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

        R::store($bean);

        return permalink($bean);
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
        $known = ['content', 'name', 'category', 'photo', 'published'];
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

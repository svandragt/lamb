<?php

namespace Lamb\Micropub;

use Nyholm\Psr7\ServerRequest;
use RedBeanPHP\R;
use Taproot\Micropub\MicropubAdapter;

use function Lamb\permalink;
use function Lamb\Post\populate_bean;

class LambMicropubAdapter extends MicropubAdapter
{
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

        $content = $this->extractContent($props);
        if ($content === null) {
            return 'invalid_request';
        }

        $body = $this->buildBody($props, $content);

        $bean = populate_bean($body);
        R::store($bean);

        return permalink($bean);
    }

    /**
     * Extract plain-text content from micropub properties.
     * Returns null if no content is present.
     *
     * @param array $props
     * @return string|null
     */
    private function extractContent(array $props): ?string
    {
        if (empty($props['content'])) {
            return null;
        }

        $raw = $props['content'][0];

        if (is_array($raw)) {
            return $raw['value'] ?? $raw['html'] ?? null;
        }

        return (string) $raw;
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

        if ($title === null) {
            return $content;
        }

        return "---\ntitle: $title\n---\n$content";
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

        return implode("\n", array_map(fn($url) => '![](' . $url . ')', $photos));
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

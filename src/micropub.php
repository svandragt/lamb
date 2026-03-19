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
     * Verify the bearer token against LAMB_MICROPUB_TOKEN env var.
     *
     * @param string $token
     * @return array|false
     */
    public function verifyAccessTokenCallback(string $token)
    {
        $expected = getenv('LAMB_MICROPUB_TOKEN');
        if (empty($expected) || !hash_equals($expected, $token)) {
            return false;
        }

        return [
            'me'    => ROOT_URL,
            'scope' => ['create'],
        ];
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

        if ($title === null) {
            return $content;
        }

        return "---\ntitle: $title\n---\n$content";
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

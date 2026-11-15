<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\EndpointProbe;

/**
 * respond_micropub_media() (src/micropub.php) against the requests micropub.rocks sends.
 *
 * It sets the response via http_response_code()/header() and then exit()s, a
 * shape PHPUnit cannot observe in-process, so EndpointProbe runs it in a child
 * process and reports what it sent. The probe's token endpoint answers the
 * handler's introspection request, so every test goes through the same token
 * check a real client does.
 */
class MicropubMediaEndpointTest extends TestCase
{
    private const TOKEN = '{"me":"https://example.com","scope":"create"}';

    private const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private const JPEG = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';

    public function testRocks700JpegUploadReturns201WithTheFileUrl(): void
    {
        $this->assertStoredUpload($this->upload('sunset.jpg', self::JPEG));
    }

    public function testRocks701PngUploadReturns201WithTheFileUrl(): void
    {
        $this->assertStoredUpload($this->upload('micropub-rocks.png', self::PNG));
    }

    public function testRocks702GifUploadReturns201WithTheFileUrl(): void
    {
        $this->assertStoredUpload($this->upload('w3c-socialwg.gif', self::GIF));
    }

    /**
     * Covers the regression #653 fixed: a failed store_upload_or_fallback()
     * must answer 500 with no Location header, not a 201 the file was never
     * written to. A gif skips the WebP re-encode branch (should_convert_to_webp()
     * is false for gif) and falls straight to move_uploaded_file(), which
     * always refuses a file that did not arrive via a real HTTP upload — the
     * exact failure this endpoint has to turn into a 500, not a false-positive 201.
     */
    public function testStoreFailureReturns500WithNoLocationHeader(): void
    {
        $result = $this->upload('x.gif', self::GIF, false);

        $this->assertSame(500, $result['status'], "Expected HTTP 500 when the file could not be stored.\n{$result['stderr']}");
        $this->assertArrayNotHasKey('location', $result['headers'], 'No Location header may be sent for a file that was never stored.');
        $this->assertSame('server_error', json_decode($result['body'], true)['error'] ?? null);
    }

    public function testRequestWithNoBearerTokenGets401AndABareChallenge(): void
    {
        $result = EndpointProbe::run('\Lamb\Micropub\respond_micropub_media();', [
            'server' => ['CONTENT_TYPE' => 'multipart/form-data; boundary=x'],
            'files' => ['file' => [['x.gif', base64_decode(self::GIF)]]],
        ]);

        $this->assertSame(401, $result['status']);
        $this->assertSame(['Bearer'], $result['headers']['www-authenticate'] ?? null);
        $this->assertSame('unauthorized', json_decode($result['body'], true)['error']);
        $this->assertSame([], $result['assets']);
    }

    public function testUnverifiableTokenGets401AndAnInvalidTokenChallenge(): void
    {
        $result = $this->upload('x.gif', self::GIF, true, '{}');

        $this->assertSame(401, $result['status']);
        $this->assertSame(
            ['Bearer error="invalid_token", error_description="The access token is invalid or expired."'],
            $result['headers']['www-authenticate'] ?? null
        );
        $this->assertSame([], $result['assets']);
    }

    public function testTokenWithoutCreateScopeGets403InsufficientScope(): void
    {
        $result = $this->upload('x.gif', self::GIF, true, '{"me":"https://example.com","scope":"update"}');

        $this->assertSame(403, $result['status']);
        $this->assertSame(
            ['Bearer error="insufficient_scope", scope="create"'],
            $result['headers']['www-authenticate'] ?? null
        );
        $this->assertSame('insufficient_scope', json_decode($result['body'], true)['error']);
        $this->assertSame([], $result['assets']);
    }

    public function testRequestWithoutAFileGets400(): void
    {
        $result = $this->probe([]);

        $this->assertSame(400, $result['status']);
        $this->assertSame(
            'Expected a multipart/form-data POST with a file field.',
            json_decode($result['body'], true)['error_description']
        );
    }

    public function testGetRequestGets400(): void
    {
        $result = $this->probe(['file' => [['x.gif', base64_decode(self::GIF)]]], true, self::TOKEN, 'GET');

        $this->assertSame(400, $result['status']);
        $this->assertSame([], $result['assets']);
    }

    public function testFailedUploadGets400(): void
    {
        $result = $this->probe(['file' => [['x.gif', base64_decode(self::GIF), UPLOAD_ERR_PARTIAL]]]);

        $this->assertSame(400, $result['status']);
        $this->assertSame('File upload failed.', json_decode($result['body'], true)['error_description']);
    }

    public function testUnsupportedFileTypeGets400(): void
    {
        $result = $this->probe(['file' => [['notes.txt', 'plain text']]]);

        $this->assertSame(400, $result['status']);
        $this->assertSame('Unsupported file type.', json_decode($result['body'], true)['error_description']);
        $this->assertSame([], $result['assets']);
    }

    public function testFileWhoseBytesDoNotMatchItsExtensionGets400(): void
    {
        $result = $this->upload('x.png', self::GIF);

        $this->assertSame(400, $result['status']);
        $this->assertSame('File contents do not match its type.', json_decode($result['body'], true)['error_description']);
        $this->assertSame([], $result['assets']);
    }

    public function testResponseIsPrivateAndUncacheable(): void
    {
        $result = $this->upload('x.gif', self::GIF);

        $this->assertStringContainsString('no-store', $result['headers']['cache-control'][0] ?? '');
    }

    public function testDebugLoggingRecordsTheRequest(): void
    {
        $result = EndpointProbe::run('\Lamb\Micropub\respond_micropub_media();', [
            'server' => ['REQUEST_METHOD' => 'POST', 'HTTP_AUTHORIZATION' => 'Bearer test-token'],
            'files' => ['file' => [['x.gif', base64_decode(self::GIF)]]],
            'token' => self::TOKEN,
            'accept_uploads' => true,
            'debug' => true,
        ]);

        $this->assertStringContainsString('"event":"media_request"', $result['log']);
        $this->assertStringContainsString('"has_auth_header":true,"has_file":true', $result['log']);
    }

    /**
     * Asserts the response micropub.rocks checks after an upload: HTTP 201 and a
     * Location header, which is the URL of a file that was stored.
     *
     * @param array<string, mixed> $result The handler's response.
     * @return void
     */
    private function assertStoredUpload(array $result): void
    {
        $this->assertSame(201, $result['status'], $result['body'] . $result['stderr']);
        $location = $result['headers']['location'][0] ?? '';
        $this->assertStringStartsWith('https://example.com/assets/', $location);
        $this->assertSame(
            ['url' => $location, 'preview' => $location, 'edit' => $location],
            json_decode($result['body'], true)
        );
        $this->assertContains(parse_url($location, PHP_URL_PATH), $result['assets']);
    }

    /**
     * Posts one file named $name to the media endpoint.
     *
     * @param string      $name          The client's filename.
     * @param string      $base64        The file's bytes, base64-encoded.
     * @param bool        $acceptUploads Whether move_uploaded_file() may move it, as it does for a real upload.
     * @param string|null $token         The JSON the token endpoint answers with.
     * @return array<string, mixed> The handler's response.
     */
    private function upload(string $name, string $base64, bool $acceptUploads = true, ?string $token = self::TOKEN): array
    {
        return $this->probe(['file' => [[$name, base64_decode($base64)]]], $acceptUploads, $token);
    }

    /**
     * Runs respond_micropub_media() against a request carrying a bearer token.
     *
     * @param array<string, list<array{0: string, 1: string, 2?: int}>> $files As for EndpointProbe::run().
     * @param bool        $acceptUploads As for EndpointProbe::run().
     * @param string|null $token         The JSON the token endpoint answers with.
     * @param string      $method        The request method.
     * @return array<string, mixed> The handler's response.
     */
    private function probe(array $files, bool $acceptUploads = true, ?string $token = self::TOKEN, string $method = 'POST'): array
    {
        return EndpointProbe::run('\Lamb\Micropub\respond_micropub_media();', [
            'server' => [
                'REQUEST_METHOD' => $method,
                'CONTENT_TYPE' => 'multipart/form-data; boundary=x',
                'HTTP_AUTHORIZATION' => 'Bearer test-token',
            ],
            'files' => $files,
            'token' => $token,
            'accept_uploads' => $acceptUploads,
        ]);
    }
}

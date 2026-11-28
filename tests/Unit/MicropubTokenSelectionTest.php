<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\EndpointProbe;

/**
 * Which access token /micropub and /micropub-media (src/modules/micropub/micropub.php) use, and when they refuse the request.
 *
 * Both endpoints must pick the token by the same rule, so each case runs
 * against both and expects the same outcome. The probe sets getallheaders()
 * directly, because `$_SERVER` cannot spell a header name such as AUTHORIZATION.
 */
class MicropubTokenSelectionTest extends TestCase
{
    private const TOKEN = '{"me":"https://example.com","scope":"create"}';

    private const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    /**
     * @dataProvider tokenRequests
     *
     * @param string                $endpoint The endpoint's name, as endpoints() lists it.
     * @param array<string, string> $headers  The request headers besides Content-Type.
     * @param array<string, mixed>  $post     The form fields besides the endpoint's own.
     * @param string                $outcome  `accepted`, `both` or `missing`.
     * @param string|null           $token    The token the token endpoint must receive, when accepted.
     */
    public function testBothEndpointsSelectTheTokenTheSameWay(
        string $endpoint,
        array $headers,
        array $post,
        string $outcome,
        ?string $token
    ): void {
        $result = $this->send($endpoint, $headers, $post);
        $error = json_decode($result['body'], true)['error'] ?? null;

        if ($outcome === 'accepted') {
            $this->assertSame(201, $result['status'], $result['body'] . $result['stderr']);
            $this->assertSame($token, $result['introspected_token']);
            return;
        }

        $this->assertNull($result['introspected_token'], 'The token endpoint must not be asked about this request.');
        if ($outcome === 'both') {
            $this->assertSame(400, $result['status']);
            $this->assertSame('invalid_request', $error);
            return;
        }

        $this->assertSame(401, $result['status']);
        $this->assertSame('unauthorized', $error);
        $this->assertSame(['Bearer'], $result['headers']['www-authenticate'] ?? null);
    }

    /**
     * @return array<string, array{string, array<string, string>, array<string, mixed>, string, string|null}>
     */
    public static function tokenRequests(): array
    {
        $cases = [
            'a whitespace-only body token is no token' => [[], ['access_token' => " \t "], 'missing', null],
            'a body token is trimmed' => [[], ['access_token' => '  body-token  '], 'accepted', 'body-token'],
            'a header token is trimmed' => [['Authorization' => 'Bearer   header-token  '], [], 'accepted', 'header-token'],
            'a whitespace-only Bearer header carries no token' => [
                ['Authorization' => 'Bearer    '],
                ['access_token' => 'body-token'],
                'accepted',
                'body-token',
            ],
            'a whitespace-only Bearer header and no body token is no token' => [
                ['Authorization' => 'Bearer    '],
                [],
                'missing',
                null,
            ],
            'Bearer glued to its token is not a Bearer header' => [
                ['Authorization' => 'Bearerxyz'],
                ['access_token' => 'body-token'],
                'accepted',
                'body-token',
            ],
            'Bearer glued to its token and no body token is no token' => [
                ['Authorization' => 'Bearerxyz'],
                [],
                'missing',
                null,
            ],
            'a header token with a blank body token uses the header token' => [
                ['Authorization' => 'Bearer header-token'],
                ['access_token' => ''],
                'accepted',
                'header-token',
            ],
            'a header token with a whitespace-only body token uses the header token' => [
                ['Authorization' => 'Bearer header-token'],
                ['access_token' => " \t "],
                'accepted',
                'header-token',
            ],
            'a header token with a list body token uses the header token' => [
                ['Authorization' => 'Bearer header-token'],
                ['access_token' => ['x']],
                'accepted',
                'header-token',
            ],
            'a header token with a padded body token is a token in both places' => [
                ['Authorization' => 'Bearer header-token'],
                ['access_token' => '  body-token  '],
                'both',
                null,
            ],
            'the header name is case-insensitive' => [
                ['AUTHORIZATION' => 'Bearer header-token'],
                ['access_token' => 'body-token'],
                'both',
                null,
            ],
        ];

        $named = [];
        foreach (array_keys(self::endpoints()) as $endpoint) {
            foreach ($cases as $name => $case) {
                $named["$endpoint: $name"] = [$endpoint, ...$case];
            }
        }

        return $named;
    }

    /**
     * The endpoints under test, by name, with the request each one takes.
     *
     * @return array<string, array{call: string, content_type: string, post: array<string, string>, files: array<string, list<array{0: string, 1: string}>>}>
     */
    private static function endpoints(): array
    {
        return [
            '/micropub' => [
                'call' => '\Lamb\Micropub\respond_micropub();',
                'content_type' => 'application/x-www-form-urlencoded; charset=utf-8',
                'post' => ['h' => 'entry', 'content' => 'Choosing the token'],
                'files' => [],
            ],
            '/micropub-media' => [
                'call' => '\Lamb\Micropub\respond_micropub_media();',
                'content_type' => 'multipart/form-data; boundary=x',
                'post' => [],
                'files' => ['file' => [['x.gif', (string) base64_decode(self::GIF)]]],
            ],
        ];
    }

    /**
     * Sends one request to an endpoint.
     *
     * @param string                $endpoint The endpoint's name, as endpoints() lists it.
     * @param array<string, string> $headers  The request headers besides Content-Type.
     * @param array<string, mixed>  $post     The form fields besides the endpoint's own.
     * @return array<string, mixed> The handler's response.
     */
    private function send(string $endpoint, array $headers, array $post): array
    {
        $shape = self::endpoints()[$endpoint];

        return EndpointProbe::run($shape['call'], [
            'server' => ['REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => $shape['content_type']],
            'headers' => ['Content-Type' => $shape['content_type']] + $headers,
            'post' => $post + $shape['post'],
            'files' => $shape['files'],
            'token' => self::TOKEN,
            'accept_uploads' => true,
        ]);
    }
}

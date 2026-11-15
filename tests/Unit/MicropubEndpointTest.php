<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\EndpointProbe;

/**
 * respond_micropub() (src/micropub.php) against the requests micropub.rocks sends.
 *
 * Each test is named after the micropub.rocks server test it mirrors and sends
 * the same request that test sends, through the same entry point a client
 * reaches: the route handler, with the request in $_SERVER, $_POST, $_FILES and
 * the raw body. EndpointProbe runs the handler in a child process, because it
 * ends in exit().
 *
 * micropub.rocks tests 100–107, 200–206 and 400–404 are pinned one level down,
 * by the createCallback() and updateCallback() tests in MicropubAdapterTest;
 * the tests here add what only the route handler does: building the request
 * from PHP's globals, the cache and challenge headers, and the 201 JSON body.
 */
class MicropubEndpointTest extends TestCase
{
    private const TOKEN = '{"me":"https://example.com","scope":"create update delete"}';

    /** A 1x1 GIF, so an upload carries bytes that are the image its name claims. */
    private const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function testRocks100FormEncodedCreateReturns201WithLocationAndJsonBody(): void
    {
        $result = $this->formRequest(['h' => 'entry', 'content' => 'Micropub test of creating a basic h-entry']);

        $this->assertSame(201, $result['status']);
        $location = $result['headers']['location'][0] ?? '';
        $this->assertStringStartsWith('https://example.com/', $location);
        $this->assertSame('application/json', $result['headers']['content-type'][0] ?? null);
        $this->assertSame(
            ['url' => $location, 'preview' => $location, 'edit' => $location],
            json_decode($result['body'], true)
        );
        $this->assertSame('Micropub test of creating a basic h-entry', $result['posts'][0]['body']);
    }

    public function testRocks101FormEncodedCreateKeepsBothCategories(): void
    {
        $result = $this->formRequest([
            'h' => 'entry',
            'content' => 'Micropub test of creating an h-entry with categories.',
            'category' => ['test1', 'test2'],
        ]);

        $this->assertSame(201, $result['status']);
        $this->assertStringEndsWith(' #test1 #test2', $result['posts'][0]['body']);
    }

    public function testRocks107FormEncodedCreateKeepsASingleCategory(): void
    {
        $result = $this->formRequest([
            'h' => 'entry',
            'content' => 'Micropub test of creating an h-entry with one category.',
            'category' => 'test1',
        ]);

        $this->assertSame(201, $result['status']);
        $this->assertStringEndsWith(' #test1', $result['posts'][0]['body']);
    }

    public function testRocks200JsonCreateReturns201WithLocation(): void
    {
        $result = $this->jsonRequest([
            'type' => ['h-entry'],
            'properties' => ['content' => ['Micropub test of creating an h-entry with a JSON request']],
        ]);

        $this->assertSame(201, $result['status']);
        $this->assertStringStartsWith('https://example.com/', $result['headers']['location'][0] ?? '');
        $this->assertSame('Micropub test of creating an h-entry with a JSON request', $result['posts'][0]['body']);
    }

    public function testRocks300MultipartCreateStoresTheUploadedPhoto(): void
    {
        $result = $this->multipartRequest(['photo' => [['sunset.gif', base64_decode(self::GIF)]]]);

        $this->assertSame(201, $result['status']);
        $this->assertMatchesRegularExpression('#!\[\]\(/assets/[^)]+\.gif\)#', $result['posts'][0]['body']);
        $this->assertCount(1, $result['assets']);
    }

    public function testRocks301MultipartCreateStoresBothUploadedPhotos(): void
    {
        $result = $this->multipartRequest([
            'photo[]' => [['sunset.gif', base64_decode(self::GIF)], ['city-at-night.gif', base64_decode(self::GIF)]],
        ]);

        $this->assertSame(201, $result['status']);
        $this->assertSame(2, preg_match_all('#!\[\]\(/assets/[^)]+\.gif\)#', $result['posts'][0]['body']));
        $this->assertCount(2, $result['assets']);
    }

    public function testRocks400ReplaceReturns204AndUpdatesThePost(): void
    {
        $result = $this->jsonRequest(
            [
                'action' => 'update',
                'url' => '%%%',
                'replace' => ['content' => ['This is the updated text. If you can see this you passed the test!']],
            ],
            ['seed' => $this->seed('Micropub update test. This text should be replaced if the test succeeds.')]
        );

        $this->assertSame(204, $result['status']);
        $this->assertSame('', $result['body']);
        $this->assertSame(
            'This is the updated text. If you can see this you passed the test!',
            $result['posts'][0]['body']
        );
    }

    public function testRocks405NonArrayOperationIsRejectedWith400(): void
    {
        $result = $this->jsonRequest(
            ['action' => 'update', 'url' => '%%%', 'replace' => 'This is not a valid update request.'],
            ['seed' => $this->seed('Micropub update test #405.')]
        );

        $this->assertSame(400, $result['status']);
        $this->assertSame('invalid_request', json_decode($result['body'], true)['error']);
        $this->assertSame('Micropub update test #405.', $result['posts'][0]['body']);
    }

    public function testRocks500FormEncodedDeleteReturns204AndDeletesThePost(): void
    {
        $result = $this->formRequest(
            ['action' => 'delete', 'url' => '%%%'],
            ['seed' => $this->seed('This post will be deleted when the test succeeds.')]
        );

        $this->assertSame(204, $result['status']);
        $this->assertEquals(1, $result['posts'][0]['deleted']);
    }

    public function testRocks501JsonDeleteReturns204AndDeletesThePost(): void
    {
        $result = $this->jsonRequest(
            ['action' => 'delete', 'url' => '%%%'],
            ['seed' => $this->seed('This post will be deleted when the test succeeds.')]
        );

        $this->assertSame(204, $result['status']);
        $this->assertEquals(1, $result['posts'][0]['deleted']);
    }

    public function testRocks502FormEncodedUndeleteReturns204AndRestoresThePost(): void
    {
        $result = $this->formRequest(
            ['action' => 'undelete', 'url' => '%%%'],
            ['seed' => $this->seed('This post will be deleted, and should be restored after undeleting it.'), 'seed_deleted' => true]
        );

        $this->assertSame(204, $result['status']);
        $this->assertEmpty($result['posts'][0]['deleted']);
    }

    public function testRocks503JsonUndeleteReturns204AndRestoresThePost(): void
    {
        $result = $this->jsonRequest(
            ['action' => 'undelete', 'url' => '%%%'],
            ['seed' => $this->seed('This post will be deleted, and should be restored after undeleting it.'), 'seed_deleted' => true]
        );

        $this->assertSame(204, $result['status']);
        $this->assertEmpty($result['posts'][0]['deleted']);
    }

    public function testADeleteThatFailsToStoreReturns500AndDeletesNothing(): void
    {
        $result = $this->requestWithFailingContentMark(
            ['action' => 'delete', 'url' => '%%%'],
            ['seed' => $this->seed('This post must survive a failed delete.')]
        );

        $this->assertSame(500, $result['status']);
        $this->assertSame('server_error', json_decode($result['body'], true)['error']);
        $this->assertEmpty($result['posts'][0]['deleted']);
    }

    public function testAnUndeleteThatFailsToStoreReturns500AndRestoresNothing(): void
    {
        $result = $this->requestWithFailingContentMark(
            ['action' => 'undelete', 'url' => '%%%'],
            ['seed' => $this->seed('This post must stay deleted after a failed undelete.'), 'seed_deleted' => true]
        );

        $this->assertSame(500, $result['status']);
        $this->assertSame('server_error', json_decode($result['body'], true)['error']);
        $this->assertEquals(1, $result['posts'][0]['deleted']);
    }

    public function testRocks600ConfigQueryReturnsTheMediaEndpoint(): void
    {
        $result = $this->query('/micropub?q=config');

        $this->assertSame(200, $result['status']);
        $this->assertSame(
            'https://example.com/micropub-media',
            json_decode($result['body'], true)['media-endpoint']
        );
    }

    public function testRocks601SyndicateToQueryReturnsAnArrayOfTargets(): void
    {
        $result = $this->query('/micropub?q=syndicate-to');

        $this->assertSame(200, $result['status']);
        $this->assertSame(['syndicate-to' => []], json_decode($result['body'], true));
    }

    public function testRocks602SourceQueryReturnsAllProperties(): void
    {
        $result = $this->query('/micropub?q=source&url=%%%', $this->seed(
            'Test of querying the endpoint for the source content',
            ['micropub', 'test']
        ));

        $this->assertSame(200, $result['status']);
        $this->assertSame(
            [
                'type' => ['h-entry'],
                'properties' => [
                    'content' => ['Test of querying the endpoint for the source content'],
                    'category' => ['micropub', 'test'],
                ],
            ],
            json_decode($result['body'], true)
        );
    }

    public function testRocks603SourceQueryReturnsOnlyTheRequestedProperties(): void
    {
        $result = $this->query(
            '/micropub?q=source&properties%5B%5D=content&properties%5B%5D=category&url=%%%',
            $this->seed('Test of querying the endpoint for the source content', ['micropub', 'test'])
        );

        $this->assertSame(200, $result['status']);
        $properties = json_decode($result['body'], true)['properties'];
        $this->assertSame(['content', 'category'], array_keys($properties));
    }

    public function testRocks801AccessTokenInThePostBodyIsAccepted(): void
    {
        $result = $this->formRequest(
            ['h' => 'entry', 'content' => 'Testing accepting access token in post body', 'access_token' => 'body-token'],
            [],
            false
        );

        $this->assertSame(201, $result['status']);
        $this->assertStringStartsWith('https://example.com/', $result['headers']['location'][0] ?? '');
    }

    public function testRocks805ATokenInBothTheHeaderAndTheBodyIsRefused(): void
    {
        $result = $this->formRequest(
            ['h' => 'entry', 'content' => 'Testing access token in both header and body', 'access_token' => 'body-token']
        );

        $this->assertSame(400, $result['status']);
        $body = json_decode($result['body'], true);
        $this->assertSame('invalid_request', $body['error']);
        $this->assertStringContainsString('both', $body['error_description']);
        $this->assertArrayNotHasKey('www-authenticate', $result['headers']);
        $this->assertSame([], $result['posts']);
    }

    public function testABasicAuthorizationHeaderDoesNotStopABodyToken(): void
    {
        $this->assertSame(201, $this->bodyTokenBehind('Basic dXNlcjpwYXNz')['status']);
    }

    public function testAnEmptyAuthorizationHeaderDoesNotStopABodyToken(): void
    {
        $this->assertSame(201, $this->bodyTokenBehind('')['status']);
    }

    /**
     * A form request with a body token and the given Authorization header value.
     *
     * @param string $authorization The Authorization header value.
     * @return array<string, mixed> The handler's response.
     */
    private function bodyTokenBehind(string $authorization): array
    {
        return $this->handle([
            'server' => [
                'CONTENT_TYPE'       => 'application/x-www-form-urlencoded; charset=utf-8',
                'HTTP_AUTHORIZATION' => $authorization,
            ],
            'post'   => ['h' => 'entry', 'content' => 'Body token behind a proxy header', 'access_token' => 'body-token'],
        ]);
    }

    public function testRocks802TheAccessTokenIsNotStoredWithThePost(): void
    {
        $result = $this->formRequest(
            ['h' => 'entry', 'content' => 'Testing accepting access token in post body', 'access_token' => 'body-token'],
            [],
            false
        );

        $this->assertSame('Testing accepting access token in post body', $result['posts'][0]['body']);
    }

    public function testRocks803UnauthenticatedRequestGets401AndABearerChallenge(): void
    {
        $result = EndpointProbe::run('\Lamb\Micropub\respond_micropub();', [
            'server' => ['CONTENT_TYPE' => 'application/x-www-form-urlencoded'],
            'post' => ['h' => 'entry', 'content' => 'Testing unauthenticated request. This should not create a post.'],
        ]);

        $this->assertSame(401, $result['status']);
        $this->assertSame(['Bearer'], $result['headers']['www-authenticate'] ?? null);
        $this->assertSame('unauthorized', json_decode($result['body'], true)['error']);
        $this->assertSame([], $result['posts']);
        $this->assertArrayNotHasKey('location', $result['headers']);
    }

    /**
     * Test 804 expects HTTP 401 for a token that may not create posts. Lamb answers
     * 403 instead, as RFC 6750 §3.1 and the Micropub spec say (aaronpk/micropub.rocks#101).
     */
    public function testRocks804TokenWithoutCreateScopeGets403InsufficientScope(): void
    {
        $result = $this->formRequest(
            ['h' => 'entry', 'content' => 'Testing a request with an unauthorized access token.'],
            ['token' => '{"me":"https://example.com","scope":"update"}']
        );

        $this->assertSame(403, $result['status']);
        $this->assertSame(
            ['Bearer error="insufficient_scope", scope="create"'],
            $result['headers']['www-authenticate'] ?? null
        );
        $this->assertSame('insufficient_scope', json_decode($result['body'], true)['error']);
        $this->assertSame([], $result['posts']);
    }

    public function testEveryResponseIsPrivateAndUncacheable(): void
    {
        $result = EndpointProbe::run('\Lamb\Micropub\respond_micropub();');

        $this->assertStringContainsString('no-store', $result['headers']['cache-control'][0] ?? '');
        $this->assertSame(['Cookie'], $result['headers']['vary'] ?? null);
    }

    public function testDebugLoggingRecordsTheRequestAndTheResponse(): void
    {
        $result = $this->formRequest(
            ['h' => 'entry', 'content' => 'Logged'],
            ['debug' => true]
        );

        $this->assertStringContainsString('"event":"request"', $result['log']);
        $this->assertStringContainsString('"method":"POST"', $result['log']);
        $this->assertStringContainsString('"has_auth_header":true', $result['log']);
        $this->assertStringContainsString('"event":"response","status":201', $result['log']);
    }

    public function testDebugLoggingKeepsTheBodyOfAFailedResponse(): void
    {
        $result = EndpointProbe::run('\Lamb\Micropub\respond_micropub();', ['debug' => true]);

        $this->assertStringContainsString('"event":"response","status":401,"body":"{\"error\":\"unauthorized\"', $result['log']);
    }

    /**
     * A micropub.rocks create or action request with the form-encoded body.
     *
     * @param array<string, mixed> $fields    The form fields.
     * @param array<string, mixed> $request   More of the request, as for EndpointProbe::run().
     * @param bool                 $withHeader Whether the token travels in the Authorization header.
     * @return array<string, mixed> The handler's response.
     */
    private function formRequest(array $fields, array $request = [], bool $withHeader = true): array
    {
        $server = ['CONTENT_TYPE' => 'application/x-www-form-urlencoded; charset=utf-8'];
        if ($withHeader) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer test-token';
        }

        return $this->handle($request + ['server' => $server, 'post' => $fields]);
    }

    /**
     * A micropub.rocks request with a JSON body.
     *
     * @param array<string, mixed> $payload The JSON document.
     * @param array<string, mixed> $request More of the request, as for EndpointProbe::run().
     * @return array<string, mixed> The handler's response.
     */
    private function jsonRequest(array $payload, array $request = []): array
    {
        return $this->handle($request + [
            'server' => ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer test-token'],
            'input' => json_encode($payload),
        ]);
    }

    /**
     * A micropub.rocks create request with photos in a multipart body.
     *
     * @param array<string, list<array{0: string, 1: string}>> $files The photo uploads.
     * @return array<string, mixed> The handler's response.
     */
    private function multipartRequest(array $files): array
    {
        return $this->handle([
            'server' => ['CONTENT_TYPE' => 'multipart/form-data; boundary=x', 'HTTP_AUTHORIZATION' => 'Bearer test-token'],
            'post' => ['h' => 'entry', 'content' => 'Micropub test of creating a photo.'],
            'files' => $files,
        ]);
    }

    /**
     * A micropub.rocks GET query.
     *
     * @param string               $uri  The request URI.
     * @param array<string, mixed> $seed Micropub data for the post the query asks about.
     * @return array<string, mixed> The handler's response.
     */
    private function query(string $uri, array $seed = []): array
    {
        $request = [
            'server' => ['REQUEST_METHOD' => 'GET', 'REQUEST_URI' => $uri, 'HTTP_AUTHORIZATION' => 'Bearer test-token'],
        ];

        return $this->handle($seed === [] ? $request : $request + ['seed' => $seed]);
    }

    /**
     * A form-encoded request whose post store succeeds and whose content mark write then fails.
     *
     * The seeded post's creation has already written the mark, so the trigger blocks the
     * UPDATE that moves it. That is the last write of a delete or an undelete, so the
     * post store before it has to be rolled back.
     *
     * @param array<string, mixed> $fields  The form fields.
     * @param array<string, mixed> $request More of the request, as for EndpointProbe::run().
     * @return array<string, mixed> The handler's response.
     */
    private function requestWithFailingContentMark(array $fields, array $request): array
    {
        $blockMarkWrites = <<<'PHP'
        \RedBeanPHP\R::exec("CREATE TRIGGER block_write BEFORE UPDATE ON option BEGIN SELECT RAISE(ABORT, 'boom'); END");
        PHP;

        return EndpointProbe::run($blockMarkWrites . ' \Lamb\Micropub\respond_micropub();', $request + [
            'server' => [
                'CONTENT_TYPE' => 'application/x-www-form-urlencoded; charset=utf-8',
                'HTTP_AUTHORIZATION' => 'Bearer test-token',
            ],
            'post' => $fields,
            'token' => self::TOKEN,
        ]);
    }

    /**
     * Runs respond_micropub() with a token the fake token endpoint accepts, unless the request names its own.
     *
     * @param array<string, mixed> $request As for EndpointProbe::run().
     * @return array<string, mixed> The handler's response.
     */
    private function handle(array $request): array
    {
        return EndpointProbe::run('\Lamb\Micropub\respond_micropub();', $request + ['token' => self::TOKEN]);
    }

    /**
     * The Micropub data of a post to create before the request under test.
     *
     * @param string       $content    The post content.
     * @param list<string> $categories The post categories.
     * @return array{type: list<string>, properties: array<string, list<string>>}
     */
    private function seed(string $content, array $categories = []): array
    {
        $properties = ['content' => [$content]];
        if ($categories !== []) {
            $properties['category'] = $categories;
        }

        return ['type' => ['h-entry'], 'properties' => $properties];
    }
}

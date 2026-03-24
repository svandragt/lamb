<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;
use Lamb\Micropub\LambMicropubAdapter;
use Tests\Support\StubMicropubAdapter;

class MicropubAdapterTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);

        global $config;
        $config = $config ?? [];

        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'http://localhost');
        }
        if (!defined('ROOT_DIR')) {
            define('ROOT_DIR', sys_get_temp_dir() . '/lamb_test');
        }
    }

    // --- handleRequest (RFC 6750 multi-auth rejection) ---

    public function testHandleRequestRejects400WhenTokenInBothHeaderAndBody(): void
    {
        $adapter = new StubMicropubAdapter();
        $adapter->stubResponse = [
            'me'    => ROOT_URL . '/',
            'scope' => 'create',
        ];

        $request = new \Nyholm\Psr7\ServerRequest(
            'POST',
            ROOT_URL . '/micropub',
            [
                'Authorization' => 'Bearer some-token',
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ]
        );
        $request = $request->withParsedBody([
            'h'            => 'entry',
            'content'      => 'Test content',
            'access_token' => 'some-token',
        ]);

        $response = $adapter->handleRequest($request);
        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertSame('invalid_request', $body['error']);
    }

    // --- verifyAccessTokenCallback ---

    public function testVerifyTokenReturnsFalseWhenIntrospectionFails(): void
    {
        $adapter = new StubMicropubAdapter();
        $adapter->stubResponse = null;
        $result = $adapter->verifyAccessTokenCallback('any-token');
        $this->assertFalse($result);
    }

    public function testVerifyTokenReturnsFalseWhenMeDoesNotMatchSite(): void
    {
        $adapter = new StubMicropubAdapter();
        $adapter->stubResponse = [
            'me'    => 'https://other.example.com/',
            'scope' => 'create',
        ];
        $result = $adapter->verifyAccessTokenCallback('some-token');
        $this->assertFalse($result);
    }

    public function testVerifyTokenReturnsUserDataForValidToken(): void
    {
        $adapter = new StubMicropubAdapter();
        $adapter->stubResponse = [
            'me'    => ROOT_URL . '/',
            'scope' => 'create update',
        ];
        $result = $adapter->verifyAccessTokenCallback('valid-jwt');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('me', $result);
        $this->assertArrayHasKey('scope', $result);
    }

    public function testVerifyTokenScopeIsParsedFromSpaceSeparatedString(): void
    {
        $adapter = new StubMicropubAdapter();
        $adapter->stubResponse = [
            'me'    => ROOT_URL . '/',
            'scope' => 'create update delete',
        ];
        $result = $adapter->verifyAccessTokenCallback('valid-jwt');
        $this->assertIsArray($result['scope']);
        $this->assertContains('create', $result['scope']);
        $this->assertContains('update', $result['scope']);
    }

    public function testVerifyTokenHandlesMissingTrailingSlash(): void
    {
        $adapter = new StubMicropubAdapter();
        $adapter->stubResponse = [
            'me'    => ROOT_URL,  // no trailing slash
            'scope' => 'create',
        ];
        $result = $adapter->verifyAccessTokenCallback('valid-jwt');
        $this->assertIsArray($result);
    }

    // --- createCallback ---

    public function testCreateCallbackReturnsUrlForPlainContent(): void
    {
        $adapter = new LambMicropubAdapter();
        $data = [
            'type' => ['h-entry'],
            'properties' => [
                'content' => ['Hello from micropub'],
            ],
        ];
        $result = $adapter->createCallback($data);
        $this->assertIsString($result);
        $this->assertStringStartsWith('http', $result);
    }

    public function testCreateCallbackCreatesPostInDatabase(): void
    {
        $adapter = new LambMicropubAdapter();
        $data = [
            'type' => ['h-entry'],
            'properties' => [
                'content' => ['A new micropub post'],
            ],
        ];
        $adapter->createCallback($data);
        $post = R::findOne('post', ' body = ? ', ['A new micropub post']);
        $this->assertNotNull($post);
        $this->assertSame('A new micropub post', $post->body);
    }

    public function testCreateCallbackWithNameSetsTitleFrontMatter(): void
    {
        $adapter = new LambMicropubAdapter();
        $data = [
            'type' => ['h-entry'],
            'properties' => [
                'name' => ['My Post Title'],
                'content' => ['Post body here.'],
            ],
        ];
        $adapter->createCallback($data);
        $post = R::findOne('post', ' title = ? ', ['My Post Title']);
        $this->assertNotNull($post);
        $this->assertSame('My Post Title', $post->title);
    }

    public function testCreateCallbackWithNameCreatesSlug(): void
    {
        $adapter = new LambMicropubAdapter();
        $data = [
            'type' => ['h-entry'],
            'properties' => [
                'name' => ['Sluggable Title'],
                'content' => ['Some content.'],
            ],
        ];
        $adapter->createCallback($data);
        $post = R::findOne('post', ' title = ? ', ['Sluggable Title']);
        $this->assertNotNull($post);
        $this->assertNotEmpty($post->slug);
        $this->assertSame('sluggable-title', $post->slug);
    }

    public function testCreateCallbackPlainContentHasNoSlug(): void
    {
        $adapter = new LambMicropubAdapter();
        $data = [
            'type' => ['h-entry'],
            'properties' => [
                'content' => ['Just a status update'],
            ],
        ];
        $adapter->createCallback($data);
        $post = R::findOne('post', ' body = ? ', ['Just a status update']);
        $this->assertNotNull($post);
        $this->assertEmpty($post->slug);
    }

    public function testCreateCallbackWithArrayContent(): void
    {
        $adapter = new LambMicropubAdapter();
        $data = [
            'type' => ['h-entry'],
            'properties' => [
                'content' => [['html' => '<p>Rich content</p>', 'value' => 'Rich content']],
            ],
        ];
        $result = $adapter->createCallback($data);
        $this->assertIsString($result);
        $post = R::findOne('post', ' body = ? ', ['Rich content']);
        $this->assertNotNull($post);
    }

    public function testCreateCallbackCategoriesAppendedAsHashtags(): void
    {
        $adapter = new LambMicropubAdapter();
        $data = [
            'type' => ['h-entry'],
            'properties' => [
                'content'  => ['A post with categories'],
                'category' => ['test1', 'test2'],
            ],
        ];
        $adapter->createCallback($data);
        $post = R::findOne('post', ' body LIKE ? ', ['%#test1%']);
        $this->assertNotNull($post);
        $this->assertStringContainsString('#test1', $post->body);
        $this->assertStringContainsString('#test2', $post->body);
    }

    public function testCreateCallbackHtmlContentIsRenderedNotEscaped(): void
    {
        $adapter = new LambMicropubAdapter();
        $data = [
            'type' => ['h-entry'],
            'properties' => [
                'content' => [['html' => '<p>This has <b>bold</b> text.</p>']],
            ],
        ];
        $adapter->createCallback($data);
        $post = R::findOne('post', ' body LIKE ? ', ['%bold%']);
        $this->assertNotNull($post);
        $this->assertStringContainsString('<b>bold</b>', $post->transformed);
        $this->assertStringNotContainsString('&lt;b&gt;', $post->transformed);
    }

    public function testCreateCallbackHtmlScriptTagIsStripped(): void
    {
        $adapter = new LambMicropubAdapter();
        $data = [
            'type' => ['h-entry'],
            'properties' => [
                'content' => [['html' => '<p>Sanitise script</p><script>alert(1)</script>']],
            ],
        ];
        $adapter->createCallback($data);
        $post = R::findOne('post', ' body LIKE ? ', ['%Sanitise script%']);
        $this->assertNotNull($post);
        $this->assertStringNotContainsString('<script>', $post->transformed);
        $this->assertStringContainsString('<p>Sanitise script</p>', $post->transformed);
    }

    public function testCreateCallbackHtmlStyleTagIsStripped(): void
    {
        $adapter = new LambMicropubAdapter();
        $data = [
            'type' => ['h-entry'],
            'properties' => [
                'content' => [['html' => '<p>Sanitise style</p><style>body{display:none}</style>']],
            ],
        ];
        $adapter->createCallback($data);
        $post = R::findOne('post', ' body LIKE ? ', ['%Sanitise style%']);
        $this->assertNotNull($post);
        $this->assertStringNotContainsString('<style>', $post->transformed);
    }

    public function testCreateCallbackHtmlIframeIsStripped(): void
    {
        $adapter = new LambMicropubAdapter();
        $data = [
            'type' => ['h-entry'],
            'properties' => [
                'content' => [['html' => '<p>Sanitise iframe</p><iframe src="https://evil.example.com"></iframe>']],
            ],
        ];
        $adapter->createCallback($data);
        $post = R::findOne('post', ' body LIKE ? ', ['%Sanitise iframe%']);
        $this->assertNotNull($post);
        $this->assertStringNotContainsString('<iframe', $post->transformed);
    }

    public function testCreateCallbackPlainTextContentIsStillMarkdownProcessed(): void
    {
        $adapter = new LambMicropubAdapter();
        $data = [
            'type' => ['h-entry'],
            'properties' => [
                'content' => ['Plain **text** content'],
            ],
        ];
        $adapter->createCallback($data);
        $post = R::findOne('post', ' body = ? ', ['Plain **text** content']);
        $this->assertNotNull($post);
        $this->assertStringContainsString('<strong>text</strong>', $post->transformed);
    }

    public function testCreateCallbackPhotoUrlAppendsMarkdownImage(): void
    {
        $adapter = new LambMicropubAdapter();
        $data = [
            'type' => ['h-entry'],
            'properties' => [
                'content' => ['A post with a photo'],
                'photo'   => ['https://example.com/sunset.jpg'],
            ],
        ];
        $adapter->createCallback($data);
        $post = R::findOne('post', ' body LIKE ? ', ['%sunset.jpg%']);
        $this->assertNotNull($post);
        $this->assertStringContainsString('![](https://example.com/sunset.jpg)', $post->body);
    }

    public function testCreateCallbackPhotoObjectWithAltUsesAltText(): void
    {
        $adapter = new LambMicropubAdapter();
        $data = [
            'type' => ['h-entry'],
            'properties' => [
                'content' => ['A post with an alt photo'],
                'photo'   => [['value' => 'https://example.com/sunset.jpg', 'alt' => 'Photo of a sunset']],
            ],
        ];
        $adapter->createCallback($data);
        $post = R::findOne('post', ' body LIKE ? ', ['%alt photo%']);
        $this->assertNotNull($post);
        $this->assertStringContainsString('![Photo of a sunset](https://example.com/sunset.jpg)', $post->body);
    }

    public function testCreateCallbackMultiplePhotosAppendMultipleImages(): void
    {
        $adapter = new LambMicropubAdapter();
        $data = [
            'type' => ['h-entry'],
            'properties' => [
                'content' => ['Two photos'],
                'photo'   => ['https://example.com/a.jpg', 'https://example.com/b.jpg'],
            ],
        ];
        $adapter->createCallback($data);
        $post = R::findOne('post', ' body LIKE ? ', ['%a.jpg%']);
        $this->assertNotNull($post);
        $this->assertStringContainsString('![](https://example.com/a.jpg)', $post->body);
        $this->assertStringContainsString('![](https://example.com/b.jpg)', $post->body);
    }

    public function testCreateCallbackNoCategoriesLeavesBodyUnchanged(): void
    {
        $adapter = new LambMicropubAdapter();
        $data = [
            'type' => ['h-entry'],
            'properties' => [
                'content' => ['No categories here'],
            ],
        ];
        $adapter->createCallback($data);
        $post = R::findOne('post', ' body = ? ', ['No categories here']);
        $this->assertNotNull($post);
    }

    // --- sourceQueryCallback ---

    public function testSourceQueryReturnsFalseForUnknownUrl(): void
    {
        $adapter = new LambMicropubAdapter();
        $result = $adapter->sourceQueryCallback(ROOT_URL . '/status/999999');
        $this->assertFalse($result);
    }

    public function testSourceQueryReturnsContentForStatusPost(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'Source query content';
        $bean->slug = '';
        $bean->created = date('Y-m-d H:i:s');
        $bean->updated = date('Y-m-d H:i:s');
        R::store($bean);

        $result = $adapter = new LambMicropubAdapter();
        $result = $adapter->sourceQueryCallback(ROOT_URL . '/status/' . $bean->id);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('properties', $result);
        $this->assertStringContainsString('Source query content', $result['properties']['content'][0]);
    }

    public function testSourceQueryReturnsContentForSluggedPost(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'Slugged source content';
        $bean->slug = 'source-test-slug';
        $bean->created = date('Y-m-d H:i:s');
        $bean->updated = date('Y-m-d H:i:s');
        R::store($bean);

        $adapter = new LambMicropubAdapter();
        $result = $adapter->sourceQueryCallback(ROOT_URL . '/source-test-slug');
        $this->assertIsArray($result);
        $this->assertStringContainsString('Slugged source content', $result['properties']['content'][0]);
    }

    public function testSourceQueryContentExcludesAppendedCategoryHashtags(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'Source content #micropub #test';
        $bean->slug = '';
        $bean->created = date('Y-m-d H:i:s');
        $bean->updated = date('Y-m-d H:i:s');
        R::store($bean);

        $adapter = new LambMicropubAdapter();
        $result = $adapter->sourceQueryCallback(ROOT_URL . '/status/' . $bean->id);
        $this->assertSame('Source content', $result['properties']['content'][0]);
        $this->assertContains('micropub', $result['properties']['category']);
        $this->assertContains('test', $result['properties']['category']);
    }

    public function testSourceQueryReturnsCategoriesFromHashtags(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'Tagged post #micropub #test';
        $bean->slug = '';
        $bean->created = date('Y-m-d H:i:s');
        $bean->updated = date('Y-m-d H:i:s');
        R::store($bean);

        $adapter = new LambMicropubAdapter();
        $result = $adapter->sourceQueryCallback(ROOT_URL . '/status/' . $bean->id);
        $this->assertIsArray($result);
        $this->assertContains('micropub', $result['properties']['category']);
        $this->assertContains('test', $result['properties']['category']);
    }

    public function testSourceQueryFiltersToRequestedProperties(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'Filtered content #tag1';
        $bean->slug = '';
        $bean->created = date('Y-m-d H:i:s');
        $bean->updated = date('Y-m-d H:i:s');
        R::store($bean);

        $adapter = new LambMicropubAdapter();
        $result = $adapter->sourceQueryCallback(ROOT_URL . '/status/' . $bean->id, ['content']);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('content', $result['properties']);
        $this->assertArrayNotHasKey('category', $result['properties']);
    }

    public function testCreateCallbackPublishedSetsCreatedDate(): void
    {
        $adapter = new LambMicropubAdapter();
        $data = [
            'type' => ['h-entry'],
            'properties' => [
                'content'   => ['A dated post'],
                'published' => ['2017-05-31T12:03:36-07:00'],
            ],
        ];
        $adapter->createCallback($data);
        $post = R::findOne('post', ' body = ? ', ['A dated post']);
        $this->assertNotNull($post);
        $this->assertStringStartsWith('2017-05-31', $post->created);
    }

    public function testCreateCallbackNestedPropertyStoredInBody(): void
    {
        $adapter = new LambMicropubAdapter();
        $data = [
            'type' => ['h-entry'],
            'properties' => [
                'content' => ['Lunch meeting'],
                'checkin' => [[
                    'type'       => ['h-card'],
                    'properties' => ['name' => ['Los Gorditos']],
                ]],
            ],
        ];
        $adapter->createCallback($data);
        $post = R::findOne('post', ' body LIKE ? ', ['%Lunch meeting%']);
        $this->assertNotNull($post);
        $this->assertStringContainsString('Los Gorditos', $post->body);
    }

    public function testCreateCallbackWithUploadedPhotoAppendsMarkdownImage(): void
    {
        $adapter = new LambMicropubAdapter();

        // Create a real temp file to simulate an upload
        $tmpFile = tempnam(sys_get_temp_dir(), 'micropub_test_');
        file_put_contents($tmpFile, 'fake image data');

        $uploadedFile = new \Nyholm\Psr7\UploadedFile(
            $tmpFile,
            15,
            UPLOAD_ERR_OK,
            'test-photo.jpg',
            'image/jpeg'
        );

        $data = [
            'type' => ['h-entry'],
            'properties' => [
                'content' => ['A post with an uploaded photo'],
            ],
        ];

        $result = $adapter->createCallback($data, ['photo' => $uploadedFile]);

        $this->assertIsString($result);
        $post = R::findOne('post', ' body LIKE ? ', ['%A post with an uploaded photo%']);
        $this->assertNotNull($post);
        $this->assertMatchesRegularExpression('/!\[.*\]\(.+\.jpg\)/', $post->body);
    }

    public function testCreateCallbackWithDraftPostStatusSavesAsDraft(): void
    {
        $adapter = new LambMicropubAdapter();
        $data = [
            'type' => ['h-entry'],
            'properties' => [
                'content'     => ['A draft post'],
                'post-status' => ['draft'],
            ],
        ];
        $adapter->createCallback($data, []);
        $post = R::findOne('post', ' body = ? ', ['A draft post']);
        $this->assertNotNull($post);
        $this->assertSame(1, (int) $post->draft);
    }

    public function testCreateCallbackWithDraftPostStatusDoesNotSerializeAsJsonBlock(): void
    {
        $adapter = new LambMicropubAdapter();
        $data = [
            'type' => ['h-entry'],
            'properties' => [
                'content'     => ['A draft without json block'],
                'post-status' => ['draft'],
            ],
        ];
        $adapter->createCallback($data, []);
        $post = R::findOne('post', ' body = ? ', ['A draft without json block']);
        $this->assertNotNull($post);
        $this->assertStringNotContainsString('post-status', $post->body);
    }

    public function testCreateCallbackWithPublishedPostStatusSavesAsPublished(): void
    {
        $adapter = new LambMicropubAdapter();
        $data = [
            'type' => ['h-entry'],
            'properties' => [
                'content'     => ['A published post'],
                'post-status' => ['published'],
            ],
        ];
        $adapter->createCallback($data, []);
        $post = R::findOne('post', ' body = ? ', ['A published post']);
        $this->assertNotNull($post);
        $this->assertEmpty($post->draft);
    }

    public function testCreateCallbackReturnsInvalidRequestForMissingContent(): void
    {
        $adapter = new LambMicropubAdapter();
        $data = [
            'type' => ['h-entry'],
            'properties' => [],
        ];
        $result = $adapter->createCallback($data);
        $this->assertSame('invalid_request', $result);
    }

    public function testCreateCallbackReturnsInsufficientScopeWhenTokenLacksCreateScope(): void
    {
        $adapter = new LambMicropubAdapter();
        $adapter->user = [
            'me'    => ROOT_URL . '/',
            'scope' => ['read'],
        ];
        $data = [
            'type' => ['h-entry'],
            'properties' => [
                'content' => ['Testing a request with an unauthorized access token.'],
            ],
        ];
        $result = $adapter->createCallback($data, []);
        $this->assertInstanceOf(\Psr\Http\Message\ResponseInterface::class, $result);
        $this->assertSame(401, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertSame('insufficient_scope', $body['error']);
    }

    // --- updateCallback ---

    public function testUpdateCallbackReturnsFalseForUnknownUrl(): void
    {
        $adapter = new LambMicropubAdapter();
        $result = $adapter->updateCallback(ROOT_URL . '/status/999999', ['replace' => ['content' => ['new']]]);
        $this->assertSame('invalid_request', $result);
    }

    public function testUpdateCallbackReplaceContentUpdatesBody(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'Original content';
        $bean->slug = '';
        $bean->created = date('Y-m-d H:i:s');
        $bean->updated = date('Y-m-d H:i:s');
        R::store($bean);

        $adapter = new LambMicropubAdapter();
        $result = $adapter->updateCallback(
            ROOT_URL . '/status/' . $bean->id,
            ['replace' => ['content' => ['Updated content']]]
        );

        $this->assertTrue($result);
        $updated = R::load('post', $bean->id);
        $this->assertSame('Updated content', $updated->body);
    }

    public function testUpdateCallbackReplaceContentPreservesTitle(): void
    {
        $bean = R::dispense('post');
        $bean->body = "---\ntitle: My Title\n---\nOriginal content";
        $bean->title = 'My Title';
        $bean->slug = 'my-title';
        $bean->created = date('Y-m-d H:i:s');
        $bean->updated = date('Y-m-d H:i:s');
        R::store($bean);

        $adapter = new LambMicropubAdapter();
        $adapter->updateCallback(
            ROOT_URL . '/status/' . $bean->id,
            ['replace' => ['content' => ['New body text']]]
        );

        $updated = R::load('post', $bean->id);
        $this->assertSame('My Title', $updated->title);
        $this->assertStringContainsString('New body text', $updated->body);
    }

    public function testUpdateCallbackReplaceContentPreservesHashtags(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'Original content #foo #bar';
        $bean->slug = '';
        $bean->created = date('Y-m-d H:i:s');
        $bean->updated = date('Y-m-d H:i:s');
        R::store($bean);

        $adapter = new LambMicropubAdapter();
        $adapter->updateCallback(
            ROOT_URL . '/status/' . $bean->id,
            ['replace' => ['content' => ['Replaced content']]]
        );

        $updated = R::load('post', $bean->id);
        $this->assertStringContainsString('Replaced content', $updated->body);
        $this->assertStringContainsString('#foo', $updated->body);
        $this->assertStringContainsString('#bar', $updated->body);
    }

    public function testUpdateCallbackAddCategoryAppendsHashtag(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'A categorised post #test1';
        $bean->slug = '';
        $bean->created = date('Y-m-d H:i:s');
        $bean->updated = date('Y-m-d H:i:s');
        R::store($bean);

        $adapter = new LambMicropubAdapter();
        $result = $adapter->updateCallback(
            ROOT_URL . '/status/' . $bean->id,
            ['add' => ['category' => ['test2']]]
        );

        $this->assertTrue($result);
        $updated = R::load('post', $bean->id);
        $this->assertStringContainsString('#test1', $updated->body);
        $this->assertStringContainsString('#test2', $updated->body);
    }

    public function testUpdateCallbackAddCategoryDoesNotDuplicate(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'A post #test1';
        $bean->slug = '';
        $bean->created = date('Y-m-d H:i:s');
        $bean->updated = date('Y-m-d H:i:s');
        R::store($bean);

        $adapter = new LambMicropubAdapter();
        $adapter->updateCallback(
            ROOT_URL . '/status/' . $bean->id,
            ['add' => ['category' => ['test1']]]
        );

        $updated = R::load('post', $bean->id);
        $this->assertSame(1, substr_count($updated->body, '#test1'));
    }

    public function testUpdateCallbackDeleteCategoryValueRemovesHashtag(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'A post #test1 #test2';
        $bean->slug = '';
        $bean->created = date('Y-m-d H:i:s');
        $bean->updated = date('Y-m-d H:i:s');
        R::store($bean);

        $adapter = new LambMicropubAdapter();
        $result = $adapter->updateCallback(
            ROOT_URL . '/status/' . $bean->id,
            ['delete' => ['category' => ['test2']]]
        );

        $this->assertTrue($result);
        $updated = R::load('post', $bean->id);
        $this->assertStringContainsString('#test1', $updated->body);
        $this->assertStringNotContainsString('#test2', $updated->body);
    }

    public function testUpdateCallbackDeleteCategoryValueLeavesOtherCategoriesIntact(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'Content #alpha #beta #gamma';
        $bean->slug = '';
        $bean->created = date('Y-m-d H:i:s');
        $bean->updated = date('Y-m-d H:i:s');
        R::store($bean);

        $adapter = new LambMicropubAdapter();
        $adapter->updateCallback(
            ROOT_URL . '/status/' . $bean->id,
            ['delete' => ['category' => ['beta']]]
        );

        $updated = R::load('post', $bean->id);
        $this->assertStringContainsString('#alpha', $updated->body);
        $this->assertStringNotContainsString('#beta', $updated->body);
        $this->assertStringContainsString('#gamma', $updated->body);
    }

    public function testUpdateCallbackDeletePropertyRemovesAllCategoryHashtags(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'A post with tags #test1 #test2';
        $bean->slug = '';
        $bean->created = date('Y-m-d H:i:s');
        $bean->updated = date('Y-m-d H:i:s');
        R::store($bean);

        $adapter = new LambMicropubAdapter();
        $result = $adapter->updateCallback(
            ROOT_URL . '/status/' . $bean->id,
            ['delete' => ['category']]
        );

        $this->assertTrue($result);
        $updated = R::load('post', $bean->id);
        $this->assertStringNotContainsString('#test1', $updated->body);
        $this->assertStringNotContainsString('#test2', $updated->body);
    }

    public function testUpdateCallbackDeletePropertyPreservesContent(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'Keep this content #test1 #test2';
        $bean->slug = '';
        $bean->created = date('Y-m-d H:i:s');
        $bean->updated = date('Y-m-d H:i:s');
        R::store($bean);

        $adapter = new LambMicropubAdapter();
        $adapter->updateCallback(
            ROOT_URL . '/status/' . $bean->id,
            ['delete' => ['category']]
        );

        $updated = R::load('post', $bean->id);
        $this->assertStringContainsString('Keep this content', $updated->body);
    }

    // --- deleteCallback ---

    public function testDeleteCallbackReturnsTrueForExistingPost(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'Post to delete';
        $bean->slug = '';
        $bean->created = date('Y-m-d H:i:s');
        $bean->updated = date('Y-m-d H:i:s');
        R::store($bean);

        $adapter = new LambMicropubAdapter();
        $result = $adapter->deleteCallback(ROOT_URL . '/status/' . $bean->id);

        $this->assertTrue($result);
    }

    public function testDeleteCallbackSetsDeletedFlag(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'Post to soft-delete';
        $bean->slug = '';
        $bean->created = date('Y-m-d H:i:s');
        $bean->updated = date('Y-m-d H:i:s');
        R::store($bean);

        $adapter = new LambMicropubAdapter();
        $adapter->deleteCallback(ROOT_URL . '/status/' . $bean->id);

        $updated = R::load('post', $bean->id);
        $this->assertSame(1, (int) $updated->deleted);
    }

    public function testDeleteCallbackReturnsInvalidRequestForUnknownUrl(): void
    {
        $adapter = new LambMicropubAdapter();
        $result = $adapter->deleteCallback(ROOT_URL . '/status/999999');
        $this->assertSame('invalid_request', $result);
    }

    // --- undeleteCallback ---

    public function testUndeleteCallbackClearsDeletedFlag(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'Post to restore';
        $bean->slug = '';
        $bean->deleted = 1;
        $bean->created = date('Y-m-d H:i:s');
        $bean->updated = date('Y-m-d H:i:s');
        R::store($bean);

        $adapter = new LambMicropubAdapter();
        $result = $adapter->undeleteCallback(ROOT_URL . '/status/' . $bean->id);

        $this->assertTrue($result);
        $updated = R::load('post', $bean->id);
        $this->assertEmpty($updated->deleted);
    }

    public function testUndeleteCallbackReturnsInvalidRequestForUnknownUrl(): void
    {
        $adapter = new LambMicropubAdapter();
        $result = $adapter->undeleteCallback(ROOT_URL . '/status/999999');
        $this->assertSame('invalid_request', $result);
    }

    // --- beanToMf2Properties (via sourceQueryCallback) ---

    public function testSourceQueryReturnsNamePropertyForTitledPost(): void
    {
        $bean = R::dispense('post');
        $bean->body  = "---\ntitle: My Title\n---\nSome content";
        $bean->title = 'My Title';
        $bean->slug  = 'my-title';
        $bean->created = date('Y-m-d H:i:s');
        $bean->updated = date('Y-m-d H:i:s');
        R::store($bean);

        $adapter = new LambMicropubAdapter();
        $result  = $adapter->sourceQueryCallback(ROOT_URL . '/my-title');
        $this->assertIsArray($result);
        $this->assertArrayHasKey('name', $result['properties']);
        $this->assertSame('My Title', $result['properties']['name'][0]);
    }

    public function testSourceQueryNamePropertyAbsentForUntitledPost(): void
    {
        $bean = R::dispense('post');
        $bean->body  = 'No front matter here';
        $bean->slug  = '';
        $bean->created = date('Y-m-d H:i:s');
        $bean->updated = date('Y-m-d H:i:s');
        R::store($bean);

        $adapter = new LambMicropubAdapter();
        $result  = $adapter->sourceQueryCallback(ROOT_URL . '/status/' . $bean->id);
        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('name', $result['properties']);
    }

    // --- extractContent (via createCallback) ---

    public function testCreateCallbackArrayContentWithValueKeyUsesValue(): void
    {
        $adapter = new LambMicropubAdapter();
        $data = [
            'type' => ['h-entry'],
            'properties' => [
                'content' => [['value' => 'Plain value content']],
            ],
        ];
        $result = $adapter->createCallback($data);
        $this->assertIsString($result);
        $post = R::findOne('post', ' body = ? ', ['Plain value content']);
        $this->assertNotNull($post);
        // No HTML in transformed — plain markdown path was used.
        $this->assertStringNotContainsString('&lt;', $post->transformed);
    }

    // --- findPostByUrl (root/empty path) ---

    public function testSourceQueryReturnsFalseForRootUrl(): void
    {
        $adapter = new LambMicropubAdapter();
        $result  = $adapter->sourceQueryCallback(ROOT_URL . '/');
        $this->assertFalse($result);
    }

    public function testConfigurationQueryCallbackReturnsSyndicateTo(): void
    {
        $adapter = new LambMicropubAdapter();
        $result = $adapter->configurationQueryCallback([]);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('syndicate-to', $result);
    }

    public function testConfigurationQueryCallbackReturnsMediaEndpoint(): void
    {
        $adapter = new LambMicropubAdapter();
        $result = $adapter->configurationQueryCallback([]);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('media-endpoint', $result);
        $this->assertStringContainsString('/micropub-media', $result['media-endpoint']);
    }

    public function testUpdateCallbackReturnsInsufficientScopeWhenTokenLacksUpdateScope(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'Some content';
        $bean->slug = '';
        $bean->created = date('Y-m-d H:i:s');
        $bean->updated = date('Y-m-d H:i:s');
        R::store($bean);

        $adapter = new LambMicropubAdapter();
        $adapter->user = [
            'me'    => ROOT_URL . '/',
            'scope' => ['create'],
        ];
        $result = $adapter->updateCallback(
            ROOT_URL . '/status/' . $bean->id,
            ['replace' => ['content' => ['New content']]]
        );

        $this->assertInstanceOf(\Psr\Http\Message\ResponseInterface::class, $result);
        $this->assertSame(401, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertSame('insufficient_scope', $body['error']);
    }
}

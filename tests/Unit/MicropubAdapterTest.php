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
}

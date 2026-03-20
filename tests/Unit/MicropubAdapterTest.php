<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;
use Lamb\Micropub\LambMicropubAdapter;

/**
 * Subclass that stubs out the HTTP token introspection call.
 */
class StubMicropubAdapter extends LambMicropubAdapter
{
    public ?array $stubResponse = null;

    protected function introspectToken(string $token, string $endpoint): ?array
    {
        return $this->stubResponse;
    }
}

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

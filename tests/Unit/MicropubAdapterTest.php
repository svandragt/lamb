<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;
use Lamb\Micropub\LambMicropubAdapter;

class MicropubAdapterTest extends TestCase
{
    private string $testToken = 'test-micropub-token-abc123';

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

        putenv("LAMB_MICROPUB_TOKEN={$this->testToken}");
    }

    protected function tearDown(): void
    {
        putenv('LAMB_MICROPUB_TOKEN=');
    }

    // --- verifyAccessTokenCallback ---

    public function testVerifyTokenReturnsFalseWhenEnvNotSet(): void
    {
        putenv('LAMB_MICROPUB_TOKEN=');
        $adapter = new LambMicropubAdapter();
        $result = $adapter->verifyAccessTokenCallback('any-token');
        $this->assertFalse($result);
    }

    public function testVerifyTokenReturnsFalseForWrongToken(): void
    {
        $adapter = new LambMicropubAdapter();
        $result = $adapter->verifyAccessTokenCallback('wrong-token');
        $this->assertFalse($result);
    }

    public function testVerifyTokenReturnsUserDataForValidToken(): void
    {
        $adapter = new LambMicropubAdapter();
        $result = $adapter->verifyAccessTokenCallback($this->testToken);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('me', $result);
        $this->assertArrayHasKey('scope', $result);
    }

    public function testVerifyTokenScopeIncludesCreate(): void
    {
        $adapter = new LambMicropubAdapter();
        $result = $adapter->verifyAccessTokenCallback($this->testToken);
        $this->assertContains('create', $result['scope']);
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

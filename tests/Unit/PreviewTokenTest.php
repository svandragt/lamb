<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;
use Lamb\Micropub\LambMicropubAdapter;

use function Lamb\preview_token_valid;
use function Lamb\Response\respond_post;
use function Lamb\Response\respond_status;

class PreviewTokenTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);

        // Seed schema columns so WHERE/visibility filters work regardless of test order.
        $schema = R::dispense('post');
        $schema->draft                 = 0;
        $schema->deleted               = 0;
        $schema->created               = date('Y-m-d H:i:s');
        $schema->slug                  = '';
        $schema->preview_token         = '';
        $schema->preview_token_expires = '';
        R::store($schema);
        R::exec('DELETE FROM post');

        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'http://localhost');
        }

        global $config;
        $config = ['site_title' => 'Test Blog', 'posts_per_page' => 10];

        $_SESSION = [];
        $_GET = [];
    }

    protected function tearDown(): void
    {
        R::exec('DELETE FROM post');
        $_SESSION = [];
        $_GET = [];
    }

    private function makePost(array $fields): \RedBeanPHP\OODBBean
    {
        $bean = R::dispense('post');
        $bean->body                  = $fields['body'] ?? 'Body';
        $bean->title                 = $fields['title'] ?? 'Title';
        $bean->transformed           = '<p>Body</p>';
        $bean->version               = 1;
        $bean->draft                 = $fields['draft'] ?? 0;
        $bean->deleted               = $fields['deleted'] ?? 0;
        $bean->slug                  = $fields['slug'] ?? '';
        $bean->created               = $fields['created'] ?? date('Y-m-d H:i:s');
        $bean->preview_token         = $fields['preview_token'] ?? '';
        $bean->preview_token_expires = $fields['preview_token_expires'] ?? '';
        R::store($bean);
        return $bean;
    }

    // ---- preview_token_valid() predicate -----------------------------------

    public function testValidUnexpiredTokenOnDraftIsAccepted(): void
    {
        $bean = $this->makePost([
            'draft'                 => 1,
            'preview_token'         => 'secret-token',
            'preview_token_expires' => date('Y-m-d H:i:s', time() + 3600),
        ]);
        $this->assertTrue(preview_token_valid($bean, 'secret-token'));
    }

    public function testWrongTokenIsRejected(): void
    {
        $bean = $this->makePost([
            'draft'                 => 1,
            'preview_token'         => 'secret-token',
            'preview_token_expires' => date('Y-m-d H:i:s', time() + 3600),
        ]);
        $this->assertFalse(preview_token_valid($bean, 'wrong-token'));
    }

    public function testMissingTokenIsRejected(): void
    {
        $bean = $this->makePost([
            'draft'                 => 1,
            'preview_token'         => 'secret-token',
            'preview_token_expires' => date('Y-m-d H:i:s', time() + 3600),
        ]);
        $this->assertFalse(preview_token_valid($bean, null));
        $this->assertFalse(preview_token_valid($bean, ''));
    }

    public function testPostWithoutStoredTokenRejectsEmptyToken(): void
    {
        $bean = $this->makePost(['draft' => 1]);
        $this->assertFalse(preview_token_valid($bean, ''), 'Empty stored token must not match empty supplied token');
        $this->assertFalse(preview_token_valid($bean, null));
    }

    public function testExpiredTokenIsRejected(): void
    {
        $bean = $this->makePost([
            'draft'                 => 1,
            'preview_token'         => 'secret-token',
            'preview_token_expires' => date('Y-m-d H:i:s', time() - 60),
        ]);
        $this->assertFalse(preview_token_valid($bean, 'secret-token'));
    }

    public function testDeletedPostRejectsValidToken(): void
    {
        $bean = $this->makePost([
            'draft'                 => 1,
            'deleted'               => 1,
            'preview_token'         => 'secret-token',
            'preview_token_expires' => date('Y-m-d H:i:s', time() + 3600),
        ]);
        $this->assertFalse(preview_token_valid($bean, 'secret-token'));
    }

    // ---- respond_status (/status/<id>?preview=…) ---------------------------

    public function testRespondStatusShowsDraftWithValidToken(): void
    {
        $bean = $this->makePost([
            'draft'                 => 1,
            'preview_token'         => 'secret-token',
            'preview_token_expires' => date('Y-m-d H:i:s', time() + 3600),
        ]);
        $_GET['preview'] = 'secret-token';
        $data = respond_status([$bean->id]);
        $this->assertArrayHasKey('posts', $data);
        $this->assertCount(1, $data['posts']);
    }

    public function testRespondStatusHidesDraftWithWrongToken(): void
    {
        $bean = $this->makePost([
            'draft'                 => 1,
            'preview_token'         => 'secret-token',
            'preview_token_expires' => date('Y-m-d H:i:s', time() + 3600),
        ]);
        $_GET['preview'] = 'wrong-token';
        $data = respond_status([$bean->id]);
        $this->assertSame('404', $data['action'] ?? null);
    }

    public function testRespondStatusHidesDraftWithExpiredToken(): void
    {
        $bean = $this->makePost([
            'draft'                 => 1,
            'preview_token'         => 'secret-token',
            'preview_token_expires' => date('Y-m-d H:i:s', time() - 60),
        ]);
        $_GET['preview'] = 'secret-token';
        $data = respond_status([$bean->id]);
        $this->assertSame('404', $data['action'] ?? null);
    }

    // ---- respond_post (slug?preview=…) --------------------------------------

    public function testRespondPostShowsDraftWithValidTokenBySlug(): void
    {
        $this->makePost([
            'draft'                 => 1,
            'slug'                  => 'my-preview-draft',
            'preview_token'         => 'secret-token',
            'preview_token_expires' => date('Y-m-d H:i:s', time() + 3600),
        ]);
        $_GET['preview'] = 'secret-token';
        $data = respond_post(['my-preview-draft']);
        $this->assertArrayHasKey('posts', $data);
        $this->assertCount(1, $data['posts']);
    }

    public function testPostHasSlugResolvesDraftWithValidToken(): void
    {
        $this->makePost([
            'draft'                 => 1,
            'slug'                  => 'routed-preview-draft',
            'preview_token'         => 'secret-token',
            'preview_token_expires' => date('Y-m-d H:i:s', time() + 3600),
        ]);

        $this->assertSame('', \Lamb\post_has_slug('routed-preview-draft'), 'Draft slug must not resolve without token');

        $_GET['preview'] = 'secret-token';
        $this->assertSame(
            'routed-preview-draft',
            \Lamb\post_has_slug('routed-preview-draft'),
            'Draft slug resolves when a valid preview token is supplied'
        );
    }

    // ---- Micropub createCallback Location URL --------------------------------

    public function testCreateCallbackAppendsPreviewTokenForDraft(): void
    {
        $adapter = new LambMicropubAdapter();
        $data = [
            'type' => ['h-entry'],
            'properties' => [
                'content'     => ['A tokenised draft'],
                'post-status' => ['draft'],
            ],
        ];
        $location = $adapter->createCallback($data, []);
        $this->assertIsString($location);

        $post = R::findOne('post', ' body = ? ', ['A tokenised draft']);
        $this->assertNotNull($post);
        $this->assertNotEmpty($post->preview_token, 'Draft created via Micropub gets a preview token');
        $this->assertStringContainsString('?preview=' . $post->preview_token, $location);

        $expires = strtotime($post->preview_token_expires);
        $this->assertGreaterThan(time(), $expires, 'Preview token expiry is in the future');
        $this->assertLessThanOrEqual(time() + 86400, $expires, 'Preview token expires within 24 hours');
    }

    public function testCreateCallbackAppendsPreviewTokenForScheduledPost(): void
    {
        $adapter = new LambMicropubAdapter();
        $data = [
            'type' => ['h-entry'],
            'properties' => [
                'content'   => ['A tokenised scheduled post'],
                'published' => [date('c', time() + 86400)],
            ],
        ];
        $location = $adapter->createCallback($data, []);
        $this->assertIsString($location);

        $post = R::findOne('post', ' body LIKE ? ', ['%A tokenised scheduled post%']);
        $this->assertNotNull($post);
        $this->assertNotEmpty($post->preview_token, 'Scheduled post created via Micropub gets a preview token');
        $this->assertStringContainsString('?preview=' . $post->preview_token, $location);
    }

    public function testCreateCallbackReturnsPlainPermalinkForPublishedPost(): void
    {
        $adapter = new LambMicropubAdapter();
        $data = [
            'type' => ['h-entry'],
            'properties' => [
                'content' => ['A plain published post'],
            ],
        ];
        $location = $adapter->createCallback($data, []);
        $this->assertIsString($location);
        $this->assertStringNotContainsString('preview=', $location);

        $post = R::findOne('post', ' body = ? ', ['A plain published post']);
        $this->assertNotNull($post);
        $this->assertEmpty($post->preview_token, 'Published posts get no preview token');
    }
}

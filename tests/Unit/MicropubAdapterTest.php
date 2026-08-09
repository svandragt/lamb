<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\OODBBean;
use RedBeanPHP\R;
use Lamb\Micropub\LambMicropubAdapter;
use Tests\Support\StubMicropubAdapter;

use function Lamb\Micropub\has_micropub_scope;

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
        // Config\load() always provides site_title in production; respond_home()
        // reads it unguarded, so seed it here rather than relying on a sibling
        // test having populated the global $config first.
        $config['site_title'] = $config['site_title'] ?? 'Test Blog';
        // Token verification compares the token's `me` against the configured
        // canonical URL, never the request host, so a Micropub install must set
        // site_url. Seed it to match ROOT_URL for the happy-path tests.
        $config['site_url'] = 'http://localhost';

        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'http://localhost');
        }
        if (!defined('ROOT_DIR')) {
            define('ROOT_DIR', sys_get_temp_dir() . '/lamb_test');
        }

        // Route any diagnostic logging to an isolated temp file (see mp_log_path()).
        $path = tempnam(sys_get_temp_dir(), 'lamb_mplog_');
        if ($path !== false) {
            // tempnam creates the file; start each test from a clean slate.
            @unlink($path);
            $GLOBALS['lamb_mp_log_path'] = $path;
        }
    }

    protected function tearDown(): void
    {
        global $config;
        unset($config['micropub_debug'], $config['site_url']);
        if (isset($GLOBALS['lamb_mp_log_path'])) {
            @unlink($GLOBALS['lamb_mp_log_path']);
            unset($GLOBALS['lamb_mp_log_path']);
        }
    }

    // --- handleRequest ---

    public function testConfigQueryRequiresAccessToken(): void
    {
        // W3C Micropub §3.7 requires a token on the query endpoint; q=config must
        // not be a special-cased exception, or it discloses the media-endpoint URL
        // and every configured syndicate-to target to anyone (#534).
        $adapter = new StubMicropubAdapter();

        $request = new \Nyholm\Psr7\ServerRequest(
            'GET',
            ROOT_URL . '/micropub?q=config'
        );
        $request = $request->withQueryParams(['q' => 'config']);

        $response = $adapter->handleRequest($request);
        $this->assertSame(401, $response->getStatusCode());
    }

    public function testConfigQueryRespondsWithAnyValidToken(): void
    {
        // Same rule as q=source/q=syndicate-to: any verified token may read
        // q=config, regardless of its scope.
        $adapter = new StubMicropubAdapter();
        $adapter->stubResponse = [
            'me'    => ROOT_URL . '/',
            'scope' => 'create',
        ];

        $request = new \Nyholm\Psr7\ServerRequest(
            'GET',
            ROOT_URL . '/micropub?q=config',
            ['Authorization' => 'Bearer valid-jwt']
        );
        $request = $request->withQueryParams(['q' => 'config']);

        $response = $adapter->handleRequest($request);
        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true);
        $this->assertArrayHasKey('media-endpoint', $body);
        $this->assertArrayHasKey('syndicate-to', $body);
    }

    // --- diagnostic logging (micropub_debug) ---

    public function testMpLogWritesWhenDebugEnabled(): void
    {
        global $config;
        $config['micropub_debug'] = true;

        \Lamb\Micropub\mp_log('unit_test_event', ['foo' => 'bar']);

        $path = $GLOBALS['lamb_mp_log_path'];
        $this->assertFileExists($path);
        $contents = (string) file_get_contents($path);
        $this->assertStringContainsString('unit_test_event', $contents);
        $this->assertStringContainsString('bar', $contents);
    }

    public function testMpLogWritesNothingWhenDebugDisabled(): void
    {
        global $config;
        $config['micropub_debug'] = false;

        \Lamb\Micropub\mp_log('should_not_appear', []);

        $path = $GLOBALS['lamb_mp_log_path'];
        $this->assertFalse(
            is_file($path) && str_contains((string) file_get_contents($path), 'should_not_appear'),
            'mp_log must write nothing when micropub_debug is disabled'
        );
    }

    public function testVerifyTokenLogsMeMismatchReasonWithoutLeakingToken(): void
    {
        global $config;
        $config['micropub_debug'] = true;

        $adapter = new StubMicropubAdapter();
        $adapter->stubResponse = [
            'me'    => 'https://other.example.com/',
            'scope' => 'create',
        ];
        $adapter->verifyAccessTokenCallback('secret-token-value');

        $contents = (string) file_get_contents($GLOBALS['lamb_mp_log_path']);
        $this->assertStringContainsString('me_mismatch', $contents);
        $this->assertStringContainsString('https://other.example.com/', $contents);
        $this->assertStringContainsString(ROOT_URL, $contents);
        $this->assertStringNotContainsString('secret-token-value', $contents);
    }

    public function testVerifyTokenLogsOkReasonWithScope(): void
    {
        global $config;
        $config['micropub_debug'] = true;

        $adapter = new StubMicropubAdapter();
        $adapter->stubResponse = [
            'me'    => ROOT_URL . '/',
            'scope' => 'create update',
        ];
        $adapter->verifyAccessTokenCallback('valid-jwt');

        $contents = (string) file_get_contents($GLOBALS['lamb_mp_log_path']);
        $this->assertStringContainsString('"reason":"ok"', $contents);
        $this->assertStringContainsString('create', $contents);
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

    public function testVerifyTokenRejectsIdentityMatchingOnlyTheRequestHost(): void
    {
        // The request host is attacker-chosen (a spoofed Host header reaches PHP
        // through any catch-all vhost), so a token whose `me` matches it must still
        // be refused when it does not match the configured canonical URL.
        global $config;
        $config['site_url'] = 'https://real-site.example';

        $adapter = new StubMicropubAdapter();
        $adapter->stubResponse = [
            'me'    => ROOT_URL . '/',
            'scope' => 'create',
        ];

        $this->assertFalse($adapter->verifyAccessTokenCallback('token-for-request-host'));
    }

    public function testVerifyTokenFailsClosedWhenNoSiteUrlIsConfigured(): void
    {
        global $config;
        unset($config['site_url']);

        $adapter = new StubMicropubAdapter();
        $adapter->stubResponse = [
            'me'    => ROOT_URL . '/',
            'scope' => 'create',
        ];

        $this->assertFalse($adapter->verifyAccessTokenCallback('any-token'));
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

    public function testSourceQueryPreservesBodyContainingHorizontalRules(): void
    {
        // A body whose content carries its own `---` (e.g. horizontal rules)
        // must be returned whole — not truncated to the slice after the last
        // `---` (issue: explode-based front-matter split dropped content).
        $bean = R::dispense('post');
        $bean->body = "First section\n\n---\n\nSecond section\n\n---\n\nThird section";
        $bean->slug = '';
        $bean->created = date('Y-m-d H:i:s');
        $bean->updated = date('Y-m-d H:i:s');
        R::store($bean);

        $adapter = new LambMicropubAdapter();
        $result = $adapter->sourceQueryCallback(ROOT_URL . '/status/' . $bean->id);
        $this->assertStringContainsString('First section', $result['properties']['content'][0]);
        $this->assertStringContainsString('Second section', $result['properties']['content'][0]);
        $this->assertStringContainsString('Third section', $result['properties']['content'][0]);
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

    // --- sourceQueryCallback visibility (regression: content disclosure) ---
    // A source query has no scope of its own, unlike create/update/delete —
    // without a visibility check, ANY valid token could read the full
    // content of any draft/scheduled/trashed post, not merely learn it
    // exists (sequential /status/<id> ids make this trivial to enumerate).

    private function makeHiddenPost(array $fields): OODBBean
    {
        $bean = R::dispense('post');
        $bean->body = $fields['body'] ?? 'Hidden content';
        $bean->slug = '';
        $bean->created = $fields['created'] ?? date('Y-m-d H:i:s');
        $bean->updated = date('Y-m-d H:i:s');
        $bean->draft = $fields['draft'] ?? 0;
        $bean->deleted = $fields['deleted'] ?? 0;
        R::store($bean);
        return $bean;
    }

    public function testSourceQueryReturnsFalseForDraftWithoutUpdateScope(): void
    {
        $bean = $this->makeHiddenPost(['draft' => 1]);

        $adapter = new LambMicropubAdapter();
        $adapter->user = ['me' => ROOT_URL . '/', 'scope' => ['create']];

        $result = $adapter->sourceQueryCallback(ROOT_URL . '/status/' . $bean->id);
        $this->assertFalse($result, 'a create-only token must not read a draft\'s content via source query');
    }

    public function testSourceQueryReturnsContentForDraftWithUpdateScope(): void
    {
        $bean = $this->makeHiddenPost(['draft' => 1, 'body' => 'Draft-only content']);

        $adapter = new LambMicropubAdapter();
        $adapter->user = ['me' => ROOT_URL . '/', 'scope' => ['update']];

        $result = $adapter->sourceQueryCallback(ROOT_URL . '/status/' . $bean->id);
        $this->assertIsArray($result);
        $this->assertStringContainsString('Draft-only content', $result['properties']['content'][0]);
    }

    public function testSourceQueryReturnsFalseForScheduledWithoutUpdateScope(): void
    {
        $bean = $this->makeHiddenPost(['created' => date('Y-m-d H:i:s', strtotime('+1 day'))]);

        $adapter = new LambMicropubAdapter();
        $adapter->user = ['me' => ROOT_URL . '/', 'scope' => ['create']];

        $result = $adapter->sourceQueryCallback(ROOT_URL . '/status/' . $bean->id);
        $this->assertFalse($result);
    }

    public function testSourceQueryReturnsFalseForTrashedWithoutUpdateScope(): void
    {
        $bean = $this->makeHiddenPost(['deleted' => 1]);

        $adapter = new LambMicropubAdapter();
        $adapter->user = ['me' => ROOT_URL . '/', 'scope' => ['create']];

        $result = $adapter->sourceQueryCallback(ROOT_URL . '/status/' . $bean->id);
        $this->assertFalse($result);
    }

    public function testSourceQueryReturnsContentForTrashedWithUpdateScope(): void
    {
        // update scope may still see a trashed post's source (e.g. to decide
        // whether to restore it via the separately delete-scoped undelete).
        $bean = $this->makeHiddenPost(['deleted' => 1, 'body' => 'Trashed content']);

        $adapter = new LambMicropubAdapter();
        $adapter->user = ['me' => ROOT_URL . '/', 'scope' => ['update']];

        $result = $adapter->sourceQueryCallback(ROOT_URL . '/status/' . $bean->id);
        $this->assertIsArray($result);
        $this->assertStringContainsString('Trashed content', $result['properties']['content'][0]);
    }

    public function testSourceQueryReturnsContentForPublishedPostRegardlessOfScope(): void
    {
        // A published post's content is already public — any valid token
        // may read it, matching pre-fix behaviour for the common case.
        $bean = $this->makeHiddenPost(['body' => 'Published content']);

        $adapter = new LambMicropubAdapter();
        $adapter->user = ['me' => ROOT_URL . '/', 'scope' => []];

        $result = $adapter->sourceQueryCallback(ROOT_URL . '/status/' . $bean->id);
        $this->assertIsArray($result);
        $this->assertStringContainsString('Published content', $result['properties']['content'][0]);
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

        // A real GIF: uploads whose bytes don't match their extension are
        // rejected, and GIF is stored as-is rather than re-encoded to WebP, so
        // the stored name keeps the extension this test asserts on.
        $bytes = self::gifBytes();
        $tmpFile = tempnam(sys_get_temp_dir(), 'micropub_test_');
        file_put_contents($tmpFile, $bytes);

        $uploadedFile = new \Nyholm\Psr7\UploadedFile(
            $tmpFile,
            strlen($bytes),
            UPLOAD_ERR_OK,
            'test-photo.gif',
            'image/gif'
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
        $this->assertMatchesRegularExpression('/!\[.*\]\(.+\.gif\)/', $post->body);
    }

    /**
     * A minimal valid 1x1 GIF, so upload fixtures carry bytes that really are
     * the image type their filename claims.
     */
    private static function gifBytes(): string
    {
        return (string) base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    }

    public function testUploadedPhotosWithSameClientFilenameDoNotCollide(): void
    {
        // Regression: the on-disk filename was sha1(client filename) with no
        // salt, so two uploads sharing a client filename in the same month
        // silently overwrote each other.
        $adapter = new LambMicropubAdapter();

        $makeUpload = function (): \Nyholm\Psr7\UploadedFile {
            $bytes = self::gifBytes();
            $tmpFile = tempnam(sys_get_temp_dir(), 'micropub_test_');
            file_put_contents($tmpFile, $bytes);
            return new \Nyholm\Psr7\UploadedFile(
                $tmpFile,
                strlen($bytes),
                UPLOAD_ERR_OK,
                'photo.gif',
                'image/gif'
            );
        };

        $first = $adapter->createCallback(
            ['type' => ['h-entry'], 'properties' => ['content' => ['First post']]],
            ['photo' => $makeUpload()]
        );
        $second = $adapter->createCallback(
            ['type' => ['h-entry'], 'properties' => ['content' => ['Second post']]],
            ['photo' => $makeUpload()]
        );

        preg_match('/!\[.*\]\((.+\.gif)\)/', R::findOne('post', ' body LIKE ? ', ['%First post%'])->body, $m1);
        preg_match('/!\[.*\]\((.+\.gif)\)/', R::findOne('post', ' body LIKE ? ', ['%Second post%'])->body, $m2);

        $this->assertNotEmpty($m1[1] ?? null);
        $this->assertNotEmpty($m2[1] ?? null);
        $this->assertNotSame($m1[1], $m2[1], 'uploads with the same client filename must not collide on disk');
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

    public function testCreateCallbackWithScheduledPostStatusIsNotMarkedDraft(): void
    {
        $adapter = new LambMicropubAdapter();
        $future = date('Y-m-d\TH:i:sP', strtotime('+1 day'));
        $data = [
            'type' => ['h-entry'],
            'properties' => [
                'content'     => ['A scheduled micropub post'],
                'post-status' => ['scheduled'],
                'published'   => [$future],
            ],
        ];
        $adapter->createCallback($data, []);
        $post = R::findOne('post', ' body = ? ', ['A scheduled micropub post']);
        $this->assertNotNull($post);
        $this->assertEmpty($post->draft, 'Scheduled posts must not be marked as drafts');
        $this->assertGreaterThan(date('Y-m-d H:i:s'), $post->created, 'Scheduled post keeps its future publish date');
    }

    public function testCreateCallbackWithFuturePublishedDateHidesFromHome(): void
    {
        $adapter = new LambMicropubAdapter();
        $future = date('Y-m-d\TH:i:sP', strtotime('+1 day'));
        $data = [
            'type' => ['h-entry'],
            'properties' => [
                'content'   => ['A future dated micropub post'],
                'published' => [$future],
            ],
        ];
        $adapter->createCallback($data, []);

        $result = \Lamb\Response\respond_home();
        $bodies = array_map(static fn($p) => $p->body, $result['posts']);
        $this->assertNotContains('A future dated micropub post', $bodies, 'Future-dated micropub posts must not appear on the homepage');
    }

    public function testCreateCallbackWithNonStringNameDropsTitleInsteadOf500(): void
    {
        // Regression for #533: a nested array `name` (a legitimate mf2 shape) used
        // to TypeError inside assembleFrontMatter(). matter_string() (#f40d5c5)
        // now reports it as absent, so the post still saves, just without a title.
        $adapter = new LambMicropubAdapter();
        $data = [
            'type' => ['h-entry'],
            'properties' => [
                'name' => [['a' => 'b']],
                'content' => ['Post body here.'],
            ],
        ];
        $result = $adapter->createCallback($data);
        $this->assertIsString($result);
        $post = R::findOne('post', ' body = ? ', ['Post body here.']);
        $this->assertNotNull($post);
        $this->assertEmpty($post->title);
    }

    public function testCreateCallbackWithNonStringPublishedIgnoresDateInsteadOf500(): void
    {
        // Regression for #533: a non-string `published` used to TypeError inside
        // strtotime(). normalize_datetime() (#f40d5c5) now returns null for it, so
        // the post still saves with its default created date instead of crashing.
        $adapter = new LambMicropubAdapter();
        $data = [
            'type' => ['h-entry'],
            'properties' => [
                'published' => [['a' => 'b']],
                'content' => ['Post body here.'],
            ],
        ];
        $result = $adapter->createCallback($data);
        $this->assertIsString($result);
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
        // RFC 6750 §3.1 and the W3C Micropub spec both map insufficient_scope to 403
        // (a valid token lacking the required scope), not 401.
        $this->assertSame(403, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertSame('insufficient_scope', $body['error']);
        // The 403 still carries a Bearer challenge naming the error and the scope
        // required for the action (RFC 6750 §3).
        $this->assertSame(
            'Bearer error="insufficient_scope", scope="create"',
            $result->getHeaderLine('WWW-Authenticate')
        );
    }

    // --- updateCallback ---

    public function testUpdateCallbackReturnsFalseForUnknownUrl(): void
    {
        $adapter = new LambMicropubAdapter();
        $result = $adapter->updateCallback(ROOT_URL . '/status/999999', ['replace' => ['content' => ['new']]]);
        $this->assertSame('invalid_request', $result);
    }

    public function testUpdateCallbackReturnsInvalidRequestForTrashedPost(): void
    {
        // Regression: a soft-deleted post is meant to stay immutable until
        // restored via the (delete-scoped) undeleteCallback() — an
        // update-scoped token must not be able to silently rewrite trashed
        // content while it stays hidden, and the response must be
        // indistinguishable from "no such post".
        $bean = R::dispense('post');
        $bean->body = 'Trashed content must not change';
        $bean->slug = '';
        $bean->deleted = 1;
        $bean->created = date('Y-m-d H:i:s');
        $bean->updated = date('Y-m-d H:i:s');
        R::store($bean);

        $adapter = new LambMicropubAdapter();
        $adapter->user = ['me' => ROOT_URL . '/', 'scope' => ['update']];

        $result = $adapter->updateCallback(
            ROOT_URL . '/status/' . $bean->id,
            ['replace' => ['content' => ['Attempted overwrite']]]
        );

        $this->assertSame('invalid_request', $result);
        $updated = R::load('post', $bean->id);
        $this->assertSame('Trashed content must not change', $updated->body);
    }

    public function testUpdateCallbackReturnsInvalidRequestForNonArrayReplaceValues(): void
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
            ['replace' => ['content' => 'Updated content']]
        );

        $this->assertSame('invalid_request', $result);
        $updated = R::load('post', $bean->id);
        $this->assertSame('Original content', $updated->body);
    }

    public function testUpdateCallbackReturnsInvalidRequestForNonArrayAddValues(): void
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
            ['add' => ['category' => 'not-an-array']]
        );

        $this->assertSame('invalid_request', $result);
        $updated = R::load('post', $bean->id);
        $this->assertSame('Original content', $updated->body);
    }

    public function testUpdateCallbackReturnsInvalidRequestForNonArrayDeleteValues(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'Original content #tag';
        $bean->slug = '';
        $bean->created = date('Y-m-d H:i:s');
        $bean->updated = date('Y-m-d H:i:s');
        R::store($bean);

        $adapter = new LambMicropubAdapter();
        $result = $adapter->updateCallback(
            ROOT_URL . '/status/' . $bean->id,
            ['delete' => ['category' => 'not-an-array']]
        );

        $this->assertSame('invalid_request', $result);
        $updated = R::load('post', $bean->id);
        $this->assertSame('Original content #tag', $updated->body);
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

    public function testDeleteCallbackEnqueuesWebmentionResend(): void
    {
        // The Micropub delete path shares soft_delete_post(), so deleting a post
        // that previously sent webmentions re-queues them for re-send (#331).
        $bean = R::dispense('post');
        $bean->body = 'Has a sent webmention';
        $bean->slug = '';
        $bean->created = date('Y-m-d H:i:s');
        $bean->updated = date('Y-m-d H:i:s');
        $postId = (int) R::store($bean);

        $row = R::dispense('webmentionoutbox');
        $row->post_id = $postId;
        $row->source = ROOT_URL . '/status/' . $postId;
        $row->target = 'https://other.example/a';
        $row->status = 'sent';
        $row->created = date('Y-m-d H:i:s');
        $rowId = (int) R::store($row);

        $adapter = new LambMicropubAdapter();
        $adapter->deleteCallback(ROOT_URL . '/status/' . $postId);

        $updated = R::load('webmentionoutbox', $rowId);
        $this->assertSame('pending', $updated->status);
        $this->assertEquals(1, $updated->resend);
    }

    public function testDeleteCallbackReturnsInvalidRequestForUnknownUrl(): void
    {
        $adapter = new LambMicropubAdapter();
        $result = $adapter->deleteCallback(ROOT_URL . '/status/999999');
        $this->assertSame('invalid_request', $result);
    }

    public function testDeleteCallbackReturnsInsufficientScopeWhenTokenLacksDeleteScope(): void
    {
        // Regression: deleteCallback() previously had no scope check at all,
        // so any valid token — regardless of granted scope — could delete
        // arbitrary posts.
        $bean = R::dispense('post');
        $bean->body = 'Should survive an unscoped delete attempt';
        $bean->slug = '';
        $bean->created = date('Y-m-d H:i:s');
        $bean->updated = date('Y-m-d H:i:s');
        R::store($bean);

        $adapter = new LambMicropubAdapter();
        $adapter->user = ['me' => ROOT_URL . '/', 'scope' => ['create']];

        $result = $adapter->deleteCallback(ROOT_URL . '/status/' . $bean->id);

        $this->assertInstanceOf(\Psr\Http\Message\ResponseInterface::class, $result);
        $this->assertSame(403, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertSame('insufficient_scope', $body['error']);

        $updated = R::load('post', $bean->id);
        $this->assertEmpty($updated->deleted, 'the post must not have been deleted');
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

    public function testUndeleteCallbackReturnsInsufficientScopeWhenTokenLacksDeleteScope(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'Should stay deleted against an unscoped undelete attempt';
        $bean->slug = '';
        $bean->deleted = 1;
        $bean->created = date('Y-m-d H:i:s');
        $bean->updated = date('Y-m-d H:i:s');
        R::store($bean);

        $adapter = new LambMicropubAdapter();
        $adapter->user = ['me' => ROOT_URL . '/', 'scope' => ['create']];

        $result = $adapter->undeleteCallback(ROOT_URL . '/status/' . $bean->id);

        $this->assertInstanceOf(\Psr\Http\Message\ResponseInterface::class, $result);
        $this->assertSame(403, $result->getStatusCode());

        $updated = R::load('post', $bean->id);
        $this->assertEquals(1, $updated->deleted, 'the post must still be deleted');
    }

    // --- scope gating across the callbacks ---

    /**
     * Stores a post and returns an adapter whose token carries only 'read' —
     * a real scope, but never the one a scope-gated callback asks for.
     *
     * @return array{0: LambMicropubAdapter, 1: string} The adapter and the post URL.
     */
    private function readOnlyTokenFor(string $body, bool $deleted = false): array
    {
        $bean = R::dispense('post');
        $bean->body    = $body;
        $bean->slug    = '';
        $bean->deleted = $deleted ? 1 : null;
        $bean->created = date('Y-m-d H:i:s');
        $bean->updated = date('Y-m-d H:i:s');
        R::store($bean);

        $adapter = new LambMicropubAdapter();
        $adapter->user = ['me' => ROOT_URL . '/', 'scope' => ['read']];

        return [$adapter, ROOT_URL . '/status/' . $bean->id];
    }

    private function assertChallengeNamesScope(mixed $result, string $scope): void
    {
        $this->assertInstanceOf(\Psr\Http\Message\ResponseInterface::class, $result);
        $this->assertSame(403, $result->getStatusCode());
        $this->assertStringContainsString(
            'scope="' . $scope . '"',
            $result->getHeaderLine('www-authenticate')
        );
    }

    public function testDeleteChallengeNamesTheDeleteScope(): void
    {
        [$adapter, $url] = $this->readOnlyTokenFor('Not deletable by a read token');
        $this->assertChallengeNamesScope($adapter->deleteCallback($url), 'delete');
    }

    public function testUndeleteChallengeNamesTheDeleteScope(): void
    {
        [$adapter, $url] = $this->readOnlyTokenFor('Not restorable by a read token', deleted: true);
        $this->assertChallengeNamesScope($adapter->undeleteCallback($url), 'delete');
    }

    public function testUpdateChallengeNamesTheUpdateScope(): void
    {
        [$adapter, $url] = $this->readOnlyTokenFor('Not editable by a read token');
        $result = $adapter->updateCallback($url, ['replace' => ['content' => ['nope']]]);
        $this->assertChallengeNamesScope($result, 'update');
    }

    public function testScopeGatedCallbackAllowsAnUnauthenticatedCall(): void
    {
        // No token at all (the logged-in web paths): the scope gate must not
        // fire, otherwise every non-Micropub caller would 403.
        $bean = R::dispense('post');
        $bean->body    = 'Deletable without a token';
        $bean->slug    = '';
        $bean->created = date('Y-m-d H:i:s');
        $bean->updated = date('Y-m-d H:i:s');
        R::store($bean);

        $adapter = new LambMicropubAdapter();
        $this->assertTrue($adapter->deleteCallback(ROOT_URL . '/status/' . $bean->id));
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

    public function testConfigurationQueryCallbackReturnsQParameter(): void
    {
        $adapter = new LambMicropubAdapter();
        $result = $adapter->configurationQueryCallback([]);
        $this->assertIsArray($result);
        $this->assertArrayHasKey('q', $result);
        $this->assertContains('config', $result['q']);
        $this->assertContains('source', $result['q']);
        $this->assertContains('syndicate-to', $result['q']);
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
        $this->assertSame(403, $result->getStatusCode());
        $body = json_decode((string) $result->getBody(), true);
        $this->assertSame('insufficient_scope', $body['error']);
        $this->assertSame(
            'Bearer error="insufficient_scope", scope="update"',
            $result->getHeaderLine('WWW-Authenticate')
        );
    }

    // --- bearer_challenge (RFC 6750 §3 WWW-Authenticate value) ---

    public function testBearerChallengeWithNoArgumentsOmitsErrorCode(): void
    {
        // RFC 6750 §3.1: a request lacking any credentials SHOULD NOT carry an error code.
        $this->assertSame('Bearer', \Lamb\Micropub\bearer_challenge());
    }

    public function testBearerChallengeWithErrorAndDescription(): void
    {
        $this->assertSame(
            'Bearer error="invalid_token", error_description="The access token is invalid or expired."',
            \Lamb\Micropub\bearer_challenge('invalid_token', null, 'The access token is invalid or expired.')
        );
    }

    public function testBearerChallengeWithErrorAndScope(): void
    {
        $this->assertSame(
            'Bearer error="insufficient_scope", scope="create"',
            \Lamb\Micropub\bearer_challenge('insufficient_scope', 'create')
        );
    }

    // --- syndication config and create ---

    public function testConfigurationQueryCallbackReturnsSyndicateToTargetsFromConfig(): void
    {
        global $config;
        $config['syndicate_to'] = ['https://bsky.app/profile/me' => 'Bluesky'];

        $adapter = new LambMicropubAdapter();
        $result = $adapter->configurationQueryCallback([]);

        $this->assertCount(1, $result['syndicate-to']);
        $this->assertSame('https://bsky.app/profile/me', $result['syndicate-to'][0]['uid']);
        $this->assertSame('Bluesky', $result['syndicate-to'][0]['name']);

        unset($config['syndicate_to']);
    }

    public function testConfigurationQueryCallbackReturnsEmptySyndicateToWhenNoConfig(): void
    {
        global $config;
        unset($config['syndicate_to']);

        $adapter = new LambMicropubAdapter();
        $result = $adapter->configurationQueryCallback([]);

        $this->assertSame([], $result['syndicate-to']);
    }

    public function testCreateCallbackSetsSyndicatedToWhenMpSyndicateToProvided(): void
    {
        $adapter = new LambMicropubAdapter();
        $data = [
            'type'       => ['h-entry'],
            'properties' => [
                'content'        => ['Post with syndication target'],
                'mp-syndicate-to' => ['https://bsky.app/profile/me'],
            ],
        ];
        $adapter->createCallback($data);

        $post = R::findOne('post', ' body LIKE ? ', ['%syndicated-to%']);
        $this->assertNotNull($post, 'Expected a post with syndicated-to in the body');
        $this->assertSame('https://bsky.app/profile/me', $post->syndicated_to);
    }

    public function testCreateCallbackMpSyndicateToNotStoredInJsonBlock(): void
    {
        $adapter = new LambMicropubAdapter();
        $data = [
            'type'       => ['h-entry'],
            'properties' => [
                'content'        => ['Post without json block please'],
                'mp-syndicate-to' => ['https://bsky.app/profile/me'],
            ],
        ];
        $adapter->createCallback($data);

        $post = R::findOne('post', ' body LIKE ? ', ['%Post without json block please%']);
        $this->assertNotNull($post);
        $this->assertStringNotContainsString('```json', $post->body);
    }

    public function testUpdateCallbackPreservesSyndicatedTo(): void
    {
        $adapter = new LambMicropubAdapter();
        $adapter->user = ['me' => ROOT_URL . '/', 'scope' => ['create', 'update']];

        $createData = [
            'type'       => ['h-entry'],
            'properties' => [
                'content'         => ['Original content'],
                'mp-syndicate-to' => ['https://bsky.app/profile/me'],
            ],
        ];
        $location = $adapter->createCallback($createData);
        $this->assertIsString($location);

        $adapter->updateCallback($location, [
            'replace' => ['content' => ['Updated content']],
        ]);

        $post = R::findOne('post', ' body LIKE ? ', ['%syndicated-to%']);
        $this->assertNotNull($post, 'syndicated-to must survive a content update');
        $this->assertSame('https://bsky.app/profile/me', $post->syndicated_to);
    }

    // --- in-reply-to (create / source / update) ---

    /**
     * Store a reply post and return its bean.
     */
    private function storeReply(string $body): OODBBean
    {
        $bean = R::dispense('post');
        $bean->body = $body;
        $bean->slug = '';
        $bean->created = date('Y-m-d H:i:s');
        $bean->updated = date('Y-m-d H:i:s');
        \Lamb\parse_bean($bean);
        R::store($bean);

        return $bean;
    }

    public function testCreateCallbackAcceptsInReplyToHCiteObject(): void
    {
        // Micropub allows in-reply-to to be a nested h-cite rather than a bare
        // URL; the target has to come out of its `url` property, not be dropped.
        $adapter = new LambMicropubAdapter();
        $adapter->createCallback([
            'type'       => ['h-entry'],
            'properties' => [
                'content'     => ['Replying to an h-cite'],
                'in-reply-to' => [[
                    'type'       => ['h-cite'],
                    'properties' => [
                        'url'  => ['https://other.example/their-post'],
                        'name' => ['Their post'],
                    ],
                ]],
            ],
        ]);

        $post = R::findOne('post', ' body LIKE ? ', ['%Replying to an h-cite%']);
        $this->assertNotNull($post);
        $this->assertSame('https://other.example/their-post', $post->in_reply_to);
    }

    public function testCreateCallbackDoesNotDumpInReplyToAsExtraProperties(): void
    {
        // in-reply-to is consumed into front matter, so it must not also land in
        // the body as a JSON code block of "unrecognised" properties.
        $adapter = new LambMicropubAdapter();
        $adapter->createCallback([
            'type'       => ['h-entry'],
            'properties' => [
                'content'     => ['No json block please'],
                'in-reply-to' => ['https://other.example/their-post'],
            ],
        ]);

        $post = R::findOne('post', ' body LIKE ? ', ['%No json block please%']);
        $this->assertNotNull($post);
        $this->assertStringNotContainsString('```json', $post->body);
        $this->assertSame('https://other.example/their-post', $post->in_reply_to);
    }

    public function testSourceQueryReturnsInReplyTo(): void
    {
        $bean = $this->storeReply("---\nin-reply-to: https://other.example/post\n---\nSource reply content");

        $adapter = new LambMicropubAdapter();
        $result = $adapter->sourceQueryCallback(ROOT_URL . '/status/' . $bean->id);

        $this->assertSame(['https://other.example/post'], $result['properties']['in-reply-to']);
    }

    public function testSourceQueryOmitsInReplyToForNormalPost(): void
    {
        $bean = $this->storeReply('An ordinary post');

        $adapter = new LambMicropubAdapter();
        $result = $adapter->sourceQueryCallback(ROOT_URL . '/status/' . $bean->id);

        $this->assertArrayNotHasKey('in-reply-to', $result['properties']);
    }

    public function testUpdateReplaceInReplyToSetsTarget(): void
    {
        $bean = $this->storeReply('Turning this into a reply');

        $adapter = new LambMicropubAdapter();
        $result = $adapter->updateCallback(
            ROOT_URL . '/status/' . $bean->id,
            ['replace' => ['in-reply-to' => ['https://other.example/post']]]
        );

        $this->assertTrue($result);
        $updated = R::load('post', $bean->id);
        $this->assertSame('https://other.example/post', $updated->in_reply_to);
        $this->assertStringContainsString('Turning this into a reply', $updated->body);
    }

    public function testUpdateReplaceInReplyToAcceptsHCiteObject(): void
    {
        $bean = $this->storeReply('Reply target as h-cite');

        $adapter = new LambMicropubAdapter();
        $adapter->updateCallback(
            ROOT_URL . '/status/' . $bean->id,
            ['replace' => ['in-reply-to' => [[
                'type'       => ['h-cite'],
                'properties' => ['url' => ['https://other.example/post']],
            ]]]]
        );

        $updated = R::load('post', $bean->id);
        $this->assertSame('https://other.example/post', $updated->in_reply_to);
    }

    public function testUpdateReplaceInReplyToPreservesUnrelatedFrontMatter(): void
    {
        $bean = $this->storeReply("---\ntitle: Kept Title\nslug: kept-slug\n---\nBody stays");

        $adapter = new LambMicropubAdapter();
        $adapter->updateCallback(
            ROOT_URL . '/kept-slug',
            ['replace' => ['in-reply-to' => ['https://other.example/post']]]
        );

        $updated = R::load('post', $bean->id);
        $this->assertSame('https://other.example/post', $updated->in_reply_to);
        $this->assertSame('Kept Title', $updated->title);
        $this->assertSame('kept-slug', $updated->slug);
        $this->assertStringContainsString('Body stays', $updated->body);
    }

    public function testUpdateReplaceInReplyToRejectsValueCarryingNoUrl(): void
    {
        // An h-cite with no url is not a reply target; accepting it would either
        // clear the existing one or report a change that never happened.
        $bean = $this->storeReply("---\nin-reply-to: https://other.example/post\n---\nHi");

        $adapter = new LambMicropubAdapter();
        $result = $adapter->updateCallback(
            ROOT_URL . '/status/' . $bean->id,
            ['replace' => ['in-reply-to' => [[
                'type'       => ['h-cite'],
                'properties' => ['name' => ['No url here']],
            ]]]]
        );

        $this->assertSame('invalid_request', $result);
        $updated = R::load('post', $bean->id);
        $this->assertSame('https://other.example/post', $updated->in_reply_to);
    }

    public function testUpdateReplaceInReplyToWithEmptyValueClearsTarget(): void
    {
        $bean = $this->storeReply("---\nin-reply-to: https://other.example/post\n---\nNo longer a reply");

        $adapter = new LambMicropubAdapter();
        $result = $adapter->updateCallback(
            ROOT_URL . '/status/' . $bean->id,
            ['replace' => ['in-reply-to' => []]]
        );

        $this->assertTrue($result);
        $updated = R::load('post', $bean->id);
        $this->assertSame('', (string) $updated->in_reply_to);
    }

    public function testUpdateDeletePropertyRemovesInReplyTo(): void
    {
        $bean = $this->storeReply("---\nin-reply-to: https://other.example/post\n---\nPlain post now");

        $adapter = new LambMicropubAdapter();
        $result = $adapter->updateCallback(
            ROOT_URL . '/status/' . $bean->id,
            ['delete' => ['in-reply-to']]
        );

        $this->assertTrue($result);
        $updated = R::load('post', $bean->id);
        $this->assertSame('', (string) $updated->in_reply_to);
        $this->assertStringContainsString('Plain post now', $updated->body);
    }

    public function testUpdateDeleteInReplyToValueRemovesMatchingTarget(): void
    {
        $bean = $this->storeReply("---\nin-reply-to: https://other.example/post\n---\nHi");

        $adapter = new LambMicropubAdapter();
        $adapter->updateCallback(
            ROOT_URL . '/status/' . $bean->id,
            ['delete' => ['in-reply-to' => ['https://other.example/post']]]
        );

        $updated = R::load('post', $bean->id);
        $this->assertSame('', (string) $updated->in_reply_to);
    }

    public function testUpdateDeleteInReplyToValueLeavesDifferentTargetIntact(): void
    {
        $bean = $this->storeReply("---\nin-reply-to: https://other.example/post\n---\nHi");

        $adapter = new LambMicropubAdapter();
        $adapter->updateCallback(
            ROOT_URL . '/status/' . $bean->id,
            ['delete' => ['in-reply-to' => ['https://unrelated.example/post']]]
        );

        $updated = R::load('post', $bean->id);
        $this->assertSame('https://other.example/post', $updated->in_reply_to);
    }

    public function testUpdateAddInReplyToSetsTargetWhenAbsent(): void
    {
        $bean = $this->storeReply('Not yet a reply');

        $adapter = new LambMicropubAdapter();
        $result = $adapter->updateCallback(
            ROOT_URL . '/status/' . $bean->id,
            ['add' => ['in-reply-to' => ['https://other.example/post']]]
        );

        $this->assertTrue($result);
        $updated = R::load('post', $bean->id);
        $this->assertSame('https://other.example/post', $updated->in_reply_to);
    }

    public function testUpdateAddSecondInReplyToIsRejected(): void
    {
        // Storage holds one reply target, so a second one cannot be honoured:
        // Micropub requires an unsupported operation to fail rather than return
        // success the client will read as "saved".
        $bean = $this->storeReply("---\nin-reply-to: https://other.example/post\n---\nHi");

        $adapter = new LambMicropubAdapter();
        $result = $adapter->updateCallback(
            ROOT_URL . '/status/' . $bean->id,
            ['add' => ['in-reply-to' => ['https://second.example/post']]]
        );

        $this->assertSame('invalid_request', $result);
        $updated = R::load('post', $bean->id);
        $this->assertSame('https://other.example/post', $updated->in_reply_to);
    }

    public function testUpdateAddInReplyToRejectsValueCarryingNoUrl(): void
    {
        // Mirrors the replace path: an h-cite with no url is not a reply target,
        // and returning 200 for an add that stored nothing tells the client its
        // edit was saved.
        $bean = $this->storeReply('Not yet a reply');

        $adapter = new LambMicropubAdapter();
        $result = $adapter->updateCallback(
            ROOT_URL . '/status/' . $bean->id,
            ['add' => ['in-reply-to' => [[
                'type'       => ['h-cite'],
                'properties' => ['name' => ['No url here']],
            ]]]]
        );

        $this->assertSame('invalid_request', $result);
        $updated = R::load('post', $bean->id);
        $this->assertSame('', (string) $updated->in_reply_to);
    }

    public function testUpdateAddInReplyToWithEmptyValueListIsANoOp(): void
    {
        // An empty value list asks for nothing to be added, which is not the same
        // as asking for something impossible: it must not be an error.
        $bean = $this->storeReply('Not yet a reply');

        $adapter = new LambMicropubAdapter();
        $result = $adapter->updateCallback(
            ROOT_URL . '/status/' . $bean->id,
            ['add' => ['in-reply-to' => []]]
        );

        $this->assertTrue($result);
        $updated = R::load('post', $bean->id);
        $this->assertSame('', (string) $updated->in_reply_to);
    }

    // --- has_micropub_scope ---

    public function testHasMicropubScopeTrueWhenScopePresent(): void
    {
        $user = ['me' => ROOT_URL . '/', 'scope' => ['create', 'update']];
        $this->assertTrue(has_micropub_scope($user, 'create'));
    }

    public function testHasMicropubScopeFalseWhenScopeAbsent(): void
    {
        // Regression: the Micropub media endpoint accepted any valid token
        // regardless of scope, unlike createCallback()/updateCallback().
        $user = ['me' => ROOT_URL . '/', 'scope' => ['update']];
        $this->assertFalse(has_micropub_scope($user, 'create'));
    }

    public function testHasMicropubScopeFalseWhenNoScopeKey(): void
    {
        $user = ['me' => ROOT_URL . '/'];
        $this->assertFalse(has_micropub_scope($user, 'create'));
    }

    public function testHasMicropubScopeFalseForFailedToken(): void
    {
        $this->assertFalse(has_micropub_scope(false, 'create'));
    }
}

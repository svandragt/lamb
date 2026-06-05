<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\ensure_preview_token;
use function Lamb\Theme\action_preview;

class PreviewTokenParityTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);

        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'http://localhost');
        }

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function makeBean(array $fields): \RedBeanPHP\OODBBean
    {
        $bean = R::dispense('post');
        $bean->body                  = 'Body';
        $bean->draft                 = $fields['draft'] ?? 0;
        $bean->deleted               = 0;
        $bean->slug                  = $fields['slug'] ?? '';
        $bean->created               = $fields['created'] ?? date('Y-m-d H:i:s');
        $bean->preview_token         = $fields['preview_token'] ?? '';
        $bean->preview_token_expires = $fields['preview_token_expires'] ?? '';

        return $bean;
    }

    // ---- ensure_preview_token() ---------------------------------------------

    public function testIssuesTokenForDraft(): void
    {
        $bean = $this->makeBean(['draft' => 1]);
        ensure_preview_token($bean);

        $this->assertNotEmpty($bean->preview_token);
        $this->assertGreaterThan(time(), strtotime($bean->preview_token_expires));
    }

    public function testIssuesTokenForScheduledPost(): void
    {
        $bean = $this->makeBean(['created' => date('Y-m-d H:i:s', time() + 86400)]);
        ensure_preview_token($bean);

        $this->assertNotEmpty($bean->preview_token);
    }

    public function testDoesNotIssueTokenForPublishedPost(): void
    {
        $bean = $this->makeBean([]);
        ensure_preview_token($bean);

        $this->assertEmpty($bean->preview_token);
    }

    public function testKeepsExistingUnexpiredToken(): void
    {
        $bean = $this->makeBean([
            'draft'                 => 1,
            'preview_token'         => 'existing-token',
            'preview_token_expires' => date('Y-m-d H:i:s', time() + 3600),
        ]);
        ensure_preview_token($bean);

        $this->assertSame('existing-token', $bean->preview_token);
    }

    public function testReplacesExpiredToken(): void
    {
        $bean = $this->makeBean([
            'draft'                 => 1,
            'preview_token'         => 'expired-token',
            'preview_token_expires' => date('Y-m-d H:i:s', time() - 3600),
        ]);
        ensure_preview_token($bean);

        $this->assertNotSame('expired-token', $bean->preview_token);
        $this->assertGreaterThan(time(), strtotime($bean->preview_token_expires));
    }

    // ---- Theme\action_preview() ----------------------------------------------

    public function testActionPreviewRendersLinkForLoggedInAuthor(): void
    {
        $bean = $this->makeBean([
            'draft'                 => 1,
            'slug'                  => 'my-draft',
            'preview_token'         => 'secret-token',
            'preview_token_expires' => date('Y-m-d H:i:s', time() + 3600),
        ]);
        R::store($bean);

        $_SESSION[SESSION_LOGIN] = true;
        $html = action_preview($bean);

        $this->assertStringContainsString('http://localhost/my-draft?preview=secret-token', $html);
        $this->assertStringContainsString('Preview', $html);
    }

    public function testActionPreviewEmptyWhenLoggedOut(): void
    {
        $bean = $this->makeBean([
            'draft'                 => 1,
            'preview_token'         => 'secret-token',
            'preview_token_expires' => date('Y-m-d H:i:s', time() + 3600),
        ]);
        R::store($bean);

        $this->assertSame('', action_preview($bean));
    }

    public function testActionPreviewEmptyForPublishedPost(): void
    {
        $bean = $this->makeBean([]);
        R::store($bean);

        $_SESSION[SESSION_LOGIN] = true;
        $this->assertSame('', action_preview($bean));
    }

    public function testActionPreviewEmptyWithoutValidToken(): void
    {
        $bean = $this->makeBean([
            'draft'                 => 1,
            'preview_token'         => 'expired-token',
            'preview_token_expires' => date('Y-m-d H:i:s', time() - 3600),
        ]);
        R::store($bean);

        $_SESSION[SESSION_LOGIN] = true;
        $this->assertSame('', action_preview($bean));
    }
}

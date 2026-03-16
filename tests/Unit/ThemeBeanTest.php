<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Theme\action_delete;
use function Lamb\Theme\action_edit;
use function Lamb\Theme\csrf_token;
use function Lamb\Theme\date_created;
use function Lamb\Theme\li_menu_items;

class ThemeBeanTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);

        // Ensure ROOT_URL is defined for li_menu_items relative URL path
        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'http://localhost');
        }

        // Start with a clean session
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    // csrf_token

    public function testCsrfTokenReturnsString(): void
    {
        $token = csrf_token();
        $this->assertIsString($token);
        $this->assertNotEmpty($token);
    }

    public function testCsrfTokenReturnsSameTokenOnSubsequentCalls(): void
    {
        $first = csrf_token();
        $second = csrf_token();
        $this->assertSame($first, $second);
    }

    public function testCsrfTokenUsesExistingSessionValue(): void
    {
        $_SESSION[HIDDEN_CSRF_NAME] = 'my-preset-token';
        $this->assertSame('my-preset-token', csrf_token());
    }

    public function testCsrfTokenStoresTokenInSession(): void
    {
        unset($_SESSION[HIDDEN_CSRF_NAME]);
        csrf_token();
        $this->assertArrayHasKey(HIDDEN_CSRF_NAME, $_SESSION);
    }

    // action_delete

    public function testActionDeleteReturnsEmptyWhenNotLoggedIn(): void
    {
        $bean = R::dispense('post');
        R::store($bean);

        unset($_SESSION[SESSION_LOGIN]);
        $this->assertSame('', action_delete($bean));
    }

    public function testActionDeleteReturnsFormWhenLoggedIn(): void
    {
        $bean = R::dispense('post');
        R::store($bean);

        $_SESSION[SESSION_LOGIN] = true;
        $result = action_delete($bean);
        $this->assertStringContainsString('<form', $result);
        $this->assertStringContainsString('/delete/', $result);
    }

    public function testActionDeleteFormContainsCsrfField(): void
    {
        $bean = R::dispense('post');
        R::store($bean);

        $_SESSION[SESSION_LOGIN] = true;
        $result = action_delete($bean);
        $this->assertStringContainsString('name="csrf"', $result);
    }

    public function testActionDeleteFormTargetsCorrectBeanId(): void
    {
        $bean = R::dispense('post');
        R::store($bean);

        $_SESSION[SESSION_LOGIN] = true;
        $result = action_delete($bean);
        $this->assertStringContainsString('/delete/' . $bean->id, $result);
    }

    // action_edit

    public function testActionEditReturnsEmptyWhenNotLoggedIn(): void
    {
        $bean = R::dispense('post');
        R::store($bean);

        unset($_SESSION[SESSION_LOGIN]);
        $this->assertSame('', action_edit($bean));
    }

    public function testActionEditReturnsButtonWhenLoggedIn(): void
    {
        $bean = R::dispense('post');
        R::store($bean);

        $_SESSION[SESSION_LOGIN] = true;
        $result = action_edit($bean);
        $this->assertStringContainsString('<button', $result);
        $this->assertStringContainsString('button-edit', $result);
    }

    public function testActionEditButtonContainsBeanId(): void
    {
        $bean = R::dispense('post');
        R::store($bean);

        $_SESSION[SESSION_LOGIN] = true;
        $result = action_edit($bean);
        $this->assertStringContainsString('data-id="' . $bean->id . '"', $result);
    }

    // date_created

    public function testDateCreatedReturnsEmptyWhenNoCreatedField(): void
    {
        $bean = R::dispense('post');
        $this->assertSame('', date_created($bean));
    }

    public function testDateCreatedReturnsAnchorTag(): void
    {
        $bean = R::dispense('post');
        $bean->created = date('Y-m-d H:i:s', strtotime('-1 hour'));

        $result = date_created($bean);
        $this->assertStringContainsString('<a href=', $result);
        $this->assertStringContainsString('<time', $result);
    }

    public function testDateCreatedUsesSlugInUrlWhenSlugSet(): void
    {
        $bean = R::dispense('post');
        $bean->created = date('Y-m-d H:i:s', strtotime('-1 day'));
        $bean->slug = 'my-post-slug';

        $result = date_created($bean);
        $this->assertStringContainsString('href="/my-post-slug"', $result);
    }

    public function testDateCreatedUsesStatusIdUrlWhenNoSlug(): void
    {
        $bean = R::dispense('post');
        R::store($bean);
        $bean->created = date('Y-m-d H:i:s', strtotime('-1 day'));
        $bean->slug = '';

        $result = date_created($bean);
        $this->assertStringContainsString('href="/status/' . $bean->id . '"', $result);
    }

    public function testDateCreatedIncludesCreatedInTitle(): void
    {
        $created = date('Y-m-d H:i:s', strtotime('-2 days'));
        $bean = R::dispense('post');
        $bean->created = $created;

        $result = date_created($bean);
        $this->assertStringContainsString('title="Timestamp: ' . $created . '"', $result);
    }

    // li_menu_items

    public function testLiMenuItemsReturnsEmptyWhenNoMenuItems(): void
    {
        global $config;
        $original = $config;
        $config['menu_items'] = [];

        $result = li_menu_items();
        $this->assertSame('', $result);

        $config = $original;
    }

    public function testLiMenuItemsRendersAbsoluteUrl(): void
    {
        global $config;
        $original = $config;
        $config['menu_items'] = ['Blog' => 'https://example.com'];

        $result = li_menu_items();
        $this->assertStringContainsString('<li>', $result);
        $this->assertStringContainsString('https://example.com', $result);
        $this->assertStringContainsString('Blog', $result);

        $config = $original;
    }

    public function testLiMenuItemsRendersRootRelativeUrl(): void
    {
        global $config;
        $original = $config;
        $config['menu_items'] = ['About' => '/about'];

        $result = li_menu_items();
        $this->assertStringContainsString('href="/about"', $result);
        $this->assertStringContainsString('About', $result);

        $config = $original;
    }

    public function testLiMenuItemsEscapesLabels(): void
    {
        global $config;
        $original = $config;
        $config['menu_items'] = ['<script>xss</script>' => '/safe'];

        $result = li_menu_items();
        $this->assertStringNotContainsString('<script>', $result);

        $config = $original;
    }

    public function testLiMenuItemsRendersMultipleItems(): void
    {
        global $config;
        $original = $config;
        $config['menu_items'] = [
            'Home' => '/home',
            'About' => '/about',
        ];

        $result = li_menu_items();
        $this->assertStringContainsString('Home', $result);
        $this->assertStringContainsString('About', $result);

        $config = $original;
    }

    public function testLiMenuItemsRendersRelativeUrlWithRootUrl(): void
    {
        global $config;
        $original = $config;
        $config['menu_items'] = ['Archive' => 'archive'];

        $result = li_menu_items();
        $this->assertStringContainsString(ROOT_URL . '/archive', $result);

        $config = $original;
    }
}

<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Security\get_login_url;
use function Lamb\Security\require_csrf;
use function Lamb\Security\require_login;

class SecurityTest extends TestCase
{
    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST = [];
    }

    // require_login

    public function testRequireLoginDoesNothingWhenAlreadyLoggedIn(): void
    {
        $_SESSION[SESSION_LOGIN] = true;
        require_login();
        // No redirect — we get here only if the if-branch was skipped
        $this->assertTrue(isset($_SESSION[SESSION_LOGIN]));
    }

    // get_login_url

    public function testGetLoginUrlWithNoUriReturnsLoginPath(): void
    {
        $this->assertSame('/login', get_login_url(''));
    }

    public function testGetLoginUrlWithUriAppendsRedirectTo(): void
    {
        $this->assertSame('/login?redirect_to=%2Fdrafts', get_login_url('/drafts'));
    }

    public function testGetLoginUrlEncodesSpecialCharacters(): void
    {
        $this->assertSame('/login?redirect_to=%2Ftag%2Ffoo+bar', get_login_url('/tag/foo bar'));
    }

    // require_csrf

    public function testRequireCsrfPassesWhenTokensMatch(): void
    {
        $token = 'valid-csrf-token';
        $_SESSION[HIDDEN_CSRF_NAME] = $token;
        $_POST[HIDDEN_CSRF_NAME] = $token;

        require_csrf();

        $this->assertArrayNotHasKey(HIDDEN_CSRF_NAME, $_SESSION);
    }

    public function testRequireCsrfConsumesTokenFromSession(): void
    {
        $_SESSION[HIDDEN_CSRF_NAME] = 'one-time-token';
        $_POST[HIDDEN_CSRF_NAME] = 'one-time-token';

        require_csrf();

        $this->assertFalse(isset($_SESSION[HIDDEN_CSRF_NAME]));
    }
}

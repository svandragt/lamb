<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

/**
 * Covers the login sad-path and login-gating of admin pages.
 *
 * The cache-headers suite proves a *correct* password logs in; the security-
 * critical rejection of a *wrong* password, and the gating of settings/scheduled
 * (LogoutCest covers drafts/trash) had no browser-level coverage.
 */
class LoginCest
{
    public function testWrongPasswordLeavesVisitorAnonymous(AcceptanceTester $I): void
    {
        $I->amOnPage('/login');
        $I->fillField('password', 'definitely-not-the-password');
        $I->click('Log in');

        // A rejected login must not authenticate the visitor: the author-only
        // entry form must not appear, and admin pages must still bounce to login.
        $I->dontSeeElement('//textarea[@name="contents"]');
        $I->amOnPage('/settings');
        $I->seeInCurrentUrl('/login');
    }

    public function testWrongPasswordHasNoErrors(AcceptanceTester $I): void
    {
        $I->amOnPage('/login');
        $I->fillField('password', 'definitely-not-the-password');
        $I->click('Log in');

        $I->dontSee('Fatal error');
        $I->dontSee('Warning:');
    }

    public function testWrongPasswordShowsFlashMessage(AcceptanceTester $I): void
    {
        $I->amOnPage('/login');
        $I->fillField('password', 'definitely-not-the-password');
        $I->click('Log in');

        // The failure must be communicated: the visitor is bounced back to the
        // login page with the error flash, not silently dropped on the homepage.
        $I->seeInCurrentUrl('/login');
        $I->see('Password is incorrect, please try again.');
    }

    /**
     * The heart of #462: an anonymous GET /login must not start a server-side
     * session, so it never writes a session file (the disk-exhaustion DoS).
     */
    public function testAnonymousLoginPageStartsNoSession(AcceptanceTester $I): void
    {
        $I->amOnPage('/login');
        $I->seeResponseCodeIs(200);
        $I->dontSeeResponseHeaderContains('Set-Cookie', 'LAMBSESSID');
    }

    /**
     * A rejected login must also leave no session behind: the failure is
     * re-rendered in place, not flashed through a freshly minted session.
     */
    public function testWrongPasswordStartsNoSession(AcceptanceTester $I): void
    {
        $I->amOnPage('/login');
        $I->fillField('password', 'definitely-not-the-password');
        $I->click('Log in');
        $I->dontSeeResponseHeaderContains('Set-Cookie', 'LAMBSESSID');
    }

    public function testGatedPagePromptsToLogIn(AcceptanceTester $I): void
    {
        $I->amOnPage('/settings');
        $I->seeInCurrentUrl('/login');
        $I->see('Please login');
    }

    public function testSettingsPageRequiresLogin(AcceptanceTester $I): void
    {
        $I->amOnPage('/settings');
        $I->seeInCurrentUrl('/login');
    }

    public function testScheduledPageRequiresLogin(AcceptanceTester $I): void
    {
        $I->amOnPage('/scheduled');
        $I->seeInCurrentUrl('/login');
    }
}

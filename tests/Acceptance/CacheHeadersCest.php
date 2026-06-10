<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

/**
 * Sessions for previously logged-in users only (issue #116).
 *
 * Anonymous public pages must be cacheable and must not start a session
 * (no LAMBSESSID Set-Cookie). Logged-in pages stay private. Logging out
 * clears the session so the visitor is anonymous — and cacheable — again.
 */
class CacheHeadersCest
{
    private function login(AcceptanceTester $I): void
    {
        $I->amOnPage('/login');
        $I->fillField('password', $_ENV['LAMB_TEST_PASSWORD']);
        $I->click('Log in');
    }

    public function anonymousHomepageIsCacheable(AcceptanceTester $I): void
    {
        $I->amOnPage('/');
        $I->seeResponseCodeIs(200);
        $I->seeResponseHeaderContains('Cache-Control', 'max-age=300');
        $I->dontSeeResponseHeaderContains('Cache-Control', 'no-store');
    }

    public function anonymousHomepageStartsNoSession(AcceptanceTester $I): void
    {
        $I->amOnPage('/');
        $I->dontSeeResponseHeaderContains('Set-Cookie', 'LAMBSESSID');
    }

    public function loginStillWorks(AcceptanceTester $I): void
    {
        $this->login($I);
        // Logged in: a protected page renders instead of redirecting to login.
        $I->amOnPage('/settings');
        $I->seeResponseCodeIs(200);
        $I->dontSeeInCurrentUrl('/login');
    }

    public function loggedInPagesAreNotCacheable(AcceptanceTester $I): void
    {
        $this->login($I);
        $I->amOnPage('/');
        $I->seeResponseHeaderContains('Cache-Control', 'no-store');
        $I->seeResponseHeaderContains('Cache-Control', 'private');
    }

    /**
     * Regression: visiting /login while the session is still authenticated
     * server-side but the marker cookie is stale/invalid (e.g. an old cookie
     * from before marker signing) must re-issue a valid marker. Otherwise the
     * already-logged-in branch redirects without a marker and the next request
     * sees an invalid one, leaving the visitor stuck appearing logged out until
     * they clear cookies.
     */
    public function reloginReissuesMarkerWhenSessionAlreadyAuthenticated(AcceptanceTester $I): void
    {
        $this->login($I);
        // Stale, no-longer-valid marker; the LAMBSESSID session stays authed.
        $I->setCookie('lamb_logged_in', 'stale-unsigned-value');
        // GET /login lands in the already-logged-in branch and redirects home.
        $I->amOnPage('/login');
        // The reissued marker must let the next request resume the session.
        $I->amOnPage('/settings');
        $I->seeResponseCodeIs(200);
        $I->dontSeeInCurrentUrl('/login');
    }

    public function afterLogoutHomepageIsCacheableAgain(AcceptanceTester $I): void
    {
        $this->login($I);
        $I->amOnPage('/logout');
        $I->amOnPage('/');
        $I->seeResponseHeaderContains('Cache-Control', 'max-age=300');
        $I->dontSeeResponseHeaderContains('Set-Cookie', 'LAMBSESSID');
    }
}

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
     * A stale or invalid lamb_logged_in marker makes the visitor anonymous
     * everywhere (issue #462): /login no longer starts a session off a lingering
     * LAMBSESSID cookie — doing so would write a session file off the very
     * unvalidated cookie input the marker gate exists to refuse. The marker is
     * therefore the single source of truth, so a stale one means anonymous and
     * a gated page bounces to /login (re-entering the password recovers, as the
     * other login tests cover).
     */
    public function staleMarkerFallsBackToAnonymous(AcceptanceTester $I): void
    {
        $this->login($I);
        // Corrupt the marker; the lingering server-side session must NOT be
        // resumed — the visitor is treated as anonymous and gated from /settings.
        $I->setCookie('lamb_logged_in', 'stale-unsigned-value');
        $I->amOnPage('/settings');
        $I->seeInCurrentUrl('/login');
        $I->dontSeeElement('//textarea[@name="contents"]');
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

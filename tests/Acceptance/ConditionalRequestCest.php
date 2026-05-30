<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

/**
 * Conditional GET + feed cache TTL (cache optimisation).
 *
 * Anonymous content pages and feeds carry ETag/Last-Modified validators and
 * answer 304 when the client already holds the current version. Feeds get a
 * longer max-age than regular pages.
 */
class ConditionalRequestCest
{
    private function seedPost(AcceptanceTester $I): void
    {
        // A published post gives latest_content_timestamp() something to return.
        $I->amOnPage('/login');
        $I->fillField('password', $_ENV['LAMB_TEST_PASSWORD']);
        $I->click('Log in');
        $I->amOnPage('/');
        $I->fillField('contents', 'Hello cache world');
        $I->click('Create post');
        $I->amOnPage('/logout');
    }

    public function anonymousHomepageSendsValidators(AcceptanceTester $I): void
    {
        $this->seedPost($I);
        $I->amOnPage('/');
        $I->seeResponseCodeIs(200);
        $I->seeResponseHeaderExists('ETag');
        $I->seeResponseHeaderExists('Last-Modified');
    }

    public function matchingEtagReturns304(AcceptanceTester $I): void
    {
        $this->seedPost($I);
        $I->amOnPage('/');
        $I->seeResponseHeaderExists('ETag');
        $etag = $I->grabResponseHeader('ETag');

        $I->haveHttpHeader('If-None-Match', $etag);
        $I->amOnPage('/');
        $I->seeResponseCodeIs(304);
    }

    public function feedHasLongerMaxAgeThanPages(AcceptanceTester $I): void
    {
        $this->seedPost($I);
        $I->amOnPage('/feed');
        $I->seeResponseHeaderContains('Cache-Control', 'max-age=1800');
    }

    public function feedSupportsConditionalGet(AcceptanceTester $I): void
    {
        $this->seedPost($I);
        $I->amOnPage('/feed');
        $I->seeResponseHeaderExists('ETag');
        $etag = $I->grabResponseHeader('ETag');

        $I->haveHttpHeader('If-None-Match', $etag);
        $I->amOnPage('/feed');
        $I->seeResponseCodeIs(304);
    }

    public function loginPageIsNeverConditional(AcceptanceTester $I): void
    {
        // The login page must always be served fresh (CSRF token), never 304'd.
        $I->amOnPage('/login');
        $I->dontSeeResponseHeader('ETag');
    }
}

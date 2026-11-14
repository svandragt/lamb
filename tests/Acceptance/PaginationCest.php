<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

/**
 * Pagination uses clean path URLs (/page/N), not ?page= querystrings.
 *
 * Seeds enough posts to force a second page (the page size is fixed at 10, so
 * eleven posts), then checks the homepage's "Older" link is a clean path and that a
 * ?page= query string is inert (serves page one, not a redirect).
 */
class PaginationCest
{
    private function login(AcceptanceTester $I): void
    {
        $I->amOnPage('/login');
        $I->fillField('password', $_ENV['LAMB_TEST_PASSWORD']);
        $I->click('Log in');
    }

    private function seedPaginatedPosts(AcceptanceTester $I): void
    {
        $this->login($I);

        $I->haveStoredSettings("theme = base\n");

        foreach (range(1, 11) as $number) {
            $I->amOnPage('/');
            $I->fillField('contents', "Post $number");
            $I->click('Create post');
        }
    }

    public function homepageOlderLinkIsCleanPath(AcceptanceTester $I): void
    {
        $this->seedPaginatedPosts($I);

        $I->amOnPage('/');
        // The "Older" link is a clean path (/page/2), with no ?page= querystring.
        // An exact-href match proves both: the path is right and no query is appended.
        $I->seeElement('nav.pagination a.next[href="/page/2"]');
    }

    public function secondPageRendersViaCleanPath(AcceptanceTester $I): void
    {
        $this->seedPaginatedPosts($I);

        // Page one holds the ten newest posts.
        $I->amOnPage('/');
        $I->seeNumberOfElements('article', 10);

        // Page two holds the remaining eleventh post.
        $I->amOnPage('/page/2');
        $I->seeResponseCodeIs(200);
        $I->dontSee('Fatal error');
        $I->dontSee('Warning:');
        $I->seeNumberOfElements('article', 1);
    }

    public function queryStringPageIsIgnored(AcceptanceTester $I): void
    {
        $this->seedPaginatedPosts($I);

        $I->amOnPage('/?page=2');
        // No redirect: ?page= is inert, so this serves page one at a 200.
        $I->seeResponseCodeIs(200);
        $I->seeCurrentUrlEquals('/?page=2');
        $I->seeNumberOfElements('article', 10);
    }
}

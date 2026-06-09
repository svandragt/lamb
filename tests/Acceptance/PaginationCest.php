<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

/**
 * Pagination uses clean path URLs (/page/N), not ?page= querystrings.
 *
 * Seeds enough posts to force a second page (posts_per_page = 2, three posts),
 * then checks the homepage's "Older" link is a clean path and that the legacy
 * ?page= form permanently redirects to it.
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

        // Force a small page size so three posts span two pages.
        $I->amOnPage('/settings');
        $I->fillField('ini_text', "theme = base\nposts_per_page = 2\n");
        $I->click('Save settings');

        foreach (['First post', 'Second post', 'Third post'] as $body) {
            $I->amOnPage('/');
            $I->fillField('contents', $body);
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

        // Page one holds the two newest posts.
        $I->amOnPage('/');
        $I->seeNumberOfElements('article', 2);

        // Page two holds the remaining third post (posts_per_page = 2).
        $I->amOnPage('/page/2');
        $I->seeResponseCodeIs(200);
        $I->dontSee('Fatal error');
        $I->dontSee('Warning:');
        $I->seeNumberOfElements('article', 1);
    }

    public function legacyPageQueryStringRedirectsToCleanPath(AcceptanceTester $I): void
    {
        $this->seedPaginatedPosts($I);

        $I->amOnPage('/?page=2');
        // PhpBrowser follows the 301; we should land on the clean path.
        $I->seeCurrentUrlMatches('~/page/2$~');
    }
}

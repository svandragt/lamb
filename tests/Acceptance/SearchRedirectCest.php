<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

class SearchRedirectCest
{
    // redirect_search: GET /search?s=<query> → Location: /search/<query>

    public function testSearchQueryParamRedirectsToSearchPath(AcceptanceTester $I): void
    {
        $I->amOnPage('/search?s=hello');
        // PhpBrowser follows the redirect; we should land on the search results URL
        $I->seeCurrentUrlMatches('~/search/hello~');
    }

    public function testSearchQueryParamResultsPageHasCorrectTitle(AcceptanceTester $I): void
    {
        $I->amOnPage('/search?s=hello');
        $I->seeInTitle('hello');
    }

    public function testSearchQueryParamResultsPageShowsSearchTerm(AcceptanceTester $I): void
    {
        $I->amOnPage('/search?s=hello');
        $I->see('hello', 'h1');
    }

    public function testSearchQueryParamResultsPageHasNoErrors(AcceptanceTester $I): void
    {
        $I->amOnPage('/search?s=hello');
        $I->dontSee('Fatal error');
        $I->dontSee('Warning:');
    }

    public function testSearchQueryParamWithNoResultsShowsEmptyState(AcceptanceTester $I): void
    {
        $I->amOnPage('/search?s=xyzzy_unique_no_match_99');
        $I->see('No results found.');
    }
}

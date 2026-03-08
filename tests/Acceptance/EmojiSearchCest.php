<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

class EmojiSearchCest
{
    public function _before(AcceptanceTester $I)
    {
    }

    public function tryEmojiSearch(AcceptanceTester $I)
    {
        // Search for the emoji via the URL path
        $I->amOnPage('/search/🐑');
        $I->seeInTitle('Searched for "🐑"');
        $I->see('Searched for "🐑"', 'h1');
        // It shouldn't 404/redirect to fallback site
        $I->see('No results found.', 'main');

        // Search for the emoji via the URL path (encoded)
        $I->amOnPage('/search/%F0%9F%90%91');
        $I->seeInTitle('Searched for "🐑"');
        $I->see('Searched for "🐑"', 'h1');
    }

    public function tryEmojiTag(AcceptanceTester $I)
    {
        // Search for the emoji tag via the URL path
        $I->amOnPage('/tag/🐑');
        $I->seeInTitle('Tagged with #🐑');
        $I->see('Tagged with #🐑', 'h1');
        // It shouldn't 404/redirect to fallback site
        $I->see('No results found.', 'main');

        // Search for the emoji tag via the URL path (encoded)
        $I->amOnPage('/tag/%F0%9F%90%91');
        $I->seeInTitle('Tagged with #🐑');
        $I->see('Tagged with #🐑', 'h1');
    }
}

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
        // Create a post with the emoji tag so the tag page exists
        $I->amOnPage('/login');
        $I->fillField('password', $_ENV['LAMB_TEST_PASSWORD']);
        $I->click('Log in');
        $I->amOnPage('/');
        $I->fillField('contents', 'Emoji tag test #🐑');
        $I->click('Create post');

        // Visit the emoji tag page via the URL path
        $I->amOnPage('/tag/🐑');
        $I->seeInTitle('Tagged with #🐑');
        $I->see('Tagged with #🐑', 'h1');

        // Also works with the percent-encoded URL
        $I->amOnPage('/tag/%F0%9F%90%91');
        $I->seeInTitle('Tagged with #🐑');
        $I->see('Tagged with #🐑', 'h1');
    }
}

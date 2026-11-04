<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

class PreconnectCest
{
    private const HINT = '<link rel="preconnect" href="https://img.example.net">';

    public function tryPagesShowHintsForTheSitesTheirPostsLoadImagesFrom(AcceptanceTester $I)
    {
        $I->amOnPage('/login');
        $I->fillField('password', $_ENV['LAMB_TEST_PASSWORD']);
        $I->click('Log in');
        $I->amOnPage('/');
        $I->fillField('contents', "---\ntitle: Remote Image\n---\n![](https://img.example.net/a.jpg) #lamb");
        $I->click('Create post');

        foreach (['/', '/tag/lamb', '/search?s=Remote', '/remote-image'] as $path) {
            $I->amOnPage($path);
            $I->seeInSource(self::HINT);
            $I->seeInSource('<link rel="dns-prefetch" href="https://img.example.net">');
        }

        $I->amOnPage('/tag/unrelated');
        $I->dontSeeInSource(self::HINT);
    }
}

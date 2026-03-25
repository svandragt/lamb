<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

class TagFeedCest
{
    public function tryTagFeedReturnsAtomXml(AcceptanceTester $I)
    {
        $I->amOnPage('/tag/lamb/feed');
        $I->seeResponseCodeIs(200);
        $I->seeInSource('<feed xmlns="http://www.w3.org/2005/Atom">');
    }

    public function tryTagFeedContainsTagTitle(AcceptanceTester $I)
    {
        $I->amOnPage('/tag/lamb/feed');
        $I->seeInSource('<feed xmlns="http://www.w3.org/2005/Atom">');
        $I->seeInSource('lamb');
    }

    public function tryTagFeedContainsValidAtomStructure(AcceptanceTester $I)
    {
        $I->amOnPage('/tag/lamb/feed');
        $I->seeInSource('<feed xmlns="http://www.w3.org/2005/Atom">');
        $I->seeInSource('<generator>Lamb</generator>');
    }

    public function tryTagFeedUrlPointsToTagFeed(AcceptanceTester $I)
    {
        $I->amOnPage('/tag/lamb/feed');
        $I->seeInSource('<feed xmlns="http://www.w3.org/2005/Atom">');
        $I->seeInSource('/tag/lamb/feed');
    }

    public function tryTagPageHasFeedAutodiscoveryLink(AcceptanceTester $I)
    {
        // Create a post with #lamb so the tag page exists
        $I->amOnPage('/login');
        $I->fillField('password', $_ENV['LAMB_TEST_PASSWORD']);
        $I->click('Log in');
        $I->amOnPage('/');
        $I->fillField('contents', 'Test post #lamb');
        $I->click('Create post');

        $I->amOnPage('/tag/lamb');
        $I->seeInSource('application/atom+xml');
        $I->seeInSource('/tag/lamb/feed');
    }

    public function tryHomeFeedReturnsAtomXml(AcceptanceTester $I)
    {
        $I->amOnPage('/home/feed');
        $I->seeResponseCodeIs(200);
        $I->seeInSource('<feed xmlns="http://www.w3.org/2005/Atom">');
        $I->seeInSource('<generator>Lamb</generator>');
    }

    public function tryEncodedTagFeedWorks(AcceptanceTester $I)
    {
        $I->amOnPage('/tag/%F0%9F%90%91/feed');
        $I->seeResponseCodeIs(200);
        $I->seeInSource('<feed xmlns="http://www.w3.org/2005/Atom">');
        // SimpleXMLElement encodes emoji as XML character entities
        $I->seeInSource('&#x1F411;');
    }
}

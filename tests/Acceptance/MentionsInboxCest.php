<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

/**
 * Received webmentions are listed at /mentions and counted in the admin toolbar (#863).
 * Receiving one fetches the source page, so these tests store the row directly.
 */
class MentionsInboxCest
{
    private function login(AcceptanceTester $I): void
    {
        $I->amOnPage('/login');
        $I->fillField('password', $_ENV['LAMB_TEST_PASSWORD']);
        $I->click('Log in');
    }

    private function createPost(AcceptanceTester $I, string $text): void
    {
        $I->amOnPage('/');
        $I->fillField('contents', $text);
        $I->click('Create post');
    }

    public function testLoggedOutVisitorIsSentToLoginLikeDrafts(AcceptanceTester $I): void
    {
        $I->amOnPage('/drafts');
        $I->seeCurrentUrlMatches('~/login\?redirect_to=%2Fdrafts~');

        $I->amOnPage('/mentions');
        $I->seeCurrentUrlMatches('~/login\?redirect_to=%2Fmentions~');
        $I->dontSee('Mentions', 'h1');
    }

    /**
     * @group seeds-db
     */
    public function testOwnerSeesMentionsFromTwoPostsWithLinksToSourceAndPost(AcceptanceTester $I): void
    {
        $this->login($I);
        $this->createPost($I, 'first-mentioned-post');
        $this->createPost($I, 'second-mentioned-post');
        $I->haveReceivedWebmention('first-mentioned-post', 'https://one.example/reply', 'Alice', 'Alice replies');
        $I->haveReceivedWebmention('second-mentioned-post', 'https://two.example/like', 'Bob', 'Bob likes');

        $I->amOnPage('/mentions');

        $I->seeResponseCodeIs(200);
        $I->see('Alice replies');
        $I->see('Bob likes');
        $I->seeElement('a[rel="nofollow ugc"][href="https://one.example/reply"]');
        $I->seeElement('a[rel="nofollow ugc"][href="https://two.example/like"]');
        $I->seeElement('//ul[contains(@class,"mentions")]//a[contains(@href,"/status/")]');
    }

    /**
     * @group seeds-db
     */
    public function testToolbarShowsTheCountOnlyWhenThereIsAMention(AcceptanceTester $I): void
    {
        $this->login($I);
        $this->createPost($I, 'counted-post');
        $I->amOnPage('/');
        $I->dontSeeElement('//div[@id="admin-toolbar"]//a[@href="/mentions"]');

        $I->haveReceivedWebmention('counted-post', 'https://one.example/reply', 'Alice', 'Alice replies');
        $I->amOnPage('/');

        $I->seeElement('//div[@id="admin-toolbar"]//a[@href="/mentions"][normalize-space()="Mentions (1)"]');
    }

    public function testEmptyListSaysSo(AcceptanceTester $I): void
    {
        $this->login($I);

        $I->amOnPage('/mentions');

        $I->seeResponseCodeIs(200);
        $I->see('Sorry no items found.');
    }
}

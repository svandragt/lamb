<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

class RelatedPostsTagLinksCest
{
    private function login(AcceptanceTester $I): void
    {
        $I->amOnPage('/login');
        $I->fillField('password', $_ENV['LAMB_TEST_PASSWORD']);
        $I->click('Log in');
    }

    private function createPost(AcceptanceTester $I, string $contents): void
    {
        $I->amOnPage('/');
        $I->fillField('contents', $contents);
        $I->click('Create post');
    }

    public function relatedPostsContainLinkedTags(AcceptanceTester $I): void
    {
        $tag = 'relatedtagtest' . uniqid();

        $this->login($I);
        $this->createPost($I, "First post about #$tag");
        $this->createPost($I, "Second post about #$tag");

        // Grab the URL of the most recent post (top of homepage)
        $postUrl = $I->grabAttributeFrom('article small a[href^="/status/"]', 'href');
        $I->amOnPage($postUrl);

        $I->seeElement('.related-posts a[href^="/tag/"]');
    }
}

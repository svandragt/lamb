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

        // Grab the URL of the first test post using XPath to match by our unique tag,
        // avoiding flakiness when other posts share the same created timestamp.
        $postUrl = $I->grabAttributeFrom(
            '//article[.//a[contains(@href, "/tag/' . $tag . '")]]//small/a[starts-with(@href, "/status/")]',
            'href'
        );
        $I->amOnPage($postUrl);

        $I->seeElement('.related-posts a[href^="/tag/"]');
    }
}

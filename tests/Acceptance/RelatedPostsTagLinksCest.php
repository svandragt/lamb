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

    /**
     * Pin the active theme so this test exercises the reference theme it asserts.
     *
     * The default theme for new installs is `2026`, whose related-posts section
     * deliberately omits the per-item tag links this test checks. `base` is the
     * full-feature reference theme that renders them, so pin to it explicitly.
     */
    private function useBaseTheme(AcceptanceTester $I): void
    {
        $I->amOnPage('/settings');
        $I->fillField('ini_text', "theme = base\n");
        $I->click('Save settings');
    }

    public function relatedPostsContainLinkedTags(AcceptanceTester $I): void
    {
        $tag = 'relatedtagtest' . uniqid();

        $this->login($I);
        $this->useBaseTheme($I);
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

<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

/**
 * Verifies the related-posts section surfaces a tag link.
 *
 * The base theme is the reference. The 2024 theme is intentionally not covered
 * here: it does not override _related.php, so it falls back to base and behaves
 * identically. The 2026 theme overrides _related.php with different markup, so
 * it gets its own case:
 *   - base — related items render the post's transformed body, so the inline
 *            #hashtag becomes a /tag/ link. Two posts is enough.
 *   - 2026 — related items show only a title/excerpt (no body), so a /tag/ link
 *            appears solely via the "More in #tag" overflow link, which requires
 *            more than five related posts.
 */
class RelatedPostsTagLinksCest
{
    private function login(AcceptanceTester $I): void
    {
        $I->amOnPage('/login');
        $I->fillField('password', $_ENV['LAMB_TEST_PASSWORD']);
        $I->click('Log in');
    }

    private function setTheme(AcceptanceTester $I, string $theme): void
    {
        $I->amOnPage('/settings');
        $I->fillField('ini_text', "theme = $theme\n");
        $I->click('Save settings');
    }

    private function createPost(AcceptanceTester $I, string $contents): void
    {
        $I->amOnPage('/');
        $I->fillField('contents', $contents);
        $I->click('Create post');
    }

    /**
     * Seeds $count posts sharing a unique tag under $theme, opens one of them,
     * and asserts the related-posts section links to that tag.
     */
    private function assertRelatedTagLink(AcceptanceTester $I, string $theme, int $count): void
    {
        $tag = 'relatedtagtest' . uniqid();

        $this->login($I);
        $this->setTheme($I, $theme);
        for ($i = 1; $i <= $count; $i++) {
            $this->createPost($I, "Post $i about #$tag");
        }

        // Grab a test post's permalink by matching the unique tag, avoiding
        // flakiness when posts share a created timestamp. The permalink lives in
        // <small> (base) or .meta (2024/2026), so match it anywhere in the article.
        $postUrl = $I->grabAttributeFrom(
            '//article[.//a[contains(@href, "/tag/' . $tag . '")]]//a[starts-with(@href, "/status/")]',
            'href'
        );
        $I->amOnPage($postUrl);

        $I->seeElement('.related-posts a[href^="/tag/"]');
    }

    public function baseThemeRelatedPostsContainLinkedTags(AcceptanceTester $I): void
    {
        $this->assertRelatedTagLink($I, 'base', 2);
    }

    public function notes2026ThemeRelatedPostsContainLinkedTags(AcceptanceTester $I): void
    {
        // The 2026 related list only surfaces a tag link once it overflows
        // (>5 related posts), via the "More in #tag" link.
        $this->assertRelatedTagLink($I, '2026', 7);
    }
}

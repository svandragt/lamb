<?php

namespace Tests\Acceptance;

use Codeception\Example;
use Tests\Support\AcceptanceTester;

/**
 * A published post slugged `home` is shown above the timeline at `/` (issue #867).
 *
 * The 2026 theme is covered as well as base because it styles the timeline
 * differently; both render the home part from base.
 */
class HomePageCest
{
    private const HOME_BODY = 'Welcome to my home page';
    private const TIMELINE_BODY = 'Timeline entry alpha';

    /**
     * @return array<string, array{theme: string}>
     */
    protected function themeProvider(): array
    {
        return [
            'base theme' => ['theme' => 'base'],
            '2026 theme' => ['theme' => '2026'],
        ];
    }

    private function login(AcceptanceTester $I): void
    {
        $I->amOnPage('/login');
        $I->fillField('password', $_ENV['LAMB_TEST_PASSWORD']);
        $I->click('Log in');
    }

    private function setIni(AcceptanceTester $I, string $ini): void
    {
        $I->amOnPage('/settings');
        $I->haveStoredSettings($ini);
    }

    private function createPost(AcceptanceTester $I, string $contents): void
    {
        $I->amOnPage('/');
        $I->fillField('contents', $contents);
        $I->click('Create post');
    }

    private function createHomePost(AcceptanceTester $I, string $body = self::HOME_BODY, string $extra = ''): void
    {
        $this->createPost($I, "---\nslug: home\n" . $extra . "---\n" . $body);
    }

    /**
     * @dataProvider themeProvider
     * @group seeds-db
     */
    public function homePostShowsAboveTheTimeline(AcceptanceTester $I, Example $example): void
    {
        $this->login($I);
        $this->setIni($I, 'theme = ' . $example['theme'] . "\n");
        $this->createPost($I, self::TIMELINE_BODY);
        $this->createHomePost($I);
        $I->amOnPage('/logout');

        $I->amOnPage('/');
        $I->seeResponseCodeIs(200);
        $I->see(self::HOME_BODY, '.home-post');
        $I->seeElement('//section[contains(@class, "home-post")]/following::article[contains(., "' . self::TIMELINE_BODY . '")]');
        // The home post is a page, not a timeline entry.
        $I->seeNumberOfElements('article', 1);
        $I->dontSeeElement('//article[contains(., "' . self::HOME_BODY . '")]');
        // It carries the id that task-list toggles read, and the microformats of a
        // permalink. The author and permalink links are hidden, not clipped, so they
        // are not invisible tab stops.
        $I->seeElement('.home-post.h-entry[data-post-id]');
        $I->seeElement('.home-post .e-content');
        $I->seeElement('.home-post [hidden] .u-url[href="/"]');
    }

    public function homeUrlRedirectsToTheRoot(AcceptanceTester $I): void
    {
        $this->login($I);
        $this->createHomePost($I);
        $I->amOnPage('/logout');

        $I->stopFollowingRedirects();
        $I->amOnPage('/home');
        $I->seeResponseCodeIs(301);

        $I->startFollowingRedirects();
        $I->amOnPage('/home');
        $I->seeCurrentUrlEquals('/');
        $I->see(self::HOME_BODY, '.home-post');
    }

    public function draftHomePostIsNotShown(AcceptanceTester $I): void
    {
        $this->login($I);
        $this->createPost($I, self::TIMELINE_BODY);
        $this->createHomePost($I, self::HOME_BODY, "draft: true\n");
        $I->amOnPage('/logout');

        $I->amOnPage('/');
        $I->see(self::TIMELINE_BODY);
        $I->dontSee(self::HOME_BODY);
        $I->dontSeeElement('.home-post');

        // Nothing sits at the root to redirect to, so /home still shows the timeline.
        $I->amOnPage('/home');
        $I->seeCurrentUrlEquals('/home');
        $I->see(self::TIMELINE_BODY);
    }

    public function previewLinkShowsADraftHomePost(AcceptanceTester $I): void
    {
        $this->login($I);
        $this->createPost($I, self::TIMELINE_BODY);
        $this->createHomePost($I, self::HOME_BODY, "draft: true\n");
        $I->amOnPage('/drafts');
        $href = $I->grabAttributeFrom('//article[contains(., "' . self::HOME_BODY . '")]//a[contains(@href, "?preview=")]', 'href');
        $I->amOnPage('/logout');

        $I->amOnPage('/');
        $I->dontSee(self::HOME_BODY);

        $I->amOnUrl($href);
        $I->see(self::HOME_BODY, '.home-post');
        $I->see(self::TIMELINE_BODY);
    }

    /**
     * @group seeds-db
     */
    public function laterPagesDoNotShowTheHomePost(AcceptanceTester $I): void
    {
        $this->login($I);
        $this->setIni($I, "theme = base\nposts_per_page = 2\n");
        foreach (['First post', 'Second post', 'Third post'] as $body) {
            $this->createPost($I, $body);
        }
        $this->createHomePost($I);
        $I->amOnPage('/logout');

        $I->amOnPage('/');
        $I->see(self::HOME_BODY, '.home-post');
        $I->seeNumberOfElements('article', 2);

        // The home post is not a timeline entry, so it takes no place in the page count.
        $I->amOnPage('/page/2');
        $I->seeResponseCodeIs(200);
        $I->dontSee(self::HOME_BODY);
        $I->seeNumberOfElements('article', 1);
    }

    public function homePostIsLeftOutOfFeedsAndSearch(AcceptanceTester $I): void
    {
        $this->login($I);
        $this->createPost($I, self::TIMELINE_BODY);
        $this->createHomePost($I);
        $I->amOnPage('/logout');

        $I->amOnPage('/feed');
        $I->dontSeeInSource(self::HOME_BODY);
        $I->amOnPage('/feed.json');
        $I->dontSeeInSource(self::HOME_BODY);
        $I->amOnPage('/search/Welcome');
        $I->see('No results found.');
    }

    public function sitemapListsTheRootOnce(AcceptanceTester $I): void
    {
        $this->login($I);
        $this->createHomePost($I);
        $I->amOnPage('/logout');

        $I->amOnPage('/sitemap.xml');
        $I->seeResponseCodeIs(200);
        // Only the home post exists, so the root is the sitemap's single entry.
        $I->seeNumberOfElements('loc', 1);
        $I->seeInSource('/</loc>');
        $I->dontSeeInSource('/home</loc>');
    }

    public function editingTheHomePostInvalidatesTheCachedRoot(AcceptanceTester $I): void
    {
        $this->login($I);
        $this->createHomePost($I);
        $id = $I->grabAttributeFrom('.home-post button.button-edit', 'data-id');
        $I->amOnPage('/logout');

        $I->amOnPage('/');
        $oldEtag = $I->grabResponseHeader('ETag');
        $I->haveHttpHeader('If-None-Match', $oldEtag);
        $I->amOnPage('/');
        $I->seeResponseCodeIs(304);

        $this->login($I);
        $I->amOnPage('/edit/' . $id);
        $I->fillField('contents', "---\nslug: home\n---\nA rewritten welcome");
        $I->click('Update post');
        $I->amOnPage('/logout');

        $I->haveHttpHeader('If-None-Match', $oldEtag);
        $I->amOnPage('/');
        $I->seeResponseCodeIs(200);
        $I->see('A rewritten welcome', '.home-post');
    }
}

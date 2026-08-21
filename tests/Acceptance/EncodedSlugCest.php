<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

/**
 * A post is served at the URL its own permalink names. An explicit
 * front-matter slug is stored as the author wrote it, so a slug carrying a
 * space or a non-ASCII character reaches the router percent-encoded — it used
 * to 404 at the very URL every link on the site pointed at.
 */
class EncodedSlugCest
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

    public function aSlugWithASpaceIsReachable(AcceptanceTester $I)
    {
        $this->login($I);
        $this->createPost($I, "---\ntitle: Spaced Slug\nslug: my spaced slug\n---\nBody of the spaced post.");

        $I->amOnPage('/my%20spaced%20slug');
        $I->seeResponseCodeIs(200);
        $I->see('Body of the spaced post.');
        $I->dontSee('Fatal error');
    }

    public function aNonAsciiSlugIsReachable(AcceptanceTester $I)
    {
        $this->login($I);
        $this->createPost($I, "---\ntitle: Unicode Slug\nslug: café\n---\nBody of the unicode post.");

        $I->amOnPage('/caf%C3%A9');
        $I->seeResponseCodeIs(200);
        $I->see('Body of the unicode post.');
    }

    public function aSlugWithASlashIsFlattenedToOneSegment(AcceptanceTester $I)
    {
        $this->login($I);
        $this->createPost($I, "---\ntitle: Nested Slug\nslug: archive/2024\n---\nBody of the nested post.");

        $I->amOnPage('/archive-2024');
        $I->seeResponseCodeIs(200);
        $I->see('Body of the nested post.');
    }

    public function anUnknownSlugStill404s(AcceptanceTester $I)
    {
        $I->amOnPage('/no%20such%20post');
        $I->seeResponseCodeIs(404);
    }
}

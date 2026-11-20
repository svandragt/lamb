<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

/**
 * A new install starts with a welcome draft that links to the maintainer's
 * "Do you run Lamb?" post (#1004). The suite deletes the database before every
 * test, so each test here begins on a fresh install.
 */
class WelcomeDraftCest
{
    private const TITLE = 'Hello from Lamb';

    private function login(AcceptanceTester $I): void
    {
        $I->amOnPage('/login');
        $I->fillField('password', $_ENV['LAMB_TEST_PASSWORD']);
        $I->click('Log in');
    }

    public function testTheOwnerSeesTheWelcomeDraftOnAFreshInstall(AcceptanceTester $I): void
    {
        $this->login($I);

        $I->amOnPage('/drafts');

        $I->see(self::TITLE);
        $I->seeLink('Write your first post', FIRST_POST_TUTORIAL_URL);
        $I->seeLink('Do you run Lamb?', DO_YOU_RUN_LAMB_URL);
        $I->see('Publish this to say hello, or delete it.');
    }

    public function testAVisitorCannotSeeTheWelcomeDraft(AcceptanceTester $I): void
    {
        $I->amOnPage('/');
        $I->dontSee(self::TITLE);

        $I->amOnPage('/hello-from-lamb');
        $I->seeResponseCodeIs(404);
    }

    public function testDeletingTheDraftSendsNothingAndKeepsItGone(AcceptanceTester $I): void
    {
        $this->login($I);
        $I->amOnPage('/drafts');
        $I->submitForm('//article[contains(., "' . self::TITLE . '")]//form[contains(@class,"form-delete")]', []);

        // Each request boots the database again; none of them may bring the draft back.
        $I->amOnPage('/drafts');
        $I->dontSee(self::TITLE);
        $I->amOnPage('/');
        $I->amOnPage('/drafts');
        $I->dontSee(self::TITLE);

        $I->amOnPage('/trash');
        $I->see(self::TITLE);
        $I->seeNoWebmentionQueued();
    }

    public function testPublishingTheDraftQueuesAWebmentionToTheTargetPost(AcceptanceTester $I): void
    {
        $this->login($I);
        $I->amOnPage('/drafts');
        $id = (string) $I->grabAttributeFrom(
            '//article[contains(., "' . self::TITLE . '")]//button[contains(@class, "button-edit")]',
            'data-id'
        );

        $I->amOnPage('/edit/' . $id);
        $body = (string) $I->grabTextFrom('#contents');
        $I->fillField('contents', str_replace("draft: true\n", '', $body));
        $I->click('Update post');

        $I->amOnPage('/drafts');
        $I->dontSee(self::TITLE);
        $I->seeWebmentionQueuedFor(DO_YOU_RUN_LAMB_URL);
    }
}

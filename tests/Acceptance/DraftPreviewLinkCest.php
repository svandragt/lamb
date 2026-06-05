<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

class DraftPreviewLinkCest
{
    private string $uniqueContent = '';

    private function login(AcceptanceTester $I): void
    {
        $I->amOnPage('/login');
        $I->fillField('password', $_ENV['LAMB_TEST_PASSWORD']);
        $I->click('Log in');
    }

    private function createDraft(AcceptanceTester $I): void
    {
        $this->uniqueContent = 'draft-preview-test-' . uniqid();
        $I->amOnPage('/');
        $I->fillField('contents', "---\ndraft: true\n---\n" . $this->uniqueContent);
        $I->click('Create post');
    }

    public function testWebUiDraftGetsPreviewLinkOnDraftsPage(AcceptanceTester $I): void
    {
        $this->login($I);
        $this->createDraft($I);

        $I->amOnPage('/drafts');
        $I->see($this->uniqueContent);
        $I->seeElement('//article[contains(., "' . $this->uniqueContent . '")]//a[contains(@href, "?preview=")]');
    }

    public function testPreviewLinkWorksLoggedOut(AcceptanceTester $I): void
    {
        $this->login($I);
        $this->createDraft($I);

        $I->amOnPage('/drafts');
        $href = $I->grabAttributeFrom('//article[contains(., "' . $this->uniqueContent . '")]//a[contains(@href, "?preview=")]', 'href');

        $I->amOnPage('/logout');
        $I->amOnUrl($href);
        $I->see($this->uniqueContent);
    }
}

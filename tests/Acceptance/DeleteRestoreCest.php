<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

class DeleteRestoreCest
{
    private string $uniqueContent = '';

    private function login(AcceptanceTester $I): void
    {
        $I->amOnPage('/login');
        $I->fillField('password', $_ENV['LAMB_TEST_PASSWORD']);
        $I->click('Log in');
    }

    private function createPost(AcceptanceTester $I): void
    {
        $this->uniqueContent = 'delete-restore-test-' . uniqid();
        $I->amOnPage('/');
        $I->fillField('contents', $this->uniqueContent);
        $I->click('Create post');
    }

    // redirect_deleted

    public function testDeletedPostDisappearsFromHomepage(AcceptanceTester $I): void
    {
        $this->login($I);
        $this->createPost($I);

        $I->see($this->uniqueContent);
        $I->submitForm(
            '//article[contains(., "' . $this->uniqueContent . '")]//form[contains(@class,"form-delete")]',
            []
        );

        $I->dontSee($this->uniqueContent);
    }

    public function testDeletedPostAppearsInTrash(AcceptanceTester $I): void
    {
        $this->login($I);
        $this->createPost($I);
        $I->submitForm(
            '//article[contains(., "' . $this->uniqueContent . '")]//form[contains(@class,"form-delete")]',
            []
        );

        $I->amOnPage('/trash');
        $I->see($this->uniqueContent);
    }

    public function testDeleteRedirectsToHomepage(AcceptanceTester $I): void
    {
        $this->login($I);
        $this->createPost($I);
        $I->submitForm(
            '//article[contains(., "' . $this->uniqueContent . '")]//form[contains(@class,"form-delete")]',
            []
        );

        $I->seeResponseCodeIs(200);
        // After delete we land on homepage, which has the entry form
        $I->seeElement('form');
    }

    public function testDeleteHasNoErrors(AcceptanceTester $I): void
    {
        $this->login($I);
        $this->createPost($I);
        $I->submitForm(
            '//article[contains(., "' . $this->uniqueContent . '")]//form[contains(@class,"form-delete")]',
            []
        );

        $I->dontSee('Fatal error');
        $I->dontSee('Warning:');
    }

    // redirect_restored

    public function testRestoredPostReappearsOnHomepage(AcceptanceTester $I): void
    {
        $this->login($I);
        $this->createPost($I);
        // Scope delete to this specific post to avoid non-deterministic ordering
        // when multiple posts share the same second-precision created timestamp.
        $I->submitForm(
            '//article[contains(., "' . $this->uniqueContent . '")]//form[contains(@class,"form-delete")]',
            []
        );

        $I->amOnPage('/trash');
        $I->see($this->uniqueContent);
        $I->submitForm(
            '//article[contains(., "' . $this->uniqueContent . '")]//form[contains(@class,"form-restore")]',
            []
        );

        $I->amOnPage('/');
        $I->see($this->uniqueContent);
    }

    public function testRestoredPostNoLongerInTrash(AcceptanceTester $I): void
    {
        $this->login($I);
        $this->createPost($I);
        $I->submitForm(
            '//article[contains(., "' . $this->uniqueContent . '")]//form[contains(@class,"form-delete")]',
            []
        );

        $I->amOnPage('/trash');
        $I->submitForm(
            '//article[contains(., "' . $this->uniqueContent . '")]//form[contains(@class,"form-restore")]',
            []
        );

        // After restore we land on /trash — post should no longer appear
        $I->dontSee($this->uniqueContent);
    }

    public function testRestoreRedirectsToTrashPage(AcceptanceTester $I): void
    {
        $this->login($I);
        $this->createPost($I);
        $I->submitForm(
            '//article[contains(., "' . $this->uniqueContent . '")]//form[contains(@class,"form-delete")]',
            []
        );

        $I->amOnPage('/trash');
        $I->submitForm(
            '//article[contains(., "' . $this->uniqueContent . '")]//form[contains(@class,"form-restore")]',
            []
        );

        $I->seeCurrentUrlEquals('/trash');
        $I->seeResponseCodeIs(200);
    }

    public function testRestoreHasNoErrors(AcceptanceTester $I): void
    {
        $this->login($I);
        $this->createPost($I);
        $I->submitForm(
            '//article[contains(., "' . $this->uniqueContent . '")]//form[contains(@class,"form-delete")]',
            []
        );

        $I->amOnPage('/trash');
        $I->submitForm(
            '//article[contains(., "' . $this->uniqueContent . '")]//form[contains(@class,"form-restore")]',
            []
        );

        $I->dontSee('Fatal error');
        $I->dontSee('Warning:');
    }
}

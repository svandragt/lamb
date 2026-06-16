<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

/**
 * Covers the core "edit an existing post" flow (respond_edit / redirect_edited).
 *
 * The delete/restore and draft suites exercise creating posts, but editing — a
 * primary CRUD operation for the single author — had no browser-level coverage.
 */
class EditPostCest
{
    private string $original = '';

    private function login(AcceptanceTester $I): void
    {
        $I->amOnPage('/login');
        $I->fillField('password', $_ENV['LAMB_TEST_PASSWORD']);
        $I->click('Log in');
    }

    private function createPost(AcceptanceTester $I): void
    {
        $this->original = 'edit-test-original-' . uniqid();
        $I->amOnPage('/');
        $I->fillField('contents', $this->original);
        $I->click('Create post');
    }

    private function editId(AcceptanceTester $I): string
    {
        // The edit control is a <button class="button-edit" data-id="N"> that JS
        // upgrades into an /edit/N link; read the post id straight off data-id.
        return (string) $I->grabAttributeFrom(
            '//article[contains(., "' . $this->original . '")]//button[contains(@class, "button-edit")]',
            'data-id'
        );
    }

    public function testEditUpdatesPostContent(AcceptanceTester $I): void
    {
        $this->login($I);
        $this->createPost($I);
        $I->see($this->original);

        $id      = $this->editId($I);
        $updated = 'edit-test-updated-' . uniqid();

        $I->amOnPage('/edit/' . $id);
        $I->seeInField('contents', $this->original);
        $I->fillField('contents', $updated);
        $I->click('Update post');

        $I->amOnPage('/');
        $I->see($updated);
        $I->dontSee($this->original);
    }

    public function testEditHasNoErrors(AcceptanceTester $I): void
    {
        $this->login($I);
        $this->createPost($I);

        $id = $this->editId($I);
        $I->amOnPage('/edit/' . $id);
        $I->fillField('contents', 'edit-test-noerror-' . uniqid());
        $I->click('Update post');

        $I->dontSee('Fatal error');
        $I->dontSee('Warning:');
        $I->seeResponseCodeIs(200);
    }

    public function testEditPageRequiresLogin(AcceptanceTester $I): void
    {
        // Seed a post while logged in, then drop the session.
        $this->login($I);
        $this->createPost($I);
        $id = $this->editId($I);

        $I->amOnPage('/logout');
        $I->amOnPage('/edit/' . $id);

        // Anonymous visitors are bounced to the login page, never the edit form.
        $I->seeInCurrentUrl('/login');
        $I->dontSeeElement('#editform');
    }
}

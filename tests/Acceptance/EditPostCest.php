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

    public function testEditFormKeepsAngleBracketsInTheBody(AcceptanceTester $I): void
    {
        // The edit form used to run the body through strip_tags(), which deleted
        // every `<…>` run in the Markdown source — an autolink, an HTML snippet
        // in a code fence — and the mangled text was what the form posted back,
        // so opening the editor and saving destroyed content.
        $this->login($I);

        $marker = 'edit-test-angle-' . uniqid();
        $body   = $marker . " see <https://example.com> and `<div class=\"x\">`";
        $I->amOnPage('/');
        $I->fillField('contents', $body);
        $I->click('Create post');

        $id = (string) $I->grabAttributeFrom(
            '//article[contains(., "' . $marker . '")]//button[contains(@class, "button-edit")]',
            'data-id'
        );

        $I->amOnPage('/edit/' . $id);
        $I->seeInField('contents', $body);

        // Saving the untouched form must round-trip the body unchanged.
        $I->click('Update post');
        $I->amOnPage('/edit/' . $id);
        $I->seeInField('contents', $body);
    }

    public function testEditStampsTheCurrentRenderVersion(AcceptanceTester $I): void
    {
        // An edit re-renders `transformed`, so the row must record the current
        // format version. Stamping a stale one marked every edited post as
        // needing an upgrade, so the next read re-parsed and re-stored it.
        $this->login($I);
        $this->createPost($I);

        $id = (int) $this->editId($I);
        $I->amOnPage('/edit/' . $id);
        $I->fillField('contents', 'edit-test-version-' . uniqid());

        // Check the row as the save left it: any page load after the redirect
        // runs upgrade_posts(), which would repair a stale version behind our
        // back — the very extra write this guards against.
        $I->stopFollowingRedirects();
        $I->click('Update post');
        $I->seePostColumnEquals($id, 'version', POST_VERSION);
        $I->startFollowingRedirects();
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

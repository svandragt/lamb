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
    /** Unique text present in the created post, used to locate it on the page. */
    private string $marker = '';

    /** The full body the post was created with (marker plus any extra content). */
    private string $original = '';

    private function login(AcceptanceTester $I): void
    {
        $I->amOnPage('/login');
        $I->fillField('password', $_ENV['LAMB_TEST_PASSWORD']);
        $I->click('Log in');
    }

    /**
     * Creates a post whose body is a unique marker followed by $extra.
     *
     * The marker is tracked separately because it is what editId() matches on:
     * $extra may render to something other than its source (Markdown becomes
     * HTML), so the full body is not reliably findable in the page text.
     */
    private function createPost(AcceptanceTester $I, string $extra = ''): void
    {
        $this->marker   = 'edit-test-' . uniqid();
        $this->original = $this->marker . $extra;
        $I->amOnPage('/');
        $I->fillField('contents', $this->original);
        $I->click('Create post');
    }

    private function editId(AcceptanceTester $I): string
    {
        // The edit control is a <button class="button-edit" data-id="N"> that JS
        // upgrades into an /edit/N link; read the post id straight off data-id.
        return (string) $I->grabAttributeFrom(
            '//article[contains(., "' . $this->marker . '")]//button[contains(@class, "button-edit")]',
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
        $this->createPost($I, ' see <https://example.com> and `<div class="x">`');

        $id = $this->editId($I);
        $I->amOnPage('/edit/' . $id);
        $I->seeInField('contents', $this->original);

        // Saving the untouched form must round-trip the body unchanged.
        $I->click('Update post');
        $I->amOnPage('/edit/' . $id);
        $I->seeInField('contents', $this->original);
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

    public function testEditRenamingToAReservedRouteSuffixesTheSlugInsteadOfRejectingTheEdit(AcceptanceTester $I): void
    {
        // "search" is a real registered route (src/routes.php), so renaming a
        // post's title to "Search" used to be rejected outright with a "slug is
        // in use" flash — the whole edit was silently discarded. finalize_slug()
        // now suffixes it with the post id instead, matching post creation.
        $this->login($I);
        $this->createPost($I);

        $id = (int) $this->editId($I);
        $I->amOnPage('/edit/' . $id);
        $I->fillField('contents', "# Search\n\nedit-test-reserved-slug-" . uniqid());
        $I->click('Update post');

        $I->dontSee('slug is in use');
        $I->seePostColumnEquals($id, 'slug', 'search-' . $id);

        $I->amOnPage('/search-' . $id);
        $I->seeResponseCodeIs(200);
        $I->see('edit-test-reserved-slug-');
    }
}

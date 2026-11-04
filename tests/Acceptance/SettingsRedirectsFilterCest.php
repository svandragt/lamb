<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

/**
 * The Redirections tab lists every stored redirect and, with JavaScript, a
 * filter box narrows it. PhpBrowser runs no JavaScript, so these tests cover the
 * markup the script works on: the full list, and the box that stays hidden
 * until the script shows it.
 */
class SettingsRedirectsFilterCest
{
    private function login(AcceptanceTester $I): void
    {
        $I->amOnPage('/login');
        $I->fillField('password', $_ENV['LAMB_TEST_PASSWORD']);
        $I->click('Log in');
    }

    /**
     * Renames a post's slug, which stores a redirect from the old slug.
     */
    private function renameSlug(AcceptanceTester $I, string $old, string $new): void
    {
        $I->amOnPage('/');
        $I->fillField('contents', "---\nslug: $old\n---\nBody of $old.");
        $I->click('Create post');

        $I->amOnPage('/' . $old);
        $id = (string) $I->grabAttributeFrom('//button[contains(@class, "button-edit")]', 'data-id');
        $I->amOnPage('/edit/' . $id);
        $I->fillField('contents', "---\nslug: $new\n---\nBody of $new.");
        $I->click('Update post');
    }

    public function theRedirectionsTabHasAHiddenLabelledFilterBoxAndTheFullList(AcceptanceTester $I): void
    {
        $this->login($I);
        $this->renameSlug($I, 'old-filter-slug', 'new-filter-slug');

        $I->amOnPage('/settings');

        $I->seeElement('#redirects-filter[hidden] label[for=redirects-filter-input]');
        $I->seeElement('#redirects-filter[hidden] input[type=search]#redirects-filter-input');
        $I->seeElement('#redirects-filter-status[aria-live=polite]');
        $I->see('/old-filter-slug', '#settings-panel-redirects tbody tr');
        $I->see('/new-filter-slug', '#settings-panel-redirects tbody tr');
    }

    public function theSettingsPageLoadsTheFilterScriptAndOtherPagesDoNot(AcceptanceTester $I): void
    {
        $this->login($I);

        $I->amOnPage('/settings');
        $I->seeInSource('filter-redirects.js');

        $I->amOnPage('/');
        $I->dontSeeInSource('filter-redirects.js');
    }

    public function withoutRedirectsThereIsNoFilterBox(AcceptanceTester $I): void
    {
        $this->login($I);

        $I->amOnPage('/settings');

        $I->see('No automatic redirects stored yet.');
        $I->dontSeeElement('#redirects-filter-input');
    }
}

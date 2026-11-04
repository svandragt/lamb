<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

/**
 * /settings is a form of labelled fields. Storage stays one INI text, so a save
 * has to keep every setting the form does not show.
 */
class SettingsFormCest
{
    private function login(AcceptanceTester $I): void
    {
        $I->amOnPage('/login');
        $I->fillField('password', $_ENV['LAMB_TEST_PASSWORD']);
        $I->click('Log in');
    }

    public function requiredFieldsAreMarkedAndNoPlaceholderIdentityIsPrefilled(AcceptanceTester $I): void
    {
        $this->login($I);
        $I->amOnPage('/settings');

        foreach (['site_title', 'site_url', 'author_name'] as $field) {
            $I->seeElement("input[name=$field][required]");
        }
        // A fresh install has no author: the form must not offer the feed placeholder as one.
        $I->seeInField('author_name', '');
        $I->seeInField('author_email', '');
        $I->seeInField('site_title', 'My Microblog');
    }

    public function exportTabOffersTheDownloadAsAButton(AcceptanceTester $I): void
    {
        $this->login($I);
        $I->amOnPage('/settings');

        $I->seeElement('#settings-panel-export a.button[href="/export"]');
        $I->see('Download export', '#settings-panel-export a.button');

        // The class is only a button if the active theme's stylesheet styles it.
        $href = $I->grabAttributeFrom('link[rel=stylesheet]', 'href');
        $I->amOnPage(strtok($href, '?'));
        $I->seeInSource('a.button');
        $I->seeInSource('text-decoration: none');
    }

    public function savedValuesAreShownAgainOnReload(AcceptanceTester $I): void
    {
        $this->login($I);
        $I->amOnPage('/settings');
        $I->fillField('site_title', 'Notes from the shed');
        $I->fillField('site_url', 'https://example.com');
        $I->fillField('site_description', 'Engines and other hobbies');
        $I->selectOption('timezone', 'Europe/London');
        $I->fillField('author_name', 'Ada Lovelace');
        $I->fillField('author_email', 'ada@example.com');
        $I->fillField('menu_items', "Home = /\nAbout = about");
        $I->fillField('me', 'Mastodon = https://example.social/@ada');
        $I->uncheckOption('feeds_draft');
        $I->click('Save settings');
        $I->see('Settings saved successfully.');

        $I->amOnPage('/settings');
        $I->seeInField('site_title', 'Notes from the shed');
        $I->seeInField('site_url', 'https://example.com');
        $I->seeInField('site_description', 'Engines and other hobbies');
        $I->seeOptionIsSelected('timezone', 'Europe/London');
        $I->seeInField('author_name', 'Ada Lovelace');
        $I->seeInField('author_email', 'ada@example.com');
        $I->seeInField('menu_items', "Home = /\nAbout = about");
        $I->seeInField('me', 'Mastodon = https://example.social/@ada');
        $I->dontSeeCheckboxIsChecked('feeds_draft');
    }

    public function anEmptyAuthorNameIsRefusedAndTheOtherTypedValuesStay(AcceptanceTester $I): void
    {
        $this->login($I);
        $I->amOnPage('/settings');
        $I->fillField('site_title', 'Typed but unsaved');
        $I->fillField('site_url', 'https://example.com');
        $I->fillField('author_name', '');
        $I->fillField('menu_items', 'Docs = /docs');
        $I->selectOption('timezone', 'Asia/Tokyo');
        $I->click('Save settings');

        $I->see('Author name is required.');
        $I->seeInField('site_title', 'Typed but unsaved');
        $I->seeInField('site_url', 'https://example.com');
        $I->seeInField('menu_items', 'Docs = /docs');
        $I->seeOptionIsSelected('timezone', 'Asia/Tokyo');

        // Nothing was stored.
        $I->amOnPage('/settings');
        $I->seeInField('site_title', 'My Microblog');
        $I->seeInField('site_url', '');
    }

    public function resetToDefaultsStaysUsableWhileRequiredFieldsAreEmpty(AcceptanceTester $I): void
    {
        $this->login($I);
        $I->amOnPage('/settings');

        // A browser checks `required` fields before any submit button posts the
        // form. Reset is the way out of unreadable settings, where the fields are empty.
        $I->seeElement('button[value=reset][formnovalidate]');

        $I->haveStoredSettings("[unclosed\n=== not ini ===\n");
        $I->amOnPage('/settings');
        $I->click('Reset to defaults');
        $I->see('Settings reset to defaults.');
        $I->amOnPage('/settings');
        $I->seeInField('site_title', 'My Microblog');
    }

    public function savingTheFormKeepsUnknownAndDeprecatedSettings(AcceptanceTester $I): void
    {
        $this->login($I);
        $I->haveStoredSettings(
            "site_title = Seeded\nsite_url = https://example.com\nauthor_name = Ada\ntheme = base\n"
            . "posts_per_page = 5\nmystery_key = kept\n\n[mystery_section]\nanswer = 42\n"
        );
        foreach (range(1, 6) as $number) {
            $I->amOnPage('/');
            $I->fillField('contents', "Post $number");
            $I->click('Create post');
        }

        $I->amOnPage('/settings');
        $I->fillField('site_description', 'Saved through the form');
        $I->click('Save settings');
        $I->see('Settings saved successfully.');
        $I->see('`posts_per_page` is deprecated and will be removed in 0.17');

        $I->seeStoredSettingsContain('posts_per_page = 5');
        $I->seeStoredSettingsContain('mystery_key = kept');
        $I->seeStoredSettingsContain('answer = 42');
        $I->seeStoredSettingsContain('site_description = Saved through the form');

        // The deprecated page size still applies.
        $I->amOnPage('/');
        $I->seeNumberOfElements('article', 5);
        $I->amOnPage('/page/2');
        $I->seeNumberOfElements('article', 1);
    }
}

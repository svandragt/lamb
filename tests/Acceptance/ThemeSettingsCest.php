<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

/**
 * A custom theme declares settings fields in its `settings.php`, and /settings shows them on a Theme tab.
 *
 * The tests that need a theme are in the `seeds-db` group: they install the fixture theme into
 * src/themes, which the external server release-verify.yml tests cannot see.
 */
class ThemeSettingsCest
{
    private const THEME = 'fixture_settings';

    private const SAVE_THEME_SETTINGS = '#settings-panel-theme button[value=save]';

    private function themeDir(): string
    {
        return dirname(__DIR__, 2) . '/src/themes/' . self::THEME;
    }

    public function _after(AcceptanceTester $I): void
    {
        @unlink($this->themeDir() . '/settings.php');
        @rmdir($this->themeDir());
    }

    private function login(AcceptanceTester $I): void
    {
        $I->amOnPage('/login');
        $I->fillField('password', $_ENV['LAMB_TEST_PASSWORD']);
        $I->click('Log in');
    }

    /**
     * Installs the fixture theme, and stores settings that use it, plus any extra INI text.
     */
    private function useFixtureTheme(AcceptanceTester $I, string $extraIni = ''): void
    {
        if (!is_dir($this->themeDir())) {
            mkdir($this->themeDir());
        }
        copy(dirname(__DIR__) . '/Support/Data/theme-settings/settings.php', $this->themeDir() . '/settings.php');

        $this->login($I);
        $I->haveStoredSettings(
            "site_title = Seeded\nsite_url = https://example.com\nauthor_name = Ada\ntheme = " . self::THEME . "\n" . $extraIni
        );
    }

    /**
     * @group seeds-db
     */
    public function aThemeWithASettingsFileGetsAThemeTabWhoseValuesAreSaved(AcceptanceTester $I): void
    {
        $this->useFixtureTheme($I);
        $I->amOnPage('/settings');

        $I->seeElement('label[for=settings-tab-theme]');
        $I->see('Accent colour', '#settings-panel-theme label');
        $I->see('Footer tagline', '#settings-panel-theme label');
        $I->seeElement('input[name="theme_settings[accent_colour]"][placeholder="#0a7"]');
        $I->seeInField('theme_settings[accent_colour]', '');

        $I->fillField('theme_settings[accent_colour]', '#c0ffee');
        $I->click(self::SAVE_THEME_SETTINGS);

        $I->seeInField('theme_settings[accent_colour]', '#c0ffee');
        $I->seeInField('theme_settings[tagline]', '');
        $I->seeStoredSettingsContain("[theme_fixture_settings]\naccent_colour = #c0ffee");
        $I->dontSeeStoredSettingsContain('tagline');
        $I->seeStoredSettingsContain('site_title = Seeded');
    }

    /**
     * @group seeds-db
     */
    public function aThemeValueIsEscapedWhereItIsShownAgain(AcceptanceTester $I): void
    {
        $this->useFixtureTheme($I);
        $I->amOnPage('/settings');

        $I->fillField('theme_settings[tagline]', '<b>"x"</b>');
        $I->click(self::SAVE_THEME_SETTINGS);

        $I->seeInField('theme_settings[tagline]', '<b>"x"</b>');
        $I->dontSeeInSource('<b>"x"</b>');
    }

    public function aThemeWithoutASettingsFileHasNoThemeTab(AcceptanceTester $I): void
    {
        $this->login($I);
        $I->amOnPage('/settings');

        $I->see('Settings', 'h1');
        $I->dontSeeElement('label[for=settings-tab-theme]');
        $I->dontSeeElement('#settings-panel-theme');
        $I->dontSeeElement('input[name^="theme_settings"]');
    }

    /**
     * @group seeds-db
     */
    public function switchingThemesKeepsEachThemesStoredValuesAndHidesUndeclaredKeys(AcceptanceTester $I): void
    {
        $this->useFixtureTheme(
            $I,
            "\n[theme_fixture_settings]\naccent_colour = #c0ffee\nundeclared = secret\n\n[theme_other]\nfont = serif\n"
        );
        $I->amOnPage('/settings');
        $I->seeInField('theme_settings[accent_colour]', '#c0ffee');
        $I->dontSeeElement('input[name="theme_settings[undeclared]"]');
        $I->dontSeeInSource('secret');

        $I->selectOption('theme', 'base');
        $I->click('#settingsform button[value=save]');
        $I->dontSeeElement('label[for=settings-tab-theme]');
        $I->seeStoredSettingsContain('accent_colour = #c0ffee');
        $I->seeStoredSettingsContain('undeclared = secret');
        $I->seeStoredSettingsContain('font = serif');

        $I->selectOption('theme', self::THEME);
        $I->click('#settingsform button[value=save]');
        $I->seeElement('label[for=settings-tab-theme]');
        $I->seeInField('theme_settings[accent_colour]', '#c0ffee');
    }

    /**
     * @group seeds-db
     */
    public function aSettingsFileThatCannotBeLoadedNamesTheFileAndLeavesSettingsUsable(AcceptanceTester $I): void
    {
        $this->useFixtureTheme($I);
        file_put_contents($this->themeDir() . '/settings.php', '<?php return [');

        $I->amOnPage('/settings');

        $I->see('themes/' . self::THEME . '/settings.php', '.flash');
        $I->dontSeeElement('label[for=settings-tab-theme]');

        // The way back from a broken theme is the Theme field on this page.
        $I->selectOption('theme', 'base');
        $I->click('#settingsform button[value=save]');
        $I->dontSee('settings.php', '.flash');
        $I->seeStoredSettingsContain('theme = base');
    }

    /**
     * @group seeds-db
     */
    public function aRefusedSaveKeepsTheTypedThemeValues(AcceptanceTester $I): void
    {
        $this->useFixtureTheme($I);
        $I->amOnPage('/settings');

        // The Configuration panel's required fields are hidden while this tab is open, and a browser
        // cannot show its own message for a hidden field. The server reports the problem instead.
        $I->seeElement(self::SAVE_THEME_SETTINGS . '[formnovalidate]');

        $I->fillField('author_name', '');
        $I->fillField('theme_settings[accent_colour]', 'typed but unsaved');
        $I->click(self::SAVE_THEME_SETTINGS);

        $I->see('Author name is required.');
        $I->seeInField('theme_settings[accent_colour]', 'typed but unsaved');
        $I->dontSeeStoredSettingsContain('typed but unsaved');
    }
}

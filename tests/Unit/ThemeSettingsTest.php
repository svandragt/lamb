<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Config\compose_config;
use function Lamb\Config\form_to_ini;
use function Lamb\Config\get_default_ini_text;
use function Lamb\Config\parse_ini_safe;
use function Lamb\Config\settings_form_errors;
use function Lamb\Config\settings_form_prefill;
use function Lamb\Config\theme_settings;

use const Lamb\Config\NO_THEME_SETTINGS;

/**
 * A theme declares settings fields in its own `settings.php`. The /settings form shows them as a
 * Theme tab and stores the values under `[theme_<name>]`, where the theme reads them from $config.
 */
class ThemeSettingsTest extends TestCase
{
    private string $themeDir = '';

    protected function setUp(): void
    {
        // A fresh path per test, so nothing caches an earlier test's settings.php.
        $this->themeDir = sys_get_temp_dir() . '/lamb_theme_settings_' . uniqid('', true) . '/mytheme/';
        mkdir($this->themeDir, 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->themeDir . '*') ?: []);
        rmdir($this->themeDir);
        rmdir(dirname($this->themeDir));
    }

    /**
     * @param string $php The body of the theme's settings.php, after the opening tag.
     * @return array{section: string, fields: array<string, array{label: string, default: string}>, error: string}
     */
    private function declared(string $php): array
    {
        file_put_contents($this->themeDir . 'settings.php', '<?php ' . $php);

        return theme_settings($this->themeDir);
    }

    /**
     * @return array{section: string, fields: array<string, array{label: string, default: string}>, error: string}
     */
    private function accentTheme(): array
    {
        return $this->declared(
            "return ['accent_colour' => ['label' => 'Accent colour', 'default' => '#0a7'], 'tagline' => ['label' => 'Tagline']];"
        );
    }

    // theme_settings: reading the declaration

    public function testThemeSettingsReadsTheFixtureFieldsInOrder(): void
    {
        $settings = theme_settings(dirname(__DIR__) . '/Support/Data/theme-settings');

        $this->assertSame('theme_theme-settings', $settings['section']);
        $this->assertSame(
            [
                'accent_colour' => ['label' => 'Accent colour', 'default' => '#0a7'],
                'tagline' => ['label' => 'Footer tagline', 'default' => 'Made with Lamb'],
            ],
            $settings['fields']
        );
    }

    public function testThemeSettingsNamesTheSectionAfterTheThemeDirectory(): void
    {
        $this->assertSame('theme_mytheme', $this->accentTheme()['section']);
    }

    public function testThemeSettingsIsEmptyWithoutASettingsFile(): void
    {
        $this->assertSame(NO_THEME_SETTINGS, theme_settings($this->themeDir));
        $this->assertSame(NO_THEME_SETTINGS, theme_settings(''));
    }

    /**
     * @dataProvider nonArrayReturnProvider
     */
    public function testThemeSettingsIsEmptyWhenTheFileDoesNotReturnAnArray(string $php): void
    {
        $this->assertSame(NO_THEME_SETTINGS, $this->declared($php));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nonArrayReturnProvider(): array
    {
        return ['a string' => ['return "accent_colour";'], 'nothing' => ['// returns nothing']];
    }

    /**
     * @dataProvider brokenFileProvider
     */
    public function testThemeSettingsIsEmptyAndNamesTheFileWhenItCannotBeLoaded(string $php, string $reason): void
    {
        $settings = $this->declared($php);

        $this->assertSame('', $settings['section']);
        $this->assertSame([], $settings['fields']);
        $this->assertStringContainsString('themes/mytheme/settings.php', $settings['error']);
        $this->assertStringContainsString($reason, $settings['error']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function brokenFileProvider(): array
    {
        return [
            'a syntax error' => ['return [', "Unclosed '['"],
            'an exception' => ['throw new \RuntimeException("boom");', 'boom'],
            'a missing function' => ['return missing_lamb_function();', 'missing_lamb_function'],
        ];
    }

    public function testThemeSettingsLoadsTheFileOnceSoItMayDeclareAFunction(): void
    {
        $name = 'lamb_theme_settings_helper_' . uniqid();
        file_put_contents(
            $this->themeDir . 'settings.php',
            "<?php function {$name}() { return 'x'; } return ['accent_colour' => ['label' => {$name}()]];"
        );

        $first = theme_settings($this->themeDir);
        $second = theme_settings($this->themeDir);

        $this->assertSame('x', $first['fields']['accent_colour']['label']);
        $this->assertSame($first, $second);
    }

    public function testThemeSettingsFallsBackToTheKeyForALabelAndEmptyForADefault(): void
    {
        $settings = $this->declared("return ['accent_colour' => [], 'tagline' => ['label' => 'Tagline']];");

        $this->assertSame(['label' => 'accent_colour', 'default' => ''], $settings['fields']['accent_colour']);
        $this->assertSame(['label' => 'Tagline', 'default' => ''], $settings['fields']['tagline']);
    }

    public function testThemeSettingsSkipsEntriesThatCannotBeStoredAsAKey(): void
    {
        // A reserved word and an ampersand cannot be INI keys, and a bare value is not a field.
        $settings = $this->declared(
            "return ['loose' => 'value', 'yes' => ['label' => 'Yes'], 'a&b' => ['label' => 'Amp'], 'kept' => ['label' => 'Kept']];"
        );

        $this->assertSame(['kept'], array_keys($settings['fields']));
    }

    // values reach $config

    public function testTheStoredValuesReachTheConfigUnderTheThemeSection(): void
    {
        $config = compose_config("[theme_mytheme]\naccent_colour = #c0ffee\n", get_default_ini_text());

        $this->assertSame('#c0ffee', $config['theme_mytheme']['accent_colour']);
    }

    // form_to_ini: saving

    public function testFormToIniStoresTheDeclaredFieldsUnderTheThemeSection(): void
    {
        $post = ['theme_settings' => ['accent_colour' => ' #c0ffee ', 'tagline' => '']];

        $saved = parse_ini_safe(form_to_ini($post, '', $this->accentTheme()));

        // An empty field is left out, so the theme's default applies.
        $this->assertSame(['accent_colour' => '#c0ffee'], $saved['theme_mytheme']);
    }

    public function testFormToIniIgnoresAThemeKeyThatIsNotDeclared(): void
    {
        $post = ['theme_settings' => ['accent_colour' => '#c0ffee', 'injected' => 'x']];

        $saved = parse_ini_safe(form_to_ini($post, '', $this->accentTheme()));

        $this->assertSame(['accent_colour' => '#c0ffee'], $saved['theme_mytheme']);
    }

    public function testFormToIniKeepsEveryOtherThemesSection(): void
    {
        $current = "[theme_other]\nfont = serif\n[theme_mytheme]\naccent_colour = #111\n";
        $post = ['theme_settings' => ['accent_colour' => '#222']];

        $saved = parse_ini_safe(form_to_ini($post, $current, $this->accentTheme()));

        $this->assertSame(['font' => 'serif'], $saved['theme_other']);
        $this->assertSame(['accent_colour' => '#222'], $saved['theme_mytheme']);
    }

    public function testFormToIniKeepsAStoredThemeKeyTheThemeNoLongerDeclares(): void
    {
        $current = "[theme_mytheme]\nretired = still here\naccent_colour = #111\n";
        $post = ['theme_settings' => ['accent_colour' => '#222']];

        $saved = parse_ini_safe(form_to_ini($post, $current, $this->accentTheme()));

        $this->assertSame(['accent_colour' => '#222', 'retired' => 'still here'], $saved['theme_mytheme']);
    }

    public function testFormToIniKeepsADeclaredKeyThePostDoesNotCarry(): void
    {
        $current = "[theme_mytheme]\naccent_colour = #111\ntagline = Kept\n";
        $post = ['theme_settings' => ['accent_colour' => '#222']];

        $saved = parse_ini_safe(form_to_ini($post, $current, $this->accentTheme()));

        $this->assertSame(['accent_colour' => '#222', 'tagline' => 'Kept'], $saved['theme_mytheme']);
    }

    public function testFormToIniKeepsTheStoredValuesWhenThePostCarriesNoThemeFields(): void
    {
        $current = "[theme_mytheme]\naccent_colour = #111\n";

        $saved = parse_ini_safe(form_to_ini([], $current, $this->accentTheme()));

        $this->assertSame(['accent_colour' => '#111'], $saved['theme_mytheme']);
    }

    public function testFormToIniKeepsTheThemeSectionWhenTheActiveThemeDeclaresNothing(): void
    {
        $current = "[theme_mytheme]\naccent_colour = #111\n";
        $post = ['theme_settings' => ['accent_colour' => '#222']];

        $saved = parse_ini_safe(form_to_ini($post, $current, NO_THEME_SETTINGS));

        $this->assertSame(['accent_colour' => '#111'], $saved['theme_mytheme']);
    }

    public function testFormToIniRoundTripsAwkwardThemeValues(): void
    {
        $value = 'Tom; "Jerry" = [1] <b>';
        $post = ['theme_settings' => ['tagline' => $value]];

        $saved = parse_ini_safe(form_to_ini($post, '', $this->accentTheme()));

        $this->assertSame($value, $saved['theme_mytheme']['tagline']);
    }

    public function testSettingsFormErrorsRefusesAThemeValueThatSpansLines(): void
    {
        $post = [
            'site_title' => 'Blog', 'site_url' => 'https://example.com', 'author_name' => 'Ada',
            'theme_settings' => ['accent_colour' => "#fff\n[evil]"],
        ];

        $errors = settings_form_errors($post, '', $this->accentTheme());

        $this->assertSame(['Accent colour must fit on one line.'], $errors);
    }

    // settings_form_prefill: what the Theme tab shows

    public function testPrefillShowsTheStoredThemeValuesAndEmptyForAnUnsetField(): void
    {
        $form = settings_form_prefill("[theme_mytheme]\naccent_colour = #c0ffee\nretired = x\n", [], $this->accentTheme());

        $this->assertSame(['accent_colour' => '#c0ffee', 'tagline' => ''], $form['theme']);
    }

    public function testPrefillPrefersWhatWasTypedInARefusedSave(): void
    {
        $posted = ['theme_settings' => ['accent_colour' => '', 'tagline' => 'Typed']];

        $form = settings_form_prefill("[theme_mytheme]\naccent_colour = #c0ffee\n", $posted, $this->accentTheme());

        $this->assertSame(['accent_colour' => '', 'tagline' => 'Typed'], $form['theme']);
    }

    public function testPrefillSurvivesAStoredThemeSectionThatIsNotASection(): void
    {
        $form = settings_form_prefill("theme_mytheme = flat\n", [], $this->accentTheme());

        $this->assertSame(['accent_colour' => '', 'tagline' => ''], $form['theme']);
    }

    public function testPrefillShowsNoThemeValuesForAThemeWithoutFields(): void
    {
        $this->assertSame([], settings_form_prefill("[theme_mytheme]\naccent_colour = #c0ffee\n")['theme']);
    }
}

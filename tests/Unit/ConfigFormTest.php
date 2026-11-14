<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;
use Symfony\Component\Process\Process;

use function Lamb\Config\form_to_ini;
use function Lamb\Config\get_default_ini_text;
use function Lamb\Config\parse_ini_safe;
use function Lamb\Config\parse_section_text;
use function Lamb\Config\removed_setting_warnings;
use function Lamb\Config\settings_form_errors;
use function Lamb\Config\shape_warnings;
use function Lamb\Config\write_ini;
use function Lamb\Response\respond_settings;

use const Lamb\Config\REMOVED_SETTINGS;

/**
 * The /settings form posts fields instead of INI text. form_to_ini() turns
 * them back into the stored INI blob, so storage needs no migration; keys the
 * form does not own (deprecated or unknown ones) must survive that trip.
 */
class ConfigFormTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::exec('DELETE FROM option');

        $_SESSION = [];
        $_POST    = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST    = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    /**
     * A valid form submission, with the required fields filled in.
     *
     * @param array<string, string> $overrides Fields to add or replace.
     * @return array<string, string>
     */
    private function post(array $overrides = []): array
    {
        return array_merge([
            'settings_form' => '1',
            'site_title' => 'My Blog',
            'site_url' => 'https://example.com',
            'author_name' => 'Ada Lovelace',
        ], $overrides);
    }

    // form_to_ini: owned fields

    public function testFormToIniWritesThePostedSingleValues(): void
    {
        $ini = form_to_ini($this->post([
            'site_description' => 'Notes on engines',
            'timezone' => 'Europe/London',
            'theme' => 'base',
            'author_email' => 'ada@example.com',
            'following_url' => 'https://reader.example/following.opml',
            'authorization_endpoint' => 'https://auth.example/auth',
            'token_endpoint' => 'https://auth.example/token',
            'websub_hubs' => 'https://hub.example/, https://hub2.example/',
        ]), '');

        $parsed = parse_ini_safe($ini);
        $this->assertSame('My Blog', $parsed['site_title']);
        $this->assertSame('https://example.com', $parsed['site_url']);
        $this->assertSame('Ada Lovelace', $parsed['author_name']);
        $this->assertSame('Notes on engines', $parsed['site_description']);
        $this->assertSame('Europe/London', $parsed['timezone']);
        $this->assertSame('base', $parsed['theme']);
        $this->assertSame('ada@example.com', $parsed['author_email']);
        $this->assertSame('https://reader.example/following.opml', $parsed['following_url']);
        $this->assertSame('https://auth.example/auth', $parsed['authorization_endpoint']);
        $this->assertSame('https://auth.example/token', $parsed['token_endpoint']);
        $this->assertSame('https://hub.example/, https://hub2.example/', $parsed['websub_hubs']);
    }

    public function testFormToIniOmitsEmptySingleFieldsSoDefaultsApply(): void
    {
        $ini = form_to_ini($this->post(['site_description' => '', 'theme' => '  ', 'timezone' => '']), '');

        $parsed = parse_ini_safe($ini);
        $this->assertArrayNotHasKey('site_description', $parsed);
        $this->assertArrayNotHasKey('theme', $parsed);
        $this->assertArrayNotHasKey('timezone', $parsed);
        $this->assertArrayNotHasKey('author_email', $parsed);
    }

    public function testFormToIniTrimsPostedValues(): void
    {
        $parsed = parse_ini_safe(form_to_ini($this->post(['site_title' => "  Padded \t"]), ''));

        $this->assertSame('Padded', $parsed['site_title']);
    }

    /**
     * @dataProvider feedsDraftProvider
     */
    public function testFormToIniTreatsFeedsDraftAsACheckbox(array $overrides, string $expected): void
    {
        $parsed = parse_ini_safe(form_to_ini($this->post($overrides), ''));

        $this->assertSame($expected, $parsed['feeds_draft']);
    }

    /**
     * @return array<string, array{array<string, string>, string}>
     */
    public static function feedsDraftProvider(): array
    {
        return [
            'checked posts a value' => [['feeds_draft' => '1'], 'true'],
            'unchecked posts nothing' => [[], 'false'],
        ];
    }

    // form_to_ini: sections

    public function testFormToIniParsesSectionTextareasIntoOrderedEntries(): void
    {
        $ini = form_to_ini($this->post([
            'menu_items' => "Home = /\r\n; a comment\r\n\r\nAbout Me=about\r\nSearch = /search?q=a&r=b\r\n",
            'syndicate_to' => 'https://bsky.app/profile/ada = Bluesky',
        ]), '');

        $parsed = parse_ini_safe($ini);
        $this->assertSame(
            ['Home' => '/', 'About Me' => 'about', 'Search' => '/search?q=a&r=b'],
            $parsed['menu_items']
        );
        $this->assertSame(['Home', 'About Me', 'Search'], array_keys($parsed['menu_items']));
        $this->assertSame(['https://bsky.app/profile/ada' => 'Bluesky'], $parsed['syndicate_to']);
    }

    public function testFormToIniReplacesTheStoredValuesTheFormOwns(): void
    {
        $current = "site_title = Old\nauthor_name = Old Name\n\n[menu_items]\nOld = /old\n\n[me]\nGithub = https://github.com/old\n";

        $parsed = parse_ini_safe(form_to_ini($this->post(['menu_items' => 'New = /new']), $current));

        $this->assertSame('My Blog', $parsed['site_title']);
        $this->assertSame(['New' => '/new'], $parsed['menu_items']);
        // An owned section the form posts empty is cleared, not carried over.
        $this->assertSame([], $parsed['me']);
    }

    public function testFormToIniKeepsAnEmptyMenuEmptyInsteadOfRestoringTheDefaultMenu(): void
    {
        // An absent [menu_items] falls back to the seeded Home/Feed menu, so a
        // cleared textarea has to be written as an empty section to stay cleared.
        $parsed = parse_ini_safe(form_to_ini($this->post(['menu_items' => '']), ''));

        $this->assertArrayHasKey('menu_items', $parsed);
        $this->assertSame([], $parsed['menu_items']);
    }

    // form_to_ini: carry-over

    public function testFormToIniDropsEveryRemovedSettingAndCarriesOverUnknownKeys(): void
    {
        $current = "posts_per_page = 5\n404_fallback = https://old.example\nmicropub_debug = true\n"
            . "experimental_features = true\nmystery_key = kept\ntheme = base\n";

        $parsed = parse_ini_safe(form_to_ini($this->post(['theme' => '2026']), $current));

        foreach (['posts_per_page', '404_fallback', 'micropub_debug', 'experimental_features'] as $key) {
            $this->assertArrayNotHasKey($key, $parsed);
        }
        $this->assertSame('kept', $parsed['mystery_key']);
        $this->assertSame('2026', $parsed['theme']);
    }

    public function testFormToIniDropsTheRemovedPreconnectSectionAndCarriesOverUnknownSections(): void
    {
        $current = "[preconnect]\nfonts = https://fonts.googleapis.com\n\n[custom]\nOne = 1\nTwo = two words\n";

        $parsed = parse_ini_safe(form_to_ini($this->post(), $current));

        $this->assertArrayNotHasKey('preconnect', $parsed);
        $this->assertSame(['One' => '1', 'Two' => 'two words'], $parsed['custom']);
    }

    public function testFormToIniCarriesOverCommentFreeValuesExactly(): void
    {
        $current = "mystery = \"semi; colon\"\n\n[custom]\nLabel = \"  spaced  \"\n";

        $parsed = parse_ini_safe(form_to_ini($this->post(), $current));

        $this->assertSame('semi; colon', $parsed['mystery']);
        $this->assertSame(['Label' => '  spaced  '], $parsed['custom']);
    }

    public function testFormToIniSurvivesAStoredIniThatDoesNotParse(): void
    {
        $parsed = parse_ini_safe(form_to_ini($this->post(), "[unclosed\n=== not ini ==="));

        $this->assertSame('My Blog', $parsed['site_title']);
    }

    // parse_section_text

    public function testParseSectionTextSkipsBlankLinesAndComments(): void
    {
        $result = parse_section_text("\n; note\n  ;; another\nHome = /\n   \nFeed=/feed\n");

        $this->assertSame(['Home' => '/', 'Feed' => '/feed'], $result['entries']);
        $this->assertSame([], $result['errors']);
    }

    public function testParseSectionTextSplitsAtTheFirstEqualsSign(): void
    {
        $result = parse_section_text('Search = /search?q=a=b');

        $this->assertSame(['Search' => '/search?q=a=b'], $result['entries']);
    }

    public function testParseSectionTextReportsMalformedLinesByNumber(): void
    {
        $result = parse_section_text("Home = /\nAbout\n= nowhere\nHome = /again\nBad[label = x\n");

        $this->assertSame(['Home' => '/'], $result['entries']);
        $this->assertCount(4, $result['errors']);
        $this->assertStringContainsString('line 2', $result['errors'][0]);
        $this->assertStringContainsString('About', $result['errors'][0]);
        $this->assertStringContainsString('line 3', $result['errors'][1]);
        $this->assertStringContainsString('line 4', $result['errors'][2]);
        $this->assertStringContainsString('more than once', $result['errors'][2]);
        $this->assertStringContainsString('line 5', $result['errors'][3]);
    }

    // settings_form_errors

    public function testSettingsFormErrorsIsEmptyForAValidSubmission(): void
    {
        $this->assertSame([], settings_form_errors($this->post([
            'menu_items' => "Home = /\nFeed = /feed",
            'footer_items' => '',
        ])));
    }

    /**
     * @dataProvider requiredFieldProvider
     */
    public function testSettingsFormErrorsRequiresTitleUrlAndAuthorName(string $field, string $mention): void
    {
        $errors = settings_form_errors($this->post([$field => '   ']));

        $this->assertCount(1, $errors);
        $this->assertStringContainsString($mention, $errors[0]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function requiredFieldProvider(): array
    {
        return [
            'site title' => ['site_title', 'Site title'],
            'site url' => ['site_url', 'Site URL'],
            'author name' => ['author_name', 'Author name'],
        ];
    }

    public function testSettingsFormErrorsReportsEveryMissingRequiredField(): void
    {
        $this->assertCount(3, settings_form_errors(['settings_form' => '1']));
    }

    /**
     * @dataProvider unusableSiteUrlProvider
     */
    public function testSettingsFormErrorsRejectsASiteUrlCanonicalSiteUrlCannotRead(string $url): void
    {
        $errors = settings_form_errors($this->post(['site_url' => $url]));

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('Site URL', $errors[0]);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unusableSiteUrlProvider(): array
    {
        return [
            'no scheme' => ['example.com'],
            'not http' => ['ftp://example.com'],
            'no host' => ['https://'],
            'not a url' => ['not a url'],
        ];
    }

    public function testSettingsFormErrorsChecksThePostedSiteUrlNotTheEnvironmentOverride(): void
    {
        putenv('LAMB_SITE_URL=https://pinned.example');
        try {
            $errors = settings_form_errors($this->post(['site_url' => 'not a url']));
        } finally {
            putenv('LAMB_SITE_URL');
        }

        $this->assertCount(1, $errors);
    }

    public function testSettingsFormErrorsNamesTheSectionAndLineOfEachMalformedEntry(): void
    {
        $errors = settings_form_errors($this->post([
            'menu_items' => "Home = /\nAbout",
            'redirections' => "old = /new\nbroken line",
        ]));

        $this->assertCount(2, $errors);
        $this->assertStringContainsString('[menu_items]', $errors[0]);
        $this->assertStringContainsString('line 2', $errors[0]);
        $this->assertStringContainsString('[redirections]', $errors[1]);
        $this->assertStringContainsString('line 2', $errors[1]);
    }

    public function testSettingsFormErrorsRefusesWhenTheStoredIniCannotBeRead(): void
    {
        // A form save carries over what is stored. Unreadable INI has nothing
        // to carry, so saving would silently drop every setting the form lacks.
        $errors = settings_form_errors($this->post(), "[unclosed\n=== not ini ===");

        $this->assertCount(1, $errors);
        $this->assertStringContainsString("can't be read", $errors[0]);
    }

    /**
     * @dataProvider nothingToCarryOverProvider
     */
    public function testSettingsFormErrorsAcceptsAStoredIniWithNothingToLose(string $stored): void
    {
        $this->assertSame([], settings_form_errors($this->post(), $stored));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nothingToCarryOverProvider(): array
    {
        return [
            'empty' => [''],
            'whitespace only' => ["  \n\t\n"],
            'comments only' => ["; just a note\n;; another\n"],
        ];
    }

    public function testSettingsFormErrorsCountsLinesFromTheTopOfTheTextarea(): void
    {
        $errors = settings_form_errors($this->post(['menu_items' => "\r\n\r\nAbout"]));

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('line 3', $errors[0]);
    }

    public function testSettingsFormErrorsRejectsANulByteInASectionLine(): void
    {
        $post = $this->post(['menu_items' => "Home = /\0evil\nAb\0out = about"]);

        $errors = settings_form_errors($post);

        $this->assertCount(2, $errors);
        $this->assertStringContainsString('line 1', $errors[0]);
        $this->assertStringContainsString('line 2', $errors[1]);
        // The handler never reaches form_to_ini() with these, but it must not throw if it did.
        $this->assertSame([], parse_ini_safe(form_to_ini($post, ''))['menu_items']);
    }

    public function testSettingsFormErrorsRejectsASingleValueSpanningLines(): void
    {
        $errors = settings_form_errors($this->post(['site_description' => "one\ntwo"]));

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('site_description', $errors[0]);
    }

    // write_ini

    /**
     * @dataProvider awkwardValueProvider
     */
    public function testWriteIniRoundTripsThroughParseIniSafe(string $value): void
    {
        $ini = write_ini(['single' => $value], ['section' => ['Label' => $value]]);

        $this->assertSame(['single' => $value, 'section' => ['Label' => $value]], parse_ini_safe($ini));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function awkwardValueProvider(): array
    {
        return [
            'plain' => ['plain'],
            'empty' => [''],
            'url with query' => ['https://example.com/search?a=b&c=d'],
            'semicolon in a title' => ['Tom; Jerry'],
            'equals sign' => ['a = b'],
            'double quotes' => ['She said "hi"'],
            'fully quoted' => ['"quoted"'],
            'leading space' => ['  leading'],
            'trailing space' => ['trailing  '],
            'brackets' => ['[not a section]'],
            'backslash' => ['C:\\path\\'],
            'single quotes' => ["it's"],
            'literal true' => ['true'],
            'unicode' => ['Café ☃ 日本語'],
            'dollar and braces' => ['${HOME} {x}'],
        ];
    }

    public function testWriteIniPutsSingleValuesBeforeSections(): void
    {
        $ini = write_ini(['a' => '1', 'b' => '2'], ['s' => ['x' => 'y']]);

        $this->assertSame("a = 1\nb = 2\n\n[s]\nx = y\n", $ini);
    }

    public function testWriteIniQuotesOnlyWhatTheParserWouldMisread(): void
    {
        $ini = write_ini(['plain' => 'https://example.com/a b?c', 'semi' => 'a;b', 'query' => '/?a=b'], []);

        $this->assertStringContainsString("plain = https://example.com/a b?c\n", $ini);
        $this->assertStringContainsString("semi = \"a;b\"\n", $ini);
        $this->assertStringContainsString("query = \"/?a=b\"\n", $ini);
    }

    /**
     * @dataProvider multilineValueProvider
     */
    public function testWriteIniRejectsValuesContainingANewline(string $value): void
    {
        $this->expectException(\InvalidArgumentException::class);

        write_ini(['k' => $value], []);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function multilineValueProvider(): array
    {
        return ['lf' => ["a\nb"], 'cr' => ["a\rb"]];
    }

    public function testFormToIniRoundTripsAwkwardFormValues(): void
    {
        $title = 'Tom; "Jerry" = [1] ☃';
        $ini = form_to_ini($this->post([
            'site_title' => $title,
            'site_url' => 'https://example.com/?a=b&c=d',
            'menu_items' => 'Café = /café?a=b&c=d',
        ]), '');

        $parsed = parse_ini_safe($ini);
        $this->assertSame($title, $parsed['site_title']);
        $this->assertSame('https://example.com/?a=b&c=d', $parsed['site_url']);
        $this->assertSame(['Café' => '/café?a=b&c=d'], $parsed['menu_items']);
    }

    // removed settings

    /**
     * @dataProvider removedScalarSettingProvider
     */
    public function testShapeWarningsIsSilentAboutARemovedScalarSetting(string $ini): void
    {
        $this->assertSame([], shape_warnings($ini));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function removedScalarSettingProvider(): array
    {
        return [
            'posts_per_page' => ["posts_per_page = 5\n"],
            '404_fallback' => ["404_fallback = https://old.example\n"],
            'micropub_debug' => ["micropub_debug = true\n"],
            'experimental_features' => ["experimental_features = true\n"],
        ];
    }

    public function testShapeWarningsStillWarnsAboutAnEmptySectionThatShadowsASingleValueSetting(): void
    {
        // `[site_title]` with no keys drops the title: it is not a retired section.
        $warnings = shape_warnings("[site_title]\n");

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('`[site_title]` is not a section Lamb reads', $warnings[0]);
    }

    /**
     * @dataProvider removedSettingInUseProvider
     */
    public function testRemovedSettingWarningsNameASettingInUseAndItsReplacement(string $ini, string $named, string $replacement): void
    {
        $warnings = removed_setting_warnings($ini);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString($named, $warnings[0]);
        $this->assertStringContainsString($replacement, $warnings[0]);
    }

    /**
     * @return array<string, array{string, string, string}>
     */
    public static function removedSettingInUseProvider(): array
    {
        return [
            'posts_per_page' => ["posts_per_page = 5\n", '`posts_per_page`', 'Lamb uses 10 posts per page.'],
            '404_fallback' => ["404_fallback = https://old.example\n", '`404_fallback`', '`[redirections]` entry'],
            'preconnect' => ["[preconnect]\nfonts = https://fonts.googleapis.com\n", '`[preconnect]`', 'adds preconnect hints'],
            'micropub_debug' => ["micropub_debug = true\n", '`micropub_debug`', '`LAMB_MICROPUB_DEBUG`'],
            'experimental_features' => ["experimental_features = true\n", '`experimental_features`', '`--experimental`'],
        ];
    }

    public function testRemovedSettingWarningsIgnoreA404FallbackThatIsNotAUrl(): void
    {
        // The old code only redirected to a value that passed FILTER_VALIDATE_URL.
        $this->assertSame([], removed_setting_warnings("404_fallback = not a url\n"));
    }

    public function testRemovedSettingWarningsAreSilentAboutTheValuesEarlierVersionsSeeded(): void
    {
        // Every install seeded before 0.17 stores these, whether or not the author set them.
        $seeded = "posts_per_page = 10\nexperimental_features = false\nmicropub_debug = false\n[preconnect]\n\n[me]\n";

        $this->assertSame([], removed_setting_warnings($seeded));
    }

    public function testRemovedSettingsReplacementsMatchTheSiteConfigurationDocs(): void
    {
        $docs = (string) file_get_contents(dirname(__DIR__, 2) . '/docs/site-configuration.md');

        foreach (REMOVED_SETTINGS as $replacement) {
            $this->assertStringContainsString("| {$replacement} |", $docs);
        }
    }

    // respond_settings

    public function testRespondSettingsRefusesAFormPostMissingARequiredField(): void
    {
        $_SESSION[SESSION_LOGIN] = true;
        $_SESSION[HIDDEN_CSRF_NAME] = 'tok';
        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_POST = $this->post([
            HIDDEN_CSRF_NAME => 'tok',
            'author_name' => '',
            'site_title' => 'Typed but not saved',
        ]);
        $before = \Lamb\Config\get_ini_text();

        $result = respond_settings();

        $this->assertSame($before, \Lamb\Config\get_ini_text());
        $this->assertSame('Typed but not saved', $result['form_values']['site_title']);
        $this->assertStringContainsString('Author name', implode("\n", $_SESSION['flash']));
    }

    public function testRespondSettingsSavesAFormPostAndKeepsUnknownSettingsQuietly(): void
    {
        $result = $this->runSettingsPost(
            $this->post(['menu_items' => 'Home = /', 'feeds_draft' => '1']),
            "posts_per_page = 10\nmystery = kept\n[preconnect]\n\n[me]\n"
        );

        $parsed = parse_ini_safe($result['ini']);
        $this->assertSame('My Blog', $parsed['site_title']);
        $this->assertSame(['Home' => '/'], $parsed['menu_items']);
        $this->assertSame('kept', $parsed['mystery']);
        $this->assertSame([], $result['flash']);
    }

    public function testRespondSettingsSaveRemovesEachRemovedSettingAndSaysSoOnce(): void
    {
        $stored = "posts_per_page = 5\n404_fallback = https://old.example\nmicropub_debug = true\n"
            . "experimental_features = true\n\n[preconnect]\nfonts = https://fonts.googleapis.com\n";

        $first = $this->runSettingsPost($this->post(['menu_items' => 'Home = /']), $stored);

        $parsed = parse_ini_safe($first['ini']);
        foreach (['posts_per_page', '404_fallback', 'micropub_debug', 'experimental_features', 'preconnect'] as $key) {
            $this->assertArrayNotHasKey($key, $parsed);
        }
        $this->assertCount(5, $first['flash']);
        $this->assertStringContainsString('`[preconnect]`', implode("\n", $first['flash']));

        $second = $this->runSettingsPost($this->post(['menu_items' => 'Home = /']), $first['ini']);

        $this->assertSame([], $second['flash']);
    }

    public function testRespondSettingsRefusesAFormPostWhenTheStoredIniCannotBeRead(): void
    {
        $broken = "[unclosed\n=== not ini ===\nposts_per_page = 5\n";

        $result = $this->runSettingsPost($this->post(['menu_items' => 'Home = /']), $broken);

        $this->assertSame($broken, $result['ini']);
        $this->assertStringContainsString("can't be read", implode("\n", $result['flash']));
    }

    public function testRespondSettingsRefusesAPostThatCarriesNoFormMarker(): void
    {
        $stored = "site_title = Kept\nposts_per_page = 5\n";

        $result = $this->runSettingsPost(['ini_text' => "site_title = Overwritten\n"], $stored);

        $this->assertSame($stored, $result['ini']);
        $this->assertStringContainsString('Settings not saved', implode("\n", $result['flash']));
    }

    public function testRespondSettingsResetStillRestoresTheDefaults(): void
    {
        $result = $this->runSettingsPost(['action' => 'reset'], "[unclosed\n=== not ini ===\n");

        $this->assertSame(get_default_ini_text(), $result['ini']);
        $this->assertContains('Settings reset to defaults.', $result['flash']);
    }

    /**
     * Runs respond_settings() with a logged-in POST in a subprocess.
     *
     * A saved form ends in redirect_uri(), which die()s, so the handler cannot
     * run inside the PHPUnit process. The subprocess reports the stored INI and
     * the flash messages from a shutdown function.
     *
     * @param array<string, string> $post       The posted fields.
     * @param string                $stored_ini The INI stored before the request.
     * @return array{ini: string, flash: list<string>}
     * @throws \JsonException When the subprocess prints something that is not JSON.
     */
    private function runSettingsPost(array $post, string $stored_ini): array
    {
        $script = <<<'PHP'
        use RedBeanPHP\R;

        require $argv[1];
        define('ROOT_URL', 'http://localhost');
        R::setup('sqlite::memory:');
        \Lamb\Bootstrap\ensure_schema();
        \Lamb\Config\save_ini_text($argv[3]);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        $_SESSION = [SESSION_LOGIN => true, HIDDEN_CSRF_NAME => 'tok'];
        $_POST = json_decode($argv[2], true) + [HIDDEN_CSRF_NAME => 'tok'];

        register_shutdown_function(function () {
            echo json_encode([
                'ini' => \Lamb\Config\get_ini_text(),
                'flash' => $_SESSION['flash'] ?? [],
            ]);
        });
        \Lamb\Response\respond_settings();
        PHP;

        $process = new Process([
            'php', '-r', $script,
            dirname(__DIR__, 2) . '/vendor/autoload.php',
            json_encode($post, JSON_THROW_ON_ERROR),
            $stored_ini,
        ]);
        $process->run();

        /** @var array{ini: string, flash: list<string>} */
        return json_decode(
            $process->getOutput(),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    }
}

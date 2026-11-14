<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Theme\action_restore;
use function Lamb\Theme\admin_toolbar_html;
use function Lamb\Theme\escape;
use function Lamb\Theme\escape_xml;
use function Lamb\Theme\format_past_date;
use function Lamb\Theme\human_time;
use function Lamb\Theme\og_escape;
use function Lamb\Theme\resolve_theme;
use function Lamb\Theme\sanitize_filename;
use function Lamb\Theme\the_entry_form;
use function Lamb\Theme\the_preconnect;

class ThemeTest extends TestCase
{
    // the_preconnect

    // the_preconnect: origins derived from the posts on the page

    /**
     * Renders the_preconnect() for a page showing the given post bodies.
     *
     * @param list<string> $bodies Rendered post HTML, one entry per post on the page; `{ROOT_URL}` stands for the site's own URL.
     * @param array<string, string> $configured The removed [preconnect] setting, set to show it has no effect.
     * @return string The emitted <link> tags.
     */
    private function preconnectFor(array $bodies, array $configured = []): string
    {
        global $config, $data, $template;
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        $config = ['preconnect' => $configured];
        $template = 'home';
        $data = ['posts' => array_map(static function (string $html) {
            $bean = R::dispense('post');
            $bean->transformed = str_replace('{ROOT_URL}', ROOT_URL, $html);
            return $bean;
        }, $bodies)];

        ob_start();
        the_preconnect();
        return ob_get_clean();
    }

    /**
     * Lists the preconnect hrefs in the order they were emitted.
     *
     * @param string $output The emitted <link> tags.
     * @return list<string>
     */
    private function preconnectHrefs(string $output): array
    {
        preg_match_all('/<link rel="preconnect" href="([^"]*)">/', $output, $m);
        return $m[1];
    }

    public function testPreconnectDerivesOriginFromRemoteImage()
    {
        $output = $this->preconnectFor(['<p><img src="https://img.example.net/a.jpg" alt=""></p>']);

        $this->assertStringContainsString('<link rel="preconnect" href="https://img.example.net">', $output);
        $this->assertStringContainsString('<link rel="dns-prefetch" href="https://img.example.net">', $output);
    }

    /**
     * @dataProvider provideBodiesWithoutRemoteMedia
     */
    public function testPreconnectIgnoresLocalMedia(string $html)
    {
        $this->assertSame('', $this->preconnectFor([$html]));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function provideBodiesWithoutRemoteMedia(): array
    {
        return [
            'no media' => ['<p>Hello</p>'],
            'root-relative image' => ['<img src="/assets/2026/01/a.webp">'],
            'same-origin absolute image' => ['<img src="{ROOT_URL}/assets/a.webp">'],
            'non-http scheme' => ['<img src="data:image/gif;base64,R0lGOD">'],
            'protocol-less relative path' => ['<img src="assets/a.webp">'],
            'remote link is not media' => ['<a href="https://example.net/page">x</a>'],
        ];
    }

    public function testPreconnectSkipsOwnOriginSpelledWithItsDefaultPort()
    {
        $default_port = parse_url(ROOT_URL, PHP_URL_SCHEME) === 'https' ? 443 : 80;

        $this->assertSame('', $this->preconnectFor(['<img src="{ROOT_URL}:' . $default_port . '/a.webp">']));
    }

    public function testPreconnectResolvesProtocolRelativeUrlWithTheSitesScheme()
    {
        $output = $this->preconnectFor(['<img src="//img.example.net/a.webp">']);

        $scheme = parse_url(ROOT_URL, PHP_URL_SCHEME);
        $this->assertSame([$scheme . '://img.example.net'], $this->preconnectHrefs($output));
    }

    public function testPreconnectSkipsProtocolRelativeUrlOnTheSitesOwnHost()
    {
        $own_host_url = preg_replace('#^https?:#', '', ROOT_URL) . '/a.webp';

        $this->assertSame('', $this->preconnectFor(['<img src="' . $own_host_url . '">']));
    }

    public function testPreconnectDerivesOriginFromSrcsetPosterAndSource()
    {
        $output = $this->preconnectFor([
            '<img src="/a.jpg" srcset="/a-480.jpg 480w, https://set.example.net/a-960.jpg 960w">'
            . '<video poster="https://poster.example.net/p.jpg"><source src="https://video.example.net/v.mp4"></video>',
        ]);

        $this->assertSame(
            ['https://set.example.net', 'https://poster.example.net', 'https://video.example.net'],
            $this->preconnectHrefs($output)
        );
    }

    public function testPreconnectKeepsPortAndRepeatsOriginOnce()
    {
        $output = $this->preconnectFor([
            '<img src="https://img.example.net:8443/a.jpg">',
            '<img src="https://IMG.example.net:8443/b.jpg"><img src="https://img.example.net/c.jpg">',
        ]);

        $this->assertSame(['https://img.example.net:8443', 'https://img.example.net'], $this->preconnectHrefs($output));
    }

    public function testPreconnectEmitsAtMostThreeOriginsInOrderOfFirstAppearance()
    {
        $output = $this->preconnectFor([
            '<img src="https://a.example.net/1.jpg">',
            '<img src="https://b.example.net/1.jpg"><img src="https://a.example.net/2.jpg">',
            '<img src="https://c.example.net/1.jpg"><img src="https://d.example.net/1.jpg">',
        ]);

        $this->assertSame(
            ['https://a.example.net', 'https://b.example.net', 'https://c.example.net'],
            $this->preconnectHrefs($output)
        );
        $this->assertSame(3, substr_count($output, 'rel="dns-prefetch"'));
    }

    public function testPreconnectIgnoresTheRemovedPreconnectSetting()
    {
        $output = $this->preconnectFor(
            ['<img src="https://a.example.net/1.jpg">'],
            ['fonts' => 'https://fonts.example.net']
        );

        $this->assertSame(['https://a.example.net'], $this->preconnectHrefs($output));
    }

    public function testPreconnectSkipsPostsHiddenFromTheListing()
    {
        global $config, $data, $template;
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        $config = ['menu_items' => ['About' => '/about']];
        $template = 'home';
        $about = R::dispense('post');
        $about->slug = 'about';
        $about->transformed = '<img src="https://hidden.example.net/a.jpg">';
        $data = ['posts' => [$about]];

        ob_start();
        try {
            the_preconnect();
        } finally {
            $output = ob_get_clean();
        }

        $this->assertSame('', $output);
    }

    public function testPreconnectIgnoresHostsThatAreNotHostnames()
    {
        $output = $this->preconnectFor(['<img src="https://a.example.net&quot;&gt;&lt;script&gt;/x.jpg">']);

        $this->assertSame('', $output);
    }

    // escape

    public function testEscapeConvertsAngleBrackets()
    {
        $this->assertSame('&lt;b&gt;bold&lt;/b&gt;', escape('<b>bold</b>'));
    }

    public function testEscapeConvertsDoubleQuotes()
    {
        $this->assertSame('say &quot;hello&quot;', escape('say "hello"'));
    }

    public function testEscapeConvertsSingleQuotes()
    {
        // ENT_HTML5 encodes single quotes as &apos;
        $this->assertSame('it&apos;s', escape("it's"));
    }

    public function testEscapePassesThroughSafeText()
    {
        $this->assertSame('Hello, world!', escape('Hello, world!'));
    }

    public function testEscapeHandlesEmptyString()
    {
        $this->assertSame('', escape(''));
    }

    public function testEscapeConvertsAmpersand()
    {
        $this->assertSame('foo &amp; bar', escape('foo & bar'));
    }

    // escape_xml

    public function testEscapeXmlConvertsAmpersand()
    {
        $this->assertSame('foo &amp; bar', escape_xml('foo & bar'));
    }

    public function testEscapeXmlConvertsAngleBrackets()
    {
        $this->assertSame('&lt;loc&gt;', escape_xml('<loc>'));
    }

    public function testEscapeXmlConvertsSingleQuoteToApos()
    {
        // ENT_XML1 encodes the single quote as the defined XML entity &apos;.
        $this->assertSame('it&apos;s', escape_xml("it's"));
    }

    public function testEscapeXmlSubstitutesInvalidUtf8ByteInsteadOfEmptyingTheString()
    {
        // ENT_SUBSTITUTE: a lone invalid byte degrades to U+FFFD rather than
        // making htmlspecialchars() return '' for the whole value.
        $result = escape_xml("ok\xB1");
        $this->assertNotSame('', $result);
        $this->assertSame("ok\u{FFFD}", $result);
    }

    public function testEscapeXmlStripsControlCharactersInvalidInXml10()
    {
        // htmlspecialchars() only rewrites & < > " ' — a raw control byte
        // (e.g. from a Micropub JSON title with a unicode-escaped control char) passes
        // straight through it, and XML 1.0 forbids that byte even escaped:
        // embedding it makes the whole document fail to parse. It must be
        // gone from the result, not merely left "escaped".
        $result = escape_xml("Hello\x01World");
        $this->assertSame('HelloWorld', $result);

        $document = new \DOMDocument();
        $wellFormed = $document->loadXML('<root><title>' . $result . '</title></root>');
        $this->assertTrue($wellFormed);
    }

    public function testEscapeXmlStripsOtherXmlInvalidControlCharactersButKeepsTabNewlineCr()
    {
        // Tab, LF and CR are explicitly valid in XML 1.0 and must survive;
        // the rest of the C0 control range (and the U+FFFE/U+FFFF
        // noncharacters) must not.
        $result = escape_xml("a\x0Bb\x0Cc\x1Fd\tE\nF\rG\u{FFFE}h\u{FFFF}");
        $this->assertSame("abcd\tE\nF\rGh", $result);
    }

    // og_escape

    public function testOgEscapeConvertsAngleBrackets()
    {
        $result = og_escape('<b>bold</b>');
        $this->assertStringNotContainsString('<b>', $result);
    }

    public function testOgEscapeDoesNotDoubleEncodeEntities()
    {
        // og_escape decodes then re-encodes, so &amp; should stay as &amp;
        $result = og_escape('foo &amp; bar');
        $this->assertSame('foo &amp; bar', $result);
    }

    public function testOgEscapeHandlesPlainText()
    {
        $this->assertSame('Hello world', og_escape('Hello world'));
    }

    // sanitize_filename

    public function testSanitizeFilenameAllowsAlphanumeric()
    {
        $this->assertSame('hello123', sanitize_filename('hello123'));
    }

    public function testSanitizeFilenameAllowsHyphensAndUnderscores()
    {
        $this->assertSame('hello-world_test', sanitize_filename('hello-world_test'));
    }

    public function testSanitizeFilenameReplacesSpacesWithUnderscores()
    {
        $this->assertSame('hello_world', sanitize_filename('hello world'));
    }

    public function testSanitizeFilenameReplacesSlashesWithUnderscores()
    {
        $this->assertSame('path_to_file', sanitize_filename('path/to/file'));
    }

    public function testSanitizeFilenameReplacesDotsWithUnderscores()
    {
        $this->assertSame('file_php', sanitize_filename('file.php'));
    }

    public function testSanitizeFilenameHandlesEmptyString()
    {
        $this->assertSame('', sanitize_filename(''));
    }

    // Regression: index.php defines THEME from the admin-editable config INI's
    // `theme` value and uses it both to build a require() path (THEME_DIR) and
    // echoes it raw into HTML on every page view (THEME_URL, e.g. font preload
    // links in themes/2026/html.php) — sanitize_filename() is applied to that
    // value specifically to close both a path-traversal primitive and a
    // site-wide stored-XSS surface.

    public function testSanitizeFilenameStripsPathTraversalSequences()
    {
        $sanitized = sanitize_filename('../../../../etc/passwd');
        $this->assertStringNotContainsString('.', $sanitized);
        $this->assertStringNotContainsString('/', $sanitized);
        $this->assertStringContainsString('etc', $sanitized);
        $this->assertStringContainsString('passwd', $sanitized);
    }

    public function testSanitizeFilenameNeutralizesHtmlBreakoutCharacters()
    {
        $sanitized = sanitize_filename('x"><script>alert(1)</script>');
        $this->assertStringNotContainsString('"', $sanitized);
        $this->assertStringNotContainsString('<', $sanitized);
        $this->assertStringNotContainsString('>', $sanitized);
    }

    // resolve_theme

    /**
     * ROOT_DIR is a process-wide constant that other unit tests may have
     * already pointed at their own temp dir (see ConfigLoadTest), so we
     * operate on wherever it actually points rather than assume our own
     * define() wins — same rationale as ThemeMetaTest::resetWebRoot().
     */
    private function ensureRootDir(): void
    {
        if (!defined('ROOT_DIR')) {
            define('ROOT_DIR', sys_get_temp_dir() . '/lamb_theme_resolve_test_' . getmypid());
        }
    }

    private function themesDir(): string
    {
        $this->ensureRootDir();
        $dir = ROOT_DIR . '/themes';
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        return $dir;
    }

    /**
     * Runs $callback with the named theme directories present, then removes
     * only the ones this call created.
     *
     * @param string[] $names Theme directory names under ROOT_DIR/themes.
     * @param callable $callback The code to run.
     * @return mixed What $callback returns.
     */
    private function withThemeDirs(array $names, callable $callback): mixed
    {
        $themes  = $this->themesDir();
        $created = [];

        try {
            foreach ($names as $name) {
                if (!is_dir($themes . '/' . $name)) {
                    mkdir($themes . '/' . $name, 0777, true);
                    $created[] = $themes . '/' . $name;
                }
            }

            return $callback();
        } finally {
            foreach (array_reverse($created) as $dir) {
                rmdir($dir);
            }
        }
    }

    public function testResolveThemeReturnsBaseWhenNoThemeConfigured(): void
    {
        $this->assertSame('base', resolve_theme(null));
    }

    // Regression: this is the legacy pre-#289 theme name (src/themes/default
    // was renamed to src/themes/base) that a migration used to rewrite on
    // read. Without a directory of that name, it must still resolve to a
    // working theme rather than a broken THEME_DIR.
    public function testResolveThemeFallsBackToBaseForLegacyDefaultName(): void
    {
        $this->ensureRootDir();

        $this->assertSame('base', resolve_theme('default'));
    }

    public function testResolveThemeFallsBackForNonScalarConfiguredValue(): void
    {
        // normalize_config() drops a `[theme]` section header entirely, so in
        // practice this arrives as null — covered directly here too since
        // resolve_theme() takes mixed input.
        $this->assertSame('base', resolve_theme(['nested' => 'array']));
    }

    public function testResolveThemeReturnsTheConfiguredNameWhenItsDirectoryExists(): void
    {
        $dir = $this->themesDir() . '/resolve_theme_fixture';
        mkdir($dir, 0777, true);

        try {
            $this->assertSame('resolve_theme_fixture', resolve_theme('resolve_theme_fixture'));
        } finally {
            rmdir($dir);
        }
    }

    // The 2024 theme was removed in 0.17: a stored `2024` renders as 2026, even
    // when a tarball upgrade left the old src/themes/2024 directory behind.
    public function testResolveThemeRendersARemovedThemeAsItsReplacement(): void
    {
        $this->assertSame('2026', $this->withThemeDirs(['2026'], fn () => resolve_theme('2024')));
    }

    public function testResolveThemeRendersARemovedThemeAsItsReplacementWhenItsOldDirectoryRemains(): void
    {
        $this->assertSame('2026', $this->withThemeDirs(['2026', '2024'], fn () => resolve_theme('2024')));
    }

    // A leftover src/themes/2024 would otherwise be offered as a choice that renders as 2026.
    public function testSettingsThemeDropdownOmitsRemovedThemes(): void
    {
        global $data;
        $data = ['ini_text' => "theme = 2024\n"];

        $html = $this->withThemeDirs(['2026', '2024'], function (): string {
            ob_start();
            include dirname(__DIR__, 2) . '/src/themes/base/parts/settings.php';

            return (string) ob_get_clean();
        });

        $this->assertStringContainsString('<option selected>2026</option>', $html);
        $this->assertDoesNotMatchRegularExpression('/>2024<\/option>/', $html);
    }

    public function testResolveThemeFallsBackWhenConfiguredThemeHasNoDirectory(): void
    {
        $this->ensureRootDir();

        $this->assertSame('base', resolve_theme('totally-made-up-theme-xyz'));
    }

    public function testResolveThemeSanitizesBeforeMatchingADirectory(): void
    {
        $dir = $this->themesDir() . '/sanitized_theme_name';
        mkdir($dir, 0777, true);

        try {
            $this->assertSame('sanitized_theme_name', resolve_theme('sanitized theme name'));
        } finally {
            rmdir($dir);
        }
    }

    public function testResolveThemeNeverEscapesThemesDirectoryViaTraversal(): void
    {
        $this->ensureRootDir();

        $resolved = resolve_theme('../../../../etc/passwd');

        $this->assertSame('base', $resolved);
        $this->assertStringNotContainsString('/', $resolved);
        $this->assertStringNotContainsString('.', $resolved);
    }

    // format_past_date

    public function testFormatPastDateYesterdayWhenJIs3AndDiffIs1()
    {
        $ts = mktime(10, 0, 0, 1, 1, 2020);
        $result = format_past_date(3, 1, $ts);
        $this->assertStringContainsString('Yesterday', $result);
    }

    public function testFormatPastDateDayNameWhenJIs3AndDiffIsNot1()
    {
        $ts = mktime(10, 0, 0, 1, 1, 2020);
        $result = format_past_date(3, 3, $ts);
        // Should be a day-of-week name like "Wednesday at ..."
        $this->assertMatchesRegularExpression('/^(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)/', $result);
    }

    public function testFormatPastDateMonthDayIncludesYearWhenJIs4AndYearDiffers()
    {
        $ts = mktime(10, 0, 0, 6, 15, 2020);
        $result = format_past_date(4, 3, $ts);
        $this->assertStringContainsString('June 15', $result);
        $this->assertStringContainsString('2020', $result);
    }

    public function testFormatPastDateMonthDayOmitsYearWhenJIs4AndYearMatches()
    {
        $year = (int) date('Y');
        $ts = mktime(10, 0, 0, 1, 15, $year);
        $result = format_past_date(4, 3, $ts);
        $this->assertStringContainsString('January 15', $result);
        $this->assertStringNotContainsString((string) $year, $result);
    }

    public function testFormatPastDateMonthDayIncludesYearWhenJIs5AndYearDiffers()
    {
        $ts = mktime(10, 0, 0, 3, 10, 2020);
        $result = format_past_date(5, 3, $ts);
        $this->assertStringContainsString('March 10', $result);
        $this->assertStringContainsString('2020', $result);
    }

    public function testFormatPastDateMonthDayOmitsYearWhenJIs5AndYearMatches()
    {
        $year = (int) date('Y');
        $ts = mktime(10, 0, 0, 2, 10, $year);
        $result = format_past_date(5, 3, $ts);
        $this->assertStringContainsString('February 10', $result);
        $this->assertStringNotContainsString((string) $year, $result);
    }

    public function testFormatPastDateFullDateWithYearWhenJIs6()
    {
        $ts = mktime(10, 0, 0, 6, 30, 2010);
        $result = format_past_date(6, 2, $ts);
        $this->assertStringContainsString('2010', $result);
    }

    public function testFormatPastDateFullDateWhenJIs5AndDiffIs12()
    {
        $ts = mktime(10, 0, 0, 6, 30, 2010);
        $result = format_past_date(5, 12, $ts);
        // 12 months = 1 year ago, so should show year
        $this->assertStringContainsString('2010', $result);
    }

    // human_time

    public function testHumanTimeReturnsSecondsAgo()
    {
        $timestamp = time() - 30;
        $result = human_time($timestamp);
        $this->assertStringContainsString('ago', $result);
        $this->assertStringContainsString('second', $result);
    }

    public function testHumanTimeReturnsMinutesAgo()
    {
        $timestamp = time() - 120;
        $result = human_time($timestamp);
        $this->assertStringContainsString('ago', $result);
        $this->assertStringContainsString('minute', $result);
    }

    public function testHumanTimeReturnsHoursAgo()
    {
        $timestamp = time() - 7200;
        $result = human_time($timestamp);
        $this->assertStringContainsString('ago', $result);
        $this->assertStringContainsString('hour', $result);
    }

    public function testHumanTimeReturnsYesterdayForOneDayAgo()
    {
        $timestamp = time() - 86400;
        $result = human_time($timestamp);
        $this->assertStringContainsString('Yesterday', $result);
    }

    public function testHumanTimeReturnsToGoForFutureTimestamp()
    {
        $timestamp = time() + 30;
        $result = human_time($timestamp);
        $this->assertStringContainsString('to go', $result);
    }

    public function testHumanTimeReturnsTomorrowForOneDayAhead()
    {
        $timestamp = time() + 86400;
        $result = human_time($timestamp);
        $this->assertStringContainsString('Tomorrow', $result);
    }

    // action_restore

    public function testActionRestoreReturnsEmptyWhenNotLoggedIn(): void
    {
        $_SESSION = [];
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        $bean = R::dispense('post');
        $bean->deleted = 1;
        R::store($bean);

        $this->assertSame('', action_restore($bean));
    }

    public function testActionRestoreReturnsFormWhenLoggedIn(): void
    {
        $_SESSION[SESSION_LOGIN] = true;
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        $bean = R::dispense('post');
        $bean->deleted = 1;
        R::store($bean);

        $html = action_restore($bean);
        $this->assertStringContainsString('/restore/' . $bean->id, $html);
        $this->assertStringContainsString('Restore post', $html);
    }

    // admin_toolbar_html

    protected function setUpDb(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);

        $schema = R::dispense('post');
        $schema->draft   = null;
        $schema->deleted = null;
        R::store($schema);
        R::exec('DELETE FROM post');
    }

    public function testAdminToolbarHtmlContainsLinks(): void
    {
        $this->setUpDb();
        $html = admin_toolbar_html();
        $this->assertStringContainsString('<a href="/drafts">', $html);
        $this->assertStringContainsString('<a href="/trash">', $html);
        $this->assertStringContainsString('<a href="/settings">', $html);
        $this->assertStringContainsString('<a href="/logout">', $html);
        $this->assertMatchesRegularExpression(
            '~<a href="https://svandragt\.github\.io/lamb"[^>]*>Help</a><a href="/logout">~',
            $html
        );
    }

    public function testAdminToolbarHtmlShowsDraftCountWhenDraftsExist(): void
    {
        $this->setUpDb();

        $draft = R::dispense('post');
        $draft->body  = 'A draft';
        $draft->draft = 1;
        $draft->created = date('Y-m-d H:i:s');
        R::store($draft);

        $html = admin_toolbar_html();
        $this->assertStringContainsString('Drafts (1)', $html);
    }

    public function testAdminToolbarHtmlShowsNoDraftCountWhenNoDrafts(): void
    {
        $this->setUpDb();
        $html = admin_toolbar_html();
        $this->assertStringContainsString('Drafts</a>', $html);
        $this->assertStringNotContainsString('Drafts (', $html);
    }

    public function testAdminToolbarHtmlShowsTrashCountWhenTrashedPostsExist(): void
    {
        $this->setUpDb();

        $deleted = R::dispense('post');
        $deleted->body       = 'Deleted post';
        $deleted->deleted    = 1;
        $deleted->deleted_at = date('Y-m-d H:i:s');
        $deleted->created    = date('Y-m-d H:i:s');
        R::store($deleted);

        $html = admin_toolbar_html();
        $this->assertStringContainsString('Trash (1)', $html);
    }

    public function testAdminToolbarHtmlContainsStyleElement(): void
    {
        $this->setUpDb();
        $html = admin_toolbar_html();
        $this->assertStringContainsString('<style>', $html);
        $this->assertStringContainsString('#admin-toolbar', $html);
    }

    // the_entry_form

    public function testTheEntryFormOutputsNothingWhenNotLoggedIn(): void
    {
        $_SESSION = [];
        ob_start();
        the_entry_form();
        $output = ob_get_clean();
        $this->assertSame('', $output);
    }

    public function testTheEntryFormOutputsFormWhenLoggedIn(): void
    {
        $_SESSION[SESSION_LOGIN] = true;
        ob_start();
        the_entry_form();
        $output = ob_get_clean();
        $this->assertStringContainsString('<form', $output);
        $this->assertStringContainsString('textarea', $output);
        $_SESSION = [];
    }

    public function testTheEntryFormContainsCsrfFieldWhenLoggedIn(): void
    {
        $_SESSION[SESSION_LOGIN] = true;
        ob_start();
        the_entry_form();
        $output = ob_get_clean();
        $this->assertStringContainsString('name="csrf"', $output);
        $_SESSION = [];
    }
}

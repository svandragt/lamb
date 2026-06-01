<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Theme\asset_version;
use function Lamb\Theme\redirect_to;
use function Lamb\Theme\the_scripts;
use function Lamb\Theme\the_styles;

class ThemeAssetsTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'http://localhost');
        }
        if (!defined('THEME_URL')) {
            define('THEME_URL', 'themes/default/');
        }

        global $template;
        $template = 'home';

        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    // -------------------------------------------------------------------------
    // the_styles
    // -------------------------------------------------------------------------

    public function testTheStylesOutputsLinkStylesheetTag(): void
    {
        ob_start();
        the_styles();
        $output = ob_get_clean();

        $this->assertStringContainsString('<link rel="stylesheet"', $output);
    }

    public function testTheStylesOutputsStylesCssHref(): void
    {
        ob_start();
        the_styles();
        $output = ob_get_clean();

        $this->assertStringContainsString('styles.css', $output);
    }

    public function testTheStylesAppendsCacheBusterToHref(): void
    {
        ob_start();
        the_styles();
        $output = ob_get_clean();

        // href="...styles.css?ver=<md5hash>"
        $this->assertMatchesRegularExpression('/styles\.css\?ver=[a-f0-9]{32}/', $output);
    }

    public function testTheStylesOutputsIdAttribute(): void
    {
        ob_start();
        the_styles();
        $output = ob_get_clean();

        $this->assertStringContainsString(' id="', $output);
    }

    // -------------------------------------------------------------------------
    // the_scripts
    // -------------------------------------------------------------------------

    public function testTheScriptsOutputsShorthandJs(): void
    {
        ob_start();
        the_scripts();
        $output = ob_get_clean();

        $this->assertStringContainsString('shorthand.js', $output);
    }

    public function testTheScriptsOutputsScriptDeferTag(): void
    {
        ob_start();
        the_scripts();
        $output = ob_get_clean();

        $this->assertStringContainsString('<script', $output);
        $this->assertStringContainsString('defer', $output);
    }

    public function testTheScriptsDoesNotIncludeAdminScriptsWhenNotLoggedIn(): void
    {
        unset($_SESSION[SESSION_LOGIN]);

        ob_start();
        the_scripts();
        $output = ob_get_clean();

        $this->assertStringNotContainsString('growing-input.js', $output);
        $this->assertStringNotContainsString('confirm-delete.js', $output);
        $this->assertStringNotContainsString('link-edit-buttons.js', $output);
        $this->assertStringNotContainsString('upload-image.js', $output);
    }

    public function testTheScriptsIncludesAllAdminScriptsWhenLoggedIn(): void
    {
        $_SESSION[SESSION_LOGIN] = true;

        ob_start();
        the_scripts();
        $output = ob_get_clean();

        $this->assertStringContainsString('growing-input.js', $output);
        $this->assertStringContainsString('confirm-delete.js', $output);
        $this->assertStringContainsString('link-edit-buttons.js', $output);
        $this->assertStringContainsString('upload-image.js', $output);
        $this->assertStringContainsString('paste-link.js', $output);
    }

    public function testTheScriptsDoesNotIncludePasteLinkJsWhenNotLoggedIn(): void
    {
        unset($_SESSION[SESSION_LOGIN]);

        ob_start();
        the_scripts();
        $output = ob_get_clean();

        $this->assertStringNotContainsString('paste-link.js', $output);
    }

    public function testTheScriptsPasteLinkJsUrlPointsToExistingFile(): void
    {
        $_SESSION[SESSION_LOGIN] = true;

        ob_start();
        the_scripts();
        $output = ob_get_clean();

        preg_match('/src="([^"]*paste-link\.js[^"]*)"/', $output, $m);
        $this->assertNotEmpty($m, 'paste-link.js src attribute not found in output');

        $url_path = parse_url($m[1], PHP_URL_PATH);
        $project_src = dirname(__DIR__, 2) . '/src/';
        $file = $project_src . ltrim($url_path, '/');
        $this->assertFileExists($file, "Script file not found at $file — URL '$url_path' does not resolve to a real file");
    }

    public function testTheScriptsIncludesSearchHighlightJsWhenTemplateIsSearch(): void
    {
        global $template;
        $template = 'search';

        ob_start();
        the_scripts();
        $output = ob_get_clean();

        $this->assertStringContainsString('search-highlight.js', $output);
    }

    public function testTheScriptsDoesNotIncludeSearchHighlightJsOnHomePage(): void
    {
        global $template;
        $template = 'home';

        ob_start();
        the_scripts();
        $output = ob_get_clean();

        $this->assertStringNotContainsString('search-highlight.js', $output);
    }

    public function testTheScriptsSearchHighlightJsUrlPointsToExistingFile(): void
    {
        // The asset_loader uses the array key as both a condition and a subdirectory path.
        // search-highlight.js must live at src/scripts/search/search-highlight.js so that
        // the emitted URL scripts/search/search-highlight.js resolves to a real file.
        global $template;
        $template = 'search';

        ob_start();
        the_scripts();
        $output = ob_get_clean();

        preg_match('/src="([^"]*search-highlight\.js[^"]*)"/', $output, $m);
        $this->assertNotEmpty($m, 'search-highlight.js src attribute not found in output');

        // Strip ROOT_URL prefix and query string to get the relative URL path,
        // then map it to the real filesystem under src/ using the project root.
        $url_path = parse_url($m[1], PHP_URL_PATH);
        $project_src = dirname(__DIR__, 2) . '/src/';
        $file = $project_src . ltrim($url_path, '/');
        $this->assertFileExists($file, "Script file not found at $file — URL '$url_path' does not resolve to a real file");
    }

    // -------------------------------------------------------------------------
    // asset_version (cache-buster)
    // -------------------------------------------------------------------------

    public function testAssetVersionHashesFileContentsNotUrl(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'lamb_asset_');
        file_put_contents($tmp, 'body { color: red; }');
        try {
            $href = 'http://localhost/themes/default/styles/styles.css';
            $this->assertSame(md5_file($tmp), asset_version($tmp, $href));
            $this->assertSame(md5('body { color: red; }'), asset_version($tmp, $href));
        } finally {
            unlink($tmp);
        }
    }

    public function testAssetVersionChangesWhenContentChanges(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'lamb_asset_');
        try {
            $href = 'http://localhost/scripts/shorthand.js';
            file_put_contents($tmp, 'v1');
            $v1 = asset_version($tmp, $href);
            file_put_contents($tmp, 'v2');
            $v2 = asset_version($tmp, $href);
            $this->assertNotSame($v1, $v2, 'cache-buster must change when file content changes');
        } finally {
            unlink($tmp);
        }
    }

    public function testAssetVersionFallsBackToUrlHashWhenFileMissing(): void
    {
        $href = 'http://localhost/themes/default/styles/missing.css';
        $this->assertSame(md5($href), asset_version('/no/such/file.css', $href));
    }

    // -------------------------------------------------------------------------
    // redirect_to
    // -------------------------------------------------------------------------

    public function testRedirectToReturnsString(): void
    {
        $result = redirect_to();
        $this->assertIsString($result);
    }

    public function testRedirectToReturnsEmptyStringWhenQueryParamAbsent(): void
    {
        // filter_input(INPUT_GET, ...) returns null in CLI context; cast gives ''
        $result = redirect_to();
        $this->assertSame('', $result);
    }
}

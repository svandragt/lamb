<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

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

        // href="...styles.css?<md5hash>"
        $this->assertMatchesRegularExpression('/styles\.css\?[a-f0-9]{32}/', $output);
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

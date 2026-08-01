<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Covers the base theme's 404 part.
 *
 * The "why not search for …" suggestion used to read $data['action'], which the
 * router has always overwritten with the literal '404' by render time — so every
 * 404 page offered to search for "404". It is now driven by $data['requested']
 * (the path the visitor actually asked for), which is request-controlled and so
 * must also be escaped rather than echoed raw.
 */
class Theme404PartTest extends TestCase
{
    protected function setUp(): void
    {
        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'http://localhost');
        }
    }

    protected function tearDown(): void
    {
        global $data, $template;
        $data = [];
        $template = null;
    }

    private function render(string $requested): string
    {
        global $data, $config, $template;
        $config   = ['site_title' => 'Test Blog'];
        $template = '404';
        $data     = [
            'title'     => 'Page not found',
            'intro'     => 'Page not found.',
            'action'    => '404',
            'requested' => $requested,
        ];

        ob_start();
        include dirname(__DIR__, 2) . '/src/themes/base/parts/404.php';
        return (string) ob_get_clean();
    }

    public function testSuggestsSearchingForTheRequestedPath(): void
    {
        $html = $this->render('missing-post');

        $this->assertStringContainsString('/search/missing-post', $html);
        $this->assertStringContainsString('searching for missing-post', $html);
    }

    public function testDoesNotSuggestSearchingForTheLiteral404(): void
    {
        $html = $this->render('missing-post');

        $this->assertStringNotContainsString('/search/404', $html);
    }

    public function testOmitsTheSuggestionWhenThereIsNoRequestedPath(): void
    {
        $this->assertStringNotContainsString('/search/', $this->render(''));
    }

    public function testEscapesTheRequestedPath(): void
    {
        $html = $this->render('"><script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testRendersTheHumanTitleRatherThanAStatusLine(): void
    {
        $html = $this->render('missing-post');

        $this->assertStringContainsString('<h1>Page not found</h1>', $html);
        $this->assertStringNotContainsString('HTTP/', $html);
    }
}

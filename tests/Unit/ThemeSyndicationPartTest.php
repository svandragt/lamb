<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

/**
 * Every bundled theme has to render a post's syndication targets.
 *
 * docs/micropub.md promises that a post carrying `syndicated-to` shows
 * "Also on: …" links with the `u-syndication` microformat — the markup Bridgy
 * and other IndieWeb consumers read. Only the base theme did, so on a default
 * install (theme = 2026) the recorded targets were never shown at all.
 */
class ThemeSyndicationPartTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::nuke();

        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'http://localhost');
        }
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
    }

    private function renderItem(string $theme): string
    {
        $bean = R::dispense('post');
        $bean->title = 'Syndicated';
        $bean->transformed = '<p>body</p>';
        $bean->created = '2024-01-01 12:00:00';
        $bean->deleted = false;
        $bean->syndicated_to = 'https://bsky.app/profile/me';
        R::store($bean);

        global $data, $template, $config;
        $config = ['menu_items' => [], 'syndicate_to' => ['https://bsky.app/profile/me' => 'Bluesky']];
        $data = ['posts' => [$bean]];
        $template = 'status';

        ob_start();
        include dirname(__DIR__, 2) . "/src/themes/$theme/parts/_items.php";
        return (string) ob_get_clean();
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function themeProvider(): array
    {
        return [
            'base' => ['base'],
            '2026' => ['2026'],
            '2024' => ['2024'],
        ];
    }

    /**
     * @dataProvider themeProvider
     */
    public function testThemeRendersSyndicationLinks(string $theme): void
    {
        $html = $this->renderItem($theme);

        $this->assertStringContainsString('Also on:', $html);
        $this->assertStringContainsString('u-syndication', $html);
        $this->assertStringContainsString('Bluesky', $html);
    }
}

<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Theme\author_card;
use function Lamb\Theme\date_created;
use function Lamb\Theme\title_link;

/**
 * Verifies the microformats2 (h-entry / h-card) markup that Webmention
 * receivers and other mf2 parsers rely on to attribute and categorise posts.
 *
 * Covers the shared helpers (date_created, title_link, author_card) as units
 * and the rendered post-list partial of each shipped theme.
 */
class MicroformatsTest extends TestCase
{
    /** @var mixed */
    private $originalConfig;

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
        global $config;
        $this->originalConfig = $config;
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        global $config;
        $config = $this->originalConfig;
    }

    // Helpers -----------------------------------------------------------------

    public function testDateCreatedCarriesUUrlAndDtPublished(): void
    {
        $bean = R::dispense('post');
        $bean->created = '2024-01-01 12:00:00';

        $result = date_created($bean);
        $this->assertStringContainsString('class="u-url"', $result);
        $this->assertStringContainsString('class="dt-published"', $result);
        $this->assertStringContainsString('datetime="2024-01-01 12:00:00"', $result);
    }

    public function testTitleLinkCarriesPName(): void
    {
        $bean = R::dispense('post');
        $bean->title = 'My Post';
        R::store($bean);

        $result = title_link($bean);
        $this->assertStringContainsString('p-name', $result);
        $this->assertStringContainsString('title-link', $result);
    }

    public function testAuthorCardIsAnHCardWithAuthorUrl(): void
    {
        global $config;
        $config = ['author_name' => 'Jane Doe'];

        $result = author_card();
        $this->assertStringContainsString('p-author', $result);
        $this->assertStringContainsString('h-card', $result);
        $this->assertStringContainsString('href="' . ROOT_URL . '"', $result);
        $this->assertStringContainsString('Jane Doe', $result);
    }

    public function testAuthorCardEscapesName(): void
    {
        global $config;
        $config = ['author_name' => '<script>xss</script>'];

        $result = author_card();
        $this->assertStringNotContainsString('<script>', $result);
    }

    public function testAuthorCardEmptyWhenNoAuthorName(): void
    {
        global $config;
        $config = ['author_name' => ''];

        $this->assertSame('', author_card());
    }

    // Theme partials ----------------------------------------------------------

    private function renderItems(string $theme, string $tpl, array $props = []): string
    {
        $bean = R::dispense('post');
        $bean->title = $props['title'] ?? '';
        $bean->transformed = $props['transformed'] ?? '<p>body</p>';
        $bean->created = '2024-01-01 12:00:00';
        $bean->deleted = false;
        R::store($bean);

        global $data, $template, $config;
        $config = ['menu_items' => [], 'author_name' => 'Jane Doe'];
        $data = ['posts' => [$bean]];
        $template = $tpl;

        ob_start();
        include dirname(__DIR__, 2) . "/src/themes/$theme/parts/_items.php";
        return (string) ob_get_clean();
    }

    /**
     * @dataProvider themeProvider
     */
    public function testThemeMarksEntryAndContent(string $theme): void
    {
        $html = $this->renderItems($theme, 'home', ['title' => 'Hello']);
        $this->assertStringContainsString('h-entry', $html, "$theme: article should be an h-entry");
        $this->assertStringContainsString('e-content', $html, "$theme: body should be e-content");
    }

    /**
     * @dataProvider themeProvider
     */
    public function testThemeIncludesAuthorHCard(string $theme): void
    {
        $html = $this->renderItems($theme, 'home');
        $this->assertStringContainsString('p-author', $html, "$theme: entry should carry a p-author");
        $this->assertStringContainsString('h-card', $html, "$theme: author should be an h-card");
    }

    public function testBaseStatusTitleCarriesPName(): void
    {
        $html = $this->renderItems('base', 'status', ['title' => 'Hello World']);
        $this->assertStringContainsString('p-name', $html);
        $this->assertStringContainsString('Hello World', $html);
    }

    public static function themeProvider(): array
    {
        return [
            'base' => ['base'],
            '2026' => ['2026'],
            '2024' => ['2024'],
        ];
    }
}

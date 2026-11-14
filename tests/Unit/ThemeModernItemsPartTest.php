<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\OODBBean;
use RedBeanPHP\R;

use function Lamb\Theme\author_card;

/**
 * Characterisation test for the 2026 theme's _items.php.
 *
 * These tests pin the exact rendered HTML of the markup that Lamb\Theme\render_post_list()
 * produces, so a change to it is verified byte-for-byte rather than eyeballed.
 *
 * Fixtures live in tests/Support/Data/theme-items/*.html, captured from the
 * theme's output with the per-post author line replaced by a
 * `{{AUTHOR_SPAN}}` placeholder (see expectedAuthorSpan()) and the
 * relative "human time" text normalised (it drifts with the current time).
 */
class ThemeModernItemsPartTest extends TestCase
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
        global $data, $template, $config;
        $_SESSION = [];
        $data     = [];
        $template = null;
        $config   = [];
    }

    /**
     * @param array<int, OODBBean> $posts
     */
    private function render(string $theme, string $templateName, array $posts): string
    {
        global $data, $template, $config;
        $config   = ['author_name' => 'Jane Doe', 'menu_items' => []];
        $data     = ['posts' => $posts];
        $template = $templateName;

        ob_start();
        include dirname(__DIR__, 2) . "/src/themes/$theme/parts/_items.php";
        return (string) ob_get_clean();
    }

    private function makeBean(string $title, string $body, string $created): OODBBean
    {
        $bean              = R::dispense('post');
        $bean->title       = $title;
        $bean->transformed = $body;
        $bean->created     = $created;
        $bean->deleted     = false;
        R::store($bean);
        return $bean;
    }

    /**
     * Masks the relative "human time" text (e.g. "3 years ago"), which
     * changes with the current date, while leaving the absolute
     * datetime/title attributes intact.
     */
    private function normalize(string $html): string
    {
        return (string) preg_replace(
            '/(<time class="dt-published" datetime="[^"]*">)[^<]*(<\/time>)/',
            '$1NORMALIZED$2',
            $html
        );
    }

    private function loadFixture(string $name): string
    {
        return (string) file_get_contents(
            dirname(__DIR__) . "/Support/Data/theme-items/$name.html"
        );
    }

    /**
     * Builds the author line from the real author_card() output, so the
     * fixture doesn't have to hardcode it.
     */
    private function expectedAuthorSpan(): string
    {
        global $config;
        $config = ['author_name' => 'Jane Doe', 'menu_items' => []];

        return '<span itemprop="author" class="screen-reader-text">' . author_card() . '</span>';
    }

    private function assertRendersFixture(string $fixture, string $theme, string $templateName, string $title, string $body, string $created): void
    {
        $bean     = $this->makeBean($title, $body, $created);
        $html     = $this->normalize($this->render($theme, $templateName, [$bean]));
        $expected = str_replace('{{AUTHOR_SPAN}}', $this->expectedAuthorSpan(), $this->loadFixture($fixture));

        $this->assertSame($expected, $html);
    }

    public function test2026RendersTitledPost(): void
    {
        $this->assertRendersFixture('titled_single', '2026', 'tag', 'Hello World', '<p>Body text.</p>', '2024-01-01 12:00:00');
    }

    public function test2026RendersStatusPost(): void
    {
        $this->assertRendersFixture('status_single', '2026', 'status', 'Status Post Title', '<p>Status update text.</p>', '2024-02-02 08:30:00');
    }

    public function test2026RendersMultiplePostsWrappedInAList(): void
    {
        $b1 = $this->makeBean('First Post', '<p>First body.</p>', '2024-03-01 09:00:00');
        $b2 = $this->makeBean('Second Post', '<p>Second body.</p>', '2024-03-02 10:00:00');
        $html     = $this->normalize($this->render('2026', 'tag', [$b1, $b2]));
        $expected = str_replace('{{AUTHOR_SPAN}}', $this->expectedAuthorSpan(), $this->loadFixture('multiple'));

        $this->assertSame($expected, $html);
    }

    // The fixture tests above substitute the same literal the implementation
    // builds, so a typo there would be copied into both sides and pass — pin
    // the author line here with hardcoded literals instead.
    public function test2026HidesTheAuthorForScreenReadersWithNoTrailingAt(): void
    {
        $html = $this->render('2026', 'tag', [$this->makeBean('T', '<p>b</p>', '2024-01-01 12:00:00')]);
        $this->assertStringContainsString('<span itemprop="author" class="screen-reader-text">', $html);
        $this->assertStringNotContainsString('</span> @', $html);
    }

    // Custom themes copied from the 0.16 2026 theme still call render_post_list(true).
    public function testRenderPostListIgnoresTheLegacyHideAuthorArgument(): void
    {
        $bean     = $this->makeBean('T', '<p>b</p>', '2024-01-01 12:00:00');
        $expected = $this->normalize($this->render('2026', 'tag', [$bean]));

        ob_start();
        \call_user_func('Lamb\Theme\render_post_list', true);
        $html = $this->normalize((string) ob_get_clean());

        $this->assertSame($expected, $html);
        $this->assertStringContainsString('<span itemprop="author" class="screen-reader-text">', $html);
    }

    public function testEmptyPostsRendersTheNoItemsMessage(): void
    {
        $html = $this->render('2026', 'tag', []);

        $this->assertStringContainsString('<p>Sorry no items found.</p>', $html);
        $this->assertStringNotContainsString('<article', $html);
    }

    public function testLoggedInAuthorGetsTheActionsFooterAnonymousDoesNot(): void
    {
        // The actions <small> block is the only <small> in this markup and is
        // gated on the login session — pins the moved logged-in hunk.
        $bean = $this->makeBean('T', '<p>b</p>', '2024-01-01 12:00:00');

        $anon = $this->render('2026', 'tag', [$bean]);
        $this->assertStringNotContainsString('<small>', $anon);

        $_SESSION[SESSION_LOGIN] = true;
        $loggedIn = $this->render('2026', 'tag', [$bean]);
        $this->assertStringContainsString('<small>', $loggedIn);
    }

    /**
     * render_post_list() decided Restore-vs-Delete with the raw
     * `$bean->deleted` truthy check instead of the canonical is_deleted()
     * predicate ((bean->deleted ?? null) == 1). A non-canonical truthy value
     * (deleted = 2) would previously still show Restore even though
     * is_deleted() says the post is not deleted -- pin the delegation.
     */
    public function testNonCanonicalDeletedValueShowsDeleteNotRestore(): void
    {
        $_SESSION[SESSION_LOGIN] = true;
        $bean = $this->makeBean('T', '<p>b</p>', '2024-01-01 12:00:00');
        $bean->deleted = 2;
        R::store($bean);

        $html = $this->render('2026', 'tag', [$bean]);

        $this->assertStringContainsString('form-delete', $html);
        $this->assertStringNotContainsString('form-restore', $html);
    }

    public function testCanonicalDeletedValueShowsRestoreNotDelete(): void
    {
        $_SESSION[SESSION_LOGIN] = true;
        $bean = $this->makeBean('T', '<p>b</p>', '2024-01-01 12:00:00');
        $bean->deleted = 1;
        R::store($bean);

        $html = $this->render('2026', 'tag', [$bean]);

        $this->assertStringContainsString('form-restore', $html);
        $this->assertStringNotContainsString('form-delete', $html);
    }
}

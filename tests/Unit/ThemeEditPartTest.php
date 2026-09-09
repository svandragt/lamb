<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

/**
 * Covers the base theme's edit part, specifically its Delete-link visibility.
 *
 * The template used to decide whether to show the Delete action with the raw
 * `$post->deleted` truthy check instead of the canonical is_deleted()
 * predicate ((bean->deleted ?? null) == 1) -- the same predicate used
 * everywhere else a post's deleted state is asked (is_viewable(),
 * is_publicly_visible(), the Restore-vs-Delete choice in _items.php). A
 * non-canonical truthy value (e.g. deleted = 2) would previously hide the
 * Delete link even though is_deleted() says the post is not deleted.
 */
class ThemeEditPartTest extends TestCase
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
        global $data;
        $_SESSION = [];
        $data     = [];
    }

    private function render(int|bool|null $deleted): string
    {
        $post = R::dispense('post');
        $post->body    = 'Hello world';
        $post->deleted = $deleted;
        R::store($post);

        global $data;
        $data = ['post' => $post];
        $_SESSION[SESSION_LOGIN] = true;

        ob_start();
        include dirname(__DIR__, 2) . '/src/themes/base/parts/edit.php';
        return (string) ob_get_clean();
    }

    public function testDeleteLinkShownForACanonicallyLivePost(): void
    {
        $html = $this->render(null);
        $this->assertStringContainsString('form-delete', $html);
    }

    public function testDeleteLinkHiddenForACanonicallyDeletedPost(): void
    {
        $html = $this->render(1);
        $this->assertStringNotContainsString('form-delete', $html);
    }

    public function testDeleteLinkShownForANonCanonicalTruthyDeletedValue(): void
    {
        // deleted = 2 is truthy but not the canonical "1" is_deleted() checks
        // for -- is_deleted() says this post is NOT deleted, so the Delete
        // link must still show.
        $html = $this->render(2);
        $this->assertStringContainsString('form-delete', $html);
    }
}

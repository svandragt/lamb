<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Response\apply_checkbox_toggle;
use function Lamb\Response\delete_return_path;
use function Lamb\Response\lock_if_feed_sourced;
use function Lamb\Response\redirect_created;
use function Lamb\Response\redirect_edited;
use function Lamb\Response\respond_edit;
use function Lamb\Response\safe_referer_path;
use function Lamb\Response\store_slug_change_redirect;
use function Lamb\Response\warn_if_manual_redirect;

class ResponsePostsTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);

        // Seed schema columns so WHERE filters work regardless of test order
        $schema = R::dispense('post');
        $schema->draft   = null;
        $schema->deleted = null;
        R::store($schema);

        R::exec("DELETE FROM post");
        R::exec("DELETE FROM option");

        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'http://localhost');
        }

        global $config;
        $config = [
            'site_title'     => 'Test Blog',
            'posts_per_page' => 10,
            'menu_items'     => [],
            'feeds'          => [],
            'redirections'   => [],
        ];

        $_SESSION = [];
        $_POST    = [];
        $_GET     = [];
        $_SERVER['REQUEST_METHOD']  = 'GET';
        $_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
        $_SERVER['REQUEST_URI']     = '/';
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        $_POST    = [];
        $_GET     = [];
    }

    // -------------------------------------------------------------------------
    // warn_if_manual_redirect
    // -------------------------------------------------------------------------

    public function testWarnIfManualRedirectFlashesWhenConfigStillRedirectsTheSlug(): void
    {
        global $config;
        $config['redirections'] = ['about' => 'https://example.com/elsewhere'];

        warn_if_manual_redirect('about');

        $this->assertCount(1, $_SESSION['flash'] ?? []);
        $this->assertStringContainsString('about', $_SESSION['flash'][0]);
        $this->assertStringContainsString('[redirections]', $_SESSION['flash'][0]);
    }

    public function testWarnIfManualRedirectStaysQuietForAnUnrelatedSlug(): void
    {
        global $config;
        $config['redirections'] = ['about' => 'https://example.com/elsewhere'];

        warn_if_manual_redirect('contact');

        $this->assertSame([], $_SESSION['flash'] ?? []);
    }

    public function testWarnIfManualRedirectStaysQuietForAnEmptySlug(): void
    {
        global $config;
        $config['redirections'] = ['' => 'https://example.com/elsewhere'];

        warn_if_manual_redirect('');

        $this->assertSame([], $_SESSION['flash'] ?? []);
    }

    // -------------------------------------------------------------------------
    // store_slug_change_redirect
    // -------------------------------------------------------------------------

    public function testStoreSlugChangeRedirectPointsTheOldSlugAtTheNewOne(): void
    {
        R::exec('DELETE FROM redirect WHERE 1');

        store_slug_change_redirect('old-slug', 'new-slug');

        $redirect = R::findOne('redirect', ' from_slug = ? ', ['old-slug']);
        $this->assertNotNull($redirect);
        $this->assertSame('/new-slug', $redirect->to_url);
    }

    public function testStoreSlugChangeRedirectDropsAnyRedirectAwayFromTheNewSlug(): void
    {
        R::exec('DELETE FROM redirect WHERE 1');
        // A stale redirect from an earlier rename would otherwise send visitors
        // of the new slug straight back out again.
        $stale = R::dispense('redirect');
        $stale->from_slug = 'new-slug';
        $stale->to_url    = '/somewhere-else';
        R::store($stale);

        store_slug_change_redirect('old-slug', 'new-slug');

        $this->assertNull(R::findOne('redirect', ' from_slug = ? ', ['new-slug']));
    }

    public function testStoreSlugChangeRedirectSanitizesTheTarget(): void
    {
        R::exec('DELETE FROM redirect WHERE 1');

        store_slug_change_redirect('old-slug', '/evil.example.com/phish');

        $redirect = R::findOne('redirect', ' from_slug = ? ', ['old-slug']);
        $this->assertNotNull($redirect);
        // Must stay a local path — never a protocol-relative "//host/..." target.
        $this->assertStringStartsNotWith('//', $redirect->to_url);
    }

    // -------------------------------------------------------------------------
    // redirect_created — early-return paths (no die())
    // -------------------------------------------------------------------------

    public function testRedirectCreatedReturnsEarlyWhenSubmitDoesNotMatchCreate(): void
    {
        $_SESSION[SESSION_LOGIN]    = true;
        $_SESSION[HIDDEN_CSRF_NAME] = 'tok1';
        $_POST[HIDDEN_CSRF_NAME]    = 'tok1';
        $_POST['submit']            = 'not create';
        $_POST['contents']          = 'Some content here';

        redirect_created();

        // Nothing stored: the function returned before calling R::store
        $this->assertSame(0, R::count('post'));
    }

    public function testRedirectCreatedReturnsEarlyWhenContentsIsEmpty(): void
    {
        $_SESSION[SESSION_LOGIN]    = true;
        $_SESSION[HIDDEN_CSRF_NAME] = 'tok2';
        $_POST[HIDDEN_CSRF_NAME]    = 'tok2';
        $_POST['submit']            = SUBMIT_CREATE;
        $_POST['contents']          = '';

        redirect_created();

        $this->assertSame(0, R::count('post'));
    }

    public function testRedirectCreatedReturnsEarlyWhenContentsIsWhitespaceOnly(): void
    {
        $_SESSION[SESSION_LOGIN]    = true;
        $_SESSION[HIDDEN_CSRF_NAME] = 'tok3';
        $_POST[HIDDEN_CSRF_NAME]    = 'tok3';
        $_POST['submit']            = SUBMIT_CREATE;
        $_POST['contents']          = '   ';

        redirect_created();

        $this->assertSame(0, R::count('post'));
    }

    // -------------------------------------------------------------------------
    // redirect_edited — early-return paths (no die())
    // -------------------------------------------------------------------------

    public function testRedirectEditedReturnsEarlyWhenSubmitDoesNotMatchEdit(): void
    {
        $_SESSION[SESSION_LOGIN]    = true;
        $_SESSION[HIDDEN_CSRF_NAME] = 'edtok1';
        $_POST[HIDDEN_CSRF_NAME]    = 'edtok1';
        $_POST['submit']            = 'not edit';
        $_POST['contents']          = 'Updated content';

        redirect_edited();

        // Reached here without calling die()
        $this->assertTrue(true);
    }

    public function testRedirectEditedReturnsEarlyWhenContentsIsEmpty(): void
    {
        $_SESSION[SESSION_LOGIN]    = true;
        $_SESSION[HIDDEN_CSRF_NAME] = 'edtok2';
        $_POST[HIDDEN_CSRF_NAME]    = 'edtok2';
        $_POST['submit']            = SUBMIT_EDIT;
        $_POST['contents']          = '';

        redirect_edited();

        $this->assertTrue(true);
    }

    public function testRedirectEditedReturnsEarlyWhenContentsIsWhitespaceOnly(): void
    {
        $_SESSION[SESSION_LOGIN]    = true;
        $_SESSION[HIDDEN_CSRF_NAME] = 'edtok3';
        $_POST[HIDDEN_CSRF_NAME]    = 'edtok3';
        $_POST['submit']            = SUBMIT_EDIT;
        $_POST['contents']          = '   ';

        redirect_edited();

        $this->assertTrue(true);
    }

    // -------------------------------------------------------------------------
    // redirect_edited — a failed save must have no side effects
    // -------------------------------------------------------------------------

    /**
     * Seeds a post with a slug and returns its id, with the `redirect` and
     * `webmentionoutbox` tables created so a count of them means "none were
     * written" rather than "the table does not exist yet".
     */
    private function seedEditablePost(): int
    {
        $redirect = R::dispense('redirect');
        $redirect->from_slug = '';
        $redirect->to_url = '';
        R::store($redirect);
        R::exec('DELETE FROM redirect');

        $outbox = R::dispense('webmentionoutbox');
        $outbox->source = '';
        $outbox->target = '';
        $outbox->status = '';
        R::store($outbox);
        R::exec('DELETE FROM webmentionoutbox');

        $post = R::dispense('post');
        $post->body          = "# Original title\n\nsome text\n";
        $post->transformed   = '<p>some text</p>';
        $post->slug          = 'original-title';
        $post->created       = date('Y-m-d H:i:s', time() - 3600);
        $post->updated       = date('Y-m-d H:i:s', time() - 3600);
        $post->version       = POST_VERSION;
        $post->tags          = '';
        $post->feeditem_uuid = '';
        $post->feed_locked   = 0;
        $post->in_reply_to   = '';

        return (int) R::store($post);
    }

    /**
     * Submits an edit that renames the post while every UPDATE on `post` fails.
     *
     * The trigger is how a write failure is made deterministic: SELECTs and
     * INSERTs into other tables still succeed, which is exactly the shape of a
     * locked SQLite file (RedBeanPHP surfaces it as RedException\SQL, the
     * exception redirect_edited() already catches).
     */
    private function submitEditWithFailingWrite(int $id): void
    {
        $_SESSION[SESSION_LOGIN]    = true;
        $_SESSION[HIDDEN_CSRF_NAME] = 'failtok';
        $_POST[HIDDEN_CSRF_NAME]    = 'failtok';
        $_POST['submit']            = SUBMIT_EDIT;
        $_POST['id']                = (string) $id;
        $_POST['contents']          = "# Renamed title\n\nsee [elsewhere](https://other.example/a)\n";

        R::exec("CREATE TRIGGER post_block_update BEFORE UPDATE ON post BEGIN SELECT RAISE(ABORT, 'database is locked'); END");
        try {
            redirect_edited();
        } finally {
            R::exec('DROP TRIGGER IF EXISTS post_block_update');
        }
    }

    public function testRedirectEditedFlashesAndStopsWhenTheSaveFails(): void
    {
        $id = $this->seedEditablePost();

        $this->submitEditWithFailingWrite($id);

        $this->assertNotEmpty(array_filter(
            $_SESSION['flash'] ?? [],
            fn ($m) => str_contains((string) $m, 'Failed to update')
        ));
        // Unchanged on disk — the whole point of the guard.
        $this->assertSame('original-title', (string) R::load('post', $id)->slug);
    }

    public function testRedirectEditedDoesNotRedirectTheLiveSlugWhenTheSaveFails(): void
    {
        // The damaging one: the post keeps its old slug, so a redirect from that
        // slug to the never-saved new one points the post's own live URL at a
        // 404 and makes it unreachable.
        $id = $this->seedEditablePost();

        $this->submitEditWithFailingWrite($id);

        $this->assertCount(0, R::findAll('redirect'));
    }

    public function testRedirectEditedDoesNotNotifySubscribersWhenTheSaveFails(): void
    {
        // Announcing an edit that was not stored: the queued mention carries a
        // permalink built from the unsaved slug, so a receiver fetching it to
        // verify the mention gets a 404.
        $id = $this->seedEditablePost();

        $this->submitEditWithFailingWrite($id);

        $this->assertCount(0, R::findAll('webmentionoutbox'));
    }

    // -------------------------------------------------------------------------
    // redirect_edited — the post id is read from $_POST
    // -------------------------------------------------------------------------
    // FILTER_SANITIZE_NUMBER_INT over $_POST, as filter_input(INPUT_POST, ...)
    // applied to the SAPI request. These pin the sanitising semantics that read
    // relied on, which is what the change to $_POST had to preserve.

    public function testRedirectEditedReturnsEarlyWhenIdIsMissing(): void
    {
        $this->seedEditablePost();
        $_SESSION[SESSION_LOGIN]    = true;
        $_SESSION[HIDDEN_CSRF_NAME] = 'idtok1';
        $_POST[HIDDEN_CSRF_NAME]    = 'idtok1';
        $_POST['submit']            = SUBMIT_EDIT;
        $_POST['contents']          = 'Updated content';

        redirect_edited();

        // No new post was created from id 0, and the seeded one is untouched.
        $this->assertCount(1, R::findAll('post'));
        $this->assertSame('original-title', (string) R::findOne('post')->slug);
    }

    public function testRedirectEditedReturnsEarlyWhenIdSanitisesToNothing(): void
    {
        // 'abc' has no digits, so it sanitises to '' and is refused rather than
        // becoming post id 0 (which would create a new post instead of editing).
        $this->seedEditablePost();
        $_SESSION[SESSION_LOGIN]    = true;
        $_SESSION[HIDDEN_CSRF_NAME] = 'idtok2';
        $_POST[HIDDEN_CSRF_NAME]    = 'idtok2';
        $_POST['submit']            = SUBMIT_EDIT;
        $_POST['id']                = 'abc';
        $_POST['contents']          = 'Updated content';

        redirect_edited();

        // No new post was created from id 0, and the seeded one is untouched.
        $this->assertCount(1, R::findAll('post'));
        $this->assertSame('original-title', (string) R::findOne('post')->slug);
    }

    public function testRedirectEditedReturnsEarlyWhenIdIsZero(): void
    {
        $this->seedEditablePost();
        $_SESSION[SESSION_LOGIN]    = true;
        $_SESSION[HIDDEN_CSRF_NAME] = 'idtok3';
        $_POST[HIDDEN_CSRF_NAME]    = 'idtok3';
        $_POST['submit']            = SUBMIT_EDIT;
        $_POST['id']                = '0';
        $_POST['contents']          = 'Updated content';

        redirect_edited();

        // No new post was created from id 0, and the seeded one is untouched.
        $this->assertCount(1, R::findAll('post'));
        $this->assertSame('original-title', (string) R::findOne('post')->slug);
    }

    // -------------------------------------------------------------------------
    // respond_edit
    // -------------------------------------------------------------------------

    public function testRespondEditReturnsArrayWithPostKey(): void
    {
        $_SESSION[SESSION_LOGIN] = true;

        $post          = R::dispense('post');
        $post->body    = 'Post to edit';
        $post->version = 1;
        $post->created = date('Y-m-d H:i:s');
        R::store($post);

        $result = respond_edit([$post->id]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('post', $result);
    }

    public function testRespondEditReturnsCorrectPost(): void
    {
        $_SESSION[SESSION_LOGIN] = true;

        $post          = R::dispense('post');
        $post->body    = 'Editable post body';
        $post->version = 1;
        $post->created = date('Y-m-d H:i:s');
        R::store($post);

        $result = respond_edit([$post->id]);

        $this->assertSame($post->id, $result['post']->id);
    }

    public function testRespondEditSetsEditReferrerInSession(): void
    {
        $_SESSION[SESSION_LOGIN] = true;

        $post          = R::dispense('post');
        $post->body    = 'Another post';
        $post->version = 1;
        $post->created = date('Y-m-d H:i:s');
        R::store($post);

        respond_edit([$post->id]);

        $this->assertArrayHasKey('edit-referrer', $_SESSION);
    }

    public function testRespondEditEditReferrerIsNullWhenNoHttpReferer(): void
    {
        $_SESSION[SESSION_LOGIN] = true;
        unset($_SERVER['HTTP_REFERER']);

        $post          = R::dispense('post');
        $post->body    = 'Post without referrer';
        $post->version = 1;
        $post->created = date('Y-m-d H:i:s');
        R::store($post);

        respond_edit([$post->id]);

        $this->assertNull($_SESSION['edit-referrer']);
    }

    // -------------------------------------------------------------------------
    // safe_referer_path
    // -------------------------------------------------------------------------

    public function testSafeRefererPathReturnsSameOriginPath(): void
    {
        $this->assertSame('/tag/lamb', safe_referer_path(ROOT_URL . '/tag/lamb'));
    }

    public function testSafeRefererPathPreservesQuery(): void
    {
        $this->assertSame('/?page=2', safe_referer_path(ROOT_URL . '/?page=2'));
    }

    public function testSafeRefererPathFallsBackToHomeForNullOrEmpty(): void
    {
        $this->assertSame('/', safe_referer_path(null));
        $this->assertSame('/', safe_referer_path(''));
    }

    public function testSafeRefererPathRejectsCrossOrigin(): void
    {
        $this->assertSame('/', safe_referer_path('https://evil.example/phish'));
    }

    public function testSafeRefererPathKeepsHostlessRelativeReferer(): void
    {
        $this->assertSame('/drafts', safe_referer_path('/drafts'));
    }

    public function testSafeRefererPathRejectsAProtocolRelativePath(): void
    {
        // The host check passes — the Referer *is* same-origin — but the path it
        // yields is `//evil.example/x`, which a browser resolves as
        // protocol-relative and follows off-site. local_redirect_target()
        // already refuses this shape for `?redirect_to=`, and states why.
        $this->assertSame('/', safe_referer_path(ROOT_URL . '//evil.example/x'));
        $this->assertSame('/', safe_referer_path('//evil.example/x'));
    }

    public function testSafeRefererPathRejectsABackslashPrefixedPath(): void
    {
        // Browsers normalise `\` to `/` in a URL, so `/\host` resolves the same
        // way as `//host`. sanitize_explicit_slug() refuses it for a slug for
        // exactly this reason.
        $this->assertSame('/', safe_referer_path(ROOT_URL . '/\\evil.example/x'));
        $this->assertSame('/', safe_referer_path('/\\evil.example'));
    }

    // -------------------------------------------------------------------------
    // delete_return_path
    // -------------------------------------------------------------------------

    public function testDeleteReturnPathKeepsSameOriginListingPage(): void
    {
        // Deleting from a tag listing should land back on that listing, not home.
        $this->assertSame(
            '/tag/lamb',
            delete_return_path(ROOT_URL . '/tag/lamb', '/status/12')
        );
    }

    public function testDeleteReturnPathPreservesQueryString(): void
    {
        $this->assertSame(
            '/?page=2',
            delete_return_path(ROOT_URL . '/?page=2', '/status/12')
        );
    }

    public function testDeleteReturnPathFallsBackToHomeWithoutReferer(): void
    {
        $this->assertSame('/', delete_return_path(null, '/status/12'));
        $this->assertSame('/', delete_return_path('', '/status/12'));
    }

    public function testDeleteReturnPathRejectsCrossOriginReferer(): void
    {
        $this->assertSame('/', delete_return_path('https://evil.example/phish', '/status/12'));
    }

    public function testDeleteReturnPathFallsBackToHomeWhenRefererIsOwnPage(): void
    {
        // The deleted post's own permalink now 404s, so don't send the user back to it.
        $this->assertSame('/', delete_return_path(ROOT_URL . '/status/12', '/status/12'));
        $this->assertSame('/', delete_return_path(ROOT_URL . '/my-page', '/my-page'));
    }

    // -------------------------------------------------------------------------
    // apply_checkbox_toggle
    // -------------------------------------------------------------------------

    public function testApplyCheckboxToggleChecksMarkerAndReparses(): void
    {
        $post          = R::dispense('post');
        $post->body    = "- [ ] one\n- [ ] two\n";
        $post->version = 1;
        $post->created = date('Y-m-d H:i:s');
        $post->updated = '2000-01-01 00:00:00';
        R::store($post);

        $this->assertTrue(apply_checkbox_toggle($post->id, 1, true));

        $reloaded = R::load('post', $post->id);
        $this->assertSame("- [ ] one\n- [x] two\n", $reloaded->body);
        // Re-parsed: stored HTML reflects the new checked state.
        $this->assertStringContainsString('checked', (string) $reloaded->transformed);
        // Saved as an edit: updated bumped away from the seeded value.
        $this->assertNotSame('2000-01-01 00:00:00', $reloaded->updated);
    }

    public function testApplyCheckboxToggleUnchecks(): void
    {
        $post          = R::dispense('post');
        $post->body    = "- [x] done\n";
        $post->version = 1;
        $post->created = date('Y-m-d H:i:s');
        R::store($post);

        $this->assertTrue(apply_checkbox_toggle($post->id, 0, false));

        $this->assertSame("- [ ] done\n", R::load('post', $post->id)->body);
    }

    public function testApplyCheckboxToggleFailsForMissingPost(): void
    {
        $this->assertFalse(apply_checkbox_toggle(99999, 0, true));
    }

    public function testApplyCheckboxToggleFailsForNegativeIndex(): void
    {
        $post          = R::dispense('post');
        $post->body    = "- [ ] one\n";
        $post->version = 1;
        $post->created = date('Y-m-d H:i:s');
        R::store($post);

        $this->assertFalse(apply_checkbox_toggle($post->id, -1, true));
    }

    public function testApplyCheckboxToggleUsesRenderedIndex(): void
    {
        // The first rendered checkbox is the bare marker, not the list item —
        // the index the client sends counts every checkbox on the page.
        $post          = R::dispense('post');
        $post->body    = "[ ] bare\n\n- [ ] listed\n";
        $post->version = 1;
        $post->created = date('Y-m-d H:i:s');
        R::store($post);

        $this->assertTrue(apply_checkbox_toggle($post->id, 0, true));
        $this->assertSame("[x] bare\n\n- [ ] listed\n", R::load('post', $post->id)->body);
    }

    public function testApplyCheckboxToggleSkipsMarkersInFencedCode(): void
    {
        $post          = R::dispense('post');
        $post->body    = "```\n- [ ] sample\n```\n\n- [ ] real\n";
        $post->version = 1;
        $post->created = date('Y-m-d H:i:s');
        R::store($post);

        $this->assertTrue(apply_checkbox_toggle($post->id, 0, true));
        $this->assertSame("```\n- [ ] sample\n```\n\n- [x] real\n", R::load('post', $post->id)->body);
    }

    public function testApplyCheckboxToggleFailsForIndexPastLastCheckbox(): void
    {
        $post          = R::dispense('post');
        $post->body    = "- [ ] one\n";
        $post->version = 1;
        $post->created = date('Y-m-d H:i:s');
        R::store($post);

        $this->assertFalse(apply_checkbox_toggle($post->id, 3, true));
        // Refused, not silently accepted with the body left as it was.
        $this->assertSame("- [ ] one\n", R::load('post', $post->id)->body);
    }

    public function testApplyCheckboxToggleRefusesWhenRewriteWouldMoveAnotherBox(): void
    {
        // A body whose markers the source scan and the renderer read
        // differently: the fence is swallowed by the preceding list item, so
        // the renderer shows three checkboxes where the scan sees two.
        $post          = R::dispense('post');
        $post->body    = "- [ ] one\n+ [ ] two\n```\n- [ ] three\n";
        $post->version = 1;
        $post->created = date('Y-m-d H:i:s');
        R::store($post);

        $this->assertFalse(apply_checkbox_toggle($post->id, 2, true));
        $this->assertSame("- [ ] one\n+ [ ] two\n```\n- [ ] three\n", R::load('post', $post->id)->body);
    }

    // -------------------------------------------------------------------------
    // lock_if_feed_sourced
    // -------------------------------------------------------------------------

    public function testLockIfFeedSourcedLocksFeedPost(): void
    {
        $bean = R::dispense('post');
        $bean->feeditem_uuid = md5('Feed' . 'item-id');

        lock_if_feed_sourced($bean);

        $this->assertSame(1, (int) $bean->feed_locked);
    }

    public function testLockIfFeedSourcedLeavesNonFeedPostUntouched(): void
    {
        $bean = R::dispense('post');
        $bean->feeditem_uuid = '';

        lock_if_feed_sourced($bean);

        $this->assertEmpty($bean->feed_locked);
    }
}

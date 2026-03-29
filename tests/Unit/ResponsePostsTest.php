<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Response\redirect_created;
use function Lamb\Response\redirect_edited;
use function Lamb\Response\respond_edit;

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
}

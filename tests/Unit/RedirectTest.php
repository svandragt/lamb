<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\delete_redirect_for_slug;
use function Lamb\find_redirect;

class RedirectTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);

        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'http://localhost');
        }

        // Clean redirect table before each test
        R::exec('DELETE FROM redirect WHERE 1');
    }

    // find_redirect — config-based

    public function testFindRedirectReturnsAbsoluteUrlFromConfig(): void
    {
        global $config;
        $original = $config;
        $config['redirections'] = ['old-page' => 'https://example.com/new'];

        $result = find_redirect('old-page');
        $this->assertSame('https://example.com/new', $result);

        $config = $original;
    }

    public function testFindRedirectReturnsRootRelativeUrlFromConfig(): void
    {
        global $config;
        $original = $config;
        $config['redirections'] = ['old-post' => '/new-post'];

        $result = find_redirect('old-post');
        $this->assertSame('/new-post', $result);

        $config = $original;
    }

    public function testFindRedirectPrependsSlashForBareSlugInConfig(): void
    {
        global $config;
        $original = $config;
        $config['redirections'] = ['old-slug' => 'new-slug'];

        $result = find_redirect('old-slug');
        $this->assertSame('/new-slug', $result);

        $config = $original;
    }

    // find_redirect — DB-based

    public function testFindRedirectReturnsUrlFromDatabase(): void
    {
        $redirect = R::dispense('redirect');
        $redirect->from_slug = 'db-old-slug';
        $redirect->to_url = '/db-new-slug';
        R::store($redirect);

        $result = find_redirect('db-old-slug');
        $this->assertSame('/db-new-slug', $result);
    }

    public function testFindRedirectReturnsNullWhenNotFound(): void
    {
        $result = find_redirect('no-such-slug-' . uniqid());
        $this->assertNull($result);
    }

    public function testFindRedirectPrefersConfigOverDatabase(): void
    {
        global $config;
        $original = $config;
        $config['redirections'] = ['shared-slug' => '/config-destination'];

        $redirect = R::dispense('redirect');
        $redirect->from_slug = 'shared-slug';
        $redirect->to_url = '/db-destination';
        R::store($redirect);

        $result = find_redirect('shared-slug');
        $this->assertSame('/config-destination', $result);

        $config = $original;
    }

    public function testFindRedirectReturnsNullWhenNoConfigAndNoDb(): void
    {
        global $config;
        $original = $config;
        $config['redirections'] = [];

        $result = find_redirect('missing-slug');
        $this->assertNull($result);

        $config = $original;
    }

    // delete_redirect_for_slug

    public function testDeleteRedirectForSlugRemovesMatchingDbRedirect(): void
    {
        $redirect = R::dispense('redirect');
        $redirect->from_slug = 'to-delete';
        $redirect->to_url = '/somewhere';
        R::store($redirect);

        delete_redirect_for_slug('to-delete');

        $this->assertNull(R::findOne('redirect', ' from_slug = ? ', ['to-delete']));
    }

    public function testDeleteRedirectForSlugDoesNothingWhenNoRedirectExists(): void
    {
        // Should not throw; no redirect to delete
        delete_redirect_for_slug('non-existent-slug-' . uniqid());
        $this->assertTrue(true);
    }

    public function testDeleteRedirectForSlugMakesFindRedirectReturnNull(): void
    {
        $redirect = R::dispense('redirect');
        $redirect->from_slug = 'will-be-gone';
        $redirect->to_url = '/gone';
        R::store($redirect);

        delete_redirect_for_slug('will-be-gone');

        $this->assertNull(find_redirect('will-be-gone'));
    }
}

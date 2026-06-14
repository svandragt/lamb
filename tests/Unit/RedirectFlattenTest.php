<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\flatten_redirects;

class RedirectFlattenTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::nuke();
    }

    private function redirect(string $from, string $to): void
    {
        $r = R::dispense('redirect');
        $r->from_slug = $from;
        $r->to_url = $to;
        R::store($r);
    }

    private function post(string $slug, bool $deleted = false): void
    {
        $p = R::dispense('post');
        $p->slug = $slug;
        $p->body = 'x';
        $p->deleted = $deleted ? 1 : 0;
        R::store($p);
    }

    private function toUrlFor(string $from): ?string
    {
        $r = R::findOne('redirect', ' from_slug = ? ', [$from]);
        return $r?->to_url;
    }

    public function testTwoHopChainFlattensToOneHop(): void
    {
        $this->post('newest');
        $this->redirect('old', '/newer');
        $this->redirect('newer', '/newest');

        flatten_redirects();

        $this->assertSame('/newest', $this->toUrlFor('old'), 'old should point straight at the final destination');
        $this->assertSame('/newest', $this->toUrlFor('newer'));
    }

    public function testLoopIsBrokenByDeletingTheLoopingRows(): void
    {
        $this->redirect('a', '/b');
        $this->redirect('b', '/a');

        flatten_redirects();

        $this->assertSame(0, R::count('redirect'), 'looping rows are deleted');
    }

    public function testUnrelatedRedirectsAreUntouched(): void
    {
        $this->post('target');
        $this->redirect('old-page', '/target');

        flatten_redirects();

        $this->assertSame('/target', $this->toUrlFor('old-page'));
        $this->assertSame(1, R::count('redirect'));
    }

    public function testDeadRedirectIsDeletedWhenDestinationHasNoPost(): void
    {
        $this->redirect('gone', '/nowhere');

        flatten_redirects();

        $this->assertNull($this->toUrlFor('gone'), 'a redirect to a non-existent post is removed');
    }

    public function testRedirectToTrashedPostIsKept(): void
    {
        $this->post('trashed-slug', deleted: true);
        $this->redirect('old', '/trashed-slug');

        flatten_redirects();

        $this->assertSame('/trashed-slug', $this->toUrlFor('old'), 'a trashed post may be restored, so keep the redirect');
    }

    public function testExternalRedirectIsKept(): void
    {
        $this->redirect('go', 'https://example.com/elsewhere');

        flatten_redirects();

        $this->assertSame('https://example.com/elsewhere', $this->toUrlFor('go'));
    }
}

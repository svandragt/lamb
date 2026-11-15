<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\encode_path_segment;
use function Lamb\find_post_by_path;
use function Lamb\get_option;
use function Lamb\like_escape;
use function Lamb\now;
use function Lamb\permalink;
use function Lamb\permalink_path;
use function Lamb\post_has_slug;
use function Lamb\post_path;
use function Lamb\set_option;
use function Lamb\with_write_transaction;

class LambHelpersTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        // A home post a sibling test left behind would make the root path resolve.
        R::wipe('post');
    }

    // now

    public function testNowReturnsCanonicalDatetimeFormat(): void
    {
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', now());
    }

    public function testNowReturnsCurrentTime(): void
    {
        $before = date('Y-m-d H:i:s');
        $value = now();
        $after = date('Y-m-d H:i:s');
        $this->assertGreaterThanOrEqual($before, $value);
        $this->assertLessThanOrEqual($after, $value);
    }

    // find_post_by_path

    public function testFindPostByPathResolvesStatusPath(): void
    {
        $bean = R::dispense('post');
        $bean->body = 'A status post';
        R::store($bean);

        $found = find_post_by_path('/status/' . $bean->id);
        $this->assertNotNull($found);
        $this->assertEquals($bean->id, $found->id);
    }

    public function testFindPostByPathResolvesSlugPath(): void
    {
        $bean = R::dispense('post');
        $bean->slug = 'resolver-slug-' . uniqid();
        R::store($bean);

        $found = find_post_by_path('/' . $bean->slug);
        $this->assertNotNull($found);
        $this->assertEquals($bean->id, $found->id);
    }

    public function testFindPostByPathReturnsNullForUnknownStatusId(): void
    {
        $this->assertNull(find_post_by_path('/status/999999999'));
    }

    public function testFindPostByPathReturnsNullForUnknownSlug(): void
    {
        $this->assertNull(find_post_by_path('/no-such-slug-' . uniqid()));
    }

    public function testFindPostByPathReturnsNullForRootPath(): void
    {
        $this->assertNull(find_post_by_path('/'));
        $this->assertNull(find_post_by_path(''));
    }

    // permalink

    public function testPermalinkUsesSlugWhenSet(): void
    {
        $bean = R::dispense('post');
        $bean->slug = 'my-post';
        $this->assertSame(ROOT_URL . '/my-post', permalink($bean));
    }

    public function testPermalinkFallsBackToStatusIdWhenNoSlug(): void
    {
        $bean = R::dispense('post');
        R::store($bean);
        $bean->slug = '';
        $this->assertSame(ROOT_URL . '/status/' . $bean->id, permalink($bean));
    }

    public function testPermalinkFallsBackToStatusIdWhenSlugIsNull(): void
    {
        $bean = R::dispense('post');
        R::store($bean);
        $bean->slug = null;
        $this->assertSame(ROOT_URL . '/status/' . $bean->id, permalink($bean));
    }

    // permalink_path / encode_path_segment — a post's URL has to survive the
    // round trip back through the router, which matches the *decoded* request
    // path against the stored slug.

    public function testPermalinkEncodesWhatAPathCannotHold(): void
    {
        $bean = R::dispense('post');

        $bean->slug = 'my post';
        $this->assertSame('/my%20post', permalink_path($bean));

        $bean->slug = 'café';
        $this->assertSame('/caf%C3%A9', permalink_path($bean));

        $bean->slug = 'a?b#c';
        $this->assertSame('/a%3Fb%23c', permalink_path($bean));
    }

    public function testPermalinkLeavesPathLegalCharactersAlone(): void
    {
        // These already resolve, and a permalink is also the post's Atom entry
        // id — re-encoding one would show every subscriber the post as new.
        $bean = R::dispense('post');

        $bean->slug = 'tea-&-cake';
        $this->assertSame('/tea-&-cake', permalink_path($bean));

        $bean->slug = 'c++,notes;x=1:2@3';
        $this->assertSame('/c++,notes;x=1:2@3', permalink_path($bean));

        $bean->slug = 'hello-world';
        $this->assertSame('/hello-world', permalink_path($bean));
    }

    public function testEncodePathSegmentEncodesSeparatorsAndPercent(): void
    {
        $this->assertSame('a%2Fb', encode_path_segment('a/b'));
        $this->assertSame('a%5Cb', encode_path_segment('a\\b'));
        $this->assertSame('100%25', encode_path_segment('100%'));
    }

    public function testPermalinkPathFallsBackToStatusId(): void
    {
        $bean = R::dispense('post');
        R::store($bean);
        $bean->slug = '';
        $this->assertSame('/status/' . $bean->id, permalink_path($bean));
    }

    public function testPostPathMatchesPermalinkPathForTheSameSlugAndId(): void
    {
        // /sitemap.xml builds its URLs with post_path() straight from the row,
        // rather than making a bean per post to call permalink_path() on. The
        // two have to agree, or the sitemap advertises URLs the site does not
        // serve.
        $bean = R::dispense('post');
        R::store($bean);

        foreach (['hello-world', 'my post', 'café', 'tea-&-cake', ''] as $slug) {
            $bean->slug = $slug;
            $this->assertSame(
                permalink_path($bean),
                post_path($slug, (int) $bean->id),
                'slug: ' . var_export($slug, true)
            );
        }
    }

    public function testFindPostByPathDecodesTheSlug(): void
    {
        $bean = R::dispense('post');
        $bean->slug = 'my post';
        $bean->body = 'Spaced.';
        R::store($bean);

        $found = find_post_by_path('/my%20post');
        $this->assertNotNull($found);
        $this->assertSame((int) $bean->id, (int) $found->id);
    }

    // like_escape — a visitor's literal text must stay literal inside a LIKE.

    public function testLikeEscapeEscapesWildcards(): void
    {
        $this->assertSame('100\\%', like_escape('100%'));
        $this->assertSame('a\\_b', like_escape('a_b'));
        $this->assertSame('plain', like_escape('plain'));
    }

    public function testLikeEscapeEscapesTheEscapeCharacterFirst(): void
    {
        // Otherwise a trailing backslash in the term escapes the pattern's own
        // closing wildcard instead of itself.
        $this->assertSame('back\\\\slash', like_escape('back\\slash'));
        $this->assertSame('\\\\\\%', like_escape('\\%'));
    }

    // get_option

    public function testGetOptionReturnsDefaultForNewKey(): void
    {
        $bean = get_option('test_new_key_' . uniqid(), 'default-value');
        $this->assertSame('default-value', $bean->value);
        $this->assertSame(0, $bean->id);
    }

    public function testGetOptionReturnsBeanWithCorrectName(): void
    {
        $key = 'test_key_' . uniqid();
        $bean = get_option($key, 'x');
        $this->assertSame($key, $bean->name);
    }

    public function testGetOptionReturnsStoredValueAfterSave(): void
    {
        $key = 'test_stored_' . uniqid();
        $bean = get_option($key, 'original');
        $bean->value = 'stored-value';
        R::store($bean);

        $fetched = get_option($key, 'default');
        $this->assertSame('stored-value', $fetched->value);
        $this->assertGreaterThan(0, $fetched->id);
    }

    // set_option

    public function testSetOptionPersistsValue(): void
    {
        $key = 'test_set_' . uniqid();
        $bean = get_option($key, '');
        set_option($bean, 'new-value');

        $fetched = get_option($key, 'default');
        $this->assertSame('new-value', $fetched->value);
    }

    public function testSetOptionAssignsValueToBean(): void
    {
        $bean = get_option('test_assign_' . uniqid(), '');
        set_option($bean, 'assigned');
        $this->assertSame('assigned', $bean->value);
    }

    // post_has_slug

    public function testPostHasSlugReturnsNullForNonExistentSlug(): void
    {
        $result = post_has_slug('this-slug-does-not-exist-' . uniqid());
        $this->assertNull($result);
    }

    public function testPostHasSlugReturnsSlugWhenPostExists(): void
    {
        $bean = R::dispense('post');
        $bean->slug = 'existing-slug-' . uniqid();
        R::store($bean);

        $result = post_has_slug($bean->slug);
        $this->assertSame($bean->slug, $result);
    }

    public function testPostHasSlugReturnsNullForDraftPost(): void
    {
        $bean = R::dispense('post');
        $bean->slug = 'draft-slug-' . uniqid();
        $bean->draft = 1;
        R::store($bean);

        $result = post_has_slug($bean->slug);
        $this->assertNull($result);
    }

    public function testPostHasSlugResolvesDraftForLoggedInAuthor(): void
    {
        $bean = R::dispense('post');
        $bean->slug = 'draft-slug-' . uniqid();
        $bean->draft = 1;
        R::store($bean);

        $_SESSION[SESSION_LOGIN] = true;
        try {
            $this->assertSame($bean->slug, post_has_slug($bean->slug), 'Logged-in author must reach their slugged draft');
        } finally {
            $_SESSION = [];
        }
    }

    public function testPostHasSlugResolvesScheduledPostForLoggedInAuthor(): void
    {
        $bean = R::dispense('post');
        $bean->slug = 'scheduled-slug-' . uniqid();
        $bean->created = date('Y-m-d H:i:s', time() + 86400);
        R::store($bean);

        $_SESSION[SESSION_LOGIN] = true;
        try {
            $this->assertSame($bean->slug, post_has_slug($bean->slug), 'Logged-in author must reach their slugged scheduled post');
        } finally {
            $_SESSION = [];
        }
    }

    // with_write_transaction

    private function inTransaction(): bool
    {
        return R::getPDO()->inTransaction();
    }

    /**
     * Creates `writeprobe` (fluid mode only) and empties it, so a test can freeze the
     * schema without the table missing and a count means "committed rows".
     */
    private function resetProbe(): void
    {
        R::store(R::dispense('writeprobe'));
        R::wipe('writeprobe');
    }

    private function storeProbe(): void
    {
        R::store(R::dispense('writeprobe'));
    }

    public function testARawBeginImmediateOpensATransactionThePdoReports(): void
    {
        // with_write_transaction() asks the PDO whether a transaction is open, so a
        // driver that does not report one for a raw BEGIN must fail here, not in production.
        R::exec('BEGIN IMMEDIATE');
        try {
            $this->assertTrue($this->inTransaction());
        } finally {
            R::exec('ROLLBACK');
        }
        $this->assertFalse($this->inTransaction());
    }

    public function testWithWriteTransactionCommitsAndReturnsTheResult(): void
    {
        $this->resetProbe();

        $result = with_write_transaction(function (): string {
            $this->storeProbe();

            return 'done';
        });

        $this->assertSame('done', $result);
        $this->assertSame(1, R::count('writeprobe'));
        $this->assertFalse($this->inTransaction());
    }

    public function testWithWriteTransactionRollsBackAndRethrowsTheOriginalException(): void
    {
        $this->resetProbe();
        $thrown = new \RuntimeException('original');

        try {
            with_write_transaction(function () use ($thrown): void {
                $this->storeProbe();
                throw $thrown;
            });
            $this->fail('The exception should have propagated.');
        } catch (\RuntimeException $e) {
            $this->assertSame($thrown, $e);
        }

        $this->assertSame(0, R::count('writeprobe'));
        $this->assertFalse($this->inTransaction());
    }

    public function testWithWriteTransactionRethrowsTheOriginalWhenTheCallableEndedTheTransaction(): void
    {
        $this->resetProbe();
        // A frozen schema, as in production, is where a ROLLBACK with nothing to roll
        // back raises; fluid mode swallows it.
        R::freeze(true);

        try {
            with_write_transaction(function (): void {
                $this->storeProbe();
                R::exec('ROLLBACK');
                throw new \RuntimeException('original');
            });
            $this->fail('The exception should have propagated.');
        } catch (\RuntimeException $e) {
            $this->assertSame('original', $e->getMessage());
        } finally {
            R::freeze(false);
        }

        $this->assertSame(0, R::count('writeprobe'));
    }

    public function testWithWriteTransactionRefusesToRunInsideAnOpenTransaction(): void
    {
        $called = false;
        R::exec('BEGIN IMMEDIATE');

        try {
            with_write_transaction(function () use (&$called): void {
                $called = true;
            });
            $this->fail('A LogicException should have been thrown.');
        } catch (\LogicException) {
            $this->assertFalse($called);
            $this->assertTrue($this->inTransaction(), 'The outer transaction is left to its owner.');
        } finally {
            R::exec('ROLLBACK');
        }
    }
}

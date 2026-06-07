<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\get_option;
use function Lamb\now;
use function Lamb\permalink;
use function Lamb\post_has_slug;
use function Lamb\set_option;

class LambHelpersTest extends TestCase
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
}

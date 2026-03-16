<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\get_option;
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

    public function testPostHasSlugReturnsEmptyForNonExistentSlug(): void
    {
        $result = post_has_slug('this-slug-does-not-exist-' . uniqid());
        $this->assertSame('', $result);
    }

    public function testPostHasSlugReturnsSlugWhenPostExists(): void
    {
        $bean = R::dispense('post');
        $bean->slug = 'existing-slug-' . uniqid();
        R::store($bean);

        $result = post_has_slug($bean->slug);
        $this->assertSame($bean->slug, $result);
    }
}

<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Post\populate_bean;
use function Lamb\Response\upgrade_posts;

class UpgradePostsTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        global $config;
        $config = $config ?? [];
    }

    public function testVersion1PostWithCodeIsRehighlightedOnUpgrade(): void
    {
        $bean = R::dispense('post');
        $bean->body = "```php\necho 1;\n```";
        $bean->transformed = '<pre><code class="language-php">echo 1;</code></pre>';
        $bean->version = 1;
        R::store($bean);

        upgrade_posts([$bean]);

        $this->assertStringContainsString('phiki', $bean->transformed);
        $this->assertSame(POST_VERSION, (int)$bean->version);
    }

    public function testCurrentVersionPostIsNotRestored(): void
    {
        $bean = R::dispense('post');
        $bean->body = "plain post";
        $bean->transformed = '<p>stale marker that re-parsing would replace</p>';
        $bean->version = POST_VERSION;

        upgrade_posts([$bean]);

        $this->assertSame('<p>stale marker that re-parsing would replace</p>', $bean->transformed);
    }

    public function testPopulateBeanStampsCurrentVersion(): void
    {
        $bean = populate_bean('hello world');

        $this->assertSame(POST_VERSION, (int)$bean->version);
    }
}

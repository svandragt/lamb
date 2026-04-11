<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

class FeedTemplateTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testFeedEntryTitleFallsBackToDescriptionWhenEmpty(): void
    {
        require_once __DIR__ . '/../../vendor/autoload.php';

        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);

        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'http://localhost');
        }

        $bean = R::dispense('post');
        $bean->title = '';
        $bean->description = 'My status post content here';
        $bean->transformed = '<p>My status post content here</p>';
        $bean->created = '2024-01-01 12:00:00';
        $bean->updated = '2024-01-01 12:00:00';
        R::store($bean);

        global $config, $data;
        $config = [
            'site_title'   => 'Test Blog',
            'author_name'  => 'Test Author',
            'author_email' => 'test@test.com',
        ];
        $data = [
            'posts'    => [$bean],
            'title'    => 'Test Blog',
            'feed_url' => 'http://localhost/feed',
            'updated'  => '2024-01-01 12:00:00',
        ];

        ob_start();
        require __DIR__ . '/../../src/themes/default/feed.php';
        $output = ob_get_clean();

        $xml = new \SimpleXMLElement($output);
        $this->assertNotEmpty(
            (string) $xml->entry[0]->title,
            'Feed entry title should not be blank for status posts'
        );
        $this->assertSame('My status post content here', (string) $xml->entry[0]->title);
    }
}

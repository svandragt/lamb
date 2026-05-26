<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

class FeedJsonTemplateTest extends TestCase
{
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testFeedJsonHasVersionAndCoreFields(): void
    {
        $json = $this->renderJsonFeedWithPost([
            'title'       => 'Hello',
            'transformed' => '<p>Body</p>',
        ]);

        $this->assertSame('https://jsonfeed.org/version/1.1', $json['version']);
        $this->assertSame('Test Blog', $json['title']);
        $this->assertSame('http://localhost', $json['home_page_url']);
        $this->assertSame('http://localhost/feed.json', $json['feed_url']);
        $this->assertIsArray($json['authors']);
        $this->assertSame('Test Author', $json['authors'][0]['name']);
        $this->assertSame('http://localhost', $json['authors'][0]['url']);
        $this->assertIsArray($json['items']);
        $this->assertCount(1, $json['items']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testFeedJsonItemHasExpectedFields(): void
    {
        $json = $this->renderJsonFeedWithPost([
            'title'       => 'Hello',
            'transformed' => '<p>Full body content.</p>',
        ]);

        $item = $json['items'][0];
        $this->assertSame('Hello', $item['title']);
        $this->assertSame('<p>Full body content.</p>', $item['content_html']);
        $this->assertNotEmpty($item['id']);
        $this->assertNotEmpty($item['url']);
        $this->assertNotEmpty($item['date_published']);
        $this->assertNotEmpty($item['date_modified']);
        // Dates must be RFC3339
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
            $item['date_published']
        );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testFeedJsonOmitsTitleForTitlelessPosts(): void
    {
        $json = $this->renderJsonFeedWithPost([
            'title'       => '',
            'transformed' => '<p>Status update.</p>',
        ]);

        $item = $json['items'][0];
        $this->assertArrayNotHasKey('title', $item, 'Titleless posts should omit the title field per micro.blog convention');
        $this->assertSame('<p>Status update.</p>', $item['content_html']);
    }

    private function renderJsonFeedWithPost(array $fields): array
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
        $bean->title = $fields['title'] ?? '';
        $bean->description = $fields['description'] ?? '';
        $bean->transformed = $fields['transformed'] ?? '';
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
            'feed_url' => 'http://localhost/feed.json',
            'updated'  => '2024-01-01 12:00:00',
        ];

        ob_start();
        require __DIR__ . '/../../src/themes/default/feed_json.php';
        $output = ob_get_clean();

        $decoded = json_decode($output, true);
        $this->assertIsArray($decoded, 'JSON feed output must be valid JSON');
        return $decoded;
    }
}

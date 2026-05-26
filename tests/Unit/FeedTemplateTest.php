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
    public function testFeedEntryTitleIsEmptyForTitlelessPosts(): void
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
        $this->assertSame(
            '',
            (string) $xml->entry[0]->title,
            'Titleless posts should produce empty <title> for micro.blog convention'
        );
        $this->assertTrue(
            isset($xml->entry[0]->title),
            '<title> element must still be present (Atom requires it)'
        );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testFeedContentContainsFullTransformedHtmlNotDescription(): void
    {
        $xml = $this->renderFeedWithPost([
            'title'       => 'Full Content Post',
            'description' => 'Short excerpt',
            'transformed' => '<p>First paragraph.</p><p>Second paragraph with more detail.</p>',
        ]);

        $content = (string) $xml->entry[0]->content;
        $this->assertSame('html', (string) $xml->entry[0]->content['type']);
        $this->assertStringContainsString('<p>First paragraph.</p>', $content);
        $this->assertStringContainsString('<p>Second paragraph with more detail.</p>', $content);
        $this->assertStringNotContainsString('Short excerpt', $content);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testFeedContentDoesNotTruncateLongBodies(): void
    {
        $longParagraph = '<p>' . str_repeat('Lorem ipsum dolor sit amet. ', 500) . '</p>';
        $transformed = $longParagraph . '<p>FINAL_MARKER_END</p>';

        $xml = $this->renderFeedWithPost([
            'title'       => 'Long Post',
            'description' => 'Excerpt',
            'transformed' => $transformed,
        ]);

        $content = (string) $xml->entry[0]->content;
        $this->assertStringContainsString('FINAL_MARKER_END', $content, 'Long content must not be truncated');
        $this->assertGreaterThanOrEqual(strlen($transformed), strlen($content));
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testFeedContentKeepsImageTags(): void
    {
        $transformed = '<p>Here is an image:</p><p><img src="https://example.com/cat.jpg" alt="A cat"></p>';

        $xml = $this->renderFeedWithPost([
            'title'       => 'Image Post',
            'description' => 'Image excerpt',
            'transformed' => $transformed,
        ]);

        $content = (string) $xml->entry[0]->content;
        $this->assertStringContainsString('<img', $content);
        $this->assertStringContainsString('src="https://example.com/cat.jpg"', $content);
        $this->assertStringContainsString('alt="A cat"', $content);
    }

    private function renderFeedWithPost(array $fields): \SimpleXMLElement
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
            'feed_url' => 'http://localhost/feed',
            'updated'  => '2024-01-01 12:00:00',
        ];

        ob_start();
        require __DIR__ . '/../../src/themes/default/feed.php';
        $output = ob_get_clean();

        return new \SimpleXMLElement($output);
    }
}

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
        require __DIR__ . '/../../src/themes/base/feed.php';
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

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testFeedAuthorHasNoEmailAndIncludesUri(): void
    {
        $xml = $this->renderFeedWithPost([
            'title'       => 'Post',
            'description' => 'Excerpt',
            'transformed' => '<p>Body</p>',
        ]);

        $author = $xml->author;
        $this->assertSame('Test Author', (string) $author->name);
        $this->assertSame(ROOT_URL, (string) $author->uri);
        $this->assertFalse(isset($author->email), 'Author email should not be exposed in the feed');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testFeedEntryLinkHasRelAlternateAndTypeHtml(): void
    {
        $xml = $this->renderFeedWithPost([
            'title'       => 'Linked Post',
            'description' => 'Excerpt',
            'transformed' => '<p>Body</p>',
        ]);

        $link = $xml->entry[0]->link;
        $this->assertSame('alternate', (string) $link['rel']);
        $this->assertSame('text/html', (string) $link['type']);
        $this->assertNotEmpty((string) $link['href']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testFeedOmitsIconAndLogoWhenConventionFilesAbsent(): void
    {
        $xml = $this->renderFeedWithPost(
            ['title' => 'Post', 'transformed' => '<p>Body</p>'],
            []
        );

        $this->assertFalse(isset($xml->icon), 'Feed should not emit <icon> when favicon.png is absent');
        $this->assertFalse(isset($xml->logo), 'Feed should not emit <logo> when logo.png is absent');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testFeedIncludesIconFromFaviconConvention(): void
    {
        $xml = $this->renderFeedWithPost(
            ['title' => 'Post', 'transformed' => '<p>Body</p>'],
            ['favicon.png']
        );

        $this->assertSame(ROOT_URL . '/favicon.png', (string) $xml->icon);
        $this->assertFalse(isset($xml->logo), 'No logo.png means no <logo>');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testFeedIncludesLogoFromLogoConvention(): void
    {
        $xml = $this->renderFeedWithPost(
            ['title' => 'Post', 'transformed' => '<p>Body</p>'],
            ['favicon.png', 'logo.png']
        );

        $this->assertSame(ROOT_URL . '/favicon.png', (string) $xml->icon);
        $this->assertSame(ROOT_URL . '/logo.png', (string) $xml->logo);
    }

    /**
     * @param array $fields        Post bean fields.
     * @param array $conventionFiles Names of web-root convention files to create (e.g. favicon.png).
     */
    private function renderFeedWithPost(array $fields, array $conventionFiles = []): \SimpleXMLElement
    {
        require_once __DIR__ . '/../../vendor/autoload.php';

        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);

        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'http://localhost');
        }

        // ROOT_DIR is a constant defined once per process (Codeception does not
        // isolate test methods), so the web-root path is fixed and convention-file
        // presence is controlled per render by writing/removing the files on disk.
        $webRoot = sys_get_temp_dir() . '/lamb_feed_test_' . getmypid();
        if (!is_dir($webRoot)) {
            mkdir($webRoot, 0777, true);
        }
        foreach (['favicon.png', 'logo.png'] as $file) {
            @unlink($webRoot . '/' . $file);
        }
        foreach ($conventionFiles as $file) {
            file_put_contents($webRoot . '/' . $file, 'x');
        }
        if (!defined('ROOT_DIR')) {
            define('ROOT_DIR', $webRoot);
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
        require __DIR__ . '/../../src/themes/base/feed.php';
        $output = ob_get_clean();

        return new \SimpleXMLElement($output);
    }
}

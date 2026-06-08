<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Post\populate_bean;
use function Lamb\Theme\the_reply_context;

class ReplyContextTest extends TestCase
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

        R::exec('DELETE FROM post WHERE 1');
    }

    // Front-matter parsing --------------------------------------------------

    public function testFrontMatterHyphenSetsInReplyTo(): void
    {
        $bean = populate_bean("---\nin-reply-to: https://other.example/post\n---\nHi there");
        $this->assertSame('https://other.example/post', $bean->in_reply_to);
    }

    public function testFrontMatterUnderscoreSetsInReplyTo(): void
    {
        $bean = populate_bean("---\nin_reply_to: https://other.example/post\n---\nHi there");
        $this->assertSame('https://other.example/post', $bean->in_reply_to);
    }

    public function testAbsentInReplyToIsEmpty(): void
    {
        $bean = populate_bean('Just a normal post');
        $this->assertSame('', (string) $bean->in_reply_to);
    }

    public function testHyphenKeyDoesNotLeakAsProperty(): void
    {
        $bean = populate_bean("---\nin-reply-to: https://other.example/post\n---\nHi");
        // The hyphenated key must be normalised, not copied verbatim onto the bean.
        $this->assertNull($bean->{'in-reply-to'});
    }

    public function testCustomMultiWordKeyDoesNotCrashOnStore(): void
    {
        // Key normalisation rewrites `reading_time` to `reading-time`; a dashed
        // key is an invalid RedBean column, so the blind copy in
        // apply_frontmatter() must skip it rather than crash on store.
        $bean = populate_bean("---\ntitle: Hi\nreading_time: 5\n---\nBody");
        $id = R::store($bean);
        $this->assertGreaterThan(0, $id);
        $this->assertNull($bean->{'reading-time'});
        $this->assertNull($bean->{'reading_time'});
    }

    public function testListInReplyToUsesFirstEntry(): void
    {
        // A YAML list (Micropub clients may send multiple reply targets) collapses
        // to its first entry rather than being stored verbatim.
        $bean = populate_bean(
            "---\nin-reply-to:\n  - https://first.example/post\n  - https://second.example/post\n---\nHi"
        );
        $this->assertSame('https://first.example/post', $bean->in_reply_to);
    }

    // the_reply_context helper ----------------------------------------------

    public function testReplyContextHelperRendersMarkup(): void
    {
        $bean = R::dispense('post');
        $bean->in_reply_to = 'https://other.example/post';

        $html = the_reply_context($bean);
        $this->assertStringContainsString('u-in-reply-to', $html);
        $this->assertStringContainsString('https://other.example/post', $html);
        $this->assertStringContainsString('other.example', $html);
    }

    public function testReplyContextHelperEmptyWhenUnset(): void
    {
        $bean = R::dispense('post');
        $this->assertSame('', the_reply_context($bean));
    }

    // Atom feed -------------------------------------------------------------

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAtomFeedIncludesThrInReplyTo(): void
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
        $bean->transformed = '<p>A reply</p>';
        $bean->in_reply_to = 'https://other.example/post';
        $bean->created = '2024-01-01 12:00:00';
        $bean->updated = '2024-01-01 12:00:00';

        global $config, $data;
        $config = ['site_title' => 'Blog', 'author_name' => 'Author', 'author_email' => 'a@b.c'];
        $data = ['posts' => [$bean], 'title' => 'Blog', 'feed_url' => 'http://localhost/feed', 'updated' => '2024-01-01 12:00:00'];

        ob_start();
        require __DIR__ . '/../../src/themes/base/feed.php';
        $output = ob_get_clean();

        $this->assertStringContainsString('xmlns:thr', $output);
        $this->assertStringContainsString('thr:in-reply-to', $output);
        $this->assertStringContainsString('https://other.example/post', $output);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testJsonFeedIncludesMicroblogInReplyTo(): void
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
        $bean->transformed = '<p>A reply</p>';
        $bean->in_reply_to = 'https://other.example/post';
        $bean->created = '2024-01-01 12:00:00';
        $bean->updated = '2024-01-01 12:00:00';

        global $config, $data;
        $config = ['site_title' => 'Blog', 'author_name' => 'Author'];
        $data = ['posts' => [$bean], 'title' => 'Blog', 'feed_url' => 'http://localhost/feed.json', 'updated' => '2024-01-01 12:00:00'];

        ob_start();
        require __DIR__ . '/../../src/themes/base/feed_json.php';
        $output = ob_get_clean();

        $json = json_decode($output, true);
        $this->assertSame('https://other.example/post', $json['items'][0]['_microblog']['in_reply_to_url']);
    }
}

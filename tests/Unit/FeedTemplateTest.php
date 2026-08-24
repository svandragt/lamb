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
        \Lamb\Response\render_atom_feed($data, $config);
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
    public function testFeedContentAbsolutisesRootRelativeImageUrls(): void
    {
        // Pasted/uploaded images are stored as root-relative URLs. In a syndicated
        // feed those must be absolute or the reader resolves them against its own
        // host and shows a broken image (falling back to the alt text).
        $transformed = '<p><img src="/assets/2026/06/abc.webp" alt="pasted-1-0.png"></p>';

        $xml = $this->renderFeedWithPost([
            'title'       => 'Pasted Image',
            'transformed' => $transformed,
        ]);

        $content = (string) $xml->entry[0]->content;
        $this->assertStringContainsString('src="' . ROOT_URL . '/assets/2026/06/abc.webp"', $content);
        $this->assertStringNotContainsString('src="/assets', $content);
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

    public function testReplyEntryCarriesThreadTypeAndRelatedLink(): void
    {
        $target = 'https://other.example/post';
        $xml = $this->renderFeedWithPost([
            'title'       => 'A reply',
            'transformed' => '<p>Replying</p>',
            'in_reply_to' => $target,
        ]);

        // RFC 4685 item 1: the thr:in-reply-to carries ref/href and the
        // text/html media-type hint.
        $thr = $xml->entry[0]->children('http://purl.org/syndication/thread/1.0');
        $inReplyTo = $thr->{'in-reply-to'};
        // ref/href/type are in no namespace, so read them off attributes() rather
        // than $inReplyTo[...] (which resolves in the thr: namespace context here).
        $attrs = $inReplyTo->attributes();
        $this->assertSame($target, (string) $attrs->ref);
        $this->assertSame($target, (string) $attrs->href);
        $this->assertSame('text/html', (string) $attrs->type);

        // Item 4: a plain rel="related" link for readers that ignore the thr: ns.
        $related = null;
        foreach ($xml->entry[0]->link as $link) {
            if ((string) $link['rel'] === 'related') {
                $related = $link;
            }
        }
        $this->assertNotNull($related, 'entry should carry a rel="related" link');
        $this->assertSame($target, (string) $related['href']);
    }

    public function testNonReplyEntryHasNoThreadOrRelatedLink(): void
    {
        $xml = $this->renderFeedWithPost([
            'title'       => 'Not a reply',
            'transformed' => '<p>Body</p>',
        ]);

        $thr = $xml->entry[0]->children('http://purl.org/syndication/thread/1.0');
        $this->assertSame(0, $thr->{'in-reply-to'}->count());
        foreach ($xml->entry[0]->link as $link) {
            $this->assertNotSame('related', (string) $link['rel']);
        }
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
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testFeedAdvertisesWebSubHubWhenConfigured(): void
    {
        $xml = $this->renderFeedWithPost(
            ['title' => 'Post', 'transformed' => '<p>Body</p>'],
            [],
            ['websub_hubs' => 'https://hub.example.com/']
        );

        $hub = null;
        foreach ($xml->link as $link) {
            if ((string) $link['rel'] === 'hub') {
                $hub = (string) $link['href'];
            }
        }
        $this->assertSame('https://hub.example.com/', $hub, 'Feed should advertise the configured WebSub hub');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testFeedAdvertisesEveryCommaSeparatedHub(): void
    {
        $xml = $this->renderFeedWithPost(
            ['title' => 'Post', 'transformed' => '<p>Body</p>'],
            [],
            ['websub_hubs' => 'https://hub-a.example.com/, https://hub-b.example.com/']
        );

        $hubs = [];
        foreach ($xml->link as $link) {
            if ((string) $link['rel'] === 'hub') {
                $hubs[] = (string) $link['href'];
            }
        }
        $this->assertSame(
            ['https://hub-a.example.com/', 'https://hub-b.example.com/'],
            $hubs,
            'Every comma-separated hub should get its own link element'
        );
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testFeedOmitsHubLinkWhenNotConfigured(): void
    {
        $xml = $this->renderFeedWithPost(['title' => 'Post', 'transformed' => '<p>Body</p>']);

        foreach ($xml->link as $link) {
            $this->assertNotSame('hub', (string) $link['rel'], 'No hub link should be emitted without websub_hubs config');
        }
    }

    /**
     * @param array $fields        Post bean fields.
     * @param array $conventionFiles Names of web-root convention files to create (e.g. favicon.png).
     * @param array $extraConfig   Extra config keys merged into $config.
     */
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testFeedContentKeepsTheEscapingTheStoredHtmlHas(): void
    {
        // addChild() escapes `<` but not `&`, which strips exactly one layer
        // off the stored HTML: `&lt;script&gt;` — text the author typed, and
        // what Parsedown's safe mode produces for an ingested feed item —
        // reached subscribers as live markup in a type="html" element.
        $stored = '<p>Escaping demo &lt;script&gt;alert(1)&lt;/script&gt; &amp; more</p>';

        $xml = $this->renderFeedWithPost(['transformed' => $stored]);

        $this->assertSame($stored, (string) $xml->entry[0]->content);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testFeedContentStillCarriesRealMarkup(): void
    {
        $stored = '<p>Hello <a href="http://localhost/tag/php">#php</a></p>';

        $xml = $this->renderFeedWithPost(['transformed' => $stored]);

        $this->assertSame($stored, (string) $xml->entry[0]->content);
        $this->assertSame('html', (string) $xml->entry[0]->content['type']);
    }

    /**
     * Pins the deprecated theme-override path while it exists: a theme that still
     * ships its own feed.php is detected (and emit_feed() then honours it with a
     * deprecation notice), while feed_json.php it does not ship is not.
     *
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testFeedPartOverrideDetectsAThemeSuppliedFeedPart(): void
    {
        require_once __DIR__ . '/../../vendor/autoload.php';

        $themeDir = sys_get_temp_dir() . '/lamb_theme_override_' . getmypid() . '/';
        @mkdir($themeDir, 0777, true);
        file_put_contents($themeDir . 'feed.php', '<?php echo "THEME OVERRIDE";');

        if (!defined('THEME_DIR')) {
            define('THEME_DIR', $themeDir);
        }
        // THEME_DIR is a process constant; only assert when our fixture won the
        // define (guaranteed here by the separate process + disabled global state).
        if (THEME_DIR === $themeDir) {
            $this->assertSame($themeDir . 'feed.php', \Lamb\Response\feed_part_override('feed'));
            $this->assertNull(\Lamb\Response\feed_part_override('feed_json'));
        } else {
            $this->markTestSkipped('THEME_DIR already defined by another test');
        }

        @unlink($themeDir . 'feed.php');
        @rmdir($themeDir);
    }

    private function renderFeedWithPost(array $fields, array $conventionFiles = [], array $extraConfig = []): \SimpleXMLElement
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
        $bean->in_reply_to = $fields['in_reply_to'] ?? '';
        $bean->created = '2024-01-01 12:00:00';
        $bean->updated = '2024-01-01 12:00:00';
        R::store($bean);

        global $config, $data;
        $config = array_merge([
            'site_title'   => 'Test Blog',
            'author_name'  => 'Test Author',
            'author_email' => 'test@test.com',
        ], $extraConfig);
        $data = [
            'posts'    => [$bean],
            'title'    => 'Test Blog',
            'feed_url' => 'http://localhost/feed',
            'updated'  => '2024-01-01 12:00:00',
        ];

        ob_start();
        \Lamb\Response\render_atom_feed($data, $config);
        $output = ob_get_clean();

        return new \SimpleXMLElement($output);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testFeedEntryIdSurvivesAnAmpersandInTheSlug(): void
    {
        require_once __DIR__ . '/../../vendor/autoload.php';

        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);

        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'http://localhost');
        }

        // addChild() does not escape, so an unescaped `&` in the permalink used
        // to emit an empty <id/> and make the whole feed malformed.
        $bean = R::dispense('post');
        $bean->title = 'Tea & Cake';
        $bean->slug = 'tea-&-cake';
        $bean->description = 'Post';
        $bean->transformed = '<p>Post</p>';
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
        \Lamb\Response\render_atom_feed($data, $config);
        $output = ob_get_clean();

        $xml = new \SimpleXMLElement($output);
        $this->assertSame('http://localhost/tea-&-cake', (string) $xml->entry[0]->id);
    }
}

<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\parse_bean;
use function Lamb\Post\populate_bean;
use function Lamb\Post\set_reply_to;
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

    public function testListInReplyToKeepsAllEntries(): void
    {
        // A YAML list (Micropub clients may send multiple reply targets, and mf2
        // u-in-reply-to/RFC 4685 thr:in-reply-to both allow several — #583) keeps
        // every entry, stored space-separated like syndicated_to.
        $bean = populate_bean(
            "---\nin-reply-to:\n  - https://first.example/post\n  - https://second.example/post\n---\nHi"
        );
        $this->assertSame(
            'https://first.example/post https://second.example/post',
            $bean->in_reply_to
        );
    }

    public function testListInReplyToDedupesEntries(): void
    {
        $bean = populate_bean(
            "---\nin-reply-to:\n  - https://first.example/post\n  - https://first.example/post\n---\nHi"
        );
        $this->assertSame('https://first.example/post', $bean->in_reply_to);
    }

    public function testEditPathNormalizesSmartPunctuationFence(): void
    {
        // The web edit and Micropub update paths assign $bean->body directly and
        // call parse_bean() without going through populate_bean(). An iOS
        // Smart-Punctuation fence (`—-`) added on edit must still be recognised,
        // its metadata extracted, and the stored body normalised to `---` so the
        // post no longer renders the fence as literal text.
        $em = "\xE2\x80\x94"; // — em dash (U+2014)

        $bean = R::dispense('post');
        $bean->body = "$em-\nin-reply-to: https://other.example/post\n$em-\n\nReplying.";
        parse_bean($bean);

        $this->assertSame('https://other.example/post', $bean->in_reply_to);
        $this->assertSame(
            "---\nin-reply-to: https://other.example/post\n---\n\nReplying.",
            $bean->body
        );
        $this->assertStringNotContainsString($em, (string) $bean->transformed);
        $this->assertStringContainsString('Replying.', (string) $bean->transformed);
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

    public function testReplyContextRefusesToLinkANonHttpScheme(): void
    {
        // escape() encodes HTML metacharacters, not URL schemes — the same gap
        // link_source() and syndication_links() already guard. in_reply_to is
        // not author-only: a Micropub client holding just `create` scope sets
        // it, and replyTargetUrl() only unwraps the value, it does not validate
        // it. This markup is served on every page listing the post and inside
        // both feeds' content_html.
        foreach (['javascript:alert(1)', 'JaVaScRiPt:alert(1)', 'data:text/html;base64,eA==', 'vbscript:x'] as $url) {
            $bean = R::dispense('post');
            $bean->in_reply_to = $url;

            $html = the_reply_context($bean);

            $this->assertStringNotContainsString('href=', $html, $url);
            $this->assertStringNotContainsString('<a ', $html, $url);
            // The target is still shown, just not as a link.
            $this->assertStringContainsString('In reply to', $html, $url);
        }
    }

    public function testReplyContextStillLinksAnOrdinaryUrl(): void
    {
        $bean = R::dispense('post');
        $bean->in_reply_to = 'https://e.test/a?b=1&c=2';

        $html = the_reply_context($bean);

        $this->assertStringContainsString('href="https://e.test/a?b=1&amp;c=2"', $html);
        $this->assertStringContainsString('u-in-reply-to', $html);
    }

    public function testReplyContextRendersEachOfMultipleTargets(): void
    {
        // #583: a post may reply to several URLs at once, space-separated in
        // the stored column like syndicated_to.
        $bean = R::dispense('post');
        $bean->in_reply_to = 'https://first.example/post https://second.example/post';

        $html = the_reply_context($bean);

        $this->assertSame(2, substr_count($html, 'u-in-reply-to'));
        $this->assertStringContainsString('href="https://first.example/post"', $html);
        $this->assertStringContainsString('href="https://second.example/post"', $html);
        $this->assertStringContainsString('In reply to', $html);
    }

    public function testReplyContextShowsNonHttpTargetAsTextAmongValidOnes(): void
    {
        // One bad scheme among several valid targets loses only its own link;
        // the valid targets are still linked.
        $bean = R::dispense('post');
        $bean->in_reply_to = 'https://first.example/post javascript:alert(1) https://second.example/post';

        $html = the_reply_context($bean);

        $this->assertSame(2, substr_count($html, 'u-in-reply-to'));
        $this->assertStringContainsString('href="https://first.example/post"', $html);
        $this->assertStringContainsString('href="https://second.example/post"', $html);
        $this->assertStringContainsString('javascript:alert(1)', $html);
        $this->assertStringNotContainsString('href="javascript:alert(1)"', $html);
    }

    public function testReplyContextHelperEmptyWhenUnset(): void
    {
        $bean = R::dispense('post');
        $this->assertSame('', the_reply_context($bean));
    }

    public function testReplyContextDoesNotEmitSupersededRelInReplyTo(): void
    {
        // rel="in-reply-to" is superseded by the u-in-reply-to h-entry property
        // and is not a registered HTML link type — validators flag it and nothing
        // reads it that does not already read the class (#585).
        $bean = R::dispense('post');
        $bean->in_reply_to = 'https://other.example/post';

        $html = the_reply_context($bean);

        $this->assertStringContainsString('u-in-reply-to', $html);
        $this->assertStringNotContainsString('rel="in-reply-to"', $html);
    }

    // set_reply_to helper ----------------------------------------------------

    public function testSetReplyToAddsFrontMatterBlockToPlainBody(): void
    {
        $body = set_reply_to('Just a status', 'https://other.example/post');
        $bean = populate_bean($body);
        $this->assertSame('https://other.example/post', $bean->in_reply_to);
        $this->assertStringContainsString('Just a status', $bean->body);
    }

    public function testSetReplyToPreservesOtherFrontMatter(): void
    {
        $body = set_reply_to("---\nslug: keep-me\ndraft: true\n---\nBody text", 'https://other.example/post');
        $this->assertStringContainsString('slug: keep-me', $body);
        $this->assertStringContainsString('draft: true', $body);

        $bean = populate_bean($body);
        $this->assertSame('https://other.example/post', $bean->in_reply_to);
        $this->assertSame('keep-me', $bean->slug);
        $this->assertSame(1, (int) $bean->draft);
    }

    public function testSetReplyToReplacesUnderscoreSpelledKey(): void
    {
        // One key survives, whichever spelling the author used: two lines both
        // matching parse_matter()'s normalisation would race for the value.
        $body = set_reply_to("---\nin_reply_to: https://old.example/post\n---\nHi", 'https://new.example/post');
        $this->assertStringNotContainsString('old.example', $body);
        $this->assertSame('https://new.example/post', populate_bean($body)->in_reply_to);
    }

    public function testSetReplyToRemovesKeyWhenValueEmpty(): void
    {
        $body = set_reply_to("---\ntitle: Kept\nin-reply-to: https://other.example/post\n---\nHi", '');
        $this->assertStringNotContainsString('in-reply-to', $body);
        $this->assertStringContainsString('title: Kept', $body);
        $this->assertSame('', (string) populate_bean($body)->in_reply_to);
    }

    public function testSetReplyToRemovesListValueAndEmptyBlock(): void
    {
        // A YAML list spans continuation lines; leaving them behind would make
        // the block unparseable, and an emptied block must go entirely.
        $body = set_reply_to(
            "---\nin-reply-to:\n  - https://first.example/post\n  - https://second.example/post\n---\nHi",
            ''
        );
        $this->assertSame('Hi', trim($body));
    }

    public function testSetReplyToQuotesValueWithNewlineInjection(): void
    {
        // The value arrives from a Micropub request: a newline must not be able
        // to inject further front-matter keys (as build_matter() guards too).
        $body = set_reply_to('Status', "https://other.example/post\ndraft: true");
        $bean = populate_bean($body);
        $this->assertSame(0, (int) $bean->draft);
    }

    public function testSetReplyToLeavesNestedKeysAlone(): void
    {
        // Only a top-level `in-reply-to` is this function's business. An indented
        // one belongs to whatever block encloses it, and treating it as the key
        // took the rest of that block's lines with it as "continuations".
        $body = set_reply_to(
            "---\nmeta:\n  in-reply-to: https://old.example/x\n  other: keep-me\ntitle: T\n---\nHi",
            'https://new.example/post'
        );

        $this->assertStringContainsString('other: keep-me', $body);
        $this->assertStringContainsString('in-reply-to: https://old.example/x', $body);
        $this->assertSame('https://new.example/post', populate_bean($body)->in_reply_to);
    }

    public function testSetReplyToAcceptsMultipleTargets(): void
    {
        // #583: set_reply_to() (and set_frontmatter_key() underneath) can now
        // write more than one target, as a YAML list.
        $body = set_reply_to(
            'Status',
            ['https://first.example/post', 'https://second.example/post']
        );

        $bean = populate_bean($body);
        $this->assertSame(
            'https://first.example/post https://second.example/post',
            $bean->in_reply_to
        );
    }

    public function testSetReplyToSingleElementListWritesScalarShape(): void
    {
        // A one-element list is indistinguishable in effect from the plain
        // scalar call: same value, same YAML shape (not a one-item list).
        $listBody = set_reply_to('Status', ['https://first.example/post']);
        $scalarBody = set_reply_to('Status', 'https://first.example/post');

        $this->assertSame($scalarBody, $listBody);
        $this->assertStringNotContainsString('- https://first.example/post', $listBody);
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
        \Lamb\Response\render_atom_feed($data, $config);
        $output = ob_get_clean();

        $this->assertStringContainsString('xmlns:thr', $output);
        $this->assertStringContainsString('thr:in-reply-to', $output);
        $this->assertStringContainsString('https://other.example/post', $output);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAtomFeedContentCarriesReplyContextMarkup(): void
    {
        // thr:in-reply-to is invisible to most readers, and services that thread
        // replies (micro.blog) look for u-in-reply-to in the item HTML — so the
        // reply context has to travel inside <content>, not only in the theme.
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
        $data = ['posts' => [$bean], 'title' => 'Blog', 'feed_url' => 'http://localhost/feed', 'updated' => '2024-01-01 12:00:00'];

        ob_start();
        \Lamb\Response\render_atom_feed($data, $config);
        $output = ob_get_clean();

        $content = (string) (new \SimpleXMLElement($output))->entry->content;
        $this->assertStringContainsString('u-in-reply-to', $content);
        $this->assertStringContainsString('https://other.example/post', $content);
        $this->assertStringContainsString('<p>A reply</p>', $content);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAtomFeedContentUnchangedForNonReply(): void
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
        $bean->transformed = '<p>Not a reply</p>';
        $bean->created = '2024-01-01 12:00:00';
        $bean->updated = '2024-01-01 12:00:00';

        global $config, $data;
        $config = ['site_title' => 'Blog', 'author_name' => 'Author'];
        $data = ['posts' => [$bean], 'title' => 'Blog', 'feed_url' => 'http://localhost/feed', 'updated' => '2024-01-01 12:00:00'];

        ob_start();
        \Lamb\Response\render_atom_feed($data, $config);
        $output = ob_get_clean();

        $content = (string) (new \SimpleXMLElement($output))->entry->content;
        $this->assertSame('<p>Not a reply</p>', $content);
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
        \Lamb\Response\render_json_feed($data, $config);
        $output = ob_get_clean();

        $json = json_decode($output, true);
        $this->assertSame('https://other.example/post', $json['items'][0]['_microblog']['in_reply_to_url']);
        // `_microblog` is a JSON Feed extension no plain reader looks at: the
        // same relationship has to be visible in content_html as well.
        $this->assertStringContainsString('u-in-reply-to', $json['items'][0]['content_html']);
        $this->assertStringContainsString('<p>A reply</p>', $json['items'][0]['content_html']);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testAtomFeedEmitsThrInReplyToAndRelatedPerTarget(): void
    {
        // #583: a post replying to several URLs gets one thr:in-reply-to and
        // one rel="related" link per target, not just the first.
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
        $bean->in_reply_to = 'https://first.example/post https://second.example/post';
        $bean->created = '2024-01-01 12:00:00';
        $bean->updated = '2024-01-01 12:00:00';

        global $config, $data;
        $config = ['site_title' => 'Blog', 'author_name' => 'Author'];
        $data = ['posts' => [$bean], 'title' => 'Blog', 'feed_url' => 'http://localhost/feed', 'updated' => '2024-01-01 12:00:00'];

        ob_start();
        \Lamb\Response\render_atom_feed($data, $config);
        $output = ob_get_clean();

        $this->assertSame(2, substr_count($output, 'thr:in-reply-to'));
        $this->assertSame(2, substr_count($output, 'rel="related"'));
        $this->assertStringContainsString('https://first.example/post', $output);
        $this->assertStringContainsString('https://second.example/post', $output);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testJsonFeedMicroblogInReplyToUsesFirstValidTargetOnly(): void
    {
        // `_microblog.in_reply_to_url` is a micro.blog convention for a single
        // target, so a multi-target post (#583) reports only the first —
        // every target still reaches the reader via content_html.
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
        $bean->in_reply_to = 'https://first.example/post https://second.example/post';
        $bean->created = '2024-01-01 12:00:00';
        $bean->updated = '2024-01-01 12:00:00';

        global $config, $data;
        $config = ['site_title' => 'Blog', 'author_name' => 'Author'];
        $data = ['posts' => [$bean], 'title' => 'Blog', 'feed_url' => 'http://localhost/feed.json', 'updated' => '2024-01-01 12:00:00'];

        ob_start();
        \Lamb\Response\render_json_feed($data, $config);
        $output = ob_get_clean();

        $json = json_decode($output, true);
        $this->assertSame('https://first.example/post', $json['items'][0]['_microblog']['in_reply_to_url']);
        $this->assertSame(2, substr_count($json['items'][0]['content_html'], 'u-in-reply-to'));
    }
}

<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Microblog\conversation_url;
use function Lamb\Microblog\parse_replies;
use function Lamb\Microblog\posts_due;
use function Lamb\Microblog\replies_for_post;
use function Lamb\Microblog\reply_text;
use function Lamb\Microblog\store_replies;
use function Lamb\Microblog\sync_replies;
use function Lamb\Microblog\username;

use const Lamb\Microblog\SYNC_INTERVAL;
use const Lamb\Microblog\SYNC_POSTS_PER_RUN;

/**
 * Replies from Micro.blog: fetched as jf2 by /_cron, stored locally, and
 * rendered by the theme so visitors never load micro.blog's script.
 */
class MicroblogTest extends TestCase
{
    private const CONFIG = ['microblog_username' => 'lamb'];

    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::nuke();
        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'https://example.com');
        }
    }

    private function post(array $fields = []): \RedBeanPHP\OODBBean
    {
        $bean = R::dispense('post');
        $bean->body = 'Hello';
        $bean->transformed = '<p>Hello</p>';
        $bean->created = date('Y-m-d H:i:s', time() - 3600);
        $bean->updated = $bean->created;
        $bean->draft = 0;
        $bean->deleted = 0;
        foreach ($fields as $key => $value) {
            $bean->$key = $value;
        }
        R::store($bean);

        return $bean;
    }

    private static function entry(int $id, array $overrides = []): array
    {
        return array_merge([
            'type' => 'entry',
            'author' => ['type' => 'card', 'name' => "Author $id", 'url' => "https://author$id.example"],
            'url' => "https://micro.blog/someone/$id",
            'published' => '2026-11-10T18:27:17+00:00',
            'wm-id' => $id,
            'content' => ['text' => "Reply $id", 'html' => "<p>Reply $id</p>"],
        ], $overrides);
    }

    private static function feed(array $children): string
    {
        return (string) json_encode(['type' => 'feed', 'name' => 'Conversation', 'children' => $children]);
    }

    private static function ok(string $body): callable
    {
        return static fn(string $url, array $opts): array => ['status' => 200, 'headers' => [], 'body' => $body];
    }

    // username ---------------------------------------------------------------

    public function testUsernameIsOffWhenUnsetOrInvalid(): void
    {
        $this->assertNull(username([]));
        $this->assertNull(username(['microblog_username' => '']));
        $this->assertNull(username(['microblog_username' => 'bad name']));
        $this->assertNull(username(['microblog_username' => 'a/b']));
        $this->assertSame('lamb', username(['microblog_username' => ' @lamb ']));
    }

    public function testConversationUrlEncodesThePermalink(): void
    {
        $this->assertSame(
            'https://micro.blog/conversation.js?url=https%3A%2F%2Fexample.com%2Fstatus%2F1%3Fa%3Db%26c&format=jf2',
            conversation_url('https://example.com/status/1?a=b&c')
        );
    }

    // parse_replies ----------------------------------------------------------

    public function testParseRepliesReadsEntries(): void
    {
        $replies = parse_replies(self::feed([self::entry(7)]));

        $this->assertCount(1, $replies);
        $this->assertSame(7, $replies[0]['wm_id']);
        $this->assertSame('Author 7', $replies[0]['author_name']);
        $this->assertSame('https://author7.example', $replies[0]['author_url']);
        $this->assertSame('Reply 7', $replies[0]['content']);
        $this->assertSame(date('Y-m-d H:i:s', strtotime('2026-11-10T18:27:17+00:00')), $replies[0]['published']);
    }

    public function testParseRepliesAcceptsAnEmptyConversation(): void
    {
        $this->assertSame([], parse_replies(self::feed([])));
    }

    public function testParseRepliesRejectsAnythingThatIsNotJf2(): void
    {
        $this->assertNull(parse_replies('Post not found'));
        $this->assertNull(parse_replies('{"type":"feed"}'));
        $this->assertNull(parse_replies('{"children":"nope"}'));
        $this->assertNull(parse_replies('{"children": [')); // truncated
    }

    public function testParseRepliesSkipsEntriesWithoutIdOrSafeUrl(): void
    {
        $replies = parse_replies(self::feed([
            self::entry(1, ['wm-id' => null]),
            self::entry(2, ['url' => 'javascript:alert(1)']),
            'not an entry',
            self::entry(3),
        ]));

        $this->assertSame([3], array_column($replies, 'wm_id'));
    }

    public function testParseRepliesDropsUnsafeAuthorUrlAndFallsBack(): void
    {
        $replies = parse_replies(self::feed([
            self::entry(1, ['author' => ['url' => 'javascript:alert(1)']]),
            self::entry(2, ['author' => ['url' => 'https://host.example/me']]),
        ]));

        $this->assertNull($replies[0]['author_url']);
        $this->assertSame('Someone', $replies[0]['author_name']);
        $this->assertSame('host.example', $replies[1]['author_name']);
    }

    public function testReplyTextFallsBackToStrippedHtml(): void
    {
        $this->assertSame('Hi there & you', reply_text(['html' => "<p>Hi <b>there</b>\n&amp; you</p>"]));
        $this->assertSame('plain', reply_text(' plain '));
        $this->assertSame('', reply_text(null));
    }

    // store_replies ----------------------------------------------------------

    public function testStoreRepliesAddsUpdatesAndRemoves(): void
    {
        $post = $this->post();
        store_replies((int) $post->id, parse_replies(self::feed([self::entry(1), self::entry(2)])));
        $this->assertCount(2, replies_for_post((int) $post->id));

        store_replies((int) $post->id, parse_replies(self::feed([
            self::entry(2, ['content' => ['text' => 'Edited']]),
            self::entry(3),
        ])));

        $replies = replies_for_post((int) $post->id);
        $this->assertSame([2, 3], array_map(static fn($r) => (int) $r->wm_id, $replies));
        $this->assertSame('Edited', $replies[0]->content);
        $this->assertSame(2, R::count('microblogreply'));
    }

    // posts_due --------------------------------------------------------------

    public function testOnlyRecentPublishedPostsAreDue(): void
    {
        $recent = $this->post();
        $this->post(['draft' => 1]);
        $this->post(['deleted' => 1]);
        $this->post(['created' => date('Y-m-d H:i:s', time() + 3600)]);
        $this->post(['created' => date('Y-m-d H:i:s', time() - 60 * 86400)]);

        $this->assertSame([(int) $recent->id], array_map(static fn($p) => (int) $p->id, posts_due(time())));
    }

    public function testPostsAreCheckedAtMostOncePerInterval(): void
    {
        $post = $this->post();
        $now = time();

        sync_replies(self::CONFIG, self::ok(self::feed([])), $now);
        $this->assertSame([], posts_due($now + SYNC_INTERVAL - 1));
        $this->assertCount(1, posts_due($now + SYNC_INTERVAL));
    }

    public function testARunChecksABoundedNumberOfPosts(): void
    {
        for ($i = 0; $i < SYNC_POSTS_PER_RUN + 3; $i++) {
            $this->post();
        }

        $this->assertCount(SYNC_POSTS_PER_RUN, posts_due(time()));
    }

    // sync_replies -----------------------------------------------------------

    public function testSyncIsOffWithoutAUsername(): void
    {
        $this->post();
        $called = false;
        $fetcher = static function () use (&$called): ?array {
            $called = true;
            return null;
        };

        $this->assertSame('', sync_replies([], $fetcher));
        $this->assertFalse($called);
    }

    public function testSyncFetchesThePostConversationAndStoresReplies(): void
    {
        $post = $this->post();
        $urls = [];
        $fetcher = static function (string $url, array $opts) use (&$urls): array {
            $urls[] = $url;
            return ['status' => 200, 'headers' => [], 'body' => self::feed([self::entry(5)])];
        };

        $line = sync_replies(self::CONFIG, $fetcher);

        $this->assertSame([conversation_url(\Lamb\permalink($post))], $urls);
        $this->assertSame('Micro.blog replies: checked 1 post(s), 0 failed.' . PHP_EOL, $line);
        $this->assertCount(1, replies_for_post((int) $post->id));
    }

    /**
     * @dataProvider failedResponses
     */
    public function testAFailedFetchKeepsTheStoredReplies(?array $response): void
    {
        $post = $this->post();
        store_replies((int) $post->id, parse_replies(self::feed([self::entry(1)])));

        $line = sync_replies(self::CONFIG, static fn(): ?array => $response);

        $this->assertSame('Micro.blog replies: checked 1 post(s), 1 failed.' . PHP_EOL, $line);
        $this->assertCount(1, replies_for_post((int) $post->id));
    }

    /** @return array<string, array{0: ?array}> */
    public static function failedResponses(): array
    {
        return [
            'unreachable' => [null],
            'server error' => [['status' => 503, 'headers' => [], 'body' => '']],
            'not found' => [['status' => 404, 'headers' => [], 'body' => 'Post not found']],
            'not json' => [['status' => 200, 'headers' => [], 'body' => '<html>down</html>']],
        ];
    }

    public function testSyncPrunesRepliesOfPurgedPosts(): void
    {
        $post = $this->post();
        store_replies((int) $post->id, parse_replies(self::feed([self::entry(1)])));
        R::trash($post);

        sync_replies(self::CONFIG, self::ok(self::feed([])));

        $this->assertSame(0, R::count('microblogreply'));
    }

    // theme part -------------------------------------------------------------

    private function renderPart(array $config, \RedBeanPHP\OODBBean $post, string $template = 'status'): string
    {
        $GLOBALS['config'] = $config;
        $GLOBALS['data'] = ['posts' => [$post]];
        $GLOBALS['template'] = $template;
        ob_start();
        include dirname(__DIR__, 2) . '/src/themes/base/parts/_microblog_replies.php';
        return (string) ob_get_clean();
    }

    public function testPartShowsEscapedRepliesAndReplyLink(): void
    {
        $post = $this->post();
        store_replies((int) $post->id, parse_replies(self::feed([
            self::entry(1, ['content' => ['text' => '<script>alert(1)</script>']]),
        ])));

        $html = $this->renderPart(self::CONFIG, $post);

        $this->assertStringContainsString('Author 1', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('href="https://micro.blog/lamb"', $html);
    }

    public function testPartRendersNothingWhenOffOrNotAPublishedStatusPage(): void
    {
        $post = $this->post();
        $draft = $this->post(['draft' => 1]);

        $this->assertSame('', trim($this->renderPart([], $post)));
        $this->assertSame('', trim($this->renderPart(self::CONFIG, $post, 'home')));
        $this->assertSame('', trim($this->renderPart(self::CONFIG, $draft)));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function layoutProvider(): array
    {
        return ['base' => ['base'], '2026' => ['2026']];
    }

    /**
     * @dataProvider layoutProvider
     */
    public function testLayoutIncludesThePart(string $theme): void
    {
        $layout = (string) file_get_contents(dirname(__DIR__, 2) . "/src/themes/$theme/html.php");

        $this->assertMatchesRegularExpression('/part\(\s*[\'"]_microblog_replies[\'"]\s*\)/', $layout);
    }
}

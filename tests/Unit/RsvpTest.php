<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;
use Lamb\Micropub\LambMicropubAdapter;

use function Lamb\parse_bean;
use function Lamb\Post\populate_bean;
use function Lamb\Theme\preload_text;
use function Lamb\Theme\the_reply_context;

class RsvpTest extends TestCase
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
        if (!defined('ROOT_DIR')) {
            define('ROOT_DIR', sys_get_temp_dir() . '/lamb_test');
        }

        global $config;
        $config = $config ?? [];
        $config['site_url'] = 'http://localhost';

        $_GET = [];
        R::exec('DELETE FROM post WHERE 1');
    }

    protected function tearDown(): void
    {
        $_GET = [];
    }

    // Front-matter parsing --------------------------------------------------

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function rsvpValues(): array
    {
        return [
            ['yes', 'yes'],
            ['no', 'no'],
            ['maybe', 'maybe'],
            ['interested', 'interested'],
            // Case folds onto the canonical value the markup emits.
            ['Yes', 'yes'],
            ['INTERESTED', 'interested'],
            // A quoted scalar is the same value, just typed defensively.
            ['"no"', 'no'],
            // YAML booleans have no faithful text, so they are mapped rather
            // than dropped: `rsvp: true` means the author is going.
            ['true', 'yes'],
            ['false', 'no'],
            // Outside the mf2 vocabulary: stored as not-an-RSVP.
            ['probably', ''],
            ['1', ''],
            ['', ''],
        ];
    }

    /**
     * @dataProvider rsvpValues
     */
    public function testFrontMatterRsvpIsNormalised(string $written, string $expected): void
    {
        $bean = populate_bean("---\nrsvp: $written\n---\nSee you there");
        $this->assertSame($expected, (string) $bean->rsvp);
    }

    public function testAbsentRsvpIsEmpty(): void
    {
        $bean = populate_bean('Just a normal post');
        $this->assertSame('', (string) $bean->rsvp);
    }

    public function testRsvpSurvivesAStoreAndReload(): void
    {
        $bean = populate_bean("---\nin-reply-to: https://e.test/event\nrsvp: maybe\n---\nMight make it");
        $id = R::store($bean);

        $reloaded = R::load('post', $id);
        $this->assertSame('maybe', (string) $reloaded->rsvp);
        $this->assertSame('https://e.test/event', (string) $reloaded->in_reply_to);
    }

    public function testRemovingTheLineOnEditClearsTheStoredRsvp(): void
    {
        $bean = populate_bean("---\nrsvp: yes\n---\nSee you there");
        $bean->body = 'See you there';
        parse_bean($bean);

        $this->assertSame('', (string) $bean->rsvp);
    }

    public function testUnrecognisedRsvpDoesNotSurviveAnEdit(): void
    {
        // The value is validated on every parse, not only on first save: an
        // edit that mistypes the reply must leave the post a plain reply
        // rather than keep the last valid value.
        $bean = populate_bean("---\nrsvp: yes\n---\nSee you there");
        $bean->body = "---\nrsvp: probably\n---\nSee you there";
        parse_bean($bean);

        $this->assertSame('', (string) $bean->rsvp);
    }

    // the_reply_context helper ----------------------------------------------

    public function testRsvpRendersPRsvpAlongsideTheReplyTarget(): void
    {
        $bean = R::dispense('post');
        $bean->in_reply_to = 'https://e.test/event';
        $bean->rsvp = 'yes';

        $html = the_reply_context($bean);

        $this->assertStringContainsString('<data class="p-rsvp" value="yes">yes</data>', $html);
        $this->assertStringContainsString('href="https://e.test/event"', $html);
        $this->assertStringContainsString('u-in-reply-to', $html);
        // One line, not two: an RSVP is a reply to the event.
        $this->assertSame(1, substr_count($html, '<p class="reply-context">'));
        $this->assertStringNotContainsString('In reply to', $html);
    }

    public function testRsvpWithoutATargetStillRenders(): void
    {
        $bean = R::dispense('post');
        $bean->rsvp = 'interested';

        $html = the_reply_context($bean);

        $this->assertStringContainsString('<data class="p-rsvp" value="interested">interested</data>', $html);
        $this->assertStringNotContainsString('<a ', $html);
    }

    public function testAPlainReplyIsUnchangedByTheRsvpSupport(): void
    {
        $bean = R::dispense('post');
        $bean->in_reply_to = 'https://e.test/post';

        $html = the_reply_context($bean);

        $this->assertStringContainsString('In reply to', $html);
        $this->assertStringNotContainsString('p-rsvp', $html);
    }

    public function testStoredRsvpOutsideTheVocabularyIsNotEmitted(): void
    {
        // The column is not author-only (a Micropub client holding `create`
        // scope writes it) and this markup is served in both feeds, so the
        // theme drops a value a parser could not read rather than trusting it.
        foreach (['probably', '"><script>alert(1)</script>', 'yes no'] as $value) {
            $bean = R::dispense('post');
            $bean->rsvp = $value;

            $this->assertSame('', the_reply_context($bean), $value);
        }
    }

    public function testStoredRsvpCaseAndPaddingStillRender(): void
    {
        $bean = R::dispense('post');
        $bean->rsvp = ' Yes ';

        $this->assertStringContainsString('value="yes"', the_reply_context($bean));
    }

    // Entry-form pre-fill ---------------------------------------------------

    public function testQueryStringPreFillsTheRsvpFrontMatter(): void
    {
        $_GET = [
            'rsvp'        => 'YES',
            'in-reply-to' => 'https://e.test/event',
            'text'        => 'See you there',
        ];

        $body = html_entity_decode(preload_text(), ENT_QUOTES, 'UTF-8');
        $bean = populate_bean($body);

        $this->assertSame('yes', (string) $bean->rsvp);
        $this->assertSame('https://e.test/event', (string) $bean->in_reply_to);
        $this->assertStringContainsString('See you there', $body);
    }

    public function testPreFillAcceptsTheUnderscoreSpellingOfTheTarget(): void
    {
        $_GET = ['rsvp' => 'maybe', 'in_reply_to' => 'https://e.test/event'];

        $bean = populate_bean(html_entity_decode(preload_text(), ENT_QUOTES, 'UTF-8'));

        $this->assertSame('maybe', (string) $bean->rsvp);
        $this->assertSame('https://e.test/event', (string) $bean->in_reply_to);
    }

    public function testPreFillDropsValuesItCannotVouchFor(): void
    {
        // A pre-filled block is a draft the author reviews, but it must not
        // arrive carrying a scheme the reply-context markup would refuse to
        // link, or a reply the next parse would discard.
        $_GET = ['rsvp' => 'probably', 'in-reply-to' => 'javascript:alert(1)'];

        $this->assertSame('', preload_text());
    }

    public function testPreFillWithoutParametersIsUnchanged(): void
    {
        $_GET = ['text' => 'Just a note'];
        $this->assertSame('Just a note', preload_text());

        $_GET = [];
        $this->assertSame('', preload_text());
    }

    public function testPreFillWritesFrontMatterThroughTheYamlWriter(): void
    {
        // A newline in a query value must not be able to add a second key: the
        // block is dumped, not interpolated.
        $_GET = ['rsvp' => "yes\ndraft: true", 'text' => 'hi'];

        $bean = populate_bean(html_entity_decode(preload_text(), ENT_QUOTES, 'UTF-8'));

        $this->assertSame('', (string) $bean->rsvp);
        $this->assertEmpty($bean->draft);
    }

    // Micropub --------------------------------------------------------------

    public function testMicropubCreateWritesTheRsvpFrontMatter(): void
    {
        $adapter = new LambMicropubAdapter();
        $adapter->createCallback([
            'type'       => ['h-entry'],
            'properties' => [
                'content'     => ['Micropub RSVP'],
                'in-reply-to' => ['https://e.test/event'],
                'rsvp'        => ['yes'],
            ],
        ]);

        $post = R::findOne('post', ' body LIKE ? ', ['%Micropub RSVP%']);
        $this->assertNotNull($post);
        $this->assertSame('yes', (string) $post->rsvp);
        // Consumed into front matter, so it must not also be dumped as an
        // "unrecognised property" JSON block readers would see.
        $this->assertStringNotContainsString('```json', (string) $post->body);
    }

    public function testMicropubCreateKeepsAnUnrecognisedRsvpAsAnExtraProperty(): void
    {
        // No front-matter line can carry it, but silently dropping a property
        // the client sent loses data; the JSON block preserves it.
        $adapter = new LambMicropubAdapter();
        $adapter->createCallback([
            'type'       => ['h-entry'],
            'properties' => [
                'content' => ['Odd RSVP'],
                'rsvp'    => ['probably'],
            ],
        ]);

        $post = R::findOne('post', ' body LIKE ? ', ['%Odd RSVP%']);
        $this->assertNotNull($post);
        $this->assertSame('', (string) $post->rsvp);
        $this->assertStringContainsString('probably', (string) $post->body);
    }

    public function testMicropubSourceQueryReportsTheRsvp(): void
    {
        $adapter = new LambMicropubAdapter();
        $location = $adapter->createCallback([
            'type'       => ['h-entry'],
            'properties' => [
                'content'     => ['Source query RSVP'],
                'in-reply-to' => ['https://e.test/event'],
                'rsvp'        => ['maybe'],
            ],
        ]);
        $this->assertIsString($location);

        $source = $adapter->sourceQueryCallback($location);

        $this->assertIsArray($source);
        $this->assertSame(['maybe'], $source['properties']['rsvp']);
    }

    public function testMicropubReplaceChangesTheRsvpAndDeleteRemovesIt(): void
    {
        $adapter = new LambMicropubAdapter();
        $location = $adapter->createCallback([
            'type'       => ['h-entry'],
            'properties' => [
                'content'     => ['Updatable RSVP'],
                'in-reply-to' => ['https://e.test/event'],
                'rsvp'        => ['yes'],
            ],
        ]);
        $this->assertIsString($location);

        $adapter->updateCallback($location, ['replace' => ['rsvp' => ['no']]]);
        $post = R::findOne('post', ' body LIKE ? ', ['%Updatable RSVP%']);
        $this->assertSame('no', (string) $post->rsvp);

        $adapter->updateCallback($location, ['delete' => ['rsvp']]);
        $post = R::findOne('post', ' body LIKE ? ', ['%Updatable RSVP%']);
        $this->assertSame('', (string) $post->rsvp);
        // The reply target is untouched: only the RSVP was deleted.
        $this->assertSame('https://e.test/event', (string) $post->in_reply_to);
    }

    public function testMicropubRefusesAnRsvpOutsideTheVocabulary(): void
    {
        // Storing it would report a success that the post's own q=source then
        // contradicts, since the next parse discards the value.
        $adapter = new LambMicropubAdapter();
        $location = $adapter->createCallback([
            'type'       => ['h-entry'],
            'properties' => [
                'content' => ['Refused RSVP update'],
                'rsvp'    => ['yes'],
            ],
        ]);
        $this->assertIsString($location);

        $this->assertSame(
            'invalid_request',
            $adapter->updateCallback($location, ['replace' => ['rsvp' => ['probably']]])
        );

        $post = R::findOne('post', ' body LIKE ? ', ['%Refused RSVP update%']);
        $this->assertSame('yes', (string) $post->rsvp);
    }
}

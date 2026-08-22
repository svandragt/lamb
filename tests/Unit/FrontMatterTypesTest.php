<?php

namespace Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\parse_bean;
use function Lamb\Post\matter_string;
use function Lamb\Post\parse_matter;
use function Lamb\Post\populate_bean;

/**
 * Front matter is YAML, so every value carries a type the author chose — a
 * list, a map, a boolean, a date object — while the code reading it assumes a
 * string. These tests pin the coercion that keeps a mistyped line from
 * crashing the save (or the cron run that ingested it).
 */
class FrontMatterTypesTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
    }

    // matter_string()

    public function testMatterStringPassesStringsThrough(): void
    {
        $this->assertSame('Hello', matter_string('Hello'));
        $this->assertSame('', matter_string(''));
    }

    public function testMatterStringRendersNumbers(): void
    {
        $this->assertSame('42', matter_string(42));
        $this->assertSame('1.5', matter_string(1.5));
    }

    public function testMatterStringCollapsesAListToItsFirstEntry(): void
    {
        $this->assertSame('a', matter_string(['a', 'b']));
    }

    public function testMatterStringRejectsAMap(): void
    {
        $this->assertNull(matter_string(['a' => 'b']));
    }

    public function testMatterStringRejectsANestedList(): void
    {
        // One level of unwrapping only — a list of lists has no textual form.
        $this->assertNull(matter_string([['a'], 'b']));
    }

    public function testMatterStringRejectsBooleansAndNull(): void
    {
        // `title: true` is YAML's boolean; neither "1" nor "true" is reliably
        // the text the author typed, so the value is reported as absent.
        $this->assertNull(matter_string(true));
        $this->assertNull(matter_string(false));
        $this->assertNull(matter_string(null));
    }

    public function testMatterStringFormatsDatesAsTheAuthorTypedThem(): void
    {
        $this->assertSame('2024-01-02', matter_string(new DateTimeImmutable('2024-01-02 00:00:00')));
        $this->assertSame(
            '2024-01-02 10:30:00',
            matter_string(new DateTimeImmutable('2024-01-02 10:30:00'))
        );
    }

    // parse_matter() normalises the textual keys

    public function testParseMatterCollapsesAListTitle(): void
    {
        $matter = parse_matter("---\ntitle: [a, b]\n---\nBody");

        $this->assertSame('a', $matter['title']);
        $this->assertSame('a', $matter['slug']);
    }

    public function testParseMatterKeepsADateTitleAsText(): void
    {
        // `title: 2024-01-02` is a YAML date, not a string. Before the coercion
        // this reached slugify() as a DateTimeImmutable and threw a TypeError.
        $matter = parse_matter("---\ntitle: 2024-01-02\n---\nBody");

        $this->assertSame('2024-01-02', $matter['title']);
        $this->assertSame('2024-01-02', $matter['slug']);
    }

    public function testParseMatterDropsAMapTitle(): void
    {
        $matter = parse_matter("---\ntitle:\n  a: b\n---\nBody");

        $this->assertArrayNotHasKey('title', $matter);
        $this->assertArrayNotHasKey('slug', $matter);
    }

    public function testParseMatterKeepsADateSlugAsText(): void
    {
        $matter = parse_matter("---\nslug: 2024-01-02\n---\nBody");

        $this->assertSame('2024-01-02', $matter['slug']);
    }

    public function testParseMatterDropsAnUnusableSlugAndFallsBackToTheTitle(): void
    {
        // A boolean has no textual form, so the slug is derived from the title
        // rather than stored as "" (which is what `(string) false` produced).
        $matter = parse_matter("---\ntitle: My Post\nslug: false\n---\nBody");

        $this->assertSame('my-post', $matter['slug']);
    }

    public function testParseMatterCollapsesAListSyndicatedTo(): void
    {
        $body = "---\nslug: ok\nsyndicated-to:\n  - https://a.example\n  - https://b.example\n---\nBody";

        $this->assertSame('https://a.example', parse_matter($body)['syndicated-to']);
    }

    public function testParseMatterLeavesAListInReplyToUncollapsed(): void
    {
        // Unlike every other MATTER_TEXT_KEYS entry, `in-reply-to` is
        // deliberately excluded from that coercion (#583): a post may record
        // several reply targets, and collapsing here would drop all but the
        // first before Lamb\normalize_in_reply_to() (which keeps every entry
        // via matter_url_list()) ever sees the rest.
        $body = "---\nin-reply-to:\n  - https://a.example\n  - https://b.example\n---\nBody";

        $this->assertSame(['https://a.example', 'https://b.example'], parse_matter($body)['in-reply-to']);
    }

    // The save path must survive every shape

    /**
     * @return array<string, array{0: string}>
     */
    public static function hostileFrontMatterProvider(): array
    {
        return [
            'list title'            => ["---\ntitle: [a, b]\n---\nBody"],
            'map title'             => ["---\ntitle:\n  a: b\n---\nBody"],
            'date title'            => ["---\ntitle: 2024-01-02\n---\nBody"],
            'boolean title'         => ["---\ntitle: true\n---\nBody"],
            'list title with slug'  => ["---\nslug: ok\ntitle: [a, b]\n---\nBody"],
            'date slug'             => ["---\nslug: 2024-01-02\n---\nBody"],
            'list slug'             => ["---\nslug: [a, b]\n---\nBody"],
            'list syndicated-to'    => ["---\nslug: ok\nsyndicated-to: [https://a, https://b]\n---\nBody"],
            'date syndicated-to'    => ["---\nslug: ok\nsyndicated-to: 2024-01-02\n---\nBody"],
            'list created'          => ["---\nslug: ok\ncreated: [a, b]\n---\nBody"],
            'empty created'         => ["---\nslug: ok\ncreated:\n---\nBody"],
            'map description'       => ["---\nslug: ok\ndescription:\n  a: b\n---\nBody"],
            'list in-reply-to'      => ["---\nslug: ok\nin-reply-to: [https://a]\n---\nBody"],
        ];
    }

    /**
     * @dataProvider hostileFrontMatterProvider
     */
    public function testPopulateBeanStoresEveryFrontMatterShape(string $body): void
    {
        $bean = populate_bean($body);
        R::store($bean);

        // RedBean rejects an array or object property outright, so a value that
        // survived un-coerced would abort the save with a RedException.
        $this->assertIsString($bean->title);
        $this->assertIsString($bean->slug);
        $this->assertIsString($bean->syndicated_to);
        $this->assertIsString($bean->in_reply_to);
        $this->assertNotEmpty($bean->created);
    }

    public function testParseBeanKeepsTheCreatedDateWhenTheFrontMatterValueIsEmpty(): void
    {
        // `created:` with no value parses to null. isset() is false for null but
        // the copy loop still saw the key, which blanked the column and left the
        // post with no date at all.
        $bean = R::dispense('post');
        $bean->body = "---\nslug: ok\ncreated:\n---\nBody";
        $bean->created = '2024-01-02 03:04:05';

        parse_bean($bean);

        $this->assertSame('2024-01-02 03:04:05', $bean->created);
    }

    public function testParseBeanTitleFromAListIsItsFirstEntry(): void
    {
        $bean = R::dispense('post');
        $bean->body = "---\nslug: ok\ntitle: [First, Second]\n---\nBody";
        $bean->slug = '';

        parse_bean($bean);

        $this->assertSame('First', $bean->title);
    }
}

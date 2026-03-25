<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Post\get_tag_search_conditions;
use function Lamb\Post\parse_matter;
use function Lamb\Post\posts_by_tag;
use function Lamb\Post\slugify;

class PostTest extends TestCase
{
    // get_tag_search_conditions

    public function testGetTagSearchConditionsReturnsSqlAndParamsKeys()
    {
        $result = get_tag_search_conditions('php');
        $this->assertArrayHasKey('sql', $result);
        $this->assertArrayHasKey('params', $result);
    }

    public function testGetTagSearchConditionsSqlContainsBodyLike()
    {
        $result = get_tag_search_conditions('php');
        $this->assertStringContainsString('body LIKE', $result['sql']);
    }

    public function testGetTagSearchConditionsParamsAllContainTag()
    {
        $result = get_tag_search_conditions('php');
        foreach ($result['params'] as $param) {
            $this->assertStringContainsString('php', $param);
        }
    }

    public function testGetTagSearchConditionsMatchesTagWithTrailingSpace()
    {
        $result = get_tag_search_conditions('php');
        $this->assertContains('%#php %', $result['params']);
    }

    public function testGetTagSearchConditionsMatchesTagAtEndOfString()
    {
        $result = get_tag_search_conditions('php');
        $this->assertContains('%#php', $result['params']);
    }

    public function testGetTagSearchConditionsMatchesNewlineBeforeTag()
    {
        $result = get_tag_search_conditions('php');
        $found = false;
        foreach ($result['params'] as $param) {
            if (str_contains($param, "\n#php")) {
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, 'Expected a param containing newline before #tag');
    }

    // slugify

    public function testSlugifyLowercasesText()
    {
        $this->assertSame('hello-world', slugify('Hello World'));
    }

    public function testSlugifyReplacesSpacesWithHyphens()
    {
        $this->assertSame('foo-bar-baz', slugify('foo bar baz'));
    }

    public function testSlugifyReplacesSpecialCharacters()
    {
        $this->assertSame('hello-world-', slugify('Hello, World!'));
    }

    public function testSlugifyHandlesMultipleConsecutiveNonWordChars()
    {
        $this->assertSame('foo-bar', slugify('foo---bar'));
    }

    public function testSlugifyHandlesAlreadySluggedInput()
    {
        $this->assertSame('already-a-slug', slugify('already-a-slug'));
    }

    public function testSlugifyHandlesEmptyString()
    {
        $this->assertSame('', slugify(''));
    }

    // parse_matter

    public function testParseMatterReturnsEmptyArrayWhenNoFrontMatter()
    {
        $result = parse_matter('Just plain text with no front matter.');
        $this->assertSame([], $result);
    }

    public function testParseMatterExtractsTitleAndDerivesSlug()
    {
        $body = "---\ntitle: My Post Title\n---\n\nContent here.";
        $result = parse_matter($body);
        $this->assertSame('My Post Title', $result['title']);
        $this->assertSame('my-post-title', $result['slug']);
    }

    public function testParseMatterExtractsArbitraryKeys()
    {
        $body = "---\ntitle: Hello\ndescription: A short summary\n---\nContent.";
        $result = parse_matter($body);
        $this->assertSame('A short summary', $result['description']);
    }

    public function testParseMatterReturnsEmptyArrayForInvalidYaml()
    {
        $body = "---\n: this is: invalid yaml\n---\nContent.";
        $result = parse_matter($body);
        $this->assertIsArray($result);
    }

    public function testParseMatterReturnsListWhenFrontMatterIsSequence()
    {
        // YAML sequences (lists) are returned as-is since they are arrays
        $body = "---\n- item1\n- item2\n---\nContent.";
        $result = parse_matter($body);
        $this->assertIsArray($result);
        $this->assertContains('item1', $result);
    }

    public function testParseMatterSlugifiesTitle()
    {
        $body = "---\ntitle: Hello World!\n---\n";
        $result = parse_matter($body);
        $this->assertSame('hello-world-', $result['slug']);
    }

    public function testParseMatterWithNoTitleHasNoSlug()
    {
        $body = "---\nauthor: Someone\n---\nContent.";
        $result = parse_matter($body);
        $this->assertArrayNotHasKey('slug', $result);
        $this->assertSame('Someone', $result['author']);
    }

    public function testParseMatterExtractsDraftTrue()
    {
        $body = "---\ntitle: My Draft\ndraft: true\n---\nContent.";
        $result = parse_matter($body);
        $this->assertTrue((bool)$result['draft']);
    }

    public function testParseMatterExtractsDraftFalse()
    {
        $body = "---\ntitle: My Post\ndraft: false\n---\nContent.";
        $result = parse_matter($body);
        $this->assertFalse((bool)$result['draft']);
    }

    public function testParseMatterHasNoDraftKeyWhenAbsent()
    {
        $body = "---\ntitle: My Post\n---\nContent.";
        $result = parse_matter($body);
        $this->assertArrayNotHasKey('draft', $result);
    }

    public function testParseMatterUsesExplicitSlugOverTitle()
    {
        $body = "---\ntitle: My Post Title\nslug: custom-slug\n---\nContent.";
        $result = parse_matter($body);
        $this->assertSame('custom-slug', $result['slug']);
    }

    // posts_by_tag

    protected function setUpDb(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);

        $schema = R::dispense('post');
        $schema->draft   = null;
        $schema->deleted = null;
        R::store($schema);
        R::exec('DELETE FROM post');
    }

    public function testPostsByTagReturnsMatchingPost(): void
    {
        $this->setUpDb();

        $post = R::dispense('post');
        $post->body    = 'Hello #php world';
        $post->version = 1;
        $post->draft   = null;
        $post->created = date('Y-m-d H:i:s');
        R::store($post);

        $result = posts_by_tag('php');
        $this->assertCount(1, $result);
    }

    public function testPostsByTagDoesNotReturnDraftPosts(): void
    {
        $this->setUpDb();

        $draft = R::dispense('post');
        $draft->body    = 'Hello #php world';
        $draft->version = 1;
        $draft->draft   = 1;
        $draft->created = date('Y-m-d H:i:s');
        R::store($draft);

        $result = posts_by_tag('php');
        $this->assertCount(0, $result);
    }

    public function testPostsByTagReturnsEmptyArrayWhenNoMatch(): void
    {
        $this->setUpDb();

        $result = posts_by_tag('nonexistenttag999');
        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    public function testPostsByTagMatchesTagAtEndOfBody(): void
    {
        $this->setUpDb();

        $post = R::dispense('post');
        $post->body    = 'My post #endtag';
        $post->version = 1;
        $post->draft   = null;
        $post->created = date('Y-m-d H:i:s');
        R::store($post);

        $result = posts_by_tag('endtag');
        $this->assertCount(1, $result);
    }

    public function testPostsByTagReturnsMultipleMatchingPosts(): void
    {
        $this->setUpDb();

        for ($i = 0; $i < 3; $i++) {
            $post = R::dispense('post');
            $post->body    = "Post $i #multitag";
            $post->version = 1;
            $post->draft   = null;
            $post->created = date('Y-m-d H:i:s', time() - $i);
            R::store($post);
        }

        $result = posts_by_tag('multitag');
        $this->assertCount(3, $result);
    }
}

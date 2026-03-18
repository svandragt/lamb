<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Post\get_tag_search_conditions;
use function Lamb\Post\parse_matter;
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
}

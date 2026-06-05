<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Highlight\highlight_code_blocks;
use function Lamb\parse_bean;

class HighlightTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
    }

    public function testHighlightsFencedPhpBlock(): void
    {
        $html = '<pre><code class="language-php">&lt;?php echo 1;</code></pre>';

        $result = highlight_code_blocks($html);

        $this->assertStringContainsString('phiki', $result);
        $this->assertStringContainsString('<span', $result);
    }

    public function testEntitiesAreNotDoubleEscaped(): void
    {
        $html = '<pre><code class="language-php">&lt;?php echo 1 &amp;&amp; 2;</code></pre>';

        $result = highlight_code_blocks($html);

        $this->assertStringContainsString('&lt;', $result);
        $this->assertStringNotContainsString('&amp;lt;', $result);
        $this->assertStringNotContainsString('&amp;amp;', $result);
    }

    public function testUnknownLanguageIsLeftUnchanged(): void
    {
        $html = '<pre><code class="language-nosuchlang">made up</code></pre>';

        $this->assertSame($html, highlight_code_blocks($html));
    }

    public function testCodeBlockWithoutLanguageIsLeftUnchanged(): void
    {
        $html = '<pre><code>plain block</code></pre>';

        $this->assertSame($html, highlight_code_blocks($html));
    }

    public function testHtmlWithoutCodeBlocksIsLeftUnchanged(): void
    {
        $html = '<p>Hello #world</p>';

        $this->assertSame($html, highlight_code_blocks($html));
    }

    public function testParseBeanHighlightsFencedCode(): void
    {
        $bean = R::dispense('post');
        $bean->body = "Some intro\n\n```php\necho 1;\n```";

        parse_bean($bean);

        $this->assertStringContainsString('phiki', $bean->transformed);
    }

    public function testParseBeanStillLinkifiesTagsOutsideCodeBlocks(): void
    {
        $bean = R::dispense('post');
        $bean->body = "Hello #world\n\n```shell\nls\n```";

        parse_bean($bean);

        $this->assertStringContainsString('<a href="/tag/world">#world</a>', $bean->transformed);
    }

    public function testParseBeanDoesNotLinkifyInsideCodeBlocks(): void
    {
        $bean = R::dispense('post');
        $bean->body = "```shell\n#comment\n```";

        parse_bean($bean);

        $this->assertStringNotContainsString('tag/comment', $bean->transformed);
    }

    public function testParseBeanDoesNotCorruptInlineStyleColours(): void
    {
        $bean = R::dispense('post');
        $bean->body = "```php\necho 1;\n```";

        parse_bean($bean);

        // Phiki emits style="color: #rrggbb" attributes; parse_tags must not
        // turn those hex colours into /tag/ links.
        $this->assertMatchesRegularExpression('/style="color: #[0-9a-fA-F]{6}/', $bean->transformed);
        $this->assertDoesNotMatchRegularExpression('/style="[^"]*<a /', $bean->transformed);
    }

    public function testParseBeanWithoutCodeIsUnaffected(): void
    {
        $bean = R::dispense('post');
        $bean->body = "Just a plain post with #tag";

        parse_bean($bean);

        $this->assertStringNotContainsString('phiki', $bean->transformed);
        $this->assertStringContainsString('<a href="/tag/tag">#tag</a>', $bean->transformed);
    }
}

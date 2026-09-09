<?php

namespace Tests\Unit;

use Lamb\LambDown;
use PHPUnit\Framework\TestCase;

class LambDownTest extends TestCase
{
    private LambDown $parser;

    protected function setUp(): void
    {
        $this->parser = new LambDown();
        $this->parser->setSafeMode(true);
    }

    public function testH1WithSpaceRendersAsHeading(): void
    {
        $html = $this->parser->text('# Hello');
        $this->assertStringContainsString('<h1>', $html);
        $this->assertStringContainsString('Hello', $html);
    }

    public function testH2WithSpaceRendersAsHeading(): void
    {
        $html = $this->parser->text('## Hello');
        $this->assertStringContainsString('<h2>', $html);
    }

    public function testHashWithoutSpaceDoesNotRenderAsHeading(): void
    {
        $html = $this->parser->text('#nospace');
        $this->assertStringNotContainsString('<h1>', $html);
    }

    public function testDoubleHashWithoutSpaceDoesNotRenderAsHeading(): void
    {
        $html = $this->parser->text('##nospace');
        $this->assertStringNotContainsString('<h2>', $html);
    }

    public function testHashtagInTextDoesNotRenderAsHeading(): void
    {
        $html = $this->parser->text('#lamb microblog software');
        $this->assertStringNotContainsString('<h1>', $html);
    }

    public function testSafeModeEscapesScriptTags(): void
    {
        $html = $this->parser->text('<script>alert("xss")</script>');
        $this->assertStringNotContainsString('<script>', $html);
    }

    public function testSafeModeEscapesInlineHtml(): void
    {
        $html = $this->parser->text('Hello <b>world</b>');
        $this->assertStringNotContainsString('<b>', $html);
    }

    public function testUncheckedTaskRendersDisabledCheckbox(): void
    {
        $html = $this->parser->text("- [ ] buy milk");
        $this->assertStringContainsString('type="checkbox"', $html);
        $this->assertStringContainsString('disabled', $html);
        $this->assertStringNotContainsString('checked', $html);
        $this->assertStringContainsString('buy milk', $html);
        $this->assertStringNotContainsString('[ ]', $html);
    }

    public function testCheckedTaskRendersCheckedCheckbox(): void
    {
        $html = $this->parser->text("- [x] walk dog");
        $this->assertStringContainsString('checked', $html);
        $this->assertStringContainsString('disabled', $html);
        $this->assertStringContainsString('walk dog', $html);
        $this->assertStringNotContainsString('[x]', $html);
    }

    public function testUppercaseXIsTreatedAsChecked(): void
    {
        $html = $this->parser->text("- [X] done");
        $this->assertStringContainsString('checked', $html);
    }

    public function testTaskItemsGetSequentialIndices(): void
    {
        $html = $this->parser->text("- [ ] a\n- [x] b\n- [ ] c");
        $this->assertStringContainsString('data-checkbox-index="0"', $html);
        $this->assertStringContainsString('data-checkbox-index="1"', $html);
        $this->assertStringContainsString('data-checkbox-index="2"', $html);
    }

    public function testPlainListItemIsNotACheckbox(): void
    {
        $html = $this->parser->text("- normal item");
        $this->assertStringNotContainsString('type="checkbox"', $html);
    }

    public function testTaskListItemGetsClass(): void
    {
        $html = $this->parser->text("- [ ] a");
        $this->assertStringContainsString('task-list-item', $html);
    }

    public function testLooseTaskListRendersCheckbox(): void
    {
        $html = $this->parser->text("- [ ] a\n\n- [x] b\n");
        $this->assertStringContainsString('type="checkbox"', $html);
        $this->assertStringContainsString('data-checkbox-index="0"', $html);
        $this->assertStringContainsString('data-checkbox-index="1"', $html);
    }

    public function testCheckboxLabelMarkdownIsFormatted(): void
    {
        $html = $this->parser->text("- [ ] read **the** docs");
        $this->assertStringContainsString('<strong>the</strong>', $html);
    }

    public function testCheckboxLabelLinkGetsTheSafeModeSchemeAllowlist(): void
    {
        // The label used to be rendered with safe mode switched off, so
        // Parsedown's URL-scheme allowlist never ran on a link inside it —
        // while the same link outside a label was correctly neutralised.
        $inLabel  = $this->parser->text('[ ] [click](javascript:alert(1))');
        $outside  = $this->parser->text('[click](javascript:alert(1))');

        $this->assertStringNotContainsString('href="javascript:', $inLabel);
        $this->assertStringContainsString('javascript%3Aalert', $inLabel);
        // Both spellings of the same link are neutralised the same way.
        $this->assertStringContainsString('javascript%3Aalert', $outside);
    }

    public function testCheckboxLabelImageGetsTheSafeModeSchemeAllowlist(): void
    {
        $html = $this->parser->text('[x] ![a](javascript:alert(2))');

        $this->assertStringNotContainsString('src="javascript:', $html);
        $this->assertStringContainsString('javascript%3Aalert', $html);
    }

    public function testBlockquotedCheckboxLabelIsAlsoFiltered(): void
    {
        // The shape feed ingestion produces: attributed_content() strips HTML
        // tags but leaves Markdown, and prefixes every line with `> `, which
        // still parses as a task item inside the blockquote. That made this
        // remotely triggerable by any subscribed feed.
        $html = $this->parser->text('> [ ] [click](javascript:alert(4))');

        $this->assertStringNotContainsString('href="javascript:', $html);
        $this->assertStringContainsString('javascript%3Aalert', $html);
    }

    public function testCheckboxLabelStillEscapesRawHtml(): void
    {
        $html = $this->parser->text('[ ] <img src=x onerror=alert(3)>');

        $this->assertStringNotContainsString('<img src=x', $html);
        $this->assertStringContainsString('&lt;img src=x onerror=alert(3)&gt;', $html);
    }

    public function testCheckboxLabelLinkUrlIsNotDoubleEscaped(): void
    {
        // The label was escaped by hand and then again as an attribute value,
        // so `?b=1&c=2` was emitted as `?b=1&amp;amp;c=2` — a link pointing at
        // a URL with a literal `&amp;` in it.
        $html = $this->parser->text('[x] [l](https://example.test/a?b=1&c=2)');

        $this->assertStringContainsString('href="https://example.test/a?b=1&amp;c=2"', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
    }

    public function testVideoExtensionRendersVideoTag(): void
    {
        $html = $this->parser->text('![clip](video.mp4)');
        $this->assertStringContainsString('<video', $html);
        $this->assertStringContainsString('controls="controls"', $html);
        $this->assertStringContainsString('src="video.mp4"', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function testVideoTagIsProperlyClosed(): void
    {
        // <video> is not an HTML5 void element; a self-closed "<video ... />"
        // would leave the tag open in a browser and swallow following markup.
        $html = $this->parser->text('![clip](video.mp4)');
        $this->assertStringContainsString('</video>', $html);
        $this->assertStringNotContainsString('/>', $html);
    }

    public function testWebmAndMovExtensionsRenderVideoTag(): void
    {
        $this->assertStringContainsString('<video', $this->parser->text('![clip](video.webm)'));
        $this->assertStringContainsString('<video', $this->parser->text('![clip](video.mov)'));
    }

    public function testNonVideoExtensionStillRendersLazyImage(): void
    {
        $html = $this->parser->text('![photo](photo.png)');
        $this->assertStringContainsString('<img', $html);
        $this->assertStringContainsString('loading="lazy"', $html);
        $this->assertStringNotContainsString('<video', $html);
    }

    public function testVideoSrcFiltersUnsafeUrlScheme(): void
    {
        // Parsedown's safe mode only auto-filters the src scheme for elements
        // named "img"; renaming to "video" must not bypass that protection.
        $html = $this->parser->text('![clip](javascript:evil.mp4)');
        $this->assertStringContainsString('javascript%3Aevil.mp4', $html);
    }

    public function testVideoSrcWithQueryStringStillRendersVideoTag(): void
    {
        $html = $this->parser->text('![clip](https://cdn.example.com/clip.mp4?sig=abc123)');
        $this->assertStringContainsString('<video', $html);
        $this->assertStringContainsString('src="https://cdn.example.com/clip.mp4?sig=abc123"', $html);
    }

    public function testReferenceStyleVideoLinkRendersVideoTag(): void
    {
        $html = $this->parser->text("![clip][1]\n\n[1]: video.mp4");
        $this->assertStringContainsString('<video', $html);
        $this->assertStringContainsString('src="video.mp4"', $html);
    }

    public function testImageGetsIntrinsicDimensionsFromResolver(): void
    {
        $this->parser->setImageSizeResolver(static fn(string $src): array => [1600, 1200]);

        $html = $this->parser->text('![photo](/assets/2026/07/photo.webp)');
        $this->assertStringContainsString('width="1600"', $html);
        $this->assertStringContainsString('height="1200"', $html);
    }

    public function testImageWithoutResolverHasNoDimensions(): void
    {
        $html = $this->parser->text('![photo](/assets/2026/07/photo.webp)');
        $this->assertStringNotContainsString('width=', $html);
        $this->assertStringNotContainsString('height=', $html);
    }

    public function testResolverReturningNullOmitsDimensions(): void
    {
        // An image whose size cannot be determined (remote URL, deleted file)
        // must render exactly as it did before, not with bogus dimensions.
        $this->parser->setImageSizeResolver(static fn(string $src): ?array => null);

        $html = $this->parser->text('![photo](https://example.com/photo.png)');
        $this->assertStringContainsString('<img', $html);
        $this->assertStringNotContainsString('width=', $html);
    }

    public function testResolverReceivesTheImageSource(): void
    {
        $seen = [];
        $this->parser->setImageSizeResolver(static function (string $src) use (&$seen): ?array {
            $seen[] = $src;
            return null;
        });

        $this->parser->text('![photo](/assets/2026/07/photo.webp)');
        $this->assertSame(['/assets/2026/07/photo.webp'], $seen);
    }

    public function testVideoIsNotMeasured(): void
    {
        // getimagesize() cannot measure a video container, and <video> takes a
        // different sizing path — the resolver must not be consulted for it.
        $called = false;
        $this->parser->setImageSizeResolver(static function (string $src) use (&$called): ?array {
            $called = true;
            return [640, 480];
        });

        $html = $this->parser->text('![clip](/assets/2026/07/clip.mp4)');
        $this->assertFalse($called);
        $this->assertStringNotContainsString('width=', $html);
    }

    public function testImageGetsSrcsetFromResolver(): void
    {
        $this->parser->setSrcsetResolver(static fn(string $src): array => [
            ['url' => '/assets/2026/07/photo-800.webp', 'width' => 800],
            ['url' => '/assets/2026/07/photo.webp', 'width' => 1600],
        ]);

        $html = $this->parser->text('![photo](/assets/2026/07/photo.webp)');
        $this->assertStringContainsString(
            'srcset="/assets/2026/07/photo-800.webp 800w, /assets/2026/07/photo.webp 1600w"',
            $html
        );
        $this->assertStringContainsString('sizes="(max-width: 700px) 100vw, 700px"', $html);
    }

    public function testImageWithoutSrcsetResolverHasNoSrcset(): void
    {
        $html = $this->parser->text('![photo](/assets/2026/07/photo.webp)');
        $this->assertStringNotContainsString('srcset=', $html);
        $this->assertStringNotContainsString('sizes=', $html);
    }

    public function testSrcsetResolverReturningNullOmitsSrcset(): void
    {
        // Fewer than two candidates (or an unresolvable src) must render exactly
        // as before, not with a single-source srcset.
        $this->parser->setSrcsetResolver(static fn(string $src): ?array => null);

        $html = $this->parser->text('![photo](/assets/2026/07/photo.webp)');
        $this->assertStringNotContainsString('srcset=', $html);
    }

    public function testVideoIsNotPassedToSrcsetResolver(): void
    {
        $called = false;
        $this->parser->setSrcsetResolver(static function (string $src) use (&$called): ?array {
            $called = true;
            return [
                ['url' => '/a-800.webp', 'width' => 800],
                ['url' => '/a.webp', 'width' => 1600],
            ];
        });

        $html = $this->parser->text('![clip](/assets/2026/07/clip.mp4)');
        $this->assertFalse($called);
        $this->assertStringNotContainsString('srcset=', $html);
    }
}

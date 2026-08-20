<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every bundled theme layout has to include the received-webmentions part.
 *
 * The part decides for itself whether to render anything (author only, status
 * pages only, and only when the post has mentions — see
 * docs/webmentions.md). A layout that never calls it hides them from the
 * author entirely, which is what the default theme did: mentions were
 * received, verified and stored, and then shown nowhere.
 */
class ThemeWebmentionsPartTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function layoutProvider(): array
    {
        // The 2024 theme ships no html.php of its own, so it renders through
        // the base layout (Theme\part() falls back per file).
        return [
            'base' => ['base'],
            '2026' => ['2026'],
        ];
    }

    /**
     * @dataProvider layoutProvider
     */
    public function testLayoutIncludesTheWebmentionsPart(string $theme): void
    {
        $layout = file_get_contents(dirname(__DIR__, 2) . "/src/themes/$theme/html.php");

        $this->assertIsString($layout);
        $this->assertMatchesRegularExpression('/part\(\s*[\'"]_webmentions[\'"]\s*\)/', $layout);
    }
}

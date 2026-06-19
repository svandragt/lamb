<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

class ThemeScheduledPartTest extends TestCase
{
    private function partsDir(): string
    {
        return dirname(__DIR__, 2) . '/src/themes/base/parts';
    }

    public function testScheduledTemplatePartExists(): void
    {
        // html.php renders part($template); when $template is "scheduled" the default
        // theme must provide parts/scheduled.php or part() throws and the page dies.
        $this->assertFileExists(
            $this->partsDir() . '/scheduled.php',
            'The default theme must provide a scheduled template part'
        );
    }

    public function testScheduledPartRendersPostList(): void
    {
        $source = file_get_contents($this->partsDir() . '/scheduled.php');
        $this->assertStringContainsString("part('_items')", $source);
    }

    /**
     * html.php renders the pagination part once for every template, so the
     * admin list parts must NOT render it again — doing so produced two
     * pagination navs on /drafts, /trash and /scheduled.
     *
     * @dataProvider adminListParts
     */
    public function testAdminListPartDoesNotDoublePaginate(string $part): void
    {
        $source = file_get_contents($this->partsDir() . '/' . $part);
        $this->assertStringNotContainsString(
            "part('_pagination')",
            $source,
            "$part must not render _pagination — html.php already does, which double-paginates the page"
        );
    }

    /** @return array<string, array{string}> */
    public static function adminListParts(): array
    {
        return [
            'drafts'    => ['drafts.php'],
            'trash'     => ['trash.php'],
            'scheduled' => ['scheduled.php'],
        ];
    }
}

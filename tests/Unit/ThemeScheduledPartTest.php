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

    public function testScheduledPartRendersPostListAndPagination(): void
    {
        $source = file_get_contents($this->partsDir() . '/scheduled.php');
        $this->assertStringContainsString("part('_items')", $source);
        $this->assertStringContainsString("part('_pagination')", $source);
    }
}

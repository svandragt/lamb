<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Search and tag pages set $data['intro'] ("No results found.") to state the
 * empty case; the post-list renderers used to also print their own "Sorry no
 * items found." fallback, so an empty search showed both. The fallback now
 * only appears when nothing else states the empty case.
 */
class EmptyListMessageTest extends TestCase
{
    protected function tearDown(): void
    {
        global $data, $template;
        $data = [];
        $template = null;
    }

    private function renderPartial(array $dataArr): string
    {
        global $data, $template;
        $data = $dataArr;
        $template = 'search';

        ob_start();
        include dirname(__DIR__, 2) . '/src/themes/base/parts/_items.php';
        return (string) ob_get_clean();
    }

    private function renderThemeList(array $dataArr): string
    {
        global $data, $template;
        $data = $dataArr;
        $template = 'search';

        ob_start();
        \Lamb\Theme\render_post_list(false);
        return (string) ob_get_clean();
    }

    public function testPartialOmitsFallbackWhenIntroStatesEmptiness(): void
    {
        $html = $this->renderPartial(['posts' => [], 'intro' => 'No results found.']);
        $this->assertStringNotContainsString('Sorry no items found.', $html);
    }

    public function testPartialKeepsFallbackWhenNoIntro(): void
    {
        $html = $this->renderPartial(['posts' => []]);
        $this->assertStringContainsString('Sorry no items found.', $html);
    }

    public function testThemeListOmitsFallbackWhenIntroStatesEmptiness(): void
    {
        $html = $this->renderThemeList(['posts' => [], 'intro' => 'No results found.']);
        $this->assertStringNotContainsString('Sorry no items found.', $html);
    }

    public function testThemeListKeepsFallbackWhenNoIntro(): void
    {
        $html = $this->renderThemeList(['posts' => []]);
        $this->assertStringContainsString('Sorry no items found.', $html);
    }
}

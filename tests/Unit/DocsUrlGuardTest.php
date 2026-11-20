<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The docs URL and the maintainer's "Do you run Lamb?" post are written down in
 * more than one place. These tests fail when a copy drifts from the constant.
 */
class DocsUrlGuardTest extends TestCase
{
    private function docsFile(string $name): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/docs/' . $name);
    }

    public function testTheDocsUrlConstantIsWhereJekyllPublishesTheDocs(): void
    {
        $config = $this->docsFile('_config.yml');
        preg_match('/^url:\s*(\S+)/m', $config, $url);
        preg_match('/^baseurl:\s*(\S+)/m', $config, $baseurl);

        $this->assertSame(($url[1] ?? '') . ($baseurl[1] ?? ''), DOCS_URL);
    }

    public function testTheTutorialNamesTheMaintainersPostTheWelcomeDraftLinks(): void
    {
        $this->assertStringContainsString(DO_YOU_RUN_LAMB_URL, $this->docsFile('first-post.md'));
    }
}

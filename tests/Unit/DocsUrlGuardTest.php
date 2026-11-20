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

    public function testOnlyTheConstantSpellsOutWhereTheDocsAre(): void
    {
        $src = dirname(__DIR__, 2) . '/src';
        $spelled = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->getExtension() === 'php' && str_contains((string) file_get_contents($file->getPathname()), DOCS_URL)) {
                $spelled[] = substr($file->getPathname(), strlen($src) + 1);
            }
        }
        sort($spelled);

        $this->assertSame(['constants.php'], $spelled, 'build every other docs link from DOCS_URL');
    }

    public function testTheTutorialNamesTheMaintainersPostTheWelcomeDraftLinks(): void
    {
        $this->assertStringContainsString(DO_YOU_RUN_LAMB_URL, $this->docsFile('first-post.md'));
    }
}

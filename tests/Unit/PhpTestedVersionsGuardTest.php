<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The CI test matrix, the PHPCompatibility testVersion range and the
 * requirements page each state the oldest and newest PHP that Lamb tests.
 * A new PHP added to one and not the others leaves lint or the docs
 * describing a different range from the one CI runs.
 */
class PhpTestedVersionsGuardTest extends TestCase
{
    /**
     * @return void
     */
    public function testCiMatrixPhpcsTestVersionAndDocsNameTheSameRange(): void
    {
        $ci = (string) file_get_contents(codecept_root_dir('.github/workflows/ci.yml'));
        $this->assertSame(1, preg_match("/^\s*php-version: \[([^\]]+)\]/m", $ci, $matrix), 'ci.yml has no php-version matrix');
        preg_match_all("/'(\d+\.\d+)'/", $matrix[1], $versions);
        $tested = $versions[1];
        usort($tested, 'version_compare');
        $range = reset($tested) . '-' . end($tested);

        $phpcs = (string) file_get_contents(codecept_root_dir('phpcs.xml'));
        $this->assertSame(1, preg_match('/<config name="testVersion" value="([^"]+)"/', $phpcs, $testVersion), 'phpcs.xml has no testVersion');
        $this->assertSame($range, $testVersion[1], 'phpcs.xml testVersion differs from the ci.yml test matrix');

        $docs = (string) file_get_contents(codecept_root_dir('docs/index.md'));
        $this->assertSame(1, preg_match('/^- PHP [\d.]+ – (\d+\.\d+)/mu', $docs, $newest), 'docs/index.md has no PHP requirement line');
        $this->assertSame(end($tested), $newest[1], 'docs/index.md names a different newest PHP from the ci.yml test matrix');
    }
}

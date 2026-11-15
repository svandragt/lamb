<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use const Lamb\Config\SETTINGS_FORM_SCALARS;

/**
 * Since 0.16 /settings is a form, so docs/ must name a field's label and tab instead of
 * telling the author to type `key = value` for a setting the form owns. Blocks that show the
 * line format of a multi-entry box (feeds, profile links, menu items) set section entries,
 * not form scalars, and pass.
 */
class DocsIniFormFieldsGuardTest extends TestCase
{
    /**
     * @return void
     */
    public function testIniBlocksDoNotSetFormScalars(): void
    {
        $violations = [];
        foreach (glob(dirname(__DIR__, 2) . '/docs/*.md') as $file) {
            preg_match_all('/^```ini\R(.*?)^```/ms', file_get_contents($file), $blocks);
            foreach ($blocks[1] as $block) {
                foreach (preg_split('/\R/', $block) as $line) {
                    if (preg_match('/^\s*([A-Za-z_]+)\s*=/', $line, $m) && in_array($m[1], SETTINGS_FORM_SCALARS, true)) {
                        $violations[] = 'docs/' . basename($file) . ': ' . $m[1];
                    }
                }
            }
        }

        $this->assertSame([], $violations, 'ini blocks must name the /settings field, not the INI key');
    }
}

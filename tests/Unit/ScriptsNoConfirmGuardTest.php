<?php

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Guards "assume success, communicate failure" against a confirm prompt in the shipped JS.
 *
 * A delete moves the post to the trash, which restores it, so a `confirm()` in
 * front of it only adds a click. The one prompt Lamb keeps is on "Reset to
 * defaults", because a reset has no undo. It is an inline handler in the theme
 * markup (src/themes/base/parts/settings.php), not a file under src/scripts,
 * so this test needs no allow-list. A new prompt in src/scripts fails the build.
 */
class ScriptsNoConfirmGuardTest extends TestCase
{
    public function testNoScriptAsksForConfirmation(): void
    {
        $violations = [];
        foreach ($this->scriptFiles() as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES) as $i => $line) {
                if (preg_match('/\bconfirm\s*\(/', $line)) {
                    $violations[] = sprintf('%s:%d  %s', $file, $i + 1, trim($line));
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "confirm() found in src/scripts — undoable actions must not prompt:\n" . implode("\n", $violations)
        );
    }

    /**
     * Every `.js` file under src/scripts/.
     *
     * @return iterable<string> Absolute file paths.
     *
     * @throws \UnexpectedValueException When src/scripts cannot be read.
     */
    private function scriptFiles(): iterable
    {
        $scripts = dirname(__DIR__, 2) . '/src/scripts';
        $walk = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($scripts, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($walk as $file) {
            if ($file->isFile() && $file->getExtension() === 'js') {
                yield $file->getPathname();
            }
        }
    }
}

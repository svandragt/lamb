<?php

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Codeception builds the data-provided test cases itself and reads only the
 * `@dataProvider` annotation and its own `Codeception\Attribute\DataProvider`.
 * PHPUnit's provider attributes are ignored without a warning: the test runs
 * once with no rows, so it errors on a missing argument or, worse, passes
 * while covering nothing.
 */
class DataProviderAttributeGuardTest extends TestCase
{
    // Also matches a grouped import: `use PHPUnit\Framework\Attributes\{Test, DataProvider};`.
    // shortcut: an aliased namespace (`use PHPUnit\Framework\Attributes as A;`) is not caught.
    private const FORBIDDEN = '/PHPUnit\\\\Framework\\\\Attributes\\\\(\\{[^}]*\\b)?'
        . '(DataProvider|DataProviderExternal|TestWith|TestWithJson)\b/';

    /**
     * @return void
     */
    public function testNoTestUsesPhpunitProviderAttributes(): void
    {
        $violations = [];
        foreach ($this->phpFilesUnderTests() as $file) {
            if ($file === __FILE__) {
                continue;
            }
            foreach (file($file, FILE_IGNORE_NEW_LINES) as $i => $line) {
                if (preg_match(self::FORBIDDEN, $line)) {
                    $violations[] = sprintf('%s:%d  %s', $file, $i + 1, trim($line));
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Codeception ignores PHPUnit's provider attributes; use Codeception\\Attribute\\DataProvider:\n"
                . implode("\n", $violations)
        );
    }

    /**
     * Every `.php` file under tests/, generated support code included.
     *
     * @return iterable<string> Absolute file paths.
     */
    private function phpFilesUnderTests(): iterable
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(codecept_root_dir('tests'), FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }
}

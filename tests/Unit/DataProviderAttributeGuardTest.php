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
            foreach ($this->violatingLines((string) file_get_contents($file)) as $number => $line) {
                $violations[] = sprintf('%s:%d  %s', $file, $number, $line);
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Codeception ignores PHPUnit's provider attributes; use Codeception\\Attribute\\DataProvider:\n"
                . implode("\n", $violations)
        );
    }

    public function testGuardCatchesAGroupedImportSplitOverSeveralLines(): void
    {
        $source = "<?php\nuse PHPUnit\\Framework\\Attributes\\{\n    Test,\n    DataProvider,\n};\n";

        $this->assertSame([2 => 'use PHPUnit\\Framework\\Attributes\\{'], $this->violatingLines($source));
    }

    public function testGuardIgnoresAnUnrelatedAttributeImport(): void
    {
        $source = "<?php\nuse PHPUnit\\Framework\\Attributes\\Test;\n";

        $this->assertSame([], $this->violatingLines($source));
    }

    /**
     * The lines on which a forbidden import starts.
     *
     * @param string $source A PHP source file.
     * @return array<int, string> The trimmed first line of each match, by line number.
     */
    private function violatingLines(string $source): array
    {
        $source_lines = explode("\n", $source);
        $lines        = [];
        preg_match_all(self::FORBIDDEN, $source, $matches, PREG_OFFSET_CAPTURE);
        foreach ($matches[0] as [, $offset]) {
            $number         = substr_count($source, "\n", 0, $offset) + 1;
            $lines[$number] = trim($source_lines[$number - 1]);
        }

        return $lines;
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

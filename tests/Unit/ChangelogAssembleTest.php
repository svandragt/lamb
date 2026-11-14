<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * scripts/changelog-assemble.sh turns the fragments in changelog.d/ into a
 * release section of CHANGELOG.md. It works on the current directory, so each
 * test runs it in a throwaway directory holding a small CHANGELOG.md.
 */
class ChangelogAssembleTest extends TestCase
{
    private const CHANGELOG = <<<'MD'
    # Changelog

    Intro.

    ## [Unreleased]

    Changes for the next release are collected in `changelog.d/`.

    ## [0.2.0] - 2026-01-02

    ### Fixed

    - Old.

    ## [0.1.0] - 2026-01-01

    Initial release!

    [Unreleased]: https://example.test/lamb/compare/0.2.0...HEAD
    [0.2.0]: https://example.test/lamb/compare/0.1.0...0.2.0
    [0.1.0]: https://example.test/lamb/releases/tag/0.1.0

    MD;

    private const MIXED_SECTION = <<<'MD'
    ## [0.3.0] - 2026-02-03

    ### Added

    - Three.
    - Three again.

    ### Changed

    - Four changed.

    ### Deprecated

    - Four deprecated.

    ### Removed

    - Four removed.

    ### Fixed

    - Nine.
      Continued.
    - Nine A.
    - Nine B.
    - Ten.
    - Alpha.
    - Zeta.

    ### Security

    - Five.

    MD;

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/lamb-changelog-test-' . uniqid();
        mkdir($this->dir . '/changelog.d', 0777, true);
        file_put_contents($this->dir . '/CHANGELOG.md', self::CHANGELOG);
        file_put_contents($this->dir . '/changelog.d/README.md', "How to write a fragment.\n");
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->dir]))->run();
    }

    /**
     * @param array<int, string> $args Arguments for the script.
     * @return Process The finished process.
     */
    private function assemble(array $args): Process
    {
        $script = dirname(__DIR__, 2) . '/scripts/changelog-assemble.sh';
        $process = new Process(['bash', $script, ...$args], $this->dir);
        $process->run();

        return $process;
    }

    /**
     * @param string $name Fragment file name, such as 12.fixed.md.
     * @param string $body Exact file content.
     * @return void
     */
    private function fragment(string $name, string $body): void
    {
        file_put_contents($this->dir . '/changelog.d/' . $name, $body);
    }

    /**
     * @return array<int, string> Sorted file names in changelog.d/.
     */
    private function fragmentFiles(): array
    {
        $files = array_values(array_diff(scandir($this->dir . '/changelog.d'), ['.', '..']));
        sort($files);

        return $files;
    }

    private function writeMixedFragments(): void
    {
        $this->fragment('10.fixed.md', "- Ten.\n");
        $this->fragment('9.fixed.md', "- Nine.\n  Continued.\n");
        $this->fragment('9-b.fixed.md', "- Nine B.\n");
        $this->fragment('9-a.fixed.md', "- Nine A.\n");
        $this->fragment('zeta.fixed.md', '- Zeta.');
        $this->fragment('alpha.fixed.md', "\n- Alpha.\n\n\n");
        $this->fragment('3.added.md', "- Three.\n- Three again.\n");
        $this->fragment('4.changed.md', "- Four changed.\n");
        $this->fragment('4.deprecated.md', "- Four deprecated.\n");
        $this->fragment('4.removed.md', "- Four removed.\n");
        $this->fragment('5.security.md', "- Five.\n");
    }

    public function testAssemblesSectionsAndBulletsInOrderAndUpdatesCompareLinks(): void
    {
        $this->writeMixedFragments();

        $process = $this->assemble(['0.3.0', '2026-02-03']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $expected = str_replace(
            "## [0.2.0] - 2026-01-02",
            self::MIXED_SECTION . "\n## [0.2.0] - 2026-01-02",
            self::CHANGELOG
        );
        $expected = str_replace(
            "[Unreleased]: https://example.test/lamb/compare/0.2.0...HEAD\n",
            "[Unreleased]: https://example.test/lamb/compare/0.3.0...HEAD\n"
            . "[0.3.0]: https://example.test/lamb/compare/0.2.0...0.3.0\n",
            $expected
        );
        $this->assertSame($expected, file_get_contents($this->dir . '/CHANGELOG.md'));
    }

    public function testDeletesAssembledFragmentsButKeepsTheReadme(): void
    {
        $this->writeMixedFragments();

        $this->assemble(['0.3.0', '2026-02-03']);

        $this->assertSame(['README.md'], $this->fragmentFiles());
    }

    public function testDryRunPrintsTheSectionAndChangesNothing(): void
    {
        $this->writeMixedFragments();
        $before = $this->fragmentFiles();

        $process = $this->assemble(['--dry-run', '0.3.0', '2026-02-03']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame(rtrim(self::MIXED_SECTION) . "\n", $process->getOutput());
        $this->assertSame(self::CHANGELOG, file_get_contents($this->dir . '/CHANGELOG.md'));
        $this->assertSame($before, $this->fragmentFiles());
    }

    public function testDateDefaultsToToday(): void
    {
        $this->fragment('1.fixed.md', "- One.\n");

        $process = $this->assemble(['0.3.0', '--dry-run']);

        $this->assertMatchesRegularExpression('/^## \[0\.3\.0\] - \d{4}-\d{2}-\d{2}\n/', $process->getOutput());
    }

    public function testRemovesTrackedFragmentsWithGitRm(): void
    {
        $this->fragment('1.fixed.md', "- One.\n");
        (new Process(['git', 'init', '-q'], $this->dir))->mustRun();
        (new Process(['git', 'add', '.'], $this->dir))->mustRun();
        (new Process(['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.test', 'commit', '-qm', 'init'], $this->dir))->mustRun();

        $process = $this->assemble(['0.3.0', '2026-02-03']);

        $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $status = new Process(['git', 'status', '--porcelain', 'changelog.d'], $this->dir);
        $status->mustRun();
        $this->assertStringContainsString('D  changelog.d/1.fixed.md', $status->getOutput());
    }

    /**
     * @return array<string, array{0: array<string, string>, 1: array<int, string>, 2: string}>
     */
    public static function failureProvider(): array
    {
        return [
            'unknown type' => [['1.bogus.md' => "- One.\n"], ['0.3.0'], 'unknown type "bogus"'],
            'missing type' => [['notes.md' => "- One.\n"], ['0.3.0'], '<name>.<type>.md'],
            'empty fragment' => [['1.fixed.md' => ''], ['0.3.0'], 'empty'],
            'blank fragment' => [['1.fixed.md' => "\n  \n"], ['0.3.0'], 'empty'],
            'line outside a bullet' => [['1.fixed.md' => "- One.\nplain line\n"], ['0.3.0'], '1.fixed.md:2'],
            'prose before the first bullet' => [['1.fixed.md' => "Intro.\n- One.\n"], ['0.3.0'], '1.fixed.md:1'],
            'no fragments' => [[], ['0.3.0'], 'no fragments'],
            'version already released' => [['1.fixed.md' => "- One.\n"], ['0.2.0'], 'already'],
            'not a version' => [['1.fixed.md' => "- One.\n"], ['v3'], 'version'],
            'not a date' => [['1.fixed.md' => "- One.\n"], ['0.3.0', 'yesterday'], 'date'],
            'unknown option' => [['1.fixed.md' => "- One.\n"], ['0.3.0', '--force'], 'Usage'],
        ];
    }

    /**
     * @dataProvider failureProvider
     * @param array<string, string> $fragments File name => content.
     * @param array<int, string> $args Script arguments.
     * @param string $message Text the error output must contain.
     * @return void
     */
    public function testFailsClearlyAndChangesNothing(array $fragments, array $args, string $message): void
    {
        foreach ($fragments as $name => $body) {
            $this->fragment($name, $body);
        }
        $before = $this->fragmentFiles();

        $process = $this->assemble($args);

        $this->assertSame(1, $process->getExitCode(), $process->getOutput());
        $this->assertStringContainsString($message, $process->getErrorOutput());
        $this->assertSame(self::CHANGELOG, file_get_contents($this->dir . '/CHANGELOG.md'));
        $this->assertSame($before, $this->fragmentFiles());
    }

    public function testRefusesHandWrittenBulletsLeftUnderUnreleased(): void
    {
        $this->fragment('1.fixed.md', "- One.\n");
        $changelog = str_replace('collected in `changelog.d/`.', "collected in `changelog.d/`.\n\n- Stray.", self::CHANGELOG);
        file_put_contents($this->dir . '/CHANGELOG.md', $changelog);

        $process = $this->assemble(['0.3.0']);

        $this->assertSame(1, $process->getExitCode());
        $this->assertStringContainsString('Unreleased', $process->getErrorOutput());
        $this->assertSame($changelog, file_get_contents($this->dir . '/CHANGELOG.md'));
        $this->assertSame(['1.fixed.md', 'README.md'], $this->fragmentFiles());
    }
}

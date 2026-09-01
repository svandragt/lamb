<?php

namespace Tests\Unit;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Guards the canonical is_draft()/is_deleted() predicates against drift.
 *
 * A post's draft and deleted state each have one source of truth: is_draft()
 * and is_deleted() in lamb.php, both `== 1`. Inlining a raw truthiness test on
 * the column instead — `!empty($bean->draft)`, `if (!$post->deleted)`, a bare
 * `$post->deleted ?` ternary — is the "recomputed state" drift the scheduled
 * bug scan kept re-finding and fixing one call site at a time (#749, #751,
 * #779, #788). `!empty()`/truthiness treats any non-zero value as set, while
 * the predicates only accept exactly 1, so the two disagree the moment a
 * non-canonical value reaches the column. This fails the build the moment a
 * new raw test appears, so the fix is made once at the keyboard rather than
 * later in a review round.
 *
 * Writes (`$bean->draft = 1`), the predicate definitions themselves
 * (`?? null) == 1`), and value serialisation (`(bool) $bean->deleted` in the
 * exporter) are not decisions, so the pattern deliberately leaves them alone.
 */
class DraftDeletedPredicateGuardTest extends TestCase
{
    // Matches the three drift idioms — empty()/!empty() on the column, a `!`
    // truthiness test, and a bare ternary condition — while the negative
    // lookahead skips the predicates' own `?? null`.
    private const FORBIDDEN = '/(empty\(\s*[$A-Za-z_>\[\]\'-]*->(draft|deleted)\b'
        . '|!\s*[$A-Za-z_>\[\]\'-]*->(draft|deleted)\b'
        . '|->(draft|deleted)\s*\?(?!\?))/';

    public function testNoRawDraftOrDeletedTruthinessTestsOutsideThePredicates(): void
    {
        $violations = [];
        foreach ($this->phpFilesUnderSrc() as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES) as $i => $line) {
                if (preg_match(self::FORBIDDEN, $line)) {
                    $violations[] = sprintf('%s:%d  %s', $file, $i + 1, trim($line));
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            "Raw draft/deleted truthiness test found — call is_draft()/is_deleted() instead:\n"
                . implode("\n", $violations)
        );
    }

    /**
     * Every `.php` file under src/, themes included.
     *
     * @return iterable<string> Absolute file paths.
     */
    private function phpFilesUnderSrc(): iterable
    {
        $src = dirname(__DIR__, 2) . '/src';
        $walk = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($walk as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }
}

<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Keeps every function in src/micropub.php short enough to read at a glance.
 *
 * Micropub had the most fix commits of any feature in the six months before #942,
 * and its two request handlers were the longest functions in src/. A handler
 * that does everything in one body hides which step a fix belongs to, so this
 * fails the build when a function, method or closure in that file grows past
 * the limit. Split the steps into named functions instead of raising it.
 *
 * Length counts from the line holding `function` to the closing brace. The file
 * is read as tokens, not through reflection, so methods of an anonymous class
 * count too.
 */
class MicropubFunctionLengthGuardTest extends TestCase
{
    private const MAX_LINES = 60;

    public function testNoFunctionInMicropubPhpIsLongerThanTheLimit(): void
    {
        $tooLong = [];
        foreach ($this->functionLengths(__DIR__ . '/../../src/micropub.php') as $declaration => $lines) {
            if ($lines > self::MAX_LINES) {
                $tooLong[] = sprintf('%s (%d lines)', $declaration, $lines);
            }
        }

        $this->assertSame(
            [],
            $tooLong,
            sprintf("src/micropub.php functions longer than %d lines:\n", self::MAX_LINES) . implode("\n", $tooLong)
        );
    }

    /**
     * The length in lines of every function, method and closure a file declares.
     *
     * @param string $file The PHP file to read.
     * @return array<string, int> Line count by "name at line N".
     */
    private function functionLengths(string $file): array
    {
        $tokens = token_get_all((string) file_get_contents($file));
        $lengths = [];
        $line = 1;
        $previous = null;

        foreach ($tokens as $index => $token) {
            $isFunction = is_array($token) && $token[0] === T_FUNCTION && $previous !== T_USE;
            if ($isFunction) {
                $length = $this->declarationLength($tokens, $index, $line);
                if ($length !== null) {
                    $lengths[sprintf('%s at line %d', $this->declaredName($tokens, $index), $line)] = $length;
                }
            }

            $text = is_array($token) ? $token[1] : $token;
            $line += substr_count($text, "\n");
            if (!is_array($token) || !in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                $previous = is_array($token) ? $token[0] : $token;
            }
        }

        return $lengths;
    }

    /**
     * The lines from a `function` keyword to the brace that closes its body.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens The file's tokens.
     * @param int $start The index of the `function` token.
     * @param int $startLine The line of the `function` token.
     * @return int|null Null for a declaration with no body, such as an interface method.
     */
    private function declarationLength(array $tokens, int $start, int $startLine): ?int
    {
        $line = $startLine;
        $depth = 0;
        $inBody = false;

        for ($i = $start, $count = count($tokens); $i < $count; $i++) {
            $token = $tokens[$i];
            $type = is_array($token) ? $token[0] : $token;

            if ($type === ';' && !$inBody) {
                return null;
            }
            if ($type === '{' || $type === T_CURLY_OPEN || $type === T_DOLLAR_OPEN_CURLY_BRACES) {
                $inBody = true;
                $depth++;
            } elseif ($type === '}' && $inBody && --$depth === 0) {
                return $line - $startLine + 1;
            }

            $line += substr_count(is_array($token) ? $token[1] : $token, "\n");
        }

        return null;
    }

    /**
     * The name a `function` keyword declares, or "{closure}" when it has none.
     *
     * @param list<array{0: int, 1: string, 2: int}|string> $tokens The file's tokens.
     * @param int $index The index of the `function` token.
     * @return string
     */
    private function declaredName(array $tokens, int $index): string
    {
        for ($i = $index + 1; $i < count($tokens); $i++) {
            $token = $tokens[$i];
            if (is_array($token) && $token[0] === T_STRING) {
                return $token[1];
            }
            if ($token === '(') {
                return '{closure}';
            }
        }

        return '{unknown}';
    }
}

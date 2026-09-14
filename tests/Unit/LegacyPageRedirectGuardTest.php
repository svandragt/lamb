<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The legacy ?page=N redirect in src/index.php gates its parse_str() call
 * behind a cheap str_contains() check, so every GET/HEAD request no longer
 * pays for a full query-string parse just to look for one key.
 *
 * This guard must never be under-inclusive: any query string that would
 * cause parse_str() to populate a 'page' key must also make the guard
 * return true. It may be over-inclusive (parsing unnecessarily is only a
 * cost, never a bug), as shown by the 'otherpage'/'page_size' cases.
 *
 * The '%' arm exists because parse_str() urldecodes keys: '%70age=2' and
 * 'pa%67e=5' both yield a 'page' key that a plain substring test misses.
 * A missed redirect is not a cosmetic loss — the querystring URL keeps
 * serving the same posts as /page/N, with neither marked canonical.
 *
 * The guard below mirrors the expression in src/index.php by hand, because
 * that file is a procedural entry point with no function to call. Change one
 * and you must change the other.
 */
class LegacyPageRedirectGuardTest extends TestCase
{
    /**
     * Mirrors the guard expression in src/index.php.
     *
     * @param string $query_string
     * @return bool
     */
    private function guard(string $query_string): bool
    {
        return str_contains($query_string, 'page') || str_contains($query_string, '%');
    }

    /**
     * @return bool
     */
    private function parseStrSetsPage(string $query_string): bool
    {
        parse_str($query_string, $params);
        return isset($params['page']);
    }

    public static function queryStringProvider(): array
    {
        return [
            'plain page param' => ['page=2', true],
            'page param with siblings' => ['a=1&page=3', true],
            'no page key at all' => ['foo=bar', false],
            'empty query string' => ['', false],
            'unrelated key containing "page" as substring' => ['otherpage=1', false],
            'unrelated key prefixed with "page"' => ['page_size=2', false],
            'percent-encoded first letter' => ['%70age=2', true],
            'percent-encoded middle letter' => ['pa%67e=5', true],
            'percent-encoded page among siblings' => ['a=1&%70age=3', true],
            'percent-encoding on an unrelated key' => ['%66oo=bar', false],
        ];
    }

    /**
     * @dataProvider queryStringProvider
     * @param string $query_string
     * @param bool $expected_page_key_present
     * @return void
     */
    public function testGuardIsNeverUnderInclusive(string $query_string, bool $expected_page_key_present): void
    {
        $this->assertSame(
            $expected_page_key_present,
            $this->parseStrSetsPage($query_string),
            'Test fixture is wrong about what parse_str() would do.'
        );

        if ($expected_page_key_present) {
            $this->assertTrue(
                $this->guard($query_string),
                "Guard must let '$query_string' through to parse_str() since it carries a 'page' key."
            );
        }
    }

    public function testGuardSkipsQueryStringsWithNoPageSubstring(): void
    {
        $this->assertFalse($this->guard(''));
        $this->assertFalse($this->guard('foo=bar'));
    }

    public function testGuardIsOverInclusiveForNearMisses(): void
    {
        // These contain 'page' as a substring but not as an actual key, so
        // parse_str() would run unnecessarily -- allowed, since that's only
        // a cost, never a behaviour change.
        $this->assertTrue($this->guard('otherpage=1'));
        $this->assertTrue($this->guard('page_size=2'));
    }
}

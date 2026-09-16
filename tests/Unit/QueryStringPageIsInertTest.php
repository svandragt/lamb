<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * src/index.php resolves the paginated page number from the `/page/N` path
 * segment only. A query-string `page=` is never a canonical source (that
 * redirect was removed, see #813), so PHP populating $_GET['page'] from the
 * query string by itself must not leave a stale value for
 * Response\paginate_posts() to read.
 *
 * index.php is a procedural entry point with no function to call, so this
 * mirrors its `$_GET['page']` handling by hand, same as the guard test this
 * replaces. Change one and you must change the other.
 */
class QueryStringPageIsInertTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_GET['page']);
    }

    /**
     * Mirrors the `$_GET['page']` handling in src/index.php.
     *
     * @param int|null $page_from_path The page number extracted from a
     *                                  `/page/N` path segment, or null.
     * @return void
     */
    private function resolvePageFromRequest(?int $page_from_path): void
    {
        unset($_GET['page']);
        if ($page_from_path !== null) {
            $_GET['page'] = $page_from_path;
        }
    }

    public function testQueryStringPageDoesNotSurviveWhenPathHasNoPageSegment(): void
    {
        // Simulates PHP itself populating $_GET['page'] from `?page=2`,
        // on a request whose path carries no `/page/N` segment.
        $_GET['page'] = '2';

        $this->resolvePageFromRequest(null);

        $this->assertArrayNotHasKey(
            'page',
            $_GET,
            'A query-string page must never survive to serve paginated content at a second URL.'
        );
    }

    public function testPathPageSegmentStillSetsPage(): void
    {
        $_GET['page'] = '5';

        $this->resolvePageFromRequest(2);

        $this->assertSame(2, $_GET['page']);
    }
}

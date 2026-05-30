<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Bootstrap\http_date;
use function Lamb\Bootstrap\content_etag;
use function Lamb\Bootstrap\client_has_current_version;

/**
 * Conditional GET (ETag / Last-Modified / 304) for cacheable responses.
 *
 * Anonymous pages and feeds carry a validator derived from the most recently
 * updated post so a client (or CDN) can revalidate cheaply and get a 304
 * instead of a full re-render once max-age expires.
 */
class ConditionalRequestTest extends TestCase
{
    public function testHttpDateFormatsAsRfc7231GmtString(): void
    {
        $this->assertSame('Thu, 01 Jan 1970 00:00:00 GMT', http_date(0));
        $this->assertSame('Fri, 13 Feb 2009 23:31:30 GMT', http_date(1234567890));
    }

    public function testContentEtagIsQuotedAndDeterministic(): void
    {
        $etag = content_etag(1234567890);
        $this->assertSame('"', $etag[0]);
        $this->assertSame('"', $etag[strlen($etag) - 1]);
        $this->assertSame($etag, content_etag(1234567890));
    }

    public function testContentEtagDiffersWhenTimestampChanges(): void
    {
        $this->assertNotSame(content_etag(1000), content_etag(1001));
    }

    public function testNoConditionalHeadersIsNotAMatch(): void
    {
        $ts = 1234567890;
        $this->assertFalse(client_has_current_version([], content_etag($ts), $ts));
    }

    public function testMatchingIfNoneMatchIsCurrent(): void
    {
        $ts = 1234567890;
        $etag = content_etag($ts);
        $this->assertTrue(client_has_current_version(['HTTP_IF_NONE_MATCH' => $etag], $etag, $ts));
    }

    public function testNonMatchingIfNoneMatchIsNotCurrent(): void
    {
        $ts = 1234567890;
        $etag = content_etag($ts);
        $this->assertFalse(client_has_current_version(['HTTP_IF_NONE_MATCH' => '"stale"'], $etag, $ts));
    }

    public function testIfModifiedSinceAtOrAfterLastModifiedIsCurrent(): void
    {
        $ts = 1234567890;
        $etag = content_etag($ts);
        $server = ['HTTP_IF_MODIFIED_SINCE' => http_date($ts)];
        $this->assertTrue(client_has_current_version($server, $etag, $ts));

        $serverLater = ['HTTP_IF_MODIFIED_SINCE' => http_date($ts + 60)];
        $this->assertTrue(client_has_current_version($serverLater, $etag, $ts));
    }

    public function testIfModifiedSinceBeforeLastModifiedIsNotCurrent(): void
    {
        $ts = 1234567890;
        $etag = content_etag($ts);
        $server = ['HTTP_IF_MODIFIED_SINCE' => http_date($ts - 60)];
        $this->assertFalse(client_has_current_version($server, $etag, $ts));
    }
}

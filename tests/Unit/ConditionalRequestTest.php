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
        $etag = content_etag(1234567890, 100);
        $this->assertSame('"', $etag[0]);
        $this->assertSame('"', $etag[strlen($etag) - 1]);
        $this->assertSame($etag, content_etag(1234567890, 100));
    }

    public function testContentEtagDiffersWhenContentTimestampChanges(): void
    {
        $this->assertNotSame(content_etag(1000, 1), content_etag(1001, 1));
    }

    public function testContentEtagDiffersWhenConfigChangesWithinSameSecond(): void
    {
        // Same last-modified (content) timestamp, different config edit time: the
        // validator must still change so a settings edit invalidates caches even
        // when it lands in the same second as the latest post (issue #279).
        $this->assertNotSame(content_etag(1000, 500), content_etag(1000, 1000));
    }

    public function testNoConditionalHeadersIsNotAMatch(): void
    {
        $ts = 1234567890;
        $this->assertFalse(client_has_current_version([], content_etag($ts, 0), $ts));
    }

    public function testMatchingIfNoneMatchIsCurrent(): void
    {
        $ts = 1234567890;
        $etag = content_etag($ts, 0);
        $this->assertTrue(client_has_current_version(['HTTP_IF_NONE_MATCH' => $etag], $etag, $ts));
    }

    public function testNonMatchingIfNoneMatchIsNotCurrent(): void
    {
        $ts = 1234567890;
        $etag = content_etag($ts, 0);
        $this->assertFalse(client_has_current_version(['HTTP_IF_NONE_MATCH' => '"stale"'], $etag, $ts));
    }

    public function testIfModifiedSinceAtOrAfterLastModifiedIsCurrent(): void
    {
        $ts = 1234567890;
        $etag = content_etag($ts, 0);
        $server = ['HTTP_IF_MODIFIED_SINCE' => http_date($ts)];
        $this->assertTrue(client_has_current_version($server, $etag, $ts));

        $serverLater = ['HTTP_IF_MODIFIED_SINCE' => http_date($ts + 60)];
        $this->assertTrue(client_has_current_version($serverLater, $etag, $ts));
    }

    public function testIfModifiedSinceBeforeLastModifiedIsNotCurrent(): void
    {
        $ts = 1234567890;
        $etag = content_etag($ts, 0);
        $server = ['HTTP_IF_MODIFIED_SINCE' => http_date($ts - 60)];
        $this->assertFalse(client_has_current_version($server, $etag, $ts));
    }

    /**
     * RFC 9110 §13.1.3: If-Modified-Since is ignored when If-None-Match is
     * present. A browser revalidating sends both, and
     * latest_content_timestamp() is the newest `updated` among published posts
     * — so trashing the newest post moves it backwards, and the date test then
     * passed for a client holding the pre-deletion page. Checking it after a
     * non-matching ETag therefore answered 304 and left the deleted post in
     * that cache.
     */
    public function testANonMatchingEtagWinsOverAStillCurrentDate(): void
    {
        $ts = 1234567890;
        // What the client cached, then what the server holds after the newest
        // post was trashed: an earlier timestamp, so a different ETag.
        $cachedTs = $ts;
        $nowTs    = $ts - 86400;
        $server = [
            'HTTP_IF_NONE_MATCH'     => content_etag($cachedTs, 0),
            'HTTP_IF_MODIFIED_SINCE' => http_date($cachedTs),
        ];

        $this->assertFalse(client_has_current_version($server, content_etag($nowTs, 0), $nowTs));
    }

    public function testAMatchingEtagIsStillCurrentWhenADateIsAlsoSent(): void
    {
        // The other half of the precedence rule: sending both headers must not
        // stop an unchanged response from being a 304.
        $ts = 1234567890;
        $etag = content_etag($ts, 0);
        $server = [
            'HTTP_IF_NONE_MATCH'     => $etag,
            'HTTP_IF_MODIFIED_SINCE' => http_date($ts - 86400),
        ];

        $this->assertTrue(client_has_current_version($server, $etag, $ts));
    }
}

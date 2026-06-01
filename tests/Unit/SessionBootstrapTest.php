<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Bootstrap\should_start_session;
use function Lamb\Bootstrap\cache_headers;

/**
 * Sessions for previously logged-in users only (issue #116).
 *
 * Anonymous visitors must not get a session started: that forces a Set-Cookie
 * and PHP's no-cache headers, which makes every public page uncacheable.
 * A session is only started when the request carries evidence of a logged-in
 * user (the lamb_logged_in marker cookie, or an existing LAMBSESSID).
 */
class SessionBootstrapTest extends TestCase
{
    public function testAnonymousVisitorWithNoCookiesDoesNotStartSession(): void
    {
        $this->assertFalse(should_start_session([]));
    }

    public function testUnrelatedCookiesDoNotStartSession(): void
    {
        $this->assertFalse(should_start_session(['theme' => 'dark', 'consent' => '1']));
    }

    public function testLoginMarkerCookieStartsSession(): void
    {
        $this->assertTrue(should_start_session(['lamb_logged_in' => 'abc123']));
    }

    public function testExistingSessionCookieStartsSession(): void
    {
        $this->assertTrue(should_start_session(['LAMBSESSID' => 'deadbeef']));
    }

    public function testAnonymousPagesAreCacheable(): void
    {
        $headers = cache_headers(false);
        $joined = implode("\n", $headers);
        $this->assertStringContainsString('max-age=300', $joined);
        $this->assertStringNotContainsString('no-store', $joined);
    }

    public function testLoggedInPagesArePrivateAndNotCacheable(): void
    {
        $headers = cache_headers(true);
        $joined = implode("\n", $headers);
        $this->assertStringContainsString('private', $joined);
        $this->assertStringContainsString('no-store', $joined);
    }

    public function testResponsesVaryByCookieSoSharedCachesSeparateAnonymousFromLoggedIn(): void
    {
        $this->assertStringContainsString('Vary: Cookie', implode("\n", cache_headers(false)));
        $this->assertStringContainsString('Vary: Cookie', implode("\n", cache_headers(true)));
    }
}

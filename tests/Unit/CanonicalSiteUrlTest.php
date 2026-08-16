<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Config\canonical_site_url;
use function Lamb\Http\request_root_url;

/**
 * The site's own address has two sources: the canonical URL the author configures,
 * and the Host header the client sends. Only the first is trustworthy, so these
 * tests pin the boundary between them.
 */
class CanonicalSiteUrlTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('LAMB_SITE_URL');
    }

    public function testReturnsNullWhenNotConfigured(): void
    {
        $this->assertNull(canonical_site_url([]));
        $this->assertNull(canonical_site_url(['site_url' => '']));
        $this->assertNull(canonical_site_url(['site_url' => '   ']));
    }

    public function testNormalisesConfiguredValue(): void
    {
        $this->assertSame('https://example.com', canonical_site_url(['site_url' => 'https://example.com/']));
        $this->assertSame('https://example.com', canonical_site_url(['site_url' => 'https://EXAMPLE.com']));
    }

    // Subdirectory installs (issue #580). The author's IndieAuth identity is the
    // whole `https://example.com/blog`, so the base path is part of the canonical
    // value rather than something to discard.

    public function testKeepsASubdirectoryBasePath(): void
    {
        $this->assertSame('https://example.com/blog', canonical_site_url(['site_url' => 'https://example.com/blog']));
        $this->assertSame(
            'http://example.com:8747/blog',
            canonical_site_url(['site_url' => 'http://example.com:8747/blog'])
        );
        $this->assertSame('https://example.com/a/b', canonical_site_url(['site_url' => 'https://example.com/a/b']));
    }

    public function testBasePathIsCaseSensitiveWhileTheHostIsNot(): void
    {
        // Only the host is case-insensitive; a path segment is not, so folding it
        // would invent an identity the author never configured.
        $this->assertSame('https://example.com/Blog', canonical_site_url(['site_url' => 'https://EXAMPLE.com/Blog']));
    }

    public function testReducesEverySpellingOfABaseToOneForm(): void
    {
        // This value is compared against a token's `me`. Two spellings of the same
        // base must not produce two different strings, or the comparison turns on
        // how the author happened to type the setting.
        $spellings = [
            'https://example.com/blog/',
            'https://example.com//blog',
            'https://example.com/blog//',
            'https://example.com/blog?utm=1',
            'https://example.com/blog#top',
        ];

        foreach ($spellings as $spelling) {
            $this->assertSame('https://example.com/blog', canonical_site_url(['site_url' => $spelling]), $spelling);
        }
    }

    public function testRejectsBasePathsThatCannotBeComparedSafely(): void
    {
        // Refused rather than resolved or decoded: if neither `..` nor its
        // percent-encoded spelling is allowed through, the two cannot disagree.
        $this->assertNull(canonical_site_url(['site_url' => 'https://example.com/blog/../admin']));
        $this->assertNull(canonical_site_url(['site_url' => 'https://example.com/blog/%2e%2e/admin']));
        $this->assertNull(canonical_site_url(['site_url' => 'https://example.com/blog%2fadmin']));
        $this->assertNull(canonical_site_url(['site_url' => 'https://example.com/blog x']));
        $this->assertNull(canonical_site_url(['site_url' => 'https://example.com/./blog']));
    }

    public function testRejectsValuesThatAreNotAbsoluteHttpUrls(): void
    {
        $this->assertNull(canonical_site_url(['site_url' => 'example.com']));
        $this->assertNull(canonical_site_url(['site_url' => '/example']));
        $this->assertNull(canonical_site_url(['site_url' => 'javascript:alert(1)']));
        $this->assertNull(canonical_site_url(['site_url' => 'file:///etc/passwd']));
    }

    public function testEnvironmentOverridesConfiguredValue(): void
    {
        putenv('LAMB_SITE_URL=https://from-env.example');

        $this->assertSame('https://from-env.example', canonical_site_url(['site_url' => 'https://from-ini.example']));
    }

    public function testRequestRootUrlUsesSchemeAndHost(): void
    {
        $this->assertSame('http://example.com', request_root_url(['HTTP_HOST' => 'example.com']));
        $this->assertSame(
            'https://example.com',
            request_root_url(['HTTP_HOST' => 'example.com', 'HTTPS' => 'on'])
        );
        $this->assertSame('http://example.com:8747', request_root_url(['HTTP_HOST' => 'example.com:8747']));
        $this->assertSame('http://[::1]:8080', request_root_url(['HTTP_HOST' => '[::1]:8080']));
    }

    public function testRequestRootUrlRejectsMalformedHosts(): void
    {
        $this->assertNull(request_root_url([]));
        $this->assertNull(request_root_url(['HTTP_HOST' => '']));
        $this->assertNull(request_root_url(['HTTP_HOST' => 'example.com/path']));
        $this->assertNull(request_root_url(['HTTP_HOST' => 'example.com" onload="x']));
        $this->assertNull(request_root_url(['HTTP_HOST' => "example.com\r\nX-Injected: 1"]));
        $this->assertNull(request_root_url(['HTTP_HOST' => 'example.com ']));
        $this->assertNull(request_root_url(['HTTP_HOST' => str_repeat('a', 254)]));
    }
}

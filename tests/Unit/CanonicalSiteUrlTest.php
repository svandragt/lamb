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
        $this->assertSame('http://example.com:8747', canonical_site_url(['site_url' => 'http://example.com:8747/blog']));
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

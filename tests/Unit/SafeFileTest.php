<?php

namespace Tests\Unit;

use Lamb\Network\SafeFile;
use PHPUnit\Framework\TestCase;

class SafeFileTest extends TestCase
{
    // SafeFile is SimplePie's remote-fetch class, hardened against SSRF: a
    // feed URL is admin-configured, but a compromised/malicious feed host
    // could still redirect the cron's fetch to an internal address. These
    // tests only cover the "must not even attempt the request" cases (literal
    // private/loopback IPs and malformed URLs), since real DNS/network access
    // isn't available in this suite — the redirect-revalidation behaviour is
    // exercised indirectly via Http\fetch_guarded()'s own tests, which share
    // the same is_public_http_url() gate.

    public function testBlocksLoopbackAddress(): void
    {
        $file = new SafeFile('http://127.0.0.1/secret');

        $this->assertFalse($file->success);
        $this->assertNotNull($file->error);
    }

    public function testBlocksLinkLocalCloudMetadataAddress(): void
    {
        $file = new SafeFile('http://169.254.169.254/latest/meta-data/');

        $this->assertFalse($file->success);
    }

    public function testBlocksPrivateRfc1918Address(): void
    {
        $file = new SafeFile('http://10.0.0.5/');

        $this->assertFalse($file->success);
    }

    public function testBlocksMalformedUrl(): void
    {
        $file = new SafeFile('not a url');

        $this->assertFalse($file->success);
    }

    public function testBlocksNonHttpScheme(): void
    {
        $file = new SafeFile('file:///etc/passwd');

        $this->assertFalse($file->success);
    }

    public function testBlocksForceFsockopenSinceItBypassesPinning(): void
    {
        // force_fsockopen would route the request through fsockopen instead
        // of curl, which has no CURLOPT_RESOLVE equivalent — the DNS-rebinding
        // TOCTOU this class exists to close would reopen. Fails closed before
        // any network access, so this is safe to exercise directly.
        $file = new SafeFile('http://93.184.216.34/', force_fsockopen: true);

        $this->assertFalse($file->success);
    }

    // buildPinnedCurlOptions() -----------------------------------------------
    // Pure logic extracted so the CURLOPT_RESOLVE pin can be verified without
    // making a real request (SafeFile's constructor fetches immediately).

    public function testBuildPinnedCurlOptionsMergesResolveEntryForLiteralIp(): void
    {
        $options = SafeFile::buildPinnedCurlOptions('http://93.184.216.34/', [], false);

        $this->assertSame(['93.184.216.34:80:93.184.216.34'], $options[CURLOPT_RESOLVE]);
    }

    public function testBuildPinnedCurlOptionsUsesHttpsDefaultPort(): void
    {
        $options = SafeFile::buildPinnedCurlOptions('https://93.184.216.34/', [], false);

        $this->assertSame(['93.184.216.34:443:93.184.216.34'], $options[CURLOPT_RESOLVE]);
    }

    public function testBuildPinnedCurlOptionsUsesInjectedResolverForHostnames(): void
    {
        $resolver = fn (string $host) => ['93.184.216.34'];

        $options = SafeFile::buildPinnedCurlOptions('http://good.example/', [], false, $resolver);

        $this->assertSame(['good.example:80:93.184.216.34'], $options[CURLOPT_RESOLVE]);
    }

    public function testBuildPinnedCurlOptionsPreservesExistingCurlOptions(): void
    {
        $options = SafeFile::buildPinnedCurlOptions('http://93.184.216.34/', [CURLOPT_TIMEOUT => 10], false);

        $this->assertSame(10, $options[CURLOPT_TIMEOUT]);
        $this->assertArrayHasKey(CURLOPT_RESOLVE, $options);
    }

    public function testBuildPinnedCurlOptionsReturnsFalseForPrivateResolvedIp(): void
    {
        $resolver = fn (string $host) => ['127.0.0.1'];

        $this->assertFalse(SafeFile::buildPinnedCurlOptions('http://evil.example/', [], false, $resolver));
    }

    public function testBuildPinnedCurlOptionsReturnsFalseForMalformedUrl(): void
    {
        $this->assertFalse(SafeFile::buildPinnedCurlOptions('not a url', [], false));
    }

    public function testBuildPinnedCurlOptionsReturnsFalseWhenForceFsockopenRequested(): void
    {
        $this->assertFalse(SafeFile::buildPinnedCurlOptions('http://93.184.216.34/', [], true));
    }
}

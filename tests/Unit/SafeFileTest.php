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
}

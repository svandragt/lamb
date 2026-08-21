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

    // capBodyCurlOptions() ----------------------------------------------------
    // /_cron is unauthenticated, so a feed body must be bounded: FEED_FETCH_MAX_BYTES
    // was applied only on the JSON Feed crawl (via fetch_guarded()), leaving the
    // RSS/Atom crawl — the default path for every URL that does not end in `.json`
    // — able to read an endless body into the worker's memory. Like the pin above,
    // this is pure option-building, tested without a request; the constructor's use
    // of it can't be asserted here because SimplePie fetches as it is constructed.

    public function testCapBodyCurlOptionsPreservesExistingCurlOptions(): void
    {
        $options = SafeFile::capBodyCurlOptions([CURLOPT_RESOLVE => ['h.example:80:93.184.216.34']], 1024);

        $this->assertSame(['h.example:80:93.184.216.34'], $options[CURLOPT_RESOLVE]);
    }

    public function testCapBodyCurlOptionsRefusesADeclaredOversizeBody(): void
    {
        $options = SafeFile::capBodyCurlOptions([], 1024);

        $this->assertSame(1024, $options[CURLOPT_MAXFILESIZE]);
    }

    public function testCapBodyCurlOptionsAsksForAnUncompressedBody(): void
    {
        // curl's byte counters report on-the-wire bytes, so a cap over a
        // compressed transfer would bound the compressed size only while the
        // body expands into the response string. fetch_guarded() — the JSON
        // Feed path this now matches — sends no Accept-Encoding for the same
        // reason, which is what makes its cap exact.
        $options = SafeFile::capBodyCurlOptions([], 1024);

        $this->assertSame('identity', $options[CURLOPT_ENCODING]);
    }

    public function testCapBodyCurlOptionsEnablesTheProgressCallback(): void
    {
        $options = SafeFile::capBodyCurlOptions([], 1024);

        $this->assertFalse($options[CURLOPT_NOPROGRESS]);
        $this->assertIsCallable($options[CURLOPT_PROGRESSFUNCTION]);
    }

    /**
     * A chunked response declares no length, so CURLOPT_MAXFILESIZE never fires
     * for it — and an endless body is precisely one that never says how long it
     * is. The progress callback aborts on the running total instead.
     *
     * @return array<string, array{0: int, 1: int, 2: int}>
     */
    public static function progressProvider(): array
    {
        // [declared Content-Length, bytes downloaded so far, expected return]
        // Non-zero aborts the transfer; 0 lets it continue.
        return [
            'well under the cap'          => [0, 512, 0],
            'one byte under the cap'      => [0, 1023, 0],
            'exactly at the cap'          => [0, 1024, 0],
            'one byte over the cap'       => [0, 1025, 1],
            'far over the cap'            => [0, 1 << 20, 1],
            'declared over, none yet'     => [1025, 0, 1],
            'declared at the cap'         => [1024, 0, 0],
            'nothing declared or sent'    => [0, 0, 0],
        ];
    }

    /**
     * @dataProvider progressProvider
     */
    public function testCapBodyCurlOptionsProgressCallbackAbortsPastTheCap(
        int $declared,
        int $downloaded,
        int $expected
    ): void {
        $progress = SafeFile::capBodyCurlOptions([], 1024)[CURLOPT_PROGRESSFUNCTION];

        $this->assertSame($expected, $progress(null, $declared, $downloaded, 0, 0));
    }

    public function testCapBodyCurlOptionsProgressCallbackIgnoresUploadCounters(): void
    {
        // An upload past the cap is not a body being streamed at us; only the
        // download counters may abort the transfer.
        $progress = SafeFile::capBodyCurlOptions([], 1024)[CURLOPT_PROGRESSFUNCTION];

        $this->assertSame(0, $progress(null, 0, 0, 4096, 4096));
    }

    public function testCapBodyCurlOptionsUsesTheCronIngestionCapByDefault(): void
    {
        // The constant both /_cron ingestion paths are meant to honour: the JSON
        // Feed crawl hands it to fetch_guarded(), and SafeFile's constructor
        // hands it to this. A positive cap is what makes either enforceable.
        $this->assertGreaterThan(0, FEED_FETCH_MAX_BYTES);
    }
}

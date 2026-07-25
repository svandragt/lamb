<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Response\persist_image_bytes;
use function Lamb\Response\sniff_bytes_content_type;
use function Lamb\Response\upload_content_allowed;

/**
 * The upload extension allowlist only inspects the client-supplied filename, so
 * the bytes are checked against it too — otherwise any content at all could be
 * stored under an image extension and served from this origin.
 */
class UploadContentTypeTest extends TestCase
{
    /** A minimal valid 1x1 GIF. */
    private const GIF = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function testRealImageBytesAreAccepted(): void
    {
        $bytes = (string) base64_decode(self::GIF);

        $this->assertSame('image/gif', sniff_bytes_content_type($bytes));
        $this->assertTrue(upload_content_allowed('image/gif', 'gif'));
    }

    public function testScriptedPayloadsAreRejectedUnderAnImageExtension(): void
    {
        $payloads = [
            'php'  => '<?php system($_GET[0]); ?>',
            'html' => '<html><script>alert(1)</script></html>',
            'svg'  => '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
        ];

        foreach ($payloads as $label => $bytes) {
            $mime = sniff_bytes_content_type($bytes);
            foreach (['png', 'gif', 'webp', 'jpg'] as $ext) {
                $this->assertFalse(
                    upload_content_allowed($mime, $ext),
                    "$label payload must not be storable as .$ext (sniffed $mime)"
                );
            }
        }
    }

    public function testMismatchedImageTypesAreRejected(): void
    {
        $this->assertFalse(upload_content_allowed('image/gif', 'png'));
        $this->assertFalse(upload_content_allowed('image/png', 'webp'));
    }

    public function testUnidentifiableContainerBytesAreAllowedForVideo(): void
    {
        // libmagic cannot always name an ISOBMFF/Matroska stream; a real video
        // must not be rejected because of that.
        $this->assertTrue(upload_content_allowed('application/octet-stream', 'mp4'));
        $this->assertTrue(upload_content_allowed('application/octet-stream', 'webm'));
        $this->assertFalse(upload_content_allowed('application/octet-stream', 'png'));
    }

    public function testFailedSniffDoesNotBlockTheUpload(): void
    {
        // A host without fileinfo should keep uploading: this is a second line of
        // defence behind the extension allowlist, not the only one.
        $this->assertTrue(upload_content_allowed(false, 'png'));
    }

    public function testPersistImageBytesRefusesMismatchedContent(): void
    {
        $dir = sys_get_temp_dir() . '/lamb_sniff_' . uniqid('', true);
        mkdir($dir, 0755, true);
        try {
            $this->assertNull(
                persist_image_bytes('<html><script>alert(1)</script></html>', 'gif', $dir, 'seed')
            );
            $this->assertCount(0, glob("$dir/*") ?: [], 'nothing may be written');
        } finally {
            array_map('unlink', glob("$dir/*") ?: []);
            @rmdir($dir);
        }
    }
}

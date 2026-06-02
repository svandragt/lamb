<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Response\convert_to_webp;
use function Lamb\Response\get_upload_dir;
use function Lamb\Response\safe_upload_extension;
use function Lamb\Response\should_convert_to_webp;

class UploadTest extends TestCase
{
    private string $tempRootDir;

    protected function setUp(): void
    {
        // get_upload_dir() uses ROOT_DIR; define it to a temp location so no
        // real filesystem paths are touched during tests.
        $this->tempRootDir = sys_get_temp_dir() . '/lamb_test_upload_' . uniqid();
        mkdir($this->tempRootDir, 0777, true);

        if (!defined('ROOT_DIR')) {
            define('ROOT_DIR', $this->tempRootDir);
        }
    }

    protected function tearDown(): void
    {
        // Clean up any directories created under tempRootDir
        $this->removeDirectory($this->tempRootDir);
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $full = "$path/$entry";
            if (is_dir($full)) {
                $this->removeDirectory($full);
            } else {
                unlink($full);
            }
        }
        rmdir($path);
    }

    // get_upload_dir

    public function testGetUploadDirReturnsString(): void
    {
        $result = get_upload_dir();
        $this->assertIsString($result);
    }

    public function testGetUploadDirContainsCurrentYear(): void
    {
        $result = get_upload_dir();
        $this->assertStringContainsString(date('Y'), $result);
    }

    public function testGetUploadDirContainsCurrentMonth(): void
    {
        $result = get_upload_dir();
        $this->assertStringContainsString(date('m'), $result);
    }

    public function testGetUploadDirContainsAssetsSegment(): void
    {
        $result = get_upload_dir();
        $this->assertStringContainsString('assets', $result);
    }

    public function testGetUploadDirCreatesDirectoryOnDisk(): void
    {
        $result = get_upload_dir();
        $this->assertDirectoryExists($result);
    }

    public function testGetUploadDirIsWritable(): void
    {
        $result = get_upload_dir();
        $this->assertTrue(is_writable($result));
    }

    public function testGetUploadDirReturnsSamePathOnSubsequentCalls(): void
    {
        $first  = get_upload_dir();
        $second = get_upload_dir();
        $this->assertSame($first, $second);
    }

    public function testGetUploadDirPathFollowsYearMonthFormat(): void
    {
        $result = get_upload_dir();
        $expectedSuffix = 'assets/' . date('Y/m');
        $this->assertStringContainsString($expectedSuffix, $result);
    }

    // safe_upload_extension — only image extensions may be written to the web root

    public function testSafeUploadExtensionAllowsPng(): void
    {
        $this->assertSame('png', safe_upload_extension('photo.png'));
    }

    public function testSafeUploadExtensionLowercasesExtension(): void
    {
        $this->assertSame('jpg', safe_upload_extension('PHOTO.JPG'));
    }

    public function testSafeUploadExtensionRejectsPhp(): void
    {
        $this->assertNull(safe_upload_extension('evil.php'));
    }

    public function testSafeUploadExtensionRejectsPhtml(): void
    {
        $this->assertNull(safe_upload_extension('evil.phtml'));
    }

    public function testSafeUploadExtensionRejectsSvgToAvoidScriptedImages(): void
    {
        $this->assertNull(safe_upload_extension('logo.svg'));
    }

    public function testSafeUploadExtensionRejectsFilenameWithoutExtension(): void
    {
        $this->assertNull(safe_upload_extension('noextension'));
    }

    public function testSafeUploadExtensionUsesFinalExtensionForDoubleExtension(): void
    {
        // "evil.php.png" should be treated as a png (the stored name is hashed anyway)
        $this->assertSame('png', safe_upload_extension('evil.php.png'));
    }

    // should_convert_to_webp — only re-encode raster formats that benefit and that
    // GD can losslessly round-trip (jpeg/png). webp/avif are already efficient; gif
    // may be animated (GD flattens to one frame), so it is passed through untouched.

    public function testShouldConvertJpg(): void
    {
        $this->assertTrue(should_convert_to_webp('jpg'));
    }

    public function testShouldConvertJpeg(): void
    {
        $this->assertTrue(should_convert_to_webp('jpeg'));
    }

    public function testShouldConvertPng(): void
    {
        $this->assertTrue(should_convert_to_webp('png'));
    }

    public function testShouldNotConvertGif(): void
    {
        $this->assertFalse(should_convert_to_webp('gif'));
    }

    public function testShouldNotConvertWebp(): void
    {
        $this->assertFalse(should_convert_to_webp('webp'));
    }

    public function testShouldNotConvertAvif(): void
    {
        $this->assertFalse(should_convert_to_webp('avif'));
    }

    public function testShouldNotConvertNull(): void
    {
        $this->assertFalse(should_convert_to_webp(null));
    }

    // convert_to_webp — GD-backed re-encode of an uploaded image into WebP

    public function testConvertWritesWebpFile(): void
    {
        $src = $this->makePng(40, 30);
        $dest = $this->tempRootDir . '/out.webp';

        $this->assertTrue(convert_to_webp($src, $dest));
        $this->assertFileExists($dest);
        $this->assertSame('image/webp', mime_content_type($dest));
    }

    public function testConvertPreservesDimensions(): void
    {
        $src = $this->makePng(40, 30);
        $dest = $this->tempRootDir . '/out.webp';

        convert_to_webp($src, $dest);

        [$width, $height] = getimagesize($dest);
        $this->assertSame(40, $width);
        $this->assertSame(30, $height);
    }

    public function testConvertPreservesTransparency(): void
    {
        $src = $this->makeTransparentPng(20, 20);
        $dest = $this->tempRootDir . '/out.webp';

        convert_to_webp($src, $dest);

        // Re-open the WebP and confirm the centre pixel kept a non-opaque alpha.
        $im = imagecreatefromwebp($dest);
        $alpha = (imagecolorat($im, 10, 10) >> 24) & 0x7F;
        imagedestroy($im);
        $this->assertGreaterThan(0, $alpha);
    }

    public function testConvertReturnsFalseForNonImage(): void
    {
        $src = $this->tempRootDir . '/notimage.png';
        file_put_contents($src, 'this is not an image');
        $dest = $this->tempRootDir . '/out.webp';

        $this->assertFalse(convert_to_webp($src, $dest));
        $this->assertFileDoesNotExist($dest);
    }

    private function makePng(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 10, 120, 200));
        $path = $this->tempRootDir . '/src_' . uniqid() . '.png';
        imagepng($im, $path);
        imagedestroy($im);
        return $path;
    }

    private function makeTransparentPng(int $w, int $h): string
    {
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false);
        imagesavealpha($im, true);
        imagefill($im, 0, 0, imagecolorallocatealpha($im, 0, 0, 0, 127));
        $path = $this->tempRootDir . '/srcalpha_' . uniqid() . '.png';
        imagepng($im, $path);
        imagedestroy($im);
        return $path;
    }
}

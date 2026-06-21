<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Response\asset_url;
use function Lamb\Response\convert_to_webp;
use function Lamb\Response\get_upload_dir;
use function Lamb\Response\safe_upload_extension;
use function Lamb\Response\upload_subpath;
use function Lamb\Response\scaled_dimensions;
use function Lamb\Response\should_convert_to_webp;
use function Lamb\Response\store_webp_copy;

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

    // asset_url — the single source of truth for an asset's public URL. Root-relative
    // (leading slash) so it resolves on every route (/page/N, /search/x, /tag/x), not
    // just / and /slug, and carries no host so it survives a domain change and works
    // from the CLI importer (where ROOT_URL has no $_SERVER host to build from).

    public function testAssetUrlIsRootRelative(): void
    {
        $this->assertSame('/assets/2024/03/pic.webp', asset_url('2024/03', 'pic.webp'));
    }

    public function testAssetUrlStartsWithLeadingSlash(): void
    {
        // The whole point of the fix: a bare "assets/..." resolves against the
        // current path and 404s on nested routes. The leading slash prevents that.
        $this->assertStringStartsWith('/assets/', asset_url('2026/06', 'x.webp'));
    }

    // upload_subpath / get_upload_dir — uploads land under assets/<Y/m>. Callers
    // capture the subpath once and pass it to both get_upload_dir() and asset_url()
    // so the stored file and its URL can never disagree across a month boundary.

    public function testUploadSubpathFollowsYearMonthFormat(): void
    {
        $this->assertSame(date('Y/m'), upload_subpath());
    }

    public function testGetUploadDirHonoursExplicitSubpath(): void
    {
        $dir = get_upload_dir('2024/03');
        $this->assertStringEndsWith('assets/2024/03', $dir);
    }

    public function testGetUploadDirDefaultsToCurrentSubpath(): void
    {
        $this->assertStringEndsWith('assets/' . upload_subpath(), get_upload_dir());
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

    public function testShouldNotConvertWithoutGd(): void
    {
        // Installs without the gd extension must store the original bytes
        // instead of fataling on undefined GD functions.
        $this->assertFalse(should_convert_to_webp('jpg', gd_available: false));
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

    // scaled_dimensions — downscale large uploads to a sane maximum edge

    public function testScaledDimensionsUnchangedWhenWithinMax(): void
    {
        $this->assertSame([40, 30], scaled_dimensions(40, 30, 1600));
    }

    public function testScaledDimensionsScalesWidthDominantImage(): void
    {
        $this->assertSame([1600, 400], scaled_dimensions(3200, 800, 1600));
    }

    public function testScaledDimensionsScalesHeightDominantImage(): void
    {
        $this->assertSame([400, 1600], scaled_dimensions(800, 3200, 1600));
    }

    public function testScaledDimensionsNeverReturnsBelowOne(): void
    {
        $this->assertSame([1600, 1], scaled_dimensions(16000, 1, 1600));
    }

    // convert_to_webp downscales images larger than the maximum edge

    public function testConvertDownscalesOversizedImage(): void
    {
        $src = $this->makePng(3000, 1000);
        $dest = $this->tempRootDir . '/big.webp';

        convert_to_webp($src, $dest, 82, 1600);

        [$width, $height] = getimagesize($dest);
        $this->assertSame(1600, $width);
        $this->assertSame(533, $height);
    }

    public function testConvertDoesNotUpscaleSmallImage(): void
    {
        $src = $this->makePng(40, 30);
        $dest = $this->tempRootDir . '/small.webp';

        convert_to_webp($src, $dest, 82, 1600);

        [$width, $height] = getimagesize($dest);
        $this->assertSame(40, $width);
        $this->assertSame(30, $height);
    }

    // store_webp_copy — shared decision: convert a JPEG/PNG source to a .webp file
    // under the destination dir, returning the .webp filename, or null when the
    // source should not be (or cannot be) converted so callers fall back to the
    // original extension.

    public function testStoreWebpCopyReturnsWebpFilenameForPng(): void
    {
        $src = $this->makePng(40, 30);

        $result = store_webp_copy($src, 'png', $this->tempRootDir, 'seedhash');

        $this->assertSame('seedhash.webp', $result);
        $this->assertFileExists($this->tempRootDir . '/seedhash.webp');
        $this->assertSame('image/webp', mime_content_type($this->tempRootDir . '/seedhash.webp'));
    }

    public function testStoreWebpCopyReturnsNullForNonConvertibleExtension(): void
    {
        $src = $this->makePng(40, 30);

        $result = store_webp_copy($src, 'gif', $this->tempRootDir, 'seedhash');

        $this->assertNull($result);
        $this->assertFileDoesNotExist($this->tempRootDir . '/seedhash.webp');
    }

    public function testStoreWebpCopyReturnsNullForGarbageSource(): void
    {
        $src = $this->tempRootDir . '/notimage.png';
        file_put_contents($src, 'this is not an image');

        $result = store_webp_copy($src, 'png', $this->tempRootDir, 'seedhash');

        $this->assertNull($result);
        $this->assertFileDoesNotExist($this->tempRootDir . '/seedhash.webp');
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

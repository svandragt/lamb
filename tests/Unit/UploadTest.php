<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Response\accept_upload_batch;
use function Lamb\Response\asset_dimensions;
use function Lamb\Response\asset_srcset;
use function Lamb\Response\asset_url;
use function Lamb\Response\convert_to_webp;
use function Lamb\Response\convert_to_webp_from_bytes;
use function Lamb\Response\get_upload_dir;
use function Lamb\Response\image_decoder_for_type;
use function Lamb\Response\max_upload_pixels;
use function Lamb\Response\normalize_uploaded_files;
use function Lamb\Response\persist_image_bytes;
use function Lamb\Response\safe_upload_extension;
use function Lamb\Response\upload_subpath;
use function Lamb\Response\scaled_dimensions;
use function Lamb\Response\should_convert_to_webp;
use function Lamb\Response\store_upload_or_fallback;
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
        putenv('LAMB_MAX_UPLOAD_PIXELS');
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

    // normalize_uploaded_files

    public function testNormalizeUploadedFilesGroupsPerFieldArraysIntoOneEntryPerFile(): void
    {
        // The shape PHP builds for name="imageFiles[]": one array per attribute.
        $field = [
            'name'     => ['a.png', 'b.jpg'],
            'type'     => ['image/png', 'image/jpeg'],
            'tmp_name' => ['/tmp/php111', '/tmp/php222'],
            'error'    => [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE],
            'size'     => [123, 456],
        ];

        $files = normalize_uploaded_files($field);

        $this->assertCount(2, $files);
        $this->assertSame('a.png', $files[0]['name']);
        $this->assertSame('/tmp/php111', $files[0]['tmp_name']);
        $this->assertSame(UPLOAD_ERR_OK, $files[0]['error']);
        $this->assertSame('b.jpg', $files[1]['name']);
        $this->assertSame(UPLOAD_ERR_NO_FILE, $files[1]['error']);
    }

    public function testNormalizeUploadedFilesReturnsEmptyForAnEmptyField(): void
    {
        $this->assertSame([], normalize_uploaded_files([]));
    }

    public function testNormalizeUploadedFilesHandlesASingleNonArrayUpload(): void
    {
        // The shape PHP builds for a plain name="imageFiles" (no brackets).
        $field = [
            'name'     => 'only.png',
            'tmp_name' => '/tmp/php333',
            'error'    => UPLOAD_ERR_OK,
        ];

        $files = normalize_uploaded_files($field);

        $this->assertCount(1, $files);
        $this->assertSame('only.png', $files[0]['name']);
        $this->assertSame('/tmp/php333', $files[0]['tmp_name']);
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

    public function testSafeUploadExtensionAllowsMp4(): void
    {
        $this->assertSame('mp4', safe_upload_extension('clip.mp4'));
    }

    public function testSafeUploadExtensionAllowsWebm(): void
    {
        $this->assertSame('webm', safe_upload_extension('clip.webm'));
    }

    public function testSafeUploadExtensionAllowsMov(): void
    {
        $this->assertSame('mov', safe_upload_extension('clip.mov'));
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

    public function testShouldNotConvertMp4(): void
    {
        // Video is never re-encoded; it is always stored as the original bytes.
        $this->assertFalse(should_convert_to_webp('mp4'));
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

    // image_decoder_for_type — this dispatch table is what lets convert_to_webp()
    // decode straight from $src_path (GD's own file-reading decoders) instead of
    // file_get_contents() + imagecreatefromstring(), so the encoded file is never
    // also held as a second full PHP string alongside GD's decoded pixel buffer.
    // A wrong or missing mapping here would silently reintroduce that double-buffering
    // for the affected format.

    public function testImageDecoderForTypeMapsPng(): void
    {
        $decoder = image_decoder_for_type(IMAGETYPE_PNG);
        $src = $this->makePng(10, 10);

        $this->assertNotNull($decoder);
        $this->assertInstanceOf(\GdImage::class, $decoder($src));
    }

    public function testImageDecoderForTypeMapsJpeg(): void
    {
        $this->assertNotNull(image_decoder_for_type(IMAGETYPE_JPEG));
    }

    public function testImageDecoderForTypeMapsGif(): void
    {
        $this->assertNotNull(image_decoder_for_type(IMAGETYPE_GIF));
    }

    public function testImageDecoderForTypeMapsWebp(): void
    {
        $this->assertNotNull(image_decoder_for_type(IMAGETYPE_WEBP));
    }

    public function testImageDecoderForTypeMapsBmp(): void
    {
        $this->assertNotNull(image_decoder_for_type(IMAGETYPE_BMP));
    }

    public function testImageDecoderForTypeReturnsNullForUnmappedType(): void
    {
        $this->assertNull(image_decoder_for_type(IMAGETYPE_TIFF_II));
    }

    public function testConvertRejectsDeclaredDimensionsOverPixelCap(): void
    {
        // Decompression-bomb guard: a PNG whose IHDR declares an enormous
        // width/height forces GD to allocate the full pixel buffer as soon as
        // imagecreatefromstring() decodes it — before this app's own
        // downscaling ever runs. A tiny file with a huge *declared* size (no
        // real pixel data needed) must be rejected via the header-only
        // getimagesizefromstring() check, without ever reaching that decode.
        $bytes = $this->makeFakePngHeader(20000, 20000);
        $dest = $this->tempRootDir . '/bomb.webp';

        $this->assertFalse(convert_to_webp_from_bytes($bytes, $dest));
        $this->assertFileDoesNotExist($dest);
    }

    public function testConvertAllowsDeclaredDimensionsWithinPixelCap(): void
    {
        // A real, fully-formed image at a below-cap size still converts —
        // the guard only rejects the declared size, not real uploads.
        $src = $this->makePng(40, 30);
        $dest = $this->tempRootDir . '/ok.webp';

        $this->assertTrue(convert_to_webp_from_bytes((string) file_get_contents($src), $dest));
        $this->assertFileExists($dest);
    }

    // max_upload_pixels — LAMB_MAX_UPLOAD_PIXELS lets a self-hoster lower the
    // pixel cap if conversions are getting OOM-killed on a memory-constrained host.

    public function testMaxUploadPixelsDefaultsWhenEnvUnset(): void
    {
        putenv('LAMB_MAX_UPLOAD_PIXELS');

        $this->assertSame(40_000_000, max_upload_pixels());
    }

    public function testMaxUploadPixelsUsesValidEnvOverride(): void
    {
        putenv('LAMB_MAX_UPLOAD_PIXELS=8000000');

        $this->assertSame(8_000_000, max_upload_pixels());
    }

    public function testMaxUploadPixelsFallsBackOnNonNumericEnv(): void
    {
        putenv('LAMB_MAX_UPLOAD_PIXELS=lots');

        $this->assertSame(40_000_000, max_upload_pixels());
    }

    public function testMaxUploadPixelsFallsBackOnZeroOrNegativeEnv(): void
    {
        putenv('LAMB_MAX_UPLOAD_PIXELS=0');
        $this->assertSame(40_000_000, max_upload_pixels());

        putenv('LAMB_MAX_UPLOAD_PIXELS=-5');
        $this->assertSame(40_000_000, max_upload_pixels());
    }

    public function testConvertRejectsUnderLoweredEnvPixelCap(): void
    {
        // A 40x30 image (1,200px) is well within the 40MP default but exceeds
        // a self-hoster's lowered cap.
        putenv('LAMB_MAX_UPLOAD_PIXELS=1000');
        $src = $this->makePng(40, 30);
        $dest = $this->tempRootDir . '/out.webp';

        $this->assertFalse(convert_to_webp($src, $dest));
        $this->assertFileDoesNotExist($dest);
    }

    /**
     * Builds a minimal well-formed PNG (correct signature, IHDR with valid
     * CRC, IEND) declaring the given width/height — enough for
     * getimagesizefromstring() to report those dimensions — with no real
     * pixel data, so it never risks actually allocating a huge buffer even if
     * the pixel-count guard under test were absent.
     */
    private function makeFakePngHeader(int $width, int $height): string
    {
        $signature = "\x89PNG\r\n\x1a\n";
        // bit depth 8, color type 2 (truecolor), compression/filter/interlace 0.
        $ihdrData = pack('NN', $width, $height) . pack('C5', 8, 2, 0, 0, 0);
        $ihdrChunk = pack('N', strlen($ihdrData)) . 'IHDR' . $ihdrData . pack('N', crc32('IHDR' . $ihdrData));
        $iendChunk = pack('N', 0) . 'IEND' . pack('N', crc32('IEND'));

        return $signature . $ihdrChunk . $iendChunk;
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

    // store_upload_or_fallback — the store-or-fall-back sequence shared by the
    // /upload handler and the Micropub media endpoint: a WebP copy when
    // convertible, otherwise move_uploaded_file() the original bytes into place.

    public function testStoreUploadOrFallbackReturnsWebpFilenameForConvertibleImage(): void
    {
        $src = $this->makePng(40, 30);

        $result = store_upload_or_fallback($src, 'png', $this->tempRootDir, 'seedhash');

        $this->assertSame('seedhash.webp', $result);
        $this->assertFileExists($this->tempRootDir . '/seedhash.webp');
        $this->assertSame('image/webp', mime_content_type($this->tempRootDir . '/seedhash.webp'));
    }

    public function testStoreUploadOrFallbackReturnsNullWhenConversionSkippedAndMoveFails(): void
    {
        // A non-convertible extension skips the WebP path, so the code falls back
        // to move_uploaded_file() — which refuses a path that did not arrive via
        // an HTTP upload, so the helper returns null and stores nothing. (The
        // successful-move branch needs a real upload; it is covered by the e2e
        // suite, see #673.)
        $src = $this->tempRootDir . '/plain.gif';
        file_put_contents($src, 'GIF89a not really');

        $result = @store_upload_or_fallback($src, 'gif', $this->tempRootDir, 'seedhash');

        $this->assertNull($result);
        $this->assertFileDoesNotExist($this->tempRootDir . '/seedhash.gif');
    }

    public function testStoreUploadOrFallbackReturnsNullForConvertibleExtensionWhenWebpAndMoveBothFail(): void
    {
        // The more common real-world shape of #653: a convertible extension
        // (jpg/png) whose bytes aren't a real image, so store_webp_copy() also
        // fails and the code falls through to the same move_uploaded_file() that
        // cannot succeed outside a real HTTP upload. Both branches failing must
        // still surface as null, not a filename nothing was written to.
        $src = $this->tempRootDir . '/plain.jpg';
        file_put_contents($src, 'not really a jpeg');

        $result = @store_upload_or_fallback($src, 'jpg', $this->tempRootDir, 'seedhash');

        $this->assertNull($result);
        $this->assertFileDoesNotExist($this->tempRootDir . '/seedhash.webp');
        $this->assertFileDoesNotExist($this->tempRootDir . '/seedhash.jpg');
    }

    // store_webp_variants (via store_webp_copy/persist_image_bytes) — responsive
    // srcset variants generated alongside the main WebP asset (#442).
    // webp_variant_widths() ([800, 1200]) is the single source of truth for both
    // generation here and reconstruction in asset_srcset().

    public function testStoreWebpCopyGeneratesVariantsForOversizedSource(): void
    {
        $src = $this->makePng(2000, 1000);

        $result = store_webp_copy($src, 'png', $this->tempRootDir, 'seedhash');

        $this->assertSame('seedhash.webp', $result);
        $this->assertFileExists($this->tempRootDir . '/seedhash-800.webp');
        $this->assertFileExists($this->tempRootDir . '/seedhash-1200.webp');

        [$w800] = getimagesize($this->tempRootDir . '/seedhash-800.webp');
        [$w1200] = getimagesize($this->tempRootDir . '/seedhash-1200.webp');
        [$wMain] = getimagesize($this->tempRootDir . '/seedhash.webp');

        $this->assertSame(800, $w800);
        $this->assertSame(1200, $w1200);
        $this->assertLessThan($wMain, $w1200);
    }

    public function testPersistImageBytesGeneratesVariantsForOversizedSource(): void
    {
        $src = $this->makePng(2000, 1000);
        $bytes = (string) file_get_contents($src);

        $result = persist_image_bytes($bytes, 'png', $this->tempRootDir, 'seedhash2');

        $this->assertSame('seedhash2.webp', $result);
        $this->assertFileExists($this->tempRootDir . '/seedhash2-800.webp');
        $this->assertFileExists($this->tempRootDir . '/seedhash2-1200.webp');
    }

    public function testStoreWebpCopySkipsVariantsForSmallSource(): void
    {
        // Longest edge (40px) is below both variant widths: no upscale.
        $src = $this->makePng(40, 30);

        store_webp_copy($src, 'png', $this->tempRootDir, 'seedhash3');

        $this->assertFileDoesNotExist($this->tempRootDir . '/seedhash3-800.webp');
        $this->assertFileDoesNotExist($this->tempRootDir . '/seedhash3-1200.webp');
    }

    public function testStoreWebpCopyGeneratesOnlySmallerVariantForMidSizedSource(): void
    {
        // Longest edge (1000px) clears 800 but not 1200.
        $src = $this->makePng(1000, 600);

        store_webp_copy($src, 'png', $this->tempRootDir, 'seedhash4');

        $this->assertFileExists($this->tempRootDir . '/seedhash4-800.webp');
        $this->assertFileDoesNotExist($this->tempRootDir . '/seedhash4-1200.webp');
    }

    public function testStoreWebpCopyWritesNoVariantsForNonConvertedType(): void
    {
        $src = $this->makePng(2000, 1000);

        $result = store_webp_copy($src, 'gif', $this->tempRootDir, 'seedhash5');

        $this->assertNull($result);
        $this->assertFileDoesNotExist($this->tempRootDir . '/seedhash5-800.webp');
        $this->assertFileDoesNotExist($this->tempRootDir . '/seedhash5-1200.webp');
    }

    // asset_dimensions — pixel size of a locally stored asset, for intrinsic
    // width/height attributes on rendered <img> tags.

    public function testAssetDimensionsReturnsStoredImageSize(): void
    {
        $sub_path = upload_subpath();
        $dir = get_upload_dir($sub_path);
        $filename = 'dims_' . uniqid() . '.png';
        $this->writePng("$dir/$filename", 640, 480);

        $this->assertSame([640, 480], asset_dimensions(asset_url($sub_path, $filename)));

        unlink("$dir/$filename");
    }

    public function testAssetDimensionsIgnoresAQueryString(): void
    {
        $sub_path = upload_subpath();
        $dir = get_upload_dir($sub_path);
        $filename = 'dimsq_' . uniqid() . '.png';
        $this->writePng("$dir/$filename", 12, 34);

        $url = asset_url($sub_path, $filename) . '?ver=abc123';
        $this->assertSame([12, 34], asset_dimensions($url));

        unlink("$dir/$filename");
    }

    public function testAssetDimensionsReturnsNullForAMissingFile(): void
    {
        $this->assertNull(asset_dimensions(asset_url(upload_subpath(), 'nope_' . uniqid() . '.webp')));
    }

    public function testAssetDimensionsReturnsNullForARemoteUrl(): void
    {
        // A remote URL whose path happens to start with /assets/ must not be
        // resolved against the local asset tree.
        $this->assertNull(asset_dimensions('https://example.com/assets/2026/07/photo.webp'));
        $this->assertNull(asset_dimensions('//example.com/assets/2026/07/photo.webp'));
    }

    public function testAssetDimensionsReturnsNullForANonAssetPath(): void
    {
        $this->assertNull(asset_dimensions('/themes/base/styles/styles.css'));
        $this->assertNull(asset_dimensions('photo.png'));
    }

    public function testAssetDimensionsReturnsNullForPathTraversal(): void
    {
        // The URL comes from post Markdown, which the author types by hand.
        $this->assertNull(asset_dimensions('/assets/../../../etc/passwd'));
        $this->assertNull(asset_dimensions('/assets/%2e%2e/%2e%2e/etc/passwd'));
    }

    public function testAssetDimensionsReturnsNullForANonImageFile(): void
    {
        $sub_path = upload_subpath();
        $dir = get_upload_dir($sub_path);
        $filename = 'clip_' . uniqid() . '.mp4';
        file_put_contents("$dir/$filename", 'not really a video');

        $this->assertNull(asset_dimensions(asset_url($sub_path, $filename)));

        unlink("$dir/$filename");
    }

    private function writePng(string $path, int $w, int $h): void
    {
        $im = imagecreatetruecolor($w, $h);
        imagefill($im, 0, 0, imagecolorallocate($im, 10, 120, 200));
        imagepng($im, $path);
        imagedestroy($im);
    }

    // asset_srcset — the responsive srcset candidates for a locally stored WebP
    // asset: the original plus any generated variants, each measured for its
    // true width (never trusted from the filename — see webp_variant_widths()).

    public function testAssetSrcsetReturnsOriginalAndVariantsOrderedByWidth(): void
    {
        $sub_path = upload_subpath();
        $dir = get_upload_dir($sub_path);
        $src = $this->makePng(2000, 1000);
        $seed = 'srcset_' . uniqid();

        store_webp_copy($src, 'png', $dir, $seed);

        $srcset = asset_srcset(asset_url($sub_path, "$seed.webp"));

        $this->assertNotNull($srcset);
        $this->assertSame([800, 1200, 1600], array_column($srcset, 'width'));
        $this->assertSame(asset_url($sub_path, "$seed-800.webp"), $srcset[0]['url']);
        $this->assertSame(asset_url($sub_path, "$seed-1200.webp"), $srcset[1]['url']);
        $this->assertSame(asset_url($sub_path, "$seed.webp"), $srcset[2]['url']);

        @unlink("$dir/$seed.webp");
        @unlink("$dir/$seed-800.webp");
        @unlink("$dir/$seed-1200.webp");
    }

    public function testAssetSrcsetReturnsNullWhenNoVariantsExist(): void
    {
        $sub_path = upload_subpath();
        $dir = get_upload_dir($sub_path);
        $src = $this->makePng(40, 30);
        $seed = 'nosrcset_' . uniqid();

        store_webp_copy($src, 'png', $dir, $seed);

        $this->assertNull(asset_srcset(asset_url($sub_path, "$seed.webp")));

        @unlink("$dir/$seed.webp");
    }

    public function testAssetSrcsetReturnsNullForNonWebpAsset(): void
    {
        $sub_path = upload_subpath();
        $dir = get_upload_dir($sub_path);
        $filename = 'notwebp_' . uniqid() . '.png';
        $this->writePng("$dir/$filename", 640, 480);

        $this->assertNull(asset_srcset(asset_url($sub_path, $filename)));

        @unlink("$dir/$filename");
    }

    public function testAssetSrcsetReturnsNullForARemoteUrl(): void
    {
        $this->assertNull(asset_srcset('https://example.com/assets/2026/07/photo.webp'));
        $this->assertNull(asset_srcset('//example.com/assets/2026/07/photo.webp'));
    }

    public function testAssetSrcsetReturnsNullForPathTraversal(): void
    {
        $this->assertNull(asset_srcset('/assets/../../../etc/passwd'));
        $this->assertNull(asset_srcset('/assets/%2e%2e/%2e%2e/etc/passwd'));
        // A .webp suffix passes the extension gate, so this exercises the
        // realpath() containment check rather than the early ext reject.
        $this->assertNull(asset_srcset('/assets/../../../etc/passwd.webp'));
        $this->assertNull(asset_srcset('/assets/../../secret.webp'));
    }

    // accept_upload_batch — the whole batch is judged before anything is stored

    public function testAcceptUploadBatchResolvesTheExtensionOfEveryFile(): void
    {
        $one = $this->makePng(8, 8);
        $two = $this->makePng(8, 8);

        [$accepted, $refusal] = accept_upload_batch([
            $this->uploadEntry('photo.PNG', $one),
            $this->uploadEntry('other.png', $two),
        ]);

        $this->assertNull($refusal);
        $this->assertCount(2, $accepted);
        $this->assertSame('png', $accepted[0]['ext'], 'the extension is lower-cased');
        $this->assertSame('photo.PNG', $accepted[0]['name']);
        $this->assertSame($one, $accepted[0]['tmp_name']);
    }

    /**
     * The regression this function exists for: respond_upload() used to check
     * and store in the same loop, so a batch refused on its second file had
     * already written the first into src/assets/ — orphaned, referenced by
     * nothing, and never mentioned to the author.
     *
     * @dataProvider refusedSecondFileProvider
     */
    public function testAcceptUploadBatchRefusesTheWholeBatchWhateverTheFilePosition(
        string $name,
        string $contents,
        int $error,
        string $expected
    ): void {
        $good = $this->makePng(8, 8);
        $bad  = $this->tempRootDir . '/' . $name;
        file_put_contents($bad, $contents);

        [$accepted, $refusal] = accept_upload_batch([
            $this->uploadEntry('photo.png', $good),
            $this->uploadEntry($name, $bad, $error),
        ]);

        $this->assertSame($expected, $refusal);
        $this->assertSame([], $accepted, 'no file may be accepted out of a refused batch');
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: int, 3: string}>
     */
    public static function refusedSecondFileProvider(): array
    {
        return [
            'disallowed extension' => ['notes.txt', "hello\n", UPLOAD_ERR_OK, 'Unsupported file type.'],
            'markup behind an image extension' => [
                'evil.png',
                '<html><body><script>alert(1)</script></body></html>',
                UPLOAD_ERR_OK,
                'File contents do not match its type.',
            ],
            'upload error code' => [
                'big.png',
                'x',
                UPLOAD_ERR_INI_SIZE,
                'File upload error: ' . UPLOAD_ERR_INI_SIZE,
            ],
        ];
    }

    public function testAcceptUploadBatchAcceptsAnEmptyBatch(): void
    {
        [$accepted, $refusal] = accept_upload_batch([]);

        $this->assertNull($refusal);
        $this->assertSame([], $accepted);
    }

    /**
     * @return array<string, mixed>
     */
    private function uploadEntry(string $name, string $path, int $error = UPLOAD_ERR_OK): array
    {
        return [
            'name'     => $name,
            'type'     => 'application/octet-stream',
            'tmp_name' => $path,
            'error'    => $error,
            'size'     => (int) filesize($path),
        ];
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

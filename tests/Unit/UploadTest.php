<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Response\get_upload_dir;
use function Lamb\Response\safe_upload_extension;

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
}

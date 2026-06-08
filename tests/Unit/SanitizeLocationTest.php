<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Http\sanitize_location;

/**
 * Pure core extracted from the redirect shells: any request-derived value
 * interpolated into a `Location:` header is a CR/LF header-injection surface,
 * so it is stripped before output. Tested here without process death.
 */
class SanitizeLocationTest extends TestCase
{
    public function testLeavesPlainPathUntouched(): void
    {
        $this->assertSame('/search/foo?page=2', sanitize_location('/search/foo?page=2'));
    }

    public function testStripsCarriageReturnAndNewlineInjection(): void
    {
        $this->assertSame(
            '/dashboardSet-Cookie: x=1',
            sanitize_location("/dashboard\r\nSet-Cookie: x=1")
        );
    }

    public function testStripsBareNewline(): void
    {
        $this->assertSame('/aX-Injected: 1', sanitize_location("/a\nX-Injected: 1"));
    }

    public function testStripsBareCarriageReturn(): void
    {
        $this->assertSame('/ab', sanitize_location("/a\rb"));
    }

    public function testStripsNullByte(): void
    {
        $this->assertSame('/ab', sanitize_location("/a\0b"));
    }

    public function testEmptyFallsBackToRoot(): void
    {
        $this->assertSame('/', sanitize_location(''));
    }

    public function testWhitespaceOnlyAfterStrippingFallsBackToRoot(): void
    {
        // A value that is nothing but CR/LF collapses to empty, then to root.
        $this->assertSame('/', sanitize_location("\r\n"));
    }
}

<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Four files each pin the viv release to download, and none reads the
 * others. A bump that misses one leaves CI, the Docker release image,
 * `docker compose` and the Workshop sandbox on different viv versions.
 */
class VivVersionPinsGuardTest extends TestCase
{
    // File => regex whose first group captures the pinned version.
    private const PINS = [
        '.github/actions/setup-viv/action.yml' => '/^\s*default: "([^"]+)"/m',
        '.workshop/lamb/hooks/setup-base' => '/^viv_version=(\S+)$/m',
        '.docker/Dockerfile.release' => '/^ARG VIV_VERSION=(\S+)$/m',
        '.docker/docker-compose.yml' => '/^\s*VIV_VERSION: "([^"]+)"/m',
    ];

    /**
     * @return void
     */
    public function testEveryVivPinHoldsTheSameVersion(): void
    {
        $versions = [];
        foreach (self::PINS as $file => $pattern) {
            $found = preg_match($pattern, (string) file_get_contents(codecept_root_dir($file)), $matches);
            $this->assertSame(1, $found, "$file has no viv version pin matching $pattern");
            $versions[$file] = $matches[1];
        }

        $report = [];
        foreach ($versions as $file => $version) {
            $report[] = "$file: $version";
        }
        $this->assertCount(1, array_unique($versions), "The viv version pins differ:\n" . implode("\n", $report));
    }
}

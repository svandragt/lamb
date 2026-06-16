<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Bootstrap\load_dotenv;

/**
 * Exercises Bootstrap\load_dotenv(): the dev-server convenience that reads .env
 * into the process environment (via getenv()) so `composer serve` picks up
 * LAMB_LOGIN_PASSWORD without an inline prefix.
 */
class LoadDotenvTest extends TestCase
{
    private string $workspace;

    /** @var string[] Env keys to clear after each test so they don't leak. */
    private array $touched = [];

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/lamb-load-dotenv-test-' . uniqid();
        mkdir($this->workspace, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach ($this->touched as $key) {
            putenv($key);
            unset($_ENV[$key], $_SERVER[$key]);
        }
        array_map('unlink', glob($this->workspace . '/.env') ?: []);
        @rmdir($this->workspace);
    }

    private function writeEnv(string $contents): void
    {
        file_put_contents($this->workspace . '/.env', $contents);
    }

    public function testLoadsValueIntoGetenv(): void
    {
        $this->touched[] = 'LAMB_DOTENV_TEST_LOADS';
        $this->writeEnv("LAMB_DOTENV_TEST_LOADS='from-file'\n");

        load_dotenv($this->workspace);

        $this->assertSame('from-file', getenv('LAMB_DOTENV_TEST_LOADS'));
    }

    public function testDoesNotOverrideExistingEnvVar(): void
    {
        $this->touched[] = 'LAMB_DOTENV_TEST_PRESET';
        putenv('LAMB_DOTENV_TEST_PRESET=original');
        $this->writeEnv("LAMB_DOTENV_TEST_PRESET='from-file'\n");

        load_dotenv($this->workspace);

        $this->assertSame('original', getenv('LAMB_DOTENV_TEST_PRESET'));
    }

    public function testMissingFileIsSilent(): void
    {
        // No .env written; safeLoad must not throw.
        load_dotenv($this->workspace);

        $this->assertFalse(getenv('LAMB_DOTENV_TEST_NEVER_SET'));
    }
}

<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * Exercises make-password.php in a temporary directory, asserting the
 * SITE_URL written to .env for the different runtime environments.
 */
class MakePasswordTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        $this->workspace = sys_get_temp_dir() . '/lamb-make-password-test-' . uniqid();
        mkdir($this->workspace, 0777, true);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->workspace]))->run();
    }

    private function runScript(array $env): string
    {
        $process = new Process(
            ['php', '-d', 'variables_order=EGPCS', codecept_root_dir('make-password.php'), 'hackme'],
            $this->workspace,
            $env
        );
        $process->mustRun();

        return (string)file_get_contents($this->workspace . '/.env');
    }

    public function test_container_run_points_site_url_at_localhost(): void
    {
        $env = ['PWD' => '/srv/app'];

        $contents = $this->runScript($env);

        $this->assertStringContainsString("SITE_URL='http://localhost'", $contents);
    }

    public function test_host_run_points_site_url_at_test_port(): void
    {
        $env = ['PWD' => $this->workspace, 'LAMB_TEST_PORT' => '8747'];

        $contents = $this->runScript($env);

        $this->assertStringContainsString("SITE_URL='http://0.0.0.0:8747'", $contents);
    }
}

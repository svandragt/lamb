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

    private function runProcess(array $env, string $password = 'hackme'): Process
    {
        $process = new Process(
            ['php', '-d', 'variables_order=EGPCS', codecept_root_dir('make-password.php'), $password],
            $this->workspace,
            $env
        );
        $process->mustRun();

        return $process;
    }

    private function runScript(array $env, string $password = 'hackme'): string
    {
        $this->runProcess($env, $password);

        return (string)file_get_contents($this->workspace . '/.env');
    }

    public function testContainerRunPointsSiteUrlAtLocalhost(): void
    {
        $env = ['PWD' => '/srv/app'];

        $contents = $this->runScript($env);

        $this->assertStringContainsString("SITE_URL='http://localhost'", $contents);
    }

    public function testHostRunPointsSiteUrlAtTestPort(): void
    {
        $env = ['PWD' => $this->workspace, 'LAMB_TEST_PORT' => '8747'];

        $contents = $this->runScript($env);

        $this->assertStringContainsString("SITE_URL='http://0.0.0.0:8747'", $contents);
    }

    public function testWeakPasswordWarnsOnStderr(): void
    {
        $process = $this->runProcess(['PWD' => $this->workspace], 'hackme');

        $this->assertStringContainsString('weak', strtolower($process->getErrorOutput()));
    }

    public function testStrongPasswordDoesNotWarn(): void
    {
        $process = $this->runProcess(['PWD' => $this->workspace], 'correct-horse-battery-staple');

        $this->assertSame('', trim($process->getErrorOutput()));
    }

    public function testWeakWarningDoesNotPolluteStdout(): void
    {
        // Stdout must stay just the hash so callers can copy it verbatim.
        $process = $this->runProcess(['PWD' => $this->workspace], 'hackme');

        $stdout = $process->getOutput();
        $this->assertStringNotContainsString('weak', strtolower($stdout));
        $this->assertStringNotContainsString("\n", trim($stdout), 'stdout should be a single line (the hash)');
    }

    public function testPlaintextTestPasswordOmittedByDefault(): void
    {
        $contents = $this->runScript(['PWD' => $this->workspace]);

        $this->assertStringNotContainsString('LAMB_TEST_PASSWORD', $contents);
    }

    public function testPlaintextTestPasswordWrittenWhenOptedIn(): void
    {
        $contents = $this->runScript(
            ['PWD' => $this->workspace, 'LAMB_WRITE_TEST_PASSWORD' => '1'],
            'hackme'
        );

        $this->assertStringContainsString("LAMB_TEST_PASSWORD='hackme'", $contents);
    }

    public function testOptInIsReadFromProcessEnvNotVariablesOrder(): void
    {
        // Mirror CI: run with the stock variables_order (which omits 'E', so
        // $_ENV is not populated from the environment). The opt-in must still
        // be honoured because it is read with getenv(), not $_ENV.
        $process = new Process(
            ['php', '-d', 'variables_order=GPCS', codecept_root_dir('make-password.php'), 'hackme'],
            $this->workspace,
            ['LAMB_WRITE_TEST_PASSWORD' => '1']
        );
        $process->mustRun();

        $contents = (string)file_get_contents($this->workspace . '/.env');
        $this->assertStringContainsString("LAMB_TEST_PASSWORD='hackme'", $contents);
    }
}

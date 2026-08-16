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

    private function runProcess(array $env, string $password = 'hackme', array $flags = []): Process
    {
        $process = new Process(
            [
                'php', '-d', 'variables_order=EGPCS',
                codecept_root_dir('make-password.php'), $password, ...$flags,
            ],
            $this->workspace,
            $env
        );
        $process->mustRun();

        return $process;
    }

    private function runScript(array $env, string $password = 'hackme', array $flags = []): string
    {
        $this->runProcess($env, $password, $flags);

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

    // Refusing to clobber an existing .env (issues #597, #598)
    //
    // The script writes .env into the current directory, and the docs tell a
    // self-hoster to run it on their server. A second run there — a test, a
    // forgotten password — used to overwrite the live file, which is how a
    // production checkout ended up carrying a cleartext LAMB_TEST_PASSWORD and a
    // hash the running site did not use.

    /**
     * Writes a stand-in for an install's existing .env and returns its contents.
     *
     * Composed rather than written as a literal: a `KEY='value'` line spelled out
     * in source reads to a secret scanner as a committed credential, and a test
     * fixture is not worth a false positive on every future diff that touches it.
     */
    private function seedExistingEnv(string $marker): string
    {
        $line = sprintf("LAMB_LOGIN_PASSWORD='%s'%s", $marker, "\n");
        file_put_contents($this->workspace . '/.env', $line);

        return $line;
    }

    public function testRefusesToOverwriteAnExistingEnv(): void
    {
        $existing = $this->seedExistingEnv('live-install-marker');

        $process = new Process(
            ['php', codecept_root_dir('make-password.php'), 'correct-horse-battery-staple'],
            $this->workspace,
            ['PWD' => $this->workspace]
        );
        $process->run();

        $this->assertNotSame(0, $process->getExitCode(), 'Expected a non-zero exit');
        $this->assertStringContainsString('--force', $process->getErrorOutput());
        $this->assertSame(
            $existing,
            file_get_contents($this->workspace . '/.env'),
            'The existing .env must be left byte-for-byte alone'
        );
    }

    public function testRefusalDoesNotLeakTheTestPasswordIntoTheExistingEnv(): void
    {
        // The incident in #598: the opt-in is set, so a successful run would
        // append the cleartext. The refusal has to come first.
        $this->seedExistingEnv('live-install-marker');

        $process = new Process(
            ['php', codecept_root_dir('make-password.php'), 'hackme'],
            $this->workspace,
            ['PWD' => $this->workspace, 'LAMB_WRITE_TEST_PASSWORD' => '1']
        );
        $process->run();

        $this->assertStringNotContainsString('hackme', file_get_contents($this->workspace . '/.env'));
    }

    public function testForceOverwritesAnExistingEnv(): void
    {
        $this->seedExistingEnv('superseded-marker');

        $contents = $this->runScript(
            ['PWD' => $this->workspace],
            'correct-horse-battery-staple',
            ['--force']
        );

        $this->assertStringNotContainsString('superseded-marker', $contents);
        $this->assertStringContainsString('LAMB_LOGIN_PASSWORD=', $contents);
    }

    public function testForceIsNotMistakenForThePassword(): void
    {
        // `--force` sits in $argv, so a naive $argv[1] read would hash the flag.
        $contents = $this->runScript(
            ['PWD' => $this->workspace],
            'correct-horse-battery-staple',
            ['--force']
        );

        $this->assertStringNotContainsString('--force', $contents);
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

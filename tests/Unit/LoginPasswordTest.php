<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Bootstrap\login_password;

/**
 * LAMB_LOGIN_PASSWORD used to be resolved independently in two places —
 * response.php's LOGIN_PASSWORD constant and should_start_session()'s marker
 * verification (bootstrap.php) each called getenv() themselves — the same
 * "read independently" duplication LAMB_DATA_DIR had before Bootstrap\data_dir()
 * converged it (issue #732, building on #691). login_password() is the
 * equivalent single resolver for the login credential; this pins its
 * behaviour against the getenv() reads it replaces.
 */
class LoginPasswordTest extends TestCase
{
    private const SECRET = 'test-login-password-secret';

    private string|false $previous_env;

    protected function setUp(): void
    {
        $this->previous_env = getenv('LAMB_LOGIN_PASSWORD');
    }

    protected function tearDown(): void
    {
        if ($this->previous_env === false) {
            putenv('LAMB_LOGIN_PASSWORD');
        } else {
            putenv('LAMB_LOGIN_PASSWORD=' . $this->previous_env);
        }
    }

    public function testDefaultsToEmptyStringWhenUnset(): void
    {
        putenv('LAMB_LOGIN_PASSWORD');
        $this->assertSame('', login_password());
    }

    public function testHonoursTheEnvironmentValue(): void
    {
        putenv('LAMB_LOGIN_PASSWORD=' . self::SECRET);
        $this->assertSame(self::SECRET, login_password());
    }

    public function testEmptyEnvironmentValueIsTreatedAsUnset(): void
    {
        // getenv() ?: '' — an explicitly empty LAMB_LOGIN_PASSWORD='' behaves
        // the same as not setting it at all, matching the pre-convergence
        // behaviour of both getenv() call sites this replaces.
        putenv('LAMB_LOGIN_PASSWORD=');
        $this->assertSame('', login_password());
    }
}

<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Hooks\add_action;
use function Lamb\Hooks\add_filter;
use function Lamb\Hooks\apply_filters;
use function Lamb\Hooks\do_action;
use function Lamb\Hooks\has;
use function Lamb\Hooks\load_modules;
use function Lamb\Hooks\reset;
use function Lamb\Route\register_app_routes;
use function Lamb\Route\register_route;

class HooksTest extends TestCase
{
    protected function setUp(): void
    {
        reset();
    }

    protected function tearDown(): void
    {
        reset();
    }

    public function testActionsRunByPriorityThenRegistrationOrder(): void
    {
        $calls = [];
        add_action('t', function (string $x) use (&$calls): void {
            $calls[] = "b$x";
        });
        add_action('t', function (string $x) use (&$calls): void {
            $calls[] = "a$x";
        }, 5);
        add_action('t', function (string $x) use (&$calls): void {
            $calls[] = "c$x";
        });

        do_action('t', '!');

        $this->assertSame(['a!', 'b!', 'c!'], $calls);
    }

    public function testFiltersThreadTheValueWithContext(): void
    {
        add_filter('f', fn(int $v, int $by): int => $v * $by);
        add_filter('f', fn(int $v): int => $v + 1);

        $this->assertSame(7, apply_filters('f', 3, 2));
        $this->assertSame('x', apply_filters('unhooked', 'x'));
    }

    public function testDoActionSafelyLogsAThrowingCallbackAndRunsTheRest(): void
    {
        $ran = false;
        add_action('s', function (): void {
            throw new \Error('broken module');
        });
        add_action('s', function () use (&$ran): void {
            $ran = true;
        });

        $log = ini_set('error_log', '/dev/null');
        try {
            $failures = \Lamb\Hooks\do_action_safely('s');
        } finally {
            if ($log !== false) {
                ini_set('error_log', $log);
            }
        }

        $this->assertSame(1, $failures);
        $this->assertTrue($ran);
    }

    public function testResetClearsOneHookOrAll(): void
    {
        add_action('a', fn() => null);
        add_action('b', fn() => null);

        reset('a');
        $this->assertFalse(has('a'));
        $this->assertTrue(has('b'));

        reset();
        $this->assertFalse(has('b'));
    }

    public function testLoadModulesRunsEachModuleRegistrar(): void
    {
        $dir = sys_get_temp_dir() . '/lamb-modules-' . bin2hex(random_bytes(4));
        mkdir("$dir/beta", 0777, true);
        mkdir("$dir/alpha", 0777, true);
        mkdir("$dir/broken", 0777, true);
        file_put_contents("$dir/beta/module.php", '<?php return fn() => \Lamb\Hooks\add_action("m", fn() => null);');
        file_put_contents("$dir/alpha/module.php", '<?php return fn() => \Lamb\Hooks\add_filter("render.test", fn($v) => $v);');
        mkdir("$dir/throws", 0777, true);
        file_put_contents("$dir/broken/module.php", '<?php return 1;');
        file_put_contents("$dir/throws/module.php", '<?php return fn() => throw new \RuntimeException("boom");');

        $log = ini_set('error_log', '/dev/null');
        try {
            $loaded = load_modules($dir);
            $again = load_modules($dir);
        } finally {
            if ($log !== false) {
                ini_set('error_log', $log);
            }
            array_map('unlink', glob("$dir/*/module.php") ?: []);
            array_map('rmdir', glob("$dir/*") ?: []);
            rmdir($dir);
        }

        $this->assertSame(['alpha', 'beta'], $loaded);
        $this->assertSame([], $again, 'A module loads at most once per process');
        $this->assertContains('alpha', \Lamb\Hooks\render_modules(), 'alpha registers a render.* hook');
        $this->assertNotContains('beta', \Lamb\Hooks\render_modules());
        $this->assertTrue(has('m'));
    }

    public function testAModuleCannotReplaceACoreRoute(): void
    {
        global $routes;
        add_action('routes.register', function (): void {
            register_route('login', 'Tests\\Unit\\hijack');
            register_route('hello', 'Tests\\Unit\\hello');
        });

        $log = ini_set('error_log', '/dev/null');
        try {
            register_app_routes('home');
        } finally {
            if ($log !== false) {
                ini_set('error_log', $log);
            }
        }

        $this->assertSame('Lamb\\Response\\redirect_login', $routes['login'][0]);
        $this->assertSame('Tests\\Unit\\hello', $routes['hello'][0]);
    }
}

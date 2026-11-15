<?php

namespace Tests\Unit;

use Lamb\Micropub\LambMicropubAdapter;
use PHPUnit\Framework\TestCase;
use ReflectionFunction;
use ReflectionFunctionAbstract;

/**
 * Keeps every function in src/micropub.php short enough to read at a glance.
 *
 * Micropub had the most fix commits of any feature in the six months before #942,
 * and its two request handlers were the longest functions in src/. A handler
 * that does everything in one body hides which step a fix belongs to, so this
 * fails the build when a function or method in that file grows past the limit.
 * Split the steps into named functions instead of raising it.
 *
 * Length counts from the line holding `function` to the closing brace.
 */
class MicropubFunctionLengthGuardTest extends TestCase
{
    private const MAX_LINES = 60;

    public function testNoFunctionInMicropubPhpIsLongerThanTheLimit(): void
    {
        $tooLong = [];
        foreach ($this->functionsDeclaredInMicropubPhp() as $name => $function) {
            $lines = $function->getEndLine() - $function->getStartLine() + 1;
            if ($lines > self::MAX_LINES) {
                $tooLong[] = sprintf('%s (%d lines)', $name, $lines);
            }
        }

        $this->assertSame(
            [],
            $tooLong,
            sprintf("src/micropub.php functions longer than %d lines:\n", self::MAX_LINES) . implode("\n", $tooLong)
        );
    }

    /**
     * The functions and the adapter's methods that src/micropub.php declares.
     *
     * @return array<string, ReflectionFunctionAbstract> Keyed by function or Class::method name.
     */
    private function functionsDeclaredInMicropubPhp(): array
    {
        $file = realpath(__DIR__ . '/../../src/micropub.php');
        $declared = [];

        foreach (get_defined_functions()['user'] as $name) {
            $function = new ReflectionFunction($name);
            if (realpath((string) $function->getFileName()) === $file) {
                $declared[$name] = $function;
            }
        }

        foreach ((new \ReflectionClass(LambMicropubAdapter::class))->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() === LambMicropubAdapter::class) {
                $declared[LambMicropubAdapter::class . '::' . $method->getName()] = $method;
            }
        }

        return $declared;
    }
}

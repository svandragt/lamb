<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * `Helper\Acceptance::haveStoredSettings()` writes the SQLite file from the test
 * process. Against an external server (release-verify.yml, `--env external`) that
 * file belongs to another user, so the write fails and breaks every later test.
 * release-verify skips the `seeds-db` group, so a test that seeds the database
 * has to be in it.
 */
class SeedsDbGroupGuardTest extends TestCase
{
    private const SEEDER = 'haveStoredSettings';
    private const GROUP = '@group seeds-db';

    /**
     * @return void
     */
    public function testTestsThatSeedTheDatabaseAreInTheSeedsDbGroup(): void
    {
        $violations = [];
        foreach (glob(codecept_root_dir('tests/Acceptance/*Cest.php')) ?: [] as $file) {
            $class = new ReflectionClass('Tests\\Acceptance\\' . basename($file, '.php'));
            $inGroup = str_contains((string) $class->getDocComment(), self::GROUP);
            foreach ($this->seedingMethods($class, $file) as $method) {
                if ($method->isPublic() && !$inGroup && !str_contains((string) $method->getDocComment(), self::GROUP)) {
                    $violations[] = $class->getShortName() . '::' . $method->getName();
                }
            }
        }

        $this->assertSame(
            [],
            $violations,
            'These tests seed the database, so add `' . self::GROUP . "` to each (or its class):\n"
                . implode("\n", $violations)
        );
    }

    /**
     * Methods that call the seeder, directly or through another method of the class.
     *
     * @param ReflectionClass<object> $class
     * @param string                  $file Source file of $class.
     *
     * @return list<ReflectionMethod>
     */
    private function seedingMethods(ReflectionClass $class, string $file): array
    {
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        $bodies = [];
        foreach ($class->getMethods() as $method) {
            if ($method->getDeclaringClass()->getName() === $class->getName()) {
                $length = $method->getEndLine() - $method->getStartLine() + 1;
                $bodies[$method->getName()] = implode("\n", array_slice($lines, $method->getStartLine() - 1, $length));
            }
        }

        $seeding = [];
        do {
            $before = count($seeding);
            foreach ($bodies as $name => $body) {
                $calls = array_merge([self::SEEDER], array_map(fn ($seeder) => '$this->' . $seeder, array_keys($seeding)));
                foreach ($calls as $call) {
                    if (str_contains($body, $call . '(')) {
                        $seeding[$name] = true;
                    }
                }
            }
        } while (count($seeding) > $before);

        return array_map(fn ($name) => $class->getMethod($name), array_keys($seeding));
    }
}

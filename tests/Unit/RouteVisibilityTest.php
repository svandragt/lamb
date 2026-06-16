<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use ReflectionFunction;

use function Lamb\Response\robots_txt_body;
use function Lamb\Route\private_routes;
use function Lamb\Route\register_app_routes;

/**
 * Route visibility is the single source of truth behind robots.txt: a route is
 * registered either publicly (register_route) or privately (register_private_route),
 * and robots.txt disallows exactly the private set.
 *
 * These tests guarantee the two can't drift apart again: every login-gated
 * handler must be registered privately (so it lands in robots.txt), and the
 * generated robots.txt must list exactly the private routes — no more, no less.
 */
class RouteVisibilityTest extends TestCase
{
    /**
     * Registers the application's routes into the (reset) global registry exactly
     * as index.php does, and returns the route table.
     *
     * @return array<string|int, array{0: string, 1: array<int, mixed>}>
     */
    private function registerRoutes(): array
    {
        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'https://example.com');
        }
        $GLOBALS['routes'] = [];
        $GLOBALS['private_routes'] = [];
        register_app_routes('home', null, null);
        return $GLOBALS['routes'];
    }

    /**
     * True if the handler's source enforces a login (calls Security\require_login()).
     */
    private function handlerRequiresLogin(string $callback): bool
    {
        if (!function_exists($callback)) {
            return false;
        }
        $reflection = new ReflectionFunction($callback);
        $file = $reflection->getFileName();
        if ($file === false) {
            return false;
        }
        $lines = file($file);
        $body = implode('', array_slice(
            $lines,
            $reflection->getStartLine() - 1,
            $reflection->getEndLine() - $reflection->getStartLine() + 1
        ));
        return str_contains($body, 'require_login(');
    }

    public function testEveryLoginGatedRouteIsPrivate(): void
    {
        $routes = $this->registerRoutes();
        $private = array_flip(private_routes());

        foreach ($routes as $action => [$callback]) {
            if (!is_string($action) || !is_string($callback)) {
                continue;
            }
            if ($this->handlerRequiresLogin($callback)) {
                $this->assertArrayHasKey(
                    $action,
                    $private,
                    "Route '$action' enforces login but is not registered as private, "
                    . 'so it would be missing from robots.txt. '
                    . 'Register it with register_private_route().'
                );
            }
        }
    }

    public function testGeneratedRobotsListsExactlyThePrivateRoutes(): void
    {
        $this->registerRoutes();

        $expected = array_map(
            static fn ($action): string => '/' . ltrim((string) $action, '/'),
            private_routes()
        );
        sort($expected);

        $disallowed = [];
        foreach (explode("\n", robots_txt_body()) as $line) {
            if (str_starts_with($line, 'Disallow: ')) {
                $disallowed[] = substr($line, strlen('Disallow: '));
            }
        }
        sort($disallowed);

        $this->assertSame($expected, $disallowed);
    }

    public function testPreviouslyMissedActionEndpointsAreNowDisallowed(): void
    {
        // These login-gated POST actions used to be omitted from the hand-kept
        // robots list; deriving from the private registry now covers them.
        $this->registerRoutes();
        $body = robots_txt_body();
        foreach (['/delete', '/restore', '/upload', '/checkbox'] as $path) {
            $this->assertStringContainsString('Disallow: ' . $path, $body);
        }
    }
}

<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Route\call_route;
use function Lamb\Route\is_reserved_route;
use function Lamb\Route\register_route;

class RouteTest extends TestCase
{
    protected function setUp(): void
    {
        global $routes;
        $routes = [];
    }

    protected function tearDown(): void
    {
        global $routes;
        $routes = [];
    }

    public function testRegisterRouteStoresCallback(): void
    {
        register_route('home', 'array_values');
        $this->assertTrue(is_reserved_route('home'));
    }

    public function testIsReservedRouteReturnsFalseForUnregisteredRoute(): void
    {
        $this->assertFalse(is_reserved_route('nonexistent'));
    }

    public function testIsReservedRouteReturnsTrueAfterRegistering(): void
    {
        register_route('about', 'array_values');
        $this->assertTrue(is_reserved_route('about'));
    }

    public function testCallRouteInvokesRegisteredCallback(): void
    {
        register_route('test', 'array_values', 'a', 'b');
        $result = call_route('test');
        $this->assertSame(['a', 'b'], $result);
    }

    public function testCallRoutePassesArgsToCallback(): void
    {
        register_route('rev', 'array_reverse', 'x', 'y');
        $result = call_route('rev');
        $this->assertSame(['y', 'x'], $result);
    }

    public function testIsReservedRouteKnowsTheAppRoutesWithoutARequest(): void
    {
        // setUp() emptied $routes, which is the state a CLI importer runs in:
        // it bootstraps the database and the config but never the router. The
        // guard used to answer "free" for every name there, so an imported
        // post could take `login` and shadow the login page.
        global $routes;
        $this->assertSame([], $routes);

        $this->assertTrue(is_reserved_route('login'));
        $this->assertTrue(is_reserved_route('feed'));
        $this->assertTrue(is_reserved_route('_cron'));
        $this->assertFalse(is_reserved_route('an-ordinary-slug'));
    }

    public function testCallRouteReturns404ArrayForUnregisteredRoute(): void
    {
        global $config;
        $config = $config ?? [];

        $result = call_route('missing-route-xyz');
        $this->assertArrayHasKey('action', $result);
        $this->assertSame('404', $result['action']);
    }
}

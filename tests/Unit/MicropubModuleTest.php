<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Hooks\apply_filters;
use function Lamb\Hooks\do_action;
use function Lamb\Hooks\reset;

/**
 * The micropub module's wiring: its routes, its Link header and its <head> link.
 */
class MicropubModuleTest extends TestCase
{
    protected function setUp(): void
    {
        reset();
        (require dirname(__DIR__, 2) . '/src/modules/micropub/module.php')();
    }

    protected function tearDown(): void
    {
        reset();
    }

    public function testRegistersBothEndpointRoutes(): void
    {
        global $routes;

        do_action('routes.register', 'home', false, false);

        $this->assertSame('Lamb\\Micropub\\respond_micropub', $routes['micropub'][0]);
        $this->assertSame('Lamb\\Micropub\\respond_micropub_media', $routes['micropub-media'][0]);
        $this->assertTrue(function_exists('Lamb\\Micropub\\respond_micropub'));
    }

    public function testAdvertisesTheEndpointInTheLinkHeaders(): void
    {
        $this->assertSame(
            ['<a>; rel="x"', '<' . ROOT_URL . '/micropub>; rel="micropub"'],
            apply_filters('http.link_headers', ['<a>; rel="x"'])
        );
    }

    public function testAdvertisesTheEndpointInTheHead(): void
    {
        ob_start();
        do_action('theme.head');

        $this->assertSame('<link rel="micropub" href="' . ROOT_URL . '/micropub">' . "\n", ob_get_clean());
    }
}

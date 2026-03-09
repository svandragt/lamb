<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Config\get_menu_slugs;

// Since we need to test a function that depends on global $config, we'll mock it or set it.
class ConfigTest extends TestCase
{
    public function testGetMenuSlugs()
    {
        global $config;
        $original_config = $config;

        $config['menu_items'] = [
            'Home' => '/',
            'About' => 'about',
            'About Us' => '/about-us',
            'Contact' => '/contact/',
            'External' => 'https://example.com',
            'Feed' => '/feed'
        ];

        $slugs = get_menu_slugs();

        $this->assertNotContains('/', $slugs);
        $this->assertContains('about', $slugs);
        $this->assertContains('about-us', $slugs);
        $this->assertContains('contact', $slugs);
        $this->assertContains('feed', $slugs);
        $this->assertNotContains('https://example.com', $slugs);
        $this->assertNotContains('', $slugs);

        $config = $original_config;
    }
}

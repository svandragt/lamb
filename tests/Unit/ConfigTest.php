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

        // Expected slugs to exclude: 'about', 'about-us', 'contact', 'feed'
        // Wait, should '/feed' be excluded? It's a reserved route.
        // The issue says: "A configuration menu item with the value that matches a page slug results correctly in the page not being shown in the timeline."
        // "Home=/" should never match slugs.
        // "About us=/about" should match a post with slug "about".

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

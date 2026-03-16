<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Theme\page_intro;
use function Lamb\Theme\page_title;
use function Lamb\Theme\site_or_page_title;
use function Lamb\Theme\site_title;

class ThemePageTest extends TestCase
{
    protected function setUp(): void
    {
        global $config, $data;
        $config['site_title'] = 'Test Blog';
        $data = [];
    }

    // site_title

    public function testSiteTitleReturnsH1HtmlByDefault(): void
    {
        $this->assertSame('<h1>Test Blog</h1>', site_title());
    }

    public function testSiteTitleReturnsPlainTextWhenTypeIsNotHtml(): void
    {
        $this->assertSame('Test Blog', site_title('text'));
    }

    // page_title

    public function testPageTitleFallsBackToSiteTitleWhenNoDataTitle(): void
    {
        $result = page_title();
        $this->assertStringContainsString('Test Blog', $result);
    }

    public function testPageTitleUsesDataTitleWhenSet(): void
    {
        global $data;
        $data['title'] = 'Custom Page';
        $this->assertSame('<h1>Custom Page</h1>', page_title());
    }

    public function testPageTitleReturnsPlainTextWhenTypeIsNotHtml(): void
    {
        global $data;
        $data['title'] = 'Custom Page';
        $this->assertSame('Custom Page', page_title('text'));
    }

    public function testPageTitleReturnsPlainSiteTitleForTextTypeWithNoDataTitle(): void
    {
        $result = page_title('text');
        $this->assertSame('Test Blog', $result);
    }

    // site_or_page_title

    public function testSiteOrPageTitleReturnsPageTitleWhenDataTitleSet(): void
    {
        global $data;
        $data['title'] = 'Page Title';
        $this->assertSame('<h1>Page Title</h1>', site_or_page_title());
    }

    public function testSiteOrPageTitleReturnsSiteTitleWhenNoDataTitle(): void
    {
        $result = site_or_page_title();
        $this->assertStringContainsString('Test Blog', $result);
    }

    public function testSiteOrPageTitlePlainTextMode(): void
    {
        global $data;
        $data['title'] = 'My Page';
        $this->assertSame('My Page', site_or_page_title('text'));
    }

    // page_intro

    public function testPageIntroReturnsEmptyStringWhenIntroNotSet(): void
    {
        $this->assertSame('', page_intro());
    }

    public function testPageIntroReturnsParagraphWhenIntroSet(): void
    {
        global $data;
        $data['intro'] = 'Welcome to the blog.';
        $this->assertSame('<p>Welcome to the blog.</p>', page_intro());
    }
}

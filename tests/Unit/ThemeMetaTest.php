<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Theme\the_opengraph;

class ThemeMetaTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::exec("DELETE FROM post");

        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'http://localhost');
        }

        global $config;
        $config = ['site_title' => 'Test Blog'];

        global $template;
        $template = 'home';

        global $data;
        $data = [];

        $_SERVER['HTTP_HOST'] = 'localhost';
    }

    protected function tearDown(): void
    {
        global $template, $data;
        $template = null;
        $data     = [];
    }

    // -------------------------------------------------------------------------
    // the_opengraph — guard: only emits output for the 'status' template
    // -------------------------------------------------------------------------

    public function testOpenGraphOutputsNothingWhenTemplateIsHome(): void
    {
        global $template;
        $template = 'home';

        ob_start();
        the_opengraph();
        $output = ob_get_clean();

        $this->assertSame('', $output);
    }

    public function testOpenGraphOutputsNothingWhenTemplateIsTag(): void
    {
        global $template;
        $template = 'tag';

        ob_start();
        the_opengraph();
        $output = ob_get_clean();

        $this->assertSame('', $output);
    }

    public function testOpenGraphOutputsNothingWhenTemplateIsSearch(): void
    {
        global $template;
        $template = 'search';

        ob_start();
        the_opengraph();
        $output = ob_get_clean();

        $this->assertSame('', $output);
    }

    // -------------------------------------------------------------------------
    // the_opengraph — output when template === 'status'
    // -------------------------------------------------------------------------

    public function testOpenGraphOutputsMetaDescriptionTagWhenTemplateIsStatus(): void
    {
        global $template, $data;
        $template = 'status';

        $bean              = R::dispense('post');
        $bean->description = 'A test post description';
        $bean->created     = date('Y-m-d H:i:s');
        $bean->updated     = date('Y-m-d H:i:s');
        R::store($bean);
        $data = ['posts' => [$bean]];

        ob_start();
        the_opengraph();
        $output = ob_get_clean();

        $this->assertStringContainsString('<meta property="description"', $output);
    }

    public function testOpenGraphOutputsOgDescriptionWhenTemplateIsStatus(): void
    {
        global $template, $data;
        $template = 'status';

        $bean              = R::dispense('post');
        $bean->description = 'My OG description';
        $bean->created     = date('Y-m-d H:i:s');
        $bean->updated     = date('Y-m-d H:i:s');
        R::store($bean);
        $data = ['posts' => [$bean]];

        ob_start();
        the_opengraph();
        $output = ob_get_clean();

        $this->assertStringContainsString('og:description', $output);
    }

    public function testOpenGraphOutputsOgTypeArticle(): void
    {
        global $template, $data;
        $template = 'status';

        $bean              = R::dispense('post');
        $bean->description = 'Test description';
        $bean->created     = date('Y-m-d H:i:s');
        $bean->updated     = date('Y-m-d H:i:s');
        R::store($bean);
        $data = ['posts' => [$bean]];

        ob_start();
        the_opengraph();
        $output = ob_get_clean();

        $this->assertStringContainsString('og:type', $output);
        $this->assertStringContainsString('article', $output);
    }

    public function testOpenGraphIncludesTitleTagsWhenBeanHasTitle(): void
    {
        global $template, $data;
        $template = 'status';

        $bean              = R::dispense('post');
        $bean->title       = 'My Post Title';
        $bean->description = 'Description here';
        $bean->created     = date('Y-m-d H:i:s');
        $bean->updated     = date('Y-m-d H:i:s');
        R::store($bean);
        $data = ['posts' => [$bean]];

        ob_start();
        the_opengraph();
        $output = ob_get_clean();

        $this->assertStringContainsString('og:title', $output);
        $this->assertStringContainsString('My Post Title', $output);
    }

    public function testOpenGraphIncludesTwitterCardTag(): void
    {
        global $template, $data;
        $template = 'status';

        $bean              = R::dispense('post');
        $bean->description = 'Twitter description';
        $bean->created     = date('Y-m-d H:i:s');
        $bean->updated     = date('Y-m-d H:i:s');
        R::store($bean);
        $data = ['posts' => [$bean]];

        ob_start();
        the_opengraph();
        $output = ob_get_clean();

        $this->assertStringContainsString('twitter:card', $output);
    }

    public function testOpenGraphEscapesDescriptionContent(): void
    {
        global $template, $data;
        $template = 'status';

        $bean              = R::dispense('post');
        $bean->description = 'Description with <script>alert(1)</script>';
        $bean->created     = date('Y-m-d H:i:s');
        $bean->updated     = date('Y-m-d H:i:s');
        R::store($bean);
        $data = ['posts' => [$bean]];

        ob_start();
        the_opengraph();
        $output = ob_get_clean();

        $this->assertStringNotContainsString('<script>', $output);
    }
}

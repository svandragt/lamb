<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Theme\the_meta_description;
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

    public function testOpenGraphDoesNotOutputNameDescriptionTag(): void
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

        $this->assertStringNotContainsString('<meta name="description"', $output);
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

    // -------------------------------------------------------------------------
    // the_meta_description
    // -------------------------------------------------------------------------

    public function testMetaDescriptionOutputsNameDescriptionForStatusTemplate(): void
    {
        global $template, $data;
        $template = 'status';

        $bean              = R::dispense('post');
        $bean->description = 'Hello world post';
        $bean->created     = date('Y-m-d H:i:s');
        $bean->updated     = date('Y-m-d H:i:s');
        R::store($bean);
        $data = ['posts' => [$bean]];

        ob_start();
        the_meta_description();
        $output = ob_get_clean();

        $this->assertStringContainsString('<meta name="description"', $output);
        $this->assertStringContainsString('Hello world post', $output);
    }

    public function testMetaDescriptionOutputsSiteDescriptionForHomeTemplate(): void
    {
        global $template, $config;
        $template = 'home';
        $config['site_description'] = 'A personal microblog';

        ob_start();
        the_meta_description();
        $output = ob_get_clean();

        $this->assertStringContainsString('<meta name="description"', $output);
        $this->assertStringContainsString('A personal microblog', $output);
    }

    public function testMetaDescriptionOutputsNothingWhenNoDescriptionAvailable(): void
    {
        global $template, $config;
        $template = 'home';
        unset($config['site_description']);

        ob_start();
        the_meta_description();
        $output = ob_get_clean();

        $this->assertSame('', $output);
    }

    public function testMetaDescriptionEscapesContent(): void
    {
        global $template, $config;
        $template = 'home';
        $config['site_description'] = 'A blog with <script>bad</script>';

        ob_start();
        the_meta_description();
        $output = ob_get_clean();

        $this->assertStringNotContainsString('<script>', $output);
    }

    // -------------------------------------------------------------------------
    // the_opengraph — embed image selection: post image → web-root default → shipped
    // -------------------------------------------------------------------------

    public function testOpenGraphUsesFirstEmbeddedPostImage(): void
    {
        $url = 'http://localhost/assets/2024/06/example.webp';
        $output = $this->renderStatus([
            'transformed' => '<p>Hi</p>'
                . '<img src="' . $url . '" alt="x">'
                . '<img src="http://localhost/assets/2024/06/second.webp">',
        ]);

        $this->assertStringContainsString('property="og:image" content="' . $url . '"', $output);
        $this->assertStringContainsString('property="twitter:image" content="' . $url . '"', $output);
        $this->assertStringContainsString('content="summary_large_image"', $output);
        $this->assertStringNotContainsString('og-image-lamb.webp', $output);
    }

    public function testOpenGraphUsesWebRootOgImageConventionWhenPostHasNoImage(): void
    {
        $webRoot = $this->resetWebRoot();
        // A real 1x1 PNG so getimagesize() can read its dimensions/type.
        file_put_contents(
            $webRoot . '/og-image.png',
            base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII=')
        );

        $output = $this->renderStatus(['transformed' => '<p>No image here</p>']);

        @unlink($webRoot . '/og-image.png');

        $this->assertStringContainsString(ROOT_URL . '/og-image.png', $output);
        $this->assertStringNotContainsString('/images/og-image-lamb.webp', $output);
        $this->assertStringContainsString('og:image:width', $output);
        $this->assertStringContainsString('image/png', $output);
    }

    public function testOpenGraphFallsBackToShippedDefaultWhenNoConvention(): void
    {
        $this->resetWebRoot();

        $output = $this->renderStatus(['transformed' => '<p>Just text, no image</p>']);

        $this->assertStringContainsString(ROOT_URL . '/images/og-image-lamb.webp', $output);
        $this->assertStringContainsString('content="summary"', $output);
    }

    /**
     * Ensures ROOT_DIR points at a (temp) web root and clears any og-image.* convention
     * files from it. ROOT_DIR is a process-wide constant that other unit tests may have
     * already pointed at their own temp dir, so we operate on the actual ROOT_DIR rather
     * than assume our define() wins.
     */
    private function resetWebRoot(): string
    {
        if (!defined('ROOT_DIR')) {
            define('ROOT_DIR', sys_get_temp_dir() . '/lamb_og_test_' . getmypid());
        }
        $dir = ROOT_DIR;
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        foreach (['png', 'jpg', 'jpeg', 'webp', 'gif'] as $ext) {
            @unlink($dir . '/og-image.' . $ext);
        }
        return $dir;
    }

    /**
     * Renders the_opengraph() for a status post and returns the emitted markup.
     *
     * @param array $fields Post bean fields (transformed, title, description).
     */
    private function renderStatus(array $fields = []): string
    {
        global $template, $data;
        $template = 'status';

        $bean              = R::dispense('post');
        $bean->title       = $fields['title'] ?? 'Image Post';
        $bean->description = $fields['description'] ?? 'A description';
        $bean->transformed = $fields['transformed'] ?? '';
        $bean->created     = date('Y-m-d H:i:s');
        $bean->updated     = date('Y-m-d H:i:s');
        R::store($bean);
        $data = ['posts' => [$bean]];

        ob_start();
        the_opengraph();
        return ob_get_clean();
    }
}

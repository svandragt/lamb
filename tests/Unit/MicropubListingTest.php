<?php

namespace Tests\Unit;

use Lamb\Micropub\LambMicropubAdapter;
use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

/**
 * Micropub creates of the `h-product` type: a listing flows through the same
 * pipeline as any other post, with its detail fields written to front matter.
 */
class MicropubListingTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);

        global $config;
        $config = $config ?? [];
        $config['site_title'] = $config['site_title'] ?? 'Test Blog';

        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'http://localhost');
        }
        if (!defined('ROOT_DIR')) {
            define('ROOT_DIR', sys_get_temp_dir() . '/lamb_test');
        }
    }

    /**
     * @param array<string, mixed> $properties
     */
    private function createProduct(array $properties): \RedBeanPHP\OODBBean
    {
        $adapter = new LambMicropubAdapter();
        $url = $adapter->createCallback(['type' => ['h-product'], 'properties' => $properties]);
        $this->assertIsString($url);

        $bean = \Lamb\find_post_by_path(parse_url($url, PHP_URL_PATH) ?: '');
        $this->assertNotNull($bean, 'the created post should be loadable from its permalink');

        return $bean;
    }

    public function testAProductCreateIsStoredAsAListing(): void
    {
        $bean = $this->createProduct([
            'name'    => ['Blue Wool Jumper'],
            'content' => ['Warm, barely worn.'],
        ]);

        $this->assertSame('listing', $bean->post_type);
        $this->assertStringContainsString('post-type: listing', $bean->body);
    }

    public function testAnEntryCreateIsNotAListing(): void
    {
        $adapter = new LambMicropubAdapter();
        $adapter->createCallback([
            'type'       => ['h-entry'],
            'properties' => ['content' => ['An ordinary note about a listing']],
        ]);

        $post = R::findOne('post', ' body = ? ', ['An ordinary note about a listing']);
        $this->assertNotNull($post);
        $this->assertSame('', $post->post_type);
    }

    public function testListingDetailsAreWrittenToFrontMatter(): void
    {
        $bean = $this->createProduct([
            'name'      => ['Blue Wool Jumper'],
            'content'   => ['Warm, barely worn.'],
            'price'     => ['25.00'],
            'currency'  => ['EUR'],
            'condition' => ['used'],
            'contact'   => ['sander@example.com'],
        ]);

        $this->assertSame(
            ['price' => '25.00', 'currency' => 'EUR', 'condition' => 'used', 'contact' => 'sander@example.com'],
            \Lamb\Listing\listing_fields($bean)
        );
    }

    public function testListingDetailsAreNotDumpedAsAJsonCodeBlock(): void
    {
        // buildExtraProperties() serialises properties it doesn't recognise
        // into a visible ```json block; the listing fields are front matter, so
        // they must not also appear in the rendered body.
        $bean = $this->createProduct([
            'name'    => ['Blue Wool Jumper'],
            'content' => ['Warm, barely worn.'],
            'price'   => ['25.00'],
        ]);

        $this->assertStringNotContainsString('```json', $bean->body);
    }

    public function testSourceQueryReportsAListingAsAProduct(): void
    {
        $bean = $this->createProduct([
            'name'    => ['Blue Wool Jumper'],
            'content' => ['Warm, barely worn.'],
            'price'   => ['25.00'],
        ]);

        $adapter = new LambMicropubAdapter();
        $source = $adapter->sourceQueryCallback(\Lamb\permalink($bean));

        $this->assertSame(['h-product'], $source['type']);
        $this->assertSame(['25.00'], $source['properties']['price']);
    }

    public function testAnUnpricedProductStillCreatesAListing(): void
    {
        $bean = $this->createProduct(['content' => ['Free sofa, collection only.']]);

        $this->assertSame('listing', $bean->post_type);
        $this->assertSame([], \Lamb\Listing\listing_fields($bean));
    }
}

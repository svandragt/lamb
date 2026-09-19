<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Listing\is_listing;
use function Lamb\Listing\listing_fields;
use function Lamb\Listing\normalize_post_type;
use function Lamb\Listing\schema_org;
use function Lamb\Post\populate_bean;

/**
 * The listing post type: how `post-type: listing` front matter reaches the
 * bean, which detail fields survive validation, and the schema.org Product a
 * listing renders as.
 */
class ListingTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);

        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'http://localhost');
        }
    }

    private function listingBody(string $extra = ''): string
    {
        return "---\ntitle: Blue Wool Jumper\npost-type: listing\n"
            . "price: '25.00'\ncurrency: eur\ncondition: Used\ncontact: sander@example.com\n"
            . $extra
            . "---\n\nWarm, barely worn. #knitwear\n";
    }

    // --- normalize_post_type ---

    public function testNormalizePostTypeAcceptsListing(): void
    {
        $this->assertSame('listing', normalize_post_type('listing'));
    }

    public function testNormalizePostTypeIsCaseAndSpaceInsensitive(): void
    {
        $this->assertSame('listing', normalize_post_type('  Listing '));
    }

    public function testNormalizePostTypeRejectsAnUnknownType(): void
    {
        // The column drives which posts /listings selects, so an unrecognised
        // value must fall back to an ordinary post rather than create a type.
        $this->assertSame('', normalize_post_type('h-product'));
        $this->assertSame('', normalize_post_type(null));
        $this->assertSame('', normalize_post_type(true));
    }

    // --- front matter → bean ---

    public function testFrontMatterPostTypeReachesTheBean(): void
    {
        $bean = populate_bean($this->listingBody());
        $this->assertSame('listing', $bean->post_type);
        $this->assertTrue(is_listing($bean));
    }

    public function testAPostWithoutPostTypeIsNotAListing(): void
    {
        $bean = populate_bean("---\ntitle: Just a page\n---\n\nHello.\n");
        $this->assertSame('', $bean->post_type);
        $this->assertFalse(is_listing($bean));
    }

    public function testRemovingPostTypeOnEditClearsIt(): void
    {
        $bean = populate_bean($this->listingBody());
        $this->assertSame('listing', $bean->post_type);

        populate_bean("---\ntitle: Blue Wool Jumper\n---\n\nKept it after all.\n", null, null, $bean);
        $this->assertSame('', $bean->post_type);
    }

    public function testUnderscoreSpellingOfPostTypeWorks(): void
    {
        // normalize_matter_keys() folds underscores onto dashes, so both
        // spellings have to land on the same column.
        $bean = populate_bean("---\npost_type: listing\n---\n\nFor sale.\n");
        $this->assertSame('listing', $bean->post_type);
    }

    // --- listing_fields ---

    public function testListingFieldsAreNormalised(): void
    {
        $bean = populate_bean($this->listingBody());

        $this->assertSame(
            ['price' => '25.00', 'currency' => 'EUR', 'condition' => 'used', 'contact' => 'sander@example.com'],
            listing_fields($bean)
        );
    }

    public function testMalformedListingFieldsAreDropped(): void
    {
        $body = "---\ntitle: Bike\npost-type: listing\n"
            . "price: best offer\ncurrency: euros\ncondition: mint\n---\n\nA bike.\n";
        $bean = populate_bean($body);

        $this->assertSame([], listing_fields($bean));
    }

    public function testACommaDecimalPriceIsNormalisedToAPoint(): void
    {
        $bean = populate_bean("---\npost-type: listing\nprice: '25,50'\n---\n\nA thing.\n");
        $this->assertSame('25.50', listing_fields($bean)['price']);
    }

    // --- schema.org ---

    public function testSchemaOrgIsNullForANonListing(): void
    {
        $bean = populate_bean("---\ntitle: Just a page\n---\n\nHello.\n");
        $this->assertNull(schema_org($bean, []));
    }

    public function testSchemaOrgDescribesTheListingAsAProduct(): void
    {
        $bean = populate_bean($this->listingBody());
        $bean->id = 7;

        $schema = schema_org($bean, ['author_name' => 'Sander']);

        $this->assertSame('https://schema.org', $schema['@context']);
        $this->assertSame('Product', $schema['@type']);
        $this->assertSame('Blue Wool Jumper', $schema['name']);
        $this->assertSame('https://schema.org/UsedCondition', $schema['itemCondition']);
        $this->assertSame(['knitwear'], $schema['category']);
        $this->assertSame('25.00', $schema['offers']['price']);
        $this->assertSame('EUR', $schema['offers']['priceCurrency']);
        $this->assertSame('https://schema.org/InStock', $schema['offers']['availability']);
        $this->assertSame(['@type' => 'Person', 'name' => 'Sander'], $schema['offers']['seller']);
    }

    public function testSchemaOrgOmitsTheOfferWhenNoPriceIsGiven(): void
    {
        $bean = populate_bean("---\ntitle: Free sofa\npost-type: listing\n---\n\nCollection only.\n");

        $schema = schema_org($bean, []);

        $this->assertSame('Product', $schema['@type']);
        $this->assertArrayNotHasKey('offers', $schema);
    }

    public function testSchemaOrgUsesTheDescriptionWhenTheListingHasNoTitle(): void
    {
        $bean = populate_bean("---\npost-type: listing\nprice: '5'\n---\n\nA box of records.\n");

        $schema = schema_org($bean, []);

        $this->assertSame('A box of records.', $schema['name']);
    }
}

<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Post\populate_bean;
use function Lamb\Theme\listing_details;
use function Lamb\Theme\the_schema_org;

/**
 * What a listing looks like on its own page: the schema.org Product a crawler
 * reads, and the price/condition block a person reads.
 */
class ListingRenderTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        R::exec('DELETE FROM post');

        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'http://localhost');
        }

        global $config;
        $config = ['site_title' => 'Test Blog', 'author_name' => 'Sander'];

        global $template;
        $template = 'status';

        global $data;
        $data = [];
    }

    protected function tearDown(): void
    {
        global $template, $data;
        $template = null;
        $data = [];
    }

    private function renderSchema(string $body): string
    {
        global $data;
        $bean = populate_bean($body);
        $bean->id = 12;
        $data = ['posts' => [$bean]];

        ob_start();
        the_schema_org();

        return (string) ob_get_clean();
    }

    private function listingBody(): string
    {
        return "---\ntitle: Blue Wool Jumper\npost-type: listing\n"
            . "price: '25.00'\ncurrency: EUR\ncondition: used\ncontact: sander@example.com\n"
            . "---\n\nWarm, barely worn.\n";
    }

    // --- JSON-LD ---

    public function testAListingEmitsAProductJsonLdBlock(): void
    {
        $html = $this->renderSchema($this->listingBody());

        $this->assertStringContainsString('<script type="application/ld+json">', $html);

        preg_match('#<script type="application/ld\+json">(.*)</script>#s', $html, $m);
        $schema = json_decode($m[1], true);

        $this->assertSame('Product', $schema['@type']);
        $this->assertSame('Blue Wool Jumper', $schema['name']);
        $this->assertSame('25.00', $schema['offers']['price']);
        $this->assertSame('EUR', $schema['offers']['priceCurrency']);
    }

    public function testAnOrdinaryPostEmitsNoJsonLd(): void
    {
        $this->assertSame('', $this->renderSchema("---\ntitle: A page\n---\n\nHello.\n"));
    }

    public function testNoJsonLdOutsideAPostPage(): void
    {
        global $template;
        $template = 'home';

        $this->assertSame('', $this->renderSchema($this->listingBody()));
    }

    public function testMarkupInAListingCannotCloseTheScriptElement(): void
    {
        // A title is author- or Micropub-client-supplied text landing inside a
        // <script>, where htmlspecialchars() does not apply; an unescaped `<`
        // would end the element and turn the rest into live markup.
        $body = "---\ntitle: \"</script><img src=x onerror=alert(1)>\"\npost-type: listing\n---\n\nFor sale.\n";

        $html = $this->renderSchema($body);

        $this->assertStringNotContainsString('</script><img', $html);
        $this->assertStringContainsString('<', $html);
        // Exactly one closing tag: the one this helper wrote.
        $this->assertSame(1, substr_count($html, '</script>'));
    }

    // --- the human-readable block ---

    public function testListingDetailsRenderPriceConditionAndContact(): void
    {
        $html = listing_details(populate_bean($this->listingBody()));

        $this->assertStringContainsString('<dd class="p-price">EUR 25.00</dd>', $html);
        $this->assertStringContainsString('<dd class="p-condition">used</dd>', $html);
        $this->assertStringContainsString('sander@example.com', $html);
    }

    public function testListingDetailsAreEmptyForAnOrdinaryPost(): void
    {
        $this->assertSame('', listing_details(populate_bean("---\ntitle: A page\n---\n\nHello.\n")));
    }

    public function testListingDetailsAreEmptyWhenNoDetailsAreGiven(): void
    {
        $bean = populate_bean("---\ntitle: Free sofa\npost-type: listing\n---\n\nCollection only.\n");

        $this->assertSame('', listing_details($bean));
    }

    public function testListingDetailsEscapeTheContactLine(): void
    {
        $body = "---\npost-type: listing\ncontact: \"<b>me</b>\"\n---\n\nFor sale.\n";

        $this->assertStringContainsString('&lt;b&gt;me&lt;/b&gt;', listing_details(populate_bean($body)));
    }
}

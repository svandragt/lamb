<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\Listing\offer;
use function Lamb\Listing\schema_org;
use function Lamb\Listing\validate;
use function Lamb\Post\populate_bean;
use function Lamb\Theme\listing_validation;

/**
 * The author's check that a listing says what they meant it to say.
 *
 * A listing is written in a plain textarea, so a mistyped currency or
 * condition is dropped in silence — the page still looks right. validate()
 * reports what the structured data actually ended up claiming.
 */
class ListingValidationTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);

        if (!defined('ROOT_URL')) {
            define('ROOT_URL', 'https://example.com');
        }

        global $config, $template, $data;
        $config = ['site_title' => 'Test Blog', 'author_name' => 'Sander'];
        $template = 'status';
        $data = [];
        $_SESSION = [];
    }

    protected function tearDown(): void
    {
        global $template, $data;
        $template = null;
        $data = [];
        $_SESSION = [];
    }

    /**
     * @return list<string>
     */
    private function messages(string $body): array
    {
        return array_column(validate(populate_bean($body)), 'message');
    }

    private function joined(string $body): string
    {
        return implode(' | ', $this->messages($body));
    }

    // --- rejected values ---

    public function testAMistypedCurrencyIsReported(): void
    {
        $out = $this->joined("---\ntitle: Jumper\npost-type: listing\nprice: '25'\ncurrency: euros\n---\n\nWarm.\n");

        $this->assertStringContainsString('currency', $out);
        $this->assertStringContainsString('euros', $out);
    }

    public function testAMistypedConditionIsReportedWithTheAllowedValues(): void
    {
        $out = $this->joined("---\ntitle: Jumper\npost-type: listing\ncondition: mint\n---\n\nWarm.\n");

        $this->assertStringContainsString('mint', $out);
        $this->assertStringContainsString('used', $out, 'the author needs to be told what is allowed');
    }

    public function testAnUnparseablePriceIsReported(): void
    {
        $out = $this->joined("---\ntitle: Jumper\npost-type: listing\nprice: best offer\n---\n\nWarm.\n");

        $this->assertStringContainsString('price', $out);
        $this->assertStringContainsString('best offer', $out);
    }

    // --- offer completeness ---

    public function testAPriceWithNoCurrencyIsAnError(): void
    {
        // The pair is what makes an offer meaningful: "25.00" of unspecified
        // money is not a price a buyer or an aggregator can act on.
        $issues = validate(populate_bean("---\ntitle: Jumper\npost-type: listing\nprice: '25'\n---\n\nWarm.\n"));

        $this->assertNotSame([], $issues);
        $this->assertSame('error', $issues[0]['level']);
        $this->assertStringContainsString('currency', $issues[0]['message']);
    }

    public function testAnInvalidOfferIsNotPublished(): void
    {
        // Reporting the problem is not enough — a priced offer with no currency
        // must not reach the JSON-LD at all, or the site publishes a claim it
        // cannot substantiate.
        $bean = populate_bean("---\ntitle: Jumper\npost-type: listing\nprice: '25'\n---\n\nWarm.\n");

        global $config;
        $schema = schema_org($bean, $config);

        $this->assertSame('Product', $schema['@type']);
        $this->assertArrayNotHasKey('offers', $schema);
    }

    public function testOfferReturnsNullWithoutACurrency(): void
    {
        $this->assertNull(offer(['price' => '25'], ROOT_URL . '/x', []));
    }

    public function testACompleteListingHasNothingToReport(): void
    {
        $body = "---\ntitle: Blue Wool Jumper\npost-type: listing\n"
            . "price: '25.00'\ncurrency: EUR\ncondition: used\ncontact: me@example.com\n---\n\nWarm.\n";

        $this->assertSame([], validate(populate_bean($body)));
    }

    public function testAnUnpricedListingIsValidNotAnError(): void
    {
        // "Free to a good home" is a legitimate listing, not a mistake.
        $issues = validate(populate_bean("---\ntitle: Free sofa\npost-type: listing\n---\n\nCollection only.\n"));

        $this->assertSame([], array_filter($issues, fn(array $i) => $i['level'] === 'error'));
    }

    public function testANamelessListingIsReported(): void
    {
        $out = $this->joined("---\npost-type: listing\nprice: '5'\ncurrency: GBP\n---\n\n\n");

        $this->assertStringContainsString('name', strtolower($out));
    }

    public function testAnOrdinaryPostIsNotValidated(): void
    {
        $this->assertSame([], validate(populate_bean("---\ntitle: A page\n---\n\nHello.\n")));
    }

    // --- what the author sees ---

    public function testTheAuthorSeesTheProblemsOnTheirOwnListing(): void
    {
        $_SESSION[SESSION_LOGIN] = true;
        $bean = populate_bean("---\ntitle: Jumper\npost-type: listing\nprice: '25'\ncurrency: euros\n---\n\nWarm.\n");

        $html = listing_validation($bean);

        $this->assertStringContainsString('euros', $html);
        $this->assertStringContainsString('listing-validation', $html);
    }

    public function testAVisitorSeesNothing(): void
    {
        $bean = populate_bean("---\ntitle: Jumper\npost-type: listing\nprice: '25'\ncurrency: euros\n---\n\nWarm.\n");

        $this->assertSame('', listing_validation($bean));
    }

    public function testNothingIsShownForAValidListing(): void
    {
        $_SESSION[SESSION_LOGIN] = true;
        $body = "---\ntitle: Jumper\npost-type: listing\nprice: '25'\ncurrency: EUR\n---\n\nWarm.\n";

        $this->assertSame('', listing_validation(populate_bean($body)));
    }

    public function testTheReportEscapesTheOffendingValue(): void
    {
        // The value is echoed back to the author, and it comes from the post
        // body — which a Micropub client may have written.
        $_SESSION[SESSION_LOGIN] = true;
        $body = "---\ntitle: Jumper\npost-type: listing\ncondition: \"<img src=x>\"\n---\n\nWarm.\n";

        $html = listing_validation(populate_bean($body));

        $this->assertStringNotContainsString('<img src=x>', $html);
        $this->assertStringContainsString('&lt;img', $html);
    }
}

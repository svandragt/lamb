<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

class WebmentionCest
{
    public function homepageHtmlContainsWebmentionLink(AcceptanceTester $I)
    {
        $I->amOnPage('/');
        $I->seeElement('link[rel="webmention"]');
    }

    public function endpointRejectsGet(AcceptanceTester $I)
    {
        $I->amOnPage('/webmention');
        $I->seeResponseCodeIs(405);
    }

    public function endpointRejectsMissingParams(AcceptanceTester $I)
    {
        $I->sendAjaxPostRequest('/webmention', []);
        $I->seeResponseCodeIs(400);
    }

    public function endpointRejectsForeignTarget(AcceptanceTester $I)
    {
        $I->sendAjaxPostRequest('/webmention', [
            'source' => 'https://other.example/reply',
            'target' => 'https://not-this-site.example/status/1',
        ]);
        $I->seeResponseCodeIs(400);
    }
}

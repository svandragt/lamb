<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

class MicropubDiscoveryCest
{
    public function homepageHtmlContainsMicropubLink(AcceptanceTester $I)
    {
        $I->amOnPage('/');
        $I->seeElement('link[rel="micropub"]');
    }

    public function homepageHtmlContainsAuthorizationEndpoint(AcceptanceTester $I)
    {
        $I->amOnPage('/');
        $I->seeElement('link[rel="authorization_endpoint"]');
    }

    public function homepageHtmlContainsTokenEndpoint(AcceptanceTester $I)
    {
        $I->amOnPage('/');
        $I->seeElement('link[rel="token_endpoint"]');
    }
}

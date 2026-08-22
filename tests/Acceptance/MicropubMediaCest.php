<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

/**
 * The Micropub media endpoint (src/micropub.php:respond_micropub_media) had no
 * acceptance coverage at all before this. Exercising the full authenticated
 * upload path would need a stub IndieAuth token-introspection endpoint (it
 * calls out to a real `token_endpoint` over HTTP), so this covers only the
 * bearer-token gate, which every request hits before any network call is made.
 */
class MicropubMediaCest
{
    public function requestsWithNoBearerTokenAreRejected(AcceptanceTester $I): void
    {
        $I->sendAjaxPostRequest('/micropub-media', []);
        $I->seeResponseCodeIs(401);
        $I->seeResponseHeaderContains('WWW-Authenticate', 'Bearer');
        $I->seeResponseContains('"error":"unauthorized"');
    }

    public function requestsWithNoBearerTokenGetNoLocationHeader(AcceptanceTester $I): void
    {
        $I->sendAjaxPostRequest('/micropub-media', []);
        $I->dontSeeResponseHeader('Location');
    }
}

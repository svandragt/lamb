<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

class StatusNotFoundCest
{
    public function nonExistentStatusIdReturns404(AcceptanceTester $I)
    {
        $I->amOnPage('/status/99999999999');
        $I->seeResponseCodeIs(404);
    }

    public function nonExistentStatusIdShowsNoFatalError(AcceptanceTester $I)
    {
        $I->amOnPage('/status/99999999999');
        $I->dontSee('Fatal error');
        $I->dontSee('Deprecated:');
    }

    /**
     * @group seeds-db
     */
    public function theRemoved404FallbackSettingNoLongerRedirects(AcceptanceTester $I)
    {
        $I->amOnPage('/');
        $I->haveStoredSettings("404_fallback = http://127.0.0.1:1\n");

        $I->amOnPage('/status/99999999999');
        $I->seeResponseCodeIs(404);
        $I->seeCurrentUrlEquals('/status/99999999999');
    }
}

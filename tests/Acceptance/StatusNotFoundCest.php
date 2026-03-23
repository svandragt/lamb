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
}

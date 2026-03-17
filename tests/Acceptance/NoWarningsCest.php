<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

class NoWarningsCest
{
    public function homepageHasNoWarnings(AcceptanceTester $I)
    {
        $I->amOnPage('/');
        $I->dontSee('Warning:');
        $I->dontSee('Notice:');
        $I->dontSee('Deprecated:');
    }
}

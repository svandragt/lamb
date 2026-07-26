<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

class UploadCest
{
    // respond_upload

    public function testUploadRequiresLogin(AcceptanceTester $I): void
    {
        // require_login() runs before the request is inspected, so an anonymous
        // caller is bounced to the login page rather than being told whether the
        // upload it sent was well-formed.
        $I->amOnPage('/upload');
        $I->seeInCurrentUrl('/login');
    }

    public function testUploadDoesNotRevealRequestValidityToAnonymousCallers(AcceptanceTester $I): void
    {
        $I->amOnPage('/upload');
        $I->dontSee('No files uploaded');
    }

    public function testUploadWithoutFilesHasNoPhpErrors(AcceptanceTester $I): void
    {
        $I->amOnPage('/upload');
        $I->dontSee('Fatal error');
        $I->dontSee('Warning:');
    }
}

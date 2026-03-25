<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

class UploadCest
{
    // respond_upload

    public function testUploadWithoutFilesReturns400(AcceptanceTester $I): void
    {
        // A GET request carries no $_FILES, so the guard fires immediately
        $I->amOnPage('/upload');
        $I->seeResponseCodeIs(400);
    }

    public function testUploadWithoutFilesReturnsErrorBody(AcceptanceTester $I): void
    {
        $I->amOnPage('/upload');
        $I->see('No files uploaded');
    }

    public function testUploadWithoutFilesHasNoPhpErrors(AcceptanceTester $I): void
    {
        $I->amOnPage('/upload');
        $I->dontSee('Fatal error');
        $I->dontSee('Warning:');
    }
}

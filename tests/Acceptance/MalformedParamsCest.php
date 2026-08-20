<?php

namespace Tests\Acceptance;

use Tests\Support\AcceptanceTester;

/**
 * Every request parameter can arrive as an array (`?s[]=x`), and PHP 8 turns
 * the mismatch into an uncaught TypeError at the first string-typed sink. Each
 * of these used to be a 500 an anonymous visitor could ask for — one of them
 * only on the branch that checks a preview token, which made it an oracle for
 * whether a hidden post exists.
 */
class MalformedParamsCest
{
    private function login(AcceptanceTester $I): void
    {
        $I->amOnPage('/login');
        $I->fillField('password', $_ENV['LAMB_TEST_PASSWORD']);
        $I->click('Log in');
    }

    public function anArraySearchTermDoesNotCrash(AcceptanceTester $I)
    {
        $I->amOnPage('/search?s[]=x');
        $I->seeResponseCodeIs(200);
        $I->dontSee('Fatal error');
    }

    public function anArrayPreviewTokenLooksLikeNoToken(AcceptanceTester $I)
    {
        $this->login($I);
        $I->amOnPage('/');
        $I->fillField('contents', "---\ntitle: Hidden Draft\nslug: hidden-draft\ndraft: true\n---\nSecret body.");
        $I->click('Create post');
        $I->amOnPage('/logout');

        // A hidden post and a nonexistent one must answer identically: the
        // crash used to happen only for the one that exists.
        $I->amOnPage('/hidden-draft?preview[]=x');
        $I->seeResponseCodeIs(404);
        $I->dontSee('Secret body.');

        $I->amOnPage('/no-such-post?preview[]=x');
        $I->seeResponseCodeIs(404);
    }

    public function anArrayPreviewTokenOnAStatusUrlDoesNotCrash(AcceptanceTester $I)
    {
        $this->login($I);
        $I->amOnPage('/');
        $I->fillField('contents', "---\ncreated: 2099-01-01 00:00:00\n---\n\nScheduled body.");
        $I->click('Create post');
        $I->amOnPage('/logout');

        // Whatever /status/1 turns out to be here, an array token must read as
        // "no token" rather than fataling.
        $I->amOnPage('/status/1?preview[]=x');
        $I->seeResponseCodeIs(404);
        $I->dontSee('Fatal error');
    }

    public function anArrayPasswordDoesNotCrashTheLogin(AcceptanceTester $I)
    {
        $I->amOnPage('/login');
        $token = $I->grabAttributeFrom('input[name=csrf]', 'value');

        $I->sendAjaxPostRequest('/login', [
            'csrf'     => $token,
            'submit'   => 'Log in',
            'password' => ['x'],
        ]);

        $I->seeResponseCodeIs(200);
        $I->dontSee('Fatal error');
    }

    public function anArrayPreloadTextDoesNotCrashTheEntryForm(AcceptanceTester $I)
    {
        $this->login($I);
        $I->amOnPage('/?text[]=x');
        $I->seeResponseCodeIs(200);
        $I->dontSee('Fatal error');
    }
}

<?php

namespace Tests\Acceptance;

use Codeception\Example;
use Tests\Support\AcceptanceTester;

/**
 * The `<meta name="author">` tag names the configured author, and is left out
 * when no author name is set rather than printed with an empty content.
 */
class AuthorMetaCest
{
    /**
     * @return array<string, array{theme: string}>
     */
    protected function themeProvider(): array
    {
        return [
            'base theme' => ['theme' => 'base'],
            '2026 theme' => ['theme' => '2026'],
        ];
    }

    /**
     * @dataProvider themeProvider
     * @group seeds-db
     */
    public function authorMetaNamesTheAuthorWhenOneIsSet(AcceptanceTester $I, Example $example): void
    {
        $I->amOnPage('/');
        $I->haveStoredSettings('theme = ' . $example['theme'] . "\nauthor_name = Ada Lovelace\n");

        $I->amOnPage('/');
        $I->seeElement('meta[name=author][content="Ada Lovelace"]');
    }

    /**
     * @dataProvider themeProvider
     * @group seeds-db
     */
    public function authorMetaIsLeftOutWhenNoAuthorNameIsSet(AcceptanceTester $I, Example $example): void
    {
        $I->amOnPage('/');
        $I->haveStoredSettings('theme = ' . $example['theme'] . "\n");

        $I->amOnPage('/');
        $I->dontSeeElement('meta[name=author]');
    }

    /**
     * @dataProvider themeProvider
     * @group seeds-db
     */
    public function authorMetaIsLeftOutWhenTheAuthorNameIsOnlyWhitespace(AcceptanceTester $I, Example $example): void
    {
        $I->amOnPage('/');
        $I->haveStoredSettings('theme = ' . $example['theme'] . "\nauthor_name = \"   \"\n");

        $I->amOnPage('/');
        $I->dontSeeElement('meta[name=author]');
    }
}

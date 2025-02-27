<?php

namespace Tests\Functional;

use Lamb\LambDown;
use PHPUnit\Framework\Assert;
use Tests\Support\FunctionalTester;

function parse_markdown(string $text): string
{
    $parser = new LambDown();
    $parser->setSafeMode(true);
    return $parser->text($text);
}

class FirstCest
{
    public function _before(FunctionalTester $I)
    {
    }

    // tests
    public function tryToRenderStrings(FunctionalTester $I)
    {
        //  Text test
        $text = "Hello, World!";
        $expectedOutput = "<p>Hello, World!</p>";
        Assert::assertEquals($expectedOutput, parse_markdown($text));

        // Basic markdown
        $text = "_Hello, World!_";
        $expectedOutput = "<p><em>Hello, World!</em></p>";
        Assert::assertEquals($expectedOutput, parse_markdown($text));

        // Fake markdown
        $text = "#Hello, World! #til";
        $expectedOutput = "<p>#Hello, World! #til</p>";
        Assert::assertEquals($expectedOutput, parse_markdown($text));

        // Headers markdown
        $text = "# Hello, World! #til";
        $expectedOutput = "<h1>Hello, World! #til</h1>";
        Assert::assertEquals($expectedOutput, parse_markdown($text));

        // Code escaping markdown
        $text = "test `<b>bold</b>`";
        $expectedOutput = "<p>test <code>&lt;b&gt;bold&lt;/b&gt;</code></p>";
        Assert::assertEquals($expectedOutput, parse_markdown($text));
    }

    public function testSearchFunctionality(FunctionalTester $I)
    {
        $I->haveInDatabase('post', ['body' => 'TIL test for tags']);
        $I->amOnPage('/tag/til');
        $I->seeInTitle('My Microblog');
        // Assert that the search returns the correct result
        $I->see('#TIL test for tags'); // Ensure the result is displayed on the page
        $I->dontSee('no results found'); // Optionally check no errors show
    }
}

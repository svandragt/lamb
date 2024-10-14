<?php

namespace Tests\Functional;

use PHPUnit\Framework\Assert;
use Tests\Support\FunctionalTester;

use function Lamb\render;

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
        Assert::assertEquals($expectedOutput, render($text)['body']);

        // Basic markdown
        $text = "_Hello, World!_";
        $expectedOutput = "<p><em>Hello, World!</em></p>";
        Assert::assertEquals($expectedOutput, render($text)['body']);

        // Fake markdown
        $text = "#Hello, World! #til";
        $expectedOutput = "<p>#Hello, World! #til</p>";
        Assert::assertEquals($expectedOutput, render($text)['body']);

        // Headers markdown
        $text = "# Hello, World! #til";
        $expectedOutput = "<h1>Hello, World! #til</h1>";
        Assert::assertEquals($expectedOutput, render($text)['body']);
    }
}

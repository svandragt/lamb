<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\parse_bean;

class ParseBeanDescriptionTest extends TestCase
{
    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
    }

    public function testDescriptionIsPlainTextWithoutHtmlEntities()
    {
        $bean = R::dispense('post');
        $bean->body = "It seems sometimes I&#039;m researching and want the previous tab to become active.";
        $bean->slug = '';

        parse_bean($bean);

        $this->assertStringNotContainsString('&#039;', $bean->description);
        $this->assertStringNotContainsString('&amp;', $bean->description);
        $this->assertStringContainsString("I'm researching", $bean->description);
    }
}

<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RedBeanPHP\R;

use function Lamb\is_scheduled;
use function Lamb\parse_bean;

class CreatedPersistenceTest extends TestCase
{
    private string $originalTz;

    protected function setUp(): void
    {
        if (!R::testConnection()) {
            R::setup('sqlite::memory:');
        }
        R::freeze(false);
        $this->originalTz = date_default_timezone_get();
        date_default_timezone_set('UTC');
        global $config;
        $config = [];
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalTz);
    }

    public function testRelativeDateIsRewrittenIntoBodyAsAbsolute(): void
    {
        $bean = R::dispense('post');
        $bean->created = '2020-01-01 00:00:00';
        $bean->body = "---\ncreated: next friday\n---\n\nHello #news";

        parse_bean($bean);

        $this->assertStringNotContainsString('next friday', $bean->body, 'The relative phrase must be replaced in the stored body');
        $this->assertStringContainsString((string) $bean->created, $bean->body, 'The resolved absolute date must be written into the body');
    }

    public function testEditingAPublishedPostDoesNotRescheduleIt(): void
    {
        // A post that published long ago but still carries a relative front-matter date.
        $bean = R::dispense('post');
        $bean->created = '2020-01-01 00:00:00';
        $bean->body = "---\ncreated: next friday\n---\n\nHello #news";

        // First save resolves + persists the absolute date into the body.
        parse_bean($bean);
        $afterFirstSave = $bean->created;

        // A later edit (e.g. fixing a typo) re-runs parse_bean on the now-absolute body.
        parse_bean($bean);

        $this->assertSame($afterFirstSave, $bean->created, 'Re-saving must not move the resolved date');
        $this->assertFalse(is_scheduled($bean) && $afterFirstSave <= date('Y-m-d H:i:s'), 'A re-saved post must not flip back to scheduled relative to its own resolved date');
    }

    public function testAbsoluteDateIsLeftUntouchedInBody(): void
    {
        $bean = R::dispense('post');
        $bean->body = "---\ncreated: 2099-01-01 09:00:00\n---\n\nHello #news";

        parse_bean($bean);

        $this->assertSame('2099-01-01 09:00:00', $bean->created);
        // No cosmetic churn: an already-canonical date is not requoted/rewritten.
        $this->assertStringContainsString('created: 2099-01-01 09:00:00', $bean->body);
    }

    public function testOtherFrontMatterIsPreserved(): void
    {
        $bean = R::dispense('post');
        $bean->body = "---\ntitle: My Page\ncreated: tomorrow\n---\n\nBody #news";

        parse_bean($bean);

        $this->assertStringContainsString('title: My Page', $bean->body, 'Unrelated front-matter must be preserved');
        $this->assertStringNotContainsString('tomorrow', $bean->body);
    }

    public function testBodyWithoutFrontMatterIsUntouched(): void
    {
        $bean = R::dispense('post');
        $bean->body = "Just a status with a --- rule and the word created: nope";

        parse_bean($bean);

        $this->assertSame("Just a status with a --- rule and the word created: nope", $bean->body);
    }
}

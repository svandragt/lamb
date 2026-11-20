<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/** The feedback discussion URL is pinned in the docs too; moving it must fail here. */
class FeedbackDiscussionUrlGuardTest extends TestCase
{
    public function testReadmeAndReleasingLinkTheFeedbackDiscussion(): void
    {
        foreach (['README.md', 'RELEASING.md'] as $file) {
            $this->assertStringContainsString(
                FEEDBACK_DISCUSSION_URL,
                (string) file_get_contents(dirname(__DIR__, 2) . '/' . $file),
                "$file must link FEEDBACK_DISCUSSION_URL"
            );
        }
    }
}

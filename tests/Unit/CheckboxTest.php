<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Post\toggle_checkbox;

class CheckboxTest extends TestCase
{
    public function testCheckFirstMarker(): void
    {
        $body = "- [ ] one\n- [ ] two\n";
        $this->assertSame("- [x] one\n- [ ] two\n", toggle_checkbox($body, 0, true));
    }

    public function testCheckSecondMarker(): void
    {
        $body = "- [ ] one\n- [ ] two\n";
        $this->assertSame("- [ ] one\n- [x] two\n", toggle_checkbox($body, 1, true));
    }

    public function testUncheckMarker(): void
    {
        $body = "- [x] one\n- [x] two\n";
        $this->assertSame("- [x] one\n- [ ] two\n", toggle_checkbox($body, 1, false));
    }

    public function testOutOfRangeIndexIsNoOp(): void
    {
        $body = "- [ ] one\n";
        $this->assertSame($body, toggle_checkbox($body, 5, true));
    }

    public function testOnlyTargetLineChanges(): void
    {
        $body = "intro text\n\n- [ ] one\n- [ ] two\n- [ ] three\n\noutro";
        $out = toggle_checkbox($body, 1, true);
        $this->assertSame("intro text\n\n- [ ] one\n- [x] two\n- [ ] three\n\noutro", $out);
    }

    public function testMixedMarkerCharacters(): void
    {
        $body = "* [ ] star\n+ [ ] plus\n- [ ] dash\n";
        $this->assertSame("* [ ] star\n+ [x] plus\n- [ ] dash\n", toggle_checkbox($body, 1, true));
    }

    public function testUppercaseXCounted(): void
    {
        $body = "- [X] one\n- [ ] two\n";
        $this->assertSame("- [X] one\n- [x] two\n", toggle_checkbox($body, 1, true));
    }

    public function testIndentedTaskMarker(): void
    {
        $body = "- [ ] one\n  - [ ] nested\n";
        $this->assertSame("- [ ] one\n  - [x] nested\n", toggle_checkbox($body, 1, true));
    }
}

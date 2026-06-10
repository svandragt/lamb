<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

use function Lamb\Theme\anchor_headings;

class AnchorHeadingsTest extends TestCase
{
    public function testAnchorsHighestHeadingToTop()
    {
        // A body starting at h2, fitted beneath an h2 title (top = h3).
        $this->assertSame('<h3>A</h3>', anchor_headings('<h2>A</h2>', 3));
    }

    public function testShiftsShallowerBodiesDown()
    {
        // Author wrote `# Title` (consumed) then `## Section` — anchors to h3.
        $this->assertSame('<h3>A</h3>', anchor_headings('<h1>A</h1>', 3));
    }

    public function testShiftsDeeperBodiesUp()
    {
        // Author wrote everything deep (e.g. `### Title` then `#### Section`);
        // the body's top heading is pulled up to h3 — the level typed is immaterial.
        $this->assertSame('<h3>A</h3>', anchor_headings('<h4>A</h4>', 3));
    }

    public function testKeepsLevelsRelative()
    {
        $html = '<h2>A</h2><h3>B</h3>';

        $this->assertSame('<h3>A</h3><h4>B</h4>', anchor_headings($html, 3));
    }

    public function testClampsAtHSix()
    {
        // Highest is h2 (shift +1); the h6 would overflow to h7 and clamps.
        $this->assertSame('<h3>A</h3><h6>B</h6>', anchor_headings('<h2>A</h2><h6>B</h6>', 3));
    }

    public function testNoOpWhenHighestAlreadyAtTop()
    {
        $this->assertSame('<h3>A</h3>', anchor_headings('<h3>A</h3>', 3));
    }

    public function testPreservesAttributes()
    {
        $this->assertSame('<h3 id="x">A</h3>', anchor_headings('<h2 id="x">A</h2>', 3));
    }

    public function testLeavesNonHeadingMarkupUntouched()
    {
        $html = '<p>Hello</p><div>world</div>';

        $this->assertSame($html, anchor_headings($html, 3));
    }
}

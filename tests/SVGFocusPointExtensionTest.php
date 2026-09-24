<?php

namespace Restruct\Silverstripe\SVG\Tests;

use Restruct\Silverstripe\SVG\SVGImage;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Storage\AssetContainer;
use SilverStripe\Dev\SapphireTest;

/**
 * SVGFocusPointExtension: jonom/focuspoint's Focus* methods for SVGs.
 *
 * Unlike SVGCropperExtension this one needs the real module: it reads the FocusPoint field that
 * jonom/focuspoint adds to Image. It is applied by _config/extensions.yml only when that module is
 * installed, so the tests skip without it; the CI host and the local harness install it so they
 * always run there.
 *
 * Oracle, as in SVGManipulationTest: core-plus-focuspoint on a raster image of the same 200x150 size,
 * with the same focus point.
 */
class SVGFocusPointExtensionTest extends SapphireTest
{
    use SVGTestHelpers;

    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        if (!class_exists('JonoM\\FocusPoint\\Extensions\\FocusPointImageExtension')) {
            $this->markTestSkipped('jonom/focuspoint is not installed');
        }
        $this->activateTestAssetStore();
    }

    protected function tearDown(): void
    {
        $this->resetTestAssetStore();
        parent::tearDown();
    }

    /**
     * Set a focus point (-1..1 on each axis, 0,0 is the centre) and write it.
     */
    private function focusOn(Image $image, float $x, float $y): void
    {
        $image->FocusPoint->setX($x);
        $image->FocusPoint->setY($y);
        $image->write();
    }

    /**
     * Which x offset of the original an SVG crop kept. contao/imagine-svg crops by wrapping the
     * original in a new root <svg> the size of the crop and shifting the original inside it with
     * a negative x (absent when the crop starts at 0).
     */
    private function cropOffsetX(AssetContainer $result): float
    {
        $doc = new \DOMDocument();
        $this->assertTrue($doc->loadXML($result->getString()));
        $inner = null;
        foreach ($doc->documentElement->childNodes as $child) {
            if ($child instanceof \DOMElement && $child->localName === 'svg') {
                $inner = $child;
                break;
            }
        }
        $this->assertNotNull($inner, 'the crop wraps the original in a nested <svg>');

        return -(float) ($inner->getAttribute('x') ?: 0);
    }

    public function testFocusMethodsMatchCore(): void
    {
        $svg = $this->makeSVG();
        $png = $this->makePNG();
        $this->focusOn($svg, 0.5, -0.5);
        $this->focusOn($png, 0.5, -0.5);

        $calls = [
            ['FocusFill', [100, 100]],
            ['FocusFill', [300, 100]],
            ['FocusFillMax', [100, 100]],
            ['FocusFillMax', [500, 500]],
            ['FocusCropWidth', [100]],
            ['FocusCropWidth', [500]],
            ['FocusCropHeight', [100]],
            ['FocusCropHeight', [500]],
        ];

        $mismatches = [];
        foreach ($calls as [$method, $args]) {
            $svgResult = $svg->$method(...$args);
            $pngResult = $png->$method(...$args);
            $label = $method . '(' . implode(', ', $args) . ')';
            if ($this->sizeOf($svgResult) !== $this->sizeOf($pngResult)) {
                $mismatches[] = "{$label}: SVG {$this->sizeOf($svgResult)}, core {$this->sizeOf($pngResult)}";
            }
        }

        $this->assertSame([], $mismatches);
    }

    public function testFocusFillKeepsTheSideTheFocusPointIsOn(): void
    {
        // A 200x150 source filled to 100x150 crops 100 horizontally; the focus point picks which 100.
        $left = $this->makeSVG(null, 'left.svg');
        $right = $this->makeSVG(null, 'right.svg');
        $this->focusOn($left, -1, 0);
        $this->focusOn($right, 1, 0);

        $leftResult = $left->FocusFill(100, 150);
        $rightResult = $right->FocusFill(100, 150);

        $this->assertInstanceOf(AssetContainer::class, $leftResult);
        $this->assertInstanceOf(AssetContainer::class, $rightResult);
        $this->assertSame('100x150', $this->sizeOf($leftResult));
        $this->assertStringContainsString('<svg', $leftResult->getString(), 'the result is still a vector');
        $this->assertEqualsWithDelta(0.0, $this->cropOffsetX($leftResult), 0.5);
        $this->assertEqualsWithDelta(100.0, $this->cropOffsetX($rightResult), 0.5);
    }

    public function testFocusFillOnAChainedVariant(): void
    {
        // SVGDBFile carries the extension too; a variant has no FocusPoint of its own, so it must
        // still return a correctly sized vector rather than null or a raster.
        $svg = $this->makeSVG();
        $this->assertInstanceOf(SVGImage::class, $svg);

        $result = $svg->ScaleWidth(100)->FocusFill(50, 50);

        $this->assertSame('50x50', $this->sizeOf($result));
        $this->assertStringContainsString('<svg', $result->getString());
    }
}

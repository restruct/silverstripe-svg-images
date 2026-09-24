<?php

namespace Restruct\Silverstripe\SVG\Tests;

use Restruct\Silverstripe\SVG\SVGDBFile;
use Restruct\Silverstripe\SVG\SVGImage;
use SilverStripe\Assets\Image;
use SilverStripe\Core\Config\Config;
use SilverStripe\Dev\SapphireTest;

/**
 * Vector manipulation (Fit, Fill, Pad, Scale...) on SVGImage and on the SVGDBFile variants it
 * returns.
 *
 * The oracle is core itself: every size assertion compares the SVG result with what core's own
 * ImageManipulation produces for a raster image of the same 200x150 size. "Matches core" is the
 * module's stated contract (see the 1.4.1 FillMax fix), and it keeps these tests honest across
 * Silverstripe majors without hard-coding core's rounding.
 */
class SVGManipulationTest extends SapphireTest
{
    use SVGTestHelpers;

    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activateTestAssetStore();
    }

    protected function tearDown(): void
    {
        $this->resetTestAssetStore();
        parent::tearDown();
    }

    /**
     * Apply each call to the SVG and to the matching PNG and collect every size that differs,
     * so one failing run reports all mismatches instead of stopping at the first.
     *
     * @param array<array{0: string, 1: array}> $calls [method, args]
     */
    private function assertMatchesCore(array $calls): void
    {
        $svg = $this->makeSVG();
        $png = $this->makePNG();

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

    public function testFitMatchesCore(): void
    {
        $this->assertMatchesCore([
            ['Fit', [150, 150]],
            ['Fit', [200, 100]],
            ['Fit', [100, 200]],
        ]);
    }

    public function testFitMaxMatchesCore(): void
    {
        $this->assertMatchesCore([
            ['FitMax', [150, 150]],
            ['FitMax', [500, 500]],
        ]);
    }

    public function testFillMatchesCore(): void
    {
        $this->assertMatchesCore([
            ['Fill', [150, 150]],
            ['Fill', [200, 100]],
            ['Fill', [100, 200]],
        ]);
    }

    public function testPadMatchesCore(): void
    {
        $this->assertMatchesCore([
            ['Pad', [200, 200]],
            ['Pad', [300, 150]],
            ['Pad', [150, 300]],
        ]);
    }

    public function testScaleMatchesCore(): void
    {
        $this->assertMatchesCore([
            ['ScaleWidth', [150]],
            ['ScaleWidth', [300]],
            ['ScaleHeight', [100]],
            ['ScaleHeight', [200]],
        ]);
    }

    /**
     * Size parity alone cannot tell a crop from a stretch: squashing 200x150 into 150x150 has the
     * right size too. The rendered aspect (width/height) must match the viewBox aspect, or the
     * artwork is distorted.
     */
    public function testFillAndPadDoNotDistort(): void
    {
        $svg = $this->makeSVG();

        foreach ([$svg->Fill(150, 150), $svg->Fill(100, 200), $svg->Pad(200, 200), $svg->Pad(300, 150)] as $result) {
            $root = simplexml_load_string($result->getString());
            $viewBox = preg_split('/[\s,]+/', trim((string)$root['viewBox']));
            $this->assertCount(4, $viewBox, 'a manipulated SVG must keep a viewBox');

            $renderedAspect = (float)$root['width'] / (float)$root['height'];
            $viewBoxAspect = (float)$viewBox[2] / (float)$viewBox[3];
            $this->assertEqualsWithDelta($renderedAspect, $viewBoxAspect, 0.02, "distorted: {$result->getVariant()}");
        }
    }

    public function testManipulationProducesAVectorVariant(): void
    {
        $svg = $this->makeSVG();

        $result = $svg->Fit(100, 100);

        // Chaining only works because variants come back as SVGDBFile, not a raster DBFile
        $this->assertInstanceOf(SVGDBFile::class, $result);
        $this->assertStringEndsWith('.svg', $result->getURL());
        $this->assertNotEmpty($result->getVariant());

        $content = $result->getString();
        $this->assertStringContainsString('<svg', $content);
        $this->assertStringContainsString('<circle', $content, 'the vector content must survive, not be rasterised');
    }

    public function testVariantsAreReusedNotRegenerated(): void
    {
        $svg = $this->makeSVG();

        $first = $svg->ScaleWidth(120);
        $second = $svg->ScaleWidth(120);

        $this->assertSame($first->getURL(), $second->getURL());
    }

    public function testChainedManipulationsKeepWorking(): void
    {
        $svg = $this->makeSVG();

        $this->assertSame('100x100', $this->sizeOf($svg->ScaleWidth(200)->Fill(100, 100)));
        $this->assertSame('100x100', $this->sizeOf($svg->Fill(150, 150)->ScaleWidth(100)));
        $this->assertSame('250x250', $this->sizeOf($svg->Fit(200, 200)->Pad(250, 250)));
    }

    public function testManipulationCanBeSwitchedOff(): void
    {
        Config::modify()->set(SVGImage::class, 'enable_svg_manipulation', false);
        $svg = $this->makeSVG();

        // Legacy behaviour: the original comes back untouched
        $this->assertSame($svg, $svg->Fit(50, 50));
        $this->assertSame($svg, $svg->Fill(50, 50));
        $this->assertSame($svg, $svg->ScaleWidth(50));
    }

    public function testMalformedSvgFallsBackToTheOriginal(): void
    {
        Config::modify()->set(SVGImage::class, 'sanitize_on_upload', false);
        $svg = $this->makeSVG('<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect', 'broken.svg');

        // Graceful degradation: no exception, no variant, the original is returned
        $this->assertSame($svg, $svg->Fit(5, 5));
    }

    public function testRasterImagesAreUntouchedByTheModule(): void
    {
        // Reloaded, so a wrong ClassName written by the module's hooks would show here
        $png = Image::get()->byID($this->makePNG()->ID);

        $this->assertNotInstanceOf(SVGImage::class, $png);
        $this->assertStringEndsWith('.png', $png->Fit(100, 100)->getURL());
    }

    /**
     * Regression: 1.4.1 fixed FillMax to match core, but only in SVGManipulationTrait, which
     * SVGImage did not use - SVGImage kept its own older copy. So FillMax on an SVGImage (the
     * common case, straight from a template) still returned the untouched 200x150 original for
     * FillMax(500, 500), where core crops to 150x150; only chained calls got the fix.
     */
    public function testFillMaxMatchesCore(): void
    {
        $this->assertMatchesCore([
            ['FillMax', [150, 150]],
            ['FillMax', [500, 500]],
            ['FillMax', [300, 100]],
        ]);
    }

    /**
     * Regression: CropWidth()/CropHeight() for SVGs were added in 1.4.0 as SVGCropperExtension
     * methods, where they could never run - the owner already has core's ImageManipulation
     * versions, and an extension method is only reached when the owner has none. Core's raster
     * crop ran instead and returned null for an SVG.
     */
    public function testCropWidthAndHeightMatchCore(): void
    {
        $this->assertMatchesCore([
            ['CropWidth', [100]],
            ['CropHeight', [100]],
            ['CropWidth', [300]],
        ]);

        $this->assertInstanceOf(SVGDBFile::class, $this->makeSVG(null, 'crop.svg')->CropWidth(100));
    }
}

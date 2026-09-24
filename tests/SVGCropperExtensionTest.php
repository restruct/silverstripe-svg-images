<?php

namespace Restruct\Silverstripe\SVG\Tests;

use Restruct\Silverstripe\SVG\Extensions\SVGCropperExtension;
use Restruct\Silverstripe\SVG\SVGDBFile;
use Restruct\Silverstripe\SVG\SVGImage;
use SilverStripe\Dev\SapphireTest;

/**
 * SVGCropperExtension: crop methods for SVGs.
 *
 * In a project it is applied only when restruct/silverstripe-focuspointcropper is installed
 * (_config/extensions.yml). It depends on nothing from that module, so it is applied here directly
 * and tested without it.
 */
class SVGCropperExtensionTest extends SapphireTest
{
    use SVGTestHelpers;

    protected $usesDatabase = true;

    protected static $required_extensions = [
        SVGImage::class => [SVGCropperExtension::class],
        SVGDBFile::class => [SVGCropperExtension::class],
    ];

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

    public function testCropRegionCutsTheGivenRectangle(): void
    {
        $svg = $this->makeSVG();

        $this->assertSame('100x50', $this->sizeOf($svg->CropRegion(10, 20, 100, 50)));
    }

    public function testApplyCropDataUsesTheOriginalCoordinates(): void
    {
        $svg = $this->makeSVG();

        $cropped = $svg->applyCropData(json_encode([
            'originalX' => 0, 'originalY' => 0, 'originalWidth' => 120, 'originalHeight' => 90,
        ]));

        $this->assertSame('120x90', $this->sizeOf($cropped));
    }

    public function testApplyCropDataIgnoresIncompleteData(): void
    {
        $svg = $this->makeSVG();

        $this->assertNull($svg->applyCropData(null));
        $this->assertNull($svg->applyCropData(json_encode(['originalX' => 0])));
    }
}

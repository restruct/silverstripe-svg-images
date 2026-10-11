<?php

namespace Restruct\Silverstripe\SVG\Tests;

use Restruct\Silverstripe\SVG\SVGDBFile;
use Restruct\Silverstripe\SVG\SVGImage;
use Restruct\Silverstripe\SVG\SVGImageBackend;
use Restruct\Silverstripe\SVG\SVGImageBackendFactory;
use Restruct\Silverstripe\SVG\Tests\Stub\RecordingImageBackend;
use SilverStripe\AssetAdmin\Controller\AssetAdmin;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Image_Backend;
use SilverStripe\Assets\ImageBackendFactory;
use SilverStripe\Assets\Storage\AssetContainer;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\InjectionCreator;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\FieldType\DBField;

/**
 * #24: no SVG may ever reach the site's raster Image_Backend.
 *
 * Resampled(), Quality() and ResizedImage() had no SVG override, so they fell through to core's
 * ImageManipulation::manipulateImage(), which hands the file to Intervention. With the Imagick
 * driver that rasterised the SVG on the server (following absolute local hrefs) on every render,
 * and never cached the failure. GD cannot decode SVG, so on GD machines - like most CI - the
 * defect is invisible in the output; these tests therefore watch the backend being CREATED, which
 * happens on either driver before any decode.
 */
class SVGRasterBackendTest extends SapphireTest
{
    use SVGTestHelpers;

    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activateTestAssetStore();

        # Every Image_Backend the code under test asks the Injector for is recorded. Only the
        # class is swapped: the configured spec (core's factory service, which this module
        # replaces) stays, as it would on a site with its own backend class. Injector::load()
        # replaces a whole spec, so start from the current one.
        RecordingImageBackend::$created_for = [];
        $spec = Injector::inst()->getServiceSpec(Image_Backend::class) ?: [];
        $spec['class'] = RecordingImageBackend::class;
        Injector::inst()->load([Image_Backend::class => $spec]);
    }

    protected function tearDown(): void
    {
        # A public static outlives the test; never let it leak into the next class
        RecordingImageBackend::$created_for = [];
        $this->resetTestAssetStore();
        parent::tearDown();
    }

    /**
     * Control: the recorder sees a raster image, so a pass below means "no SVG reached it", not
     * "the recorder is not wired up".
     */
    public function testRecorderSeesRasterImages(): void
    {
        $png = $this->makePNG();
        $png->Resampled();

        $this->assertContains($png->getFilename(), RecordingImageBackend::$created_for);
    }

    public function testResampledReturnsTheSvgWithoutRasterBackend(): void
    {
        $svg = $this->makeSVG();

        $result = $svg->Resampled();

        $this->assertSame($svg, $result, 'Resampled() on an SVG returns the SVG itself');
        $this->assertNoSvgReachedTheRasterBackend();
    }

    public function testQualityReturnsTheSvgWithoutRasterBackend(): void
    {
        $svg = $this->makeSVG();

        $result = $svg->Quality(50);

        $this->assertSame($svg, $result);
        $this->assertNoSvgReachedTheRasterBackend();
    }

    /**
     * ImageShortcodeProvider calls ResizedImage() whenever an editor sizes an image in WYSIWYG
     * content differently from the file.
     */
    public function testResizedImageMakesAnSvgVariantOfTheRequestedSize(): void
    {
        $svg = $this->makeSVG();

        $result = $svg->ResizedImage(100, 40);

        $this->assertInstanceOf(SVGDBFile::class, $result);
        $this->assertSame('svg', strtolower(File::get_file_extension($result->getFilename())));
        $this->assertNotEmpty($result->getVariant(), 'a stored variant, not the original');
        $this->assertSame('100x40', $this->sizeOf($result));
        $this->assertStringContainsString('<svg', $result->getString());
        $this->assertNoSvgReachedTheRasterBackend();
    }

    public function testResizedImageToTheCurrentSizeReturnsTheOriginal(): void
    {
        $svg = $this->makeSVG();

        $this->assertSame($svg, $svg->ResizedImage(200, 150));
        $this->assertNoSvgReachedTheRasterBackend();
    }

    public function testResizedImageWithManipulationDisabledReturnsTheOriginal(): void
    {
        Config::modify()->set(SVGImage::class, 'enable_svg_manipulation', false);
        $svg = $this->makeSVG();

        $this->assertSame($svg, $svg->ResizedImage(100, 40));
        $this->assertNoSvgReachedTheRasterBackend();
    }

    /**
     * Chained calls run on the SVGDBFile a manipulation returns, which must be just as safe.
     */
    public function testChainedVariantsNeverReachTheRasterBackend(): void
    {
        $svg = $this->makeSVG();

        $variant = $svg->ScaleWidth(100);
        $this->assertInstanceOf(SVGDBFile::class, $variant);

        $this->assertSame($variant, $variant->Resampled());
        $this->assertSame($variant, $variant->Quality(10));
        $this->assertSame('50x20', $this->sizeOf($variant->ResizedImage(50, 20)));
        $this->assertNoSvgReachedTheRasterBackend();
    }

    /**
     * The structural part of the fix: whatever core method asks for a backend gets one that
     * cannot decode, so methods this module does not override (Convert(), RemoveAnimation() on
     * Silverstripe 6, anything added upstream later) cannot rasterise either.
     */
    public function testAnSvgOnlyEverGetsTheNonDecodingBackend(): void
    {
        $svg = $this->makeSVG();
        $variant = $svg->ScaleWidth(100);

        foreach ([$svg, $variant] as $file) {
            $backend = $file->getImageBackend();
            $this->assertInstanceOf(SVGImageBackend::class, $backend);
            $this->assertNull($backend->getImageResource(), 'nothing is ever decoded');
        }

        $this->assertNull($svg->Convert('png'), 'no raster conversion of an SVG');
        if (method_exists($svg, 'RemoveAnimation')) {
            $this->assertSame($svg, $svg->RemoveAnimation());
        }
        $this->assertNoSvgReachedTheRasterBackend();
    }

    /**
     * Core's raster manipulations still reach the real backend for everything else.
     */
    public function testRasterImagesStillUseTheSiteBackend(): void
    {
        $png = $this->makePNG();

        $this->assertInstanceOf(RecordingImageBackend::class, $png->getImageBackend());
        $resized = $png->ResizedImage(100, 40);
        $this->assertInstanceOf(AssetContainer::class, $resized);
        $this->assertSame('100x40', $this->sizeOf($resized));
    }

    /**
     * Uppercase extension: upload validation and class_for_file_extension lowercase it, so
     * up.SVG is an accepted SVGImage; it must not be treated as a raster image.
     */
    public function testUppercaseSvgExtensionNeverReachesTheRasterBackend(): void
    {
        $svg = $this->makeSVG(null, 'upper.SVG');
        $svg = SVGImage::get()->byID($svg->ID);

        $this->assertTrue($svg->IsSVG());
        $this->assertInstanceOf(SVGImageBackend::class, $svg->getImageBackend());
        $this->assertSame('100x75', $this->sizeOf($svg->ScaleWidth(100)));
        $this->assertSame($svg, $svg->Resampled());
        $svg->FitMax(40, 40);
        $this->assertNoSvgReachedTheRasterBackend();
    }

    /**
     * The sequence asset-admin's UploadField runs for a has_one Image: FileUploadReceiver creates
     * a plain Image through the Injector, Upload writes it, and the response is built by
     * AssetAdmin::getObjectFromData(), which makes thumbnails through core's FitMax() - all
     * before SVGImageExtension corrects the ClassName in the database. The in-memory object
     * stays a plain Image, so only the Image_Backend factory can keep the SVG away from the
     * raster backend here.
     */
    public function testUploadFieldResponseForAPlainImageNeverReachesTheRasterBackend(): void
    {
        if (!class_exists(AssetAdmin::class)) {
            $this->markTestSkipped('silverstripe/asset-admin is not installed');
        }

        $image = Injector::inst()->create(Image::class);
        $image->setFromString($this->referenceSVG(), 'svgtest/relation.svg');
        $image->write();
        $this->assertSame(Image::class, get_class($image), 'premise: still a plain Image in memory');

        AssetAdmin::singleton()->getObjectFromData($image);
        $image->FitMax(60, 60);
        $image->ScaleWidth(30);

        $this->assertInstanceOf(SVGImageBackend::class, $image->getImageBackend());
        $this->assertNoSvgReachedTheRasterBackend();
    }

    /**
     * A plain DBFile holding an SVG (e.g. a record's File field) goes through the same factory.
     */
    public function testAPlainDBFileHoldingAnSvgNeverReachesTheRasterBackend(): void
    {
        $svg = $this->makeSVG(null, 'dbfile.svg');
        $dbFile = DBField::create_field('DBFile', [
            'Filename' => $svg->getFilename(),
            'Hash' => $svg->getHash(),
        ]);

        # Core gives a plain DBFile no backend at all for an SVG (image/svg+xml is not in
        # DBFile.supported_images); either way it must never be the raster one
        $backend = $dbFile->getImageBackend();
        $this->assertTrue($backend === null || $backend instanceof SVGImageBackend);
        $dbFile->ScaleWidth(20);
        $this->assertNoSvgReachedTheRasterBackend();
    }

    /**
     * The dev/build notice relies on this: active by default, inactive when a project re-maps
     * the ImageBackendFactory service or gives Image_Backend its own factory.
     */
    public function testFactoryActivityIsDetected(): void
    {
        $this->assertTrue(SVGImageBackendFactory::is_active(), 'active with the module config');

        Injector::inst()->load([ImageBackendFactory::class => [
            'class' => ImageBackendFactory::class,
            'constructor' => ['creator' => '%$' . InjectionCreator::class],
        ]]);
        $this->assertFalse(SVGImageBackendFactory::is_active(), 're-mapped factory service');
    }

    public function testOwnImageBackendFactoryIsDetected(): void
    {
        Injector::inst()->load(['App\\OwnImageBackendFactory' => [
            'class' => ImageBackendFactory::class,
            'constructor' => ['creator' => '%$' . InjectionCreator::class],
        ]]);
        $spec = Injector::inst()->getServiceSpec(Image_Backend::class) ?: [];
        $spec['factory'] = '%$App\\OwnImageBackendFactory';
        Injector::inst()->load([Image_Backend::class => $spec]);

        $this->assertFalse(SVGImageBackendFactory::is_active());
    }

    private function assertNoSvgReachedTheRasterBackend(): void
    {
        $svgs = array_filter(
            RecordingImageBackend::$created_for,
            fn (string $filename) => SVGImage::is_svg_filename($filename)
        );

        $this->assertSame([], array_values($svgs), 'SVGs handed to the raster Image_Backend');
    }
}

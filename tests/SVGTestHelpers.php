<?php

namespace Restruct\Silverstripe\SVG\Tests;

use Restruct\Silverstripe\SVG\SVGImage;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Storage\AssetContainer;

/**
 * Shared set-up for the suite.
 *
 * A trait rather than an abstract base test class on purpose: nothing in a module's tests/ may be
 * abstract if it could ever end up a DataObject (see the SOP's abstract-fixture trap), and a trait
 * sidesteps the question entirely.
 *
 * Compatibility note: the suite runs under PHPUnit 9 (Silverstripe 5) and PHPUnit 11
 * (Silverstripe 6). Keep it free of doc-comment metadata (@test, @dataProvider) and of
 * assertions removed after PHPUnit 9.
 */
trait SVGTestHelpers
{
    /**
     * The reference SVG: 200x150, both a viewBox and width/height, so every manipulation has a
     * well-defined size to start from. Mirrors tests/fixtures/test-image.svg.
     */
    protected function referenceSVG(): string
    {
        return file_get_contents(__DIR__ . '/fixtures/test-image.svg');
    }

    protected function activateTestAssetStore(): void
    {
        // Every file this suite writes goes to a throwaway store under the temp folder, never the
        // host project's assets/.
        TestAssetStore::activate('SVGImagesTest');
    }

    protected function resetTestAssetStore(): void
    {
        TestAssetStore::reset();
    }

    /**
     * Write an SVGImage record with real file content.
     */
    protected function makeSVG(?string $content = null, string $name = 'test.svg', bool $publish = true): SVGImage
    {
        $svg = SVGImage::create();
        $svg->setFromString($content ?? $this->referenceSVG(), 'svgtest/' . $name);
        $svg->write();
        if ($publish) {
            $svg->publishSingle();
        }

        return $svg;
    }

    /**
     * Write a raster Image the same size and shape as the reference SVG, generated with GD, so
     * core's own manipulation can serve as the oracle for what each method should produce.
     */
    protected function makePNG(string $name = 'test.png'): Image
    {
        $img = imagecreatetruecolor(200, 150);
        imagefilledrectangle($img, 0, 0, 200, 150, imagecolorallocate($img, 52, 152, 219));
        ob_start();
        imagepng($img);
        $content = ob_get_clean();
        imagedestroy($img);

        $png = Image::create();
        $png->setFromString($content, 'svgtest/' . $name);
        $png->write();
        $png->publishSingle();

        return $png;
    }

    /**
     * "WxH" of a manipulation result, for readable assertion messages.
     */
    protected function sizeOf(?AssetContainer $result): string
    {
        if (!$result) {
            return 'null';
        }

        return $result->getWidth() . 'x' . $result->getHeight();
    }
}

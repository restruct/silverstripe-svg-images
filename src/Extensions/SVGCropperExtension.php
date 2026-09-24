<?php

namespace Restruct\Silverstripe\SVG\Extensions;

use Imagine\Image\Box;
use Imagine\Image\Point;
use Restruct\Silverstripe\SVG\SVGImage;
use SilverStripe\Assets\Storage\AssetContainer;
use SilverStripe\Core\Extension;

/**
 * Extension providing crop functionality for SVG images.
 *
 * Applied to SVGImage and SVGDBFile when restruct/silverstripe-focuspointcropper is installed.
 * Provides applyCropData() and CropRegion(). (CropWidth()/CropHeight() moved to
 * SVGManipulationTrait, where they can actually override core's - see the note there.)
 *
 * Extends Core\Extension rather than ORM\DataExtension: DataExtension is deprecated in
 * Silverstripe 5 and removed in 6, and nothing here needs more than Extension provides.
 */
class SVGCropperExtension extends Extension
{
    /**
     * Apply crop data from ImageCropperExtension to this SVG.
     *
     * This method is called by ImageCropperExtension when an SVG has CropData set.
     * It crops the SVG by modifying the viewBox to show only the selected region.
     *
     * @param string|null $cropDataJson JSON string containing crop coordinates
     * @return AssetContainer|null Returns cropped SVG or null if cropping not applicable
     */
    public function applyCropData(?string $cropDataJson): ?AssetContainer
    {
        if (!$this->owner->IsSVG() || !$cropDataJson) {
            return null;
        }

        $cropData = json_decode($cropDataJson);
        if (!$cropData ||
            !property_exists($cropData, 'originalX') ||
            !property_exists($cropData, 'originalY') ||
            !property_exists($cropData, 'originalWidth') ||
            !property_exists($cropData, 'originalHeight')) {
            return null;
        }

        // Use the CropRegion method
        return $this->CropRegion(
            (int)$cropData->originalX,
            (int)$cropData->originalY,
            (int)$cropData->originalWidth,
            (int)$cropData->originalHeight
        );
    }

    /**
     * Crop to specific region.
     *
     * @param int $x X offset
     * @param int $y Y offset
     * @param int $width Crop width
     * @param int $height Crop height
     * @return AssetContainer|null
     */
    public function CropRegion(int $x, int $y, int $width, int $height): ?AssetContainer
    {
        if (!$this->owner->IsSVG()) {
            return null;
        }

        if (!$this->isSVGManipulationEnabled()) {
            return $this->owner;
        }

        $variant = $this->owner->variantName('CropRegion', $x, $y, $width, $height);

        return $this->owner->manipulateSVG($variant, function ($image) use ($x, $y, $width, $height) {
            return $image->crop(new Point($x, $y), new Box($width, $height));
        }) ?: $this->owner;
    }

    /**
     * Check if SVG manipulation is enabled.
     *
     * @return bool
     */
    protected function isSVGManipulationEnabled(): bool
    {
        return SVGImage::config()->get('enable_svg_manipulation')
            && class_exists(\Contao\ImagineSvg\Imagine::class);
    }
}

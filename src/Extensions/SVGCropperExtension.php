<?php

namespace Restruct\Silverstripe\SVG\Extensions;

use Imagine\Image\Box;
use Imagine\Image\Point;
use Restruct\Silverstripe\SVG\SVGImage;
use SilverStripe\Assets\Storage\AssetContainer;
use SilverStripe\ORM\DataExtension;

/**
 * Extension providing crop functionality for SVG images.
 *
 * Applied to SVGImage and SVGDBFile when restruct/silverstripe-focuspointcropper is installed.
 * Provides applyCropData(), CropWidth(), CropHeight(), and CropRegion() methods.
 */
class SVGCropperExtension extends DataExtension
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
     * Crop to exact width, keeping the full height. Crops from center horizontally.
     *
     * @param int $width
     * @return AssetContainer|null
     */
    public function CropWidth($width)
    {
        if (!$this->owner->IsSVG()) {
            return $this->owner->CropWidth($width);
        }

        if (!$this->isSVGManipulationEnabled()) {
            return $this->owner;
        }

        $width = (int)$width;
        $currentWidth = $this->owner->getWidth();
        $currentHeight = $this->owner->getHeight();

        // If already narrower or equal, return as-is
        if ($currentWidth <= $width) {
            return $this->owner;
        }

        $variant = $this->owner->variantName('CropWidth', $width);

        return $this->owner->manipulateSVG($variant, function ($image) use ($width, $currentWidth, $currentHeight) {
            // Calculate center crop offset
            $cropX = (int)(($currentWidth - $width) / 2);
            // Crop from center, keeping full height
            return $image->crop(new Point($cropX, 0), new Box($width, $currentHeight));
        }) ?: $this->owner;
    }

    /**
     * Crop to exact height, keeping the full width. Crops from center vertically.
     *
     * @param int $height
     * @return AssetContainer|null
     */
    public function CropHeight($height)
    {
        if (!$this->owner->IsSVG()) {
            return $this->owner->CropHeight($height);
        }

        if (!$this->isSVGManipulationEnabled()) {
            return $this->owner;
        }

        $height = (int)$height;
        $currentWidth = $this->owner->getWidth();
        $currentHeight = $this->owner->getHeight();

        // If already shorter or equal, return as-is
        if ($currentHeight <= $height) {
            return $this->owner;
        }

        $variant = $this->owner->variantName('CropHeight', $height);

        return $this->owner->manipulateSVG($variant, function ($image) use ($height, $currentWidth, $currentHeight) {
            // Calculate center crop offset
            $cropY = (int)(($currentHeight - $height) / 2);
            // Crop from center, keeping full width
            return $image->crop(new Point(0, $cropY), new Box($currentWidth, $height));
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

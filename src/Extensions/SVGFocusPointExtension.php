<?php

namespace Restruct\Silverstripe\SVG\Extensions;

use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Imagine\Image\Point;
use Restruct\Silverstripe\SVG\SVGImage;
use SilverStripe\Assets\Storage\AssetContainer;
use SilverStripe\ORM\DataExtension;

/**
 * Extension providing FocusPoint-aware cropping for SVG images.
 *
 * Applied to SVGImage and SVGDBFile when jonom/focuspoint is installed.
 * Provides FocusFill(), FocusFillMax(), FocusCropWidth(), and FocusCropHeight() methods.
 */
class SVGFocusPointExtension extends DataExtension
{
    /**
     * FocusFill for SVG - crops and resizes keeping the focus point visible.
     *
     * Overrides FocusPointExtension's method which uses manipulateImage()
     * (doesn't work for SVGs). Uses SVG manipulation instead.
     *
     * @param int $width Target width
     * @param int $height Target height
     * @return AssetContainer|null
     */
    public function FocusFill(int $width, int $height): ?AssetContainer
    {
        if (!$this->owner->IsSVG()) {
            return null; // Let parent handle non-SVG
        }

        return $this->applyFocusCrop($width, $height, true);
    }

    /**
     * FocusFillMax for SVG - like FocusFill but won't upscale.
     *
     * @param int $width Target width
     * @param int $height Target height
     * @return AssetContainer|null
     */
    public function FocusFillMax(int $width, int $height): ?AssetContainer
    {
        if (!$this->owner->IsSVG()) {
            return null; // Let parent handle non-SVG
        }

        return $this->applyFocusCrop($width, $height, false);
    }

    /**
     * FocusCropWidth for SVG - crops to exact width keeping focus point, maintains height.
     *
     * @param int $width Target width
     * @return AssetContainer|null
     */
    public function FocusCropWidth(int $width): ?AssetContainer
    {
        if (!$this->owner->IsSVG()) {
            return null; // Let parent handle non-SVG
        }

        $focusPoint = $this->owner->FocusPoint;

        // Don't upscale - if image is narrower than requested, return as-is
        if (!$focusPoint || $this->owner->getWidth() <= $width) {
            return $this->owner;
        }

        // Calculate crop with null height (keeps original height)
        return $this->applyFocusCropDimension($width, null);
    }

    /**
     * FocusCropHeight for SVG - crops to exact height keeping focus point, maintains width.
     *
     * @param int $height Target height
     * @return AssetContainer|null
     */
    public function FocusCropHeight(int $height): ?AssetContainer
    {
        if (!$this->owner->IsSVG()) {
            return null; // Let parent handle non-SVG
        }

        $focusPoint = $this->owner->FocusPoint;

        // Don't upscale - if image is shorter than requested, return as-is
        if (!$focusPoint || $this->owner->getHeight() <= $height) {
            return $this->owner;
        }

        // Calculate crop with null width (keeps original width)
        return $this->applyFocusCropDimension(null, $height);
    }

    /**
     * Apply focus-aware crop to SVG using FocusPoint data.
     *
     * @param int $width Target width
     * @param int $height Target height
     * @param bool $upscale Whether to allow upscaling
     * @return AssetContainer|null
     */
    protected function applyFocusCrop(int $width, int $height, bool $upscale): ?AssetContainer
    {
        // Get FocusPoint data - need the composite field
        $focusPoint = $this->owner->FocusPoint;
        if (!$focusPoint) {
            // Fall back to center-based Fill
            return $upscale ? $this->owner->Fill($width, $height) : $this->owner->FillMax($width, $height);
        }

        // Calculate crop data using FocusPoint's method
        $cropData = $focusPoint->calculateCrop($width, $height, $upscale);
        if (!$cropData) {
            return $upscale ? $this->owner->Fill($width, $height) : $this->owner->FillMax($width, $height);
        }

        $targetWidth = $cropData['x']['TargetLength'];
        $targetHeight = $cropData['y']['TargetLength'];
        $cropAxis = $cropData['CropAxis'];
        $cropOffset = (int) $cropData['CropOffset'];

        // Create variant name for caching
        $variant = $this->owner->variantName(
            'focusfill',
            $width,
            $height,
            $upscale ? 1 : 0,
            round($focusPoint->getX() * 100),
            round($focusPoint->getY() * 100)
        );

        return $this->owner->manipulateSVG($variant, function (ImageInterface $image) use ($cropAxis, $cropOffset, $targetWidth, $targetHeight) {
            $currentWidth = $image->getSize()->getWidth();
            $currentHeight = $image->getSize()->getHeight();

            switch ($cropAxis) {
                case 'x':
                    // Resize by height first, then crop horizontally
                    $scaleRatio = $targetHeight / $currentHeight;
                    $scaledWidth = (int) round($currentWidth * $scaleRatio);
                    $image = $image->resize(new Box($scaledWidth, $targetHeight));
                    // Crop from (cropOffset, 0) to get target width
                    return $image->crop(new Point($cropOffset, 0), new Box($targetWidth, $targetHeight));

                case 'y':
                    // Resize by width first, then crop vertically
                    $scaleRatio = $targetWidth / $currentWidth;
                    $scaledHeight = (int) round($currentHeight * $scaleRatio);
                    $image = $image->resize(new Box($targetWidth, $scaledHeight));
                    // Crop from (0, cropOffset) to get target height
                    return $image->crop(new Point(0, $cropOffset), new Box($targetWidth, $targetHeight));

                default:
                    // No cropping needed, just resize
                    return $image->resize(new Box($targetWidth, $targetHeight));
            }
        });
    }

    /**
     * Apply focus-aware crop for single dimension (width OR height).
     * Used by FocusCropWidth and FocusCropHeight.
     *
     * @param int|null $width Target width (null to keep original)
     * @param int|null $height Target height (null to keep original)
     * @return AssetContainer|null
     */
    protected function applyFocusCropDimension(?int $width, ?int $height): ?AssetContainer
    {
        $focusPoint = $this->owner->FocusPoint;
        if (!$focusPoint) {
            // Fall back to simple crop (from SVGCropperExtension)
            if ($width !== null && $this->owner->hasMethod('CropWidth')) {
                return $this->owner->CropWidth($width);
            }
            if ($height !== null && $this->owner->hasMethod('CropHeight')) {
                return $this->owner->CropHeight($height);
            }
            return $this->owner;
        }

        // Calculate crop data - null dimension means keep original
        $cropData = $focusPoint->calculateCrop($width, $height, true);
        if (!$cropData) {
            return $this->owner;
        }

        $targetWidth = $cropData['x']['TargetLength'];
        $targetHeight = $cropData['y']['TargetLength'];
        $cropAxis = $cropData['CropAxis'];
        $cropOffset = (int) $cropData['CropOffset'];

        // Create variant name
        $variant = $this->owner->variantName(
            'focuscrop',
            $width ?? 0,
            $height ?? 0,
            round($focusPoint->getX() * 100),
            round($focusPoint->getY() * 100)
        );

        return $this->owner->manipulateSVG($variant, function (ImageInterface $image) use ($cropAxis, $cropOffset, $targetWidth, $targetHeight) {
            // For single-dimension crop, we don't resize first - just crop
            switch ($cropAxis) {
                case 'x':
                    // Crop horizontally from the focus-adjusted position
                    return $image->crop(new Point($cropOffset, 0), new Box($targetWidth, $targetHeight));

                case 'y':
                    // Crop vertically from the focus-adjusted position
                    return $image->crop(new Point(0, $cropOffset), new Box($targetWidth, $targetHeight));

                default:
                    // No cropping needed
                    return $image;
            }
        });
    }
}

<?php

namespace Restruct\Silverstripe\SVG\Extensions;

use SilverStripe\Assets\Storage\AssetContainer;
use SilverStripe\Core\Extension;

/**
 * Extension providing crop functionality for SVG images.
 *
 * Applied to SVGImage and SVGDBFile when restruct/silverstripe-focuspointcropper is installed.
 * Provides applyCropData(). (CropWidth()/CropHeight() moved to SVGManipulationTrait, where they
 * can actually override core's - see the note there. CropRegion() moved there too, so that it is
 * available without that module, as it was on SVGImage in 2.x.)
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
        # which now lives on the owner (SVGManipulationTrait), not on this extension
        return $this->owner->CropRegion(
            (int)$cropData->originalX,
            (int)$cropData->originalY,
            (int)$cropData->originalWidth,
            (int)$cropData->originalHeight
        );
    }
}

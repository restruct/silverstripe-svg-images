<?php

namespace Restruct\Silverstripe\SVG;

use SilverStripe\Assets\File;
use SilverStripe\Core\Extension;

/**
 * Extension to ensure SVG files get the correct ClassName on upload
 */
class SVGFileExtension extends Extension
{
    /**
     * Before writing a file, check if it's an SVG and update the ClassName if needed
     */
    public function onBeforeWrite()
    {
        /** @var File $owner */
        $owner = $this->getOwner();

        // Check if this is an SVG file
        if ($owner->getExtension() === 'svg') {
            // If it's not already an SVGImage instance, update the ClassName
            if (!($owner instanceof SVGImage)) {
                $owner->ClassName = SVGImage::class;
            }
        }
    }
}

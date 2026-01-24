<?php

namespace Restruct\Silverstripe\SVG;

use SilverStripe\Assets\File;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\DB;

/**
 * Extension to ensure SVG files get the correct ClassName on upload.
 *
 * Uses onAfterWrite() with a direct DB query because the ORM enforces
 * relation class types during write. When uploading through a has_one Image
 * field, the framework creates/saves as Image class even though
 * class_for_file_extension correctly maps svg to SVGImage.
 *
 * The onBeforeWrite() approach doesn't work because the ORM overwrites
 * the ClassName after our extension runs.
 */
class SVGImageExtension extends Extension
{
    /**
     * After writing a file, check if it's an SVG and fix the ClassName if needed.
     *
     * Uses direct DB query because the ORM enforces relation class types.
     */
    public function onAfterWrite(): void
    {
        /** @var File $owner */
        $owner = $this->getOwner();

        // Check if this is an SVG file that's not already an SVGImage instance
        if ($owner->getExtension() === 'svg' && !($owner instanceof SVGImage)) {
            DB::prepared_query(
                'UPDATE "File" SET "ClassName" = ? WHERE "ID" = ?',
                [SVGImage::class, $owner->ID]
            );
        }
    }
}
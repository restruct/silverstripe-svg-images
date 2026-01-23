<?php

namespace Restruct\Silverstripe\SVG;

use SilverStripe\Assets\File;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\DB;

/**
 * Extension to ensure SVG files get the correct ClassName on upload.
 *
 * When uploading SVGs through relation fields (e.g., `many_many Images => Image::class`),
 * the framework enforces the relation's class type, ignoring the `class_for_file_extension`
 * config. This extension corrects the ClassName after the write completes.
 *
 * Backported from SS6 version (commit f6d1d91), with onAfterWrite DB fix for relation uploads.
 */
class SVGImageExtension extends Extension
{
    /**
     * After writing a file, ensure SVGs have the correct ClassName.
     *
     * Uses direct DB query because the ORM writes the ClassName based on
     * the relation's class type, not the class_for_file_extension config.
     */
    public function onAfterWrite(): void
    {
        /** @var File $owner */
        $owner = $this->getOwner();

        // Fix ClassName for SVG files uploaded through Image relations
        if ($owner->getExtension() === 'svg' && !($owner instanceof SVGImage)) {
            DB::prepared_query(
                'UPDATE "File" SET "ClassName" = ? WHERE "ID" = ?',
                [SVGImage::class, $owner->ID]
            );
        }
    }
}

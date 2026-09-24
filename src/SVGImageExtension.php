<?php

namespace Restruct\Silverstripe\SVG;

use SilverStripe\Assets\File;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\DB;

/**
 * Applied to File: sanitizes SVG uploads, and ensures SVG files get the correct ClassName.
 *
 * Both jobs live here rather than on SVGImage because an SVG uploaded through a relation field
 * is written as the relation's class (usually Image), where SVGImage's own methods never run.
 *
 * When uploading SVGs through relation fields (e.g., `many_many Images => Image::class`),
 * the framework enforces the relation's class type, ignoring the `class_for_file_extension`
 * config. This extension corrects the ClassName after the write completes.
 *
 * Uses onAfterWrite() with a direct DB query because the ORM enforces
 * relation class types during write. The onBeforeWrite() approach doesn't
 * work because the ORM overwrites the ClassName after our extension runs.
 *
 * Backported from SS6 version (commit f6d1d91), with onAfterWrite DB fix for relation uploads.
 */
class SVGImageExtension extends Extension
{
    /**
     * Sanitize SVG content whenever new content arrives: the first write (upload) and any write
     * that changes the file (a replaced file keeps its record but brings new content).
     *
     * Controlled by SVGImage.sanitize_on_upload and SVGImage.sanitize_remove_remote_references,
     * whatever the record's class.
     */
    public function onBeforeWrite(): void
    {
        /** @var File $owner */
        $owner = $this->getOwner();

        if ($owner->getExtension() !== 'svg' || !SVGImage::config()->get('sanitize_on_upload')) {
            return;
        }

        if (!$owner->isInDB() || $owner->isChanged('FileHash')) {
            SVGImage::sanitize_file($owner);
        }
    }

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
            // Both stage tables, not just "File": publishing the same in-memory Image writes
            // File_Live with the wrong class too, and this hook is the only chance to correct it.
            // File_Live exists only when File is versioned, hence the check.
            foreach (['File', 'File_Live'] as $table) {
                if ($table !== 'File' && !DB::get_schema()->hasTable($table)) {
                    continue;
                }
                DB::prepared_query(
                    "UPDATE \"{$table}\" SET \"ClassName\" = ? WHERE \"ID\" = ?",
                    [SVGImage::class, $owner->ID]
                );
            }
        }
    }
}

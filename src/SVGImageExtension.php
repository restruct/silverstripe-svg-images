<?php

namespace Restruct\Silverstripe\SVG;

use SilverStripe\Assets\File;
use SilverStripe\Core\Extension;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\DB;
use SilverStripe\Versioned\Versioned;

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
     * Refuse an SVG that cannot be sanitized during validation, before any onBeforeWrite() runs.
     *
     * It has to happen this early: AssetControlExtension::onBeforeWrite() (on every DataObject,
     * running before this extension's onBeforeWrite()) deletes the record's previous file as
     * "replaced". A refusal raised in onBeforeWrite() aborts the write after that deletion, and a
     * draft whose file was replaced with an unsafe one lost its original file. Validation runs
     * before all onBeforeWrite() hooks, so the record and its file stay as they were.
     *
     * The refused bytes are removed from the store (unless a record references them) and the
     * message is added to the ValidationResult, so write() throws a ValidationException with it.
     *
     * Hook name on Silverstripe 6: File::validate() calls extend('updateValidate').
     *
     * @param \SilverStripe\Core\Validation\ValidationResult|\SilverStripe\ORM\ValidationResult $result
     */
    public function updateValidate($result): void
    {
        $this->refuseUnsafeSvg($result);
    }

    /**
     * Same hook on Silverstripe 5, where File::validate() calls extend('validate').
     *
     * @param \SilverStripe\ORM\ValidationResult $result
     */
    public function validate($result): void
    {
        $this->refuseUnsafeSvg($result);
    }

    protected function refuseUnsafeSvg($result): void
    {
        /** @var File $owner */
        $owner = $this->getOwner();

        if (!SVGImage::config()->get('sanitize_on_upload') || !$this->receivesSvgContent($owner, false)) {
            return;
        }

        $content = $owner->File->exists() ? $owner->File->getString() : null;
        if (empty($content)) {
            return;
        }

        try {
            # Memoised in SVGImage, so the sanitize in onBeforeWrite() does not parse it again
            SVGImage::sanitize_content(
                $content,
                (bool)SVGImage::config()->get('sanitize_remove_remote_references')
            );
        } catch (UnsafeSVGException $e) {
            SVGImage::discard_unreferenced($owner->File->getFilename(), $owner->File->getHash());
            $result->addError($e->getMessage());
        }
    }

    /**
     * Sanitize SVG content whenever new content arrives: the first write (upload), any write
     * that changes the file (a replaced file keeps its record but brings new content), and a
     * rename from another extension to .svg.
     *
     * Controlled by SVGImage.sanitize_on_upload and SVGImage.sanitize_remove_remote_references,
     * whatever the record's class.
     *
     * Unsafe content is normally refused earlier, in validation (see updateValidate()). This is
     * the backstop for writes that skip validation: sanitize_file() still refuses, with a
     * ValidationException.
     */
    public function onBeforeWrite(): void
    {
        /** @var File $owner */
        $owner = $this->getOwner();

        if (SVGImage::config()->get('sanitize_on_upload') && $this->receivesSvgContent($owner, true)) {
            SVGImage::sanitize_file($owner);
        }
    }

    /**
     * Whether this write brings SVG content that has not been through the sanitizer.
     *
     * @param bool $filesystemUpdated False during validation: File::onBeforeWrite() has not yet
     *                                moved a renamed file (updateFilesystem()), so the new name
     *                                is only in Name. True in onBeforeWrite(), where FileFilename
     *                                already holds it.
     */
    protected function receivesSvgContent(File $owner, bool $filesystemUpdated): bool
    {
        $isSvg = SVGImage::is_svg_filename($owner->getFilename());

        if (!$owner->isInDB()) {
            return $isSvg;
        }

        if ($this->isPublishWrite($owner)) {
            return false;
        }

        if ($isSvg && $owner->isChanged('FileHash')) {
            return true;
        }

        return $filesystemUpdated ? $this->becameSvg($owner) : $this->becomesSvg($owner);
    }

    /**
     * Records being copied to the Live stage by Versioned::writeToStage() (a publish).
     *
     * A WeakMap keyed by the record object: extension instances are shared between owners, and
     * the entry must not outlive the record.
     *
     * @var \WeakMap<File, true>|null
     */
    private static $copyingToLive = null;

    /**
     * Versioned hook, called just before writeToStage() writes the record to a stage.
     */
    public function onBeforeWriteToStage($stage, $forceInsert = false): void
    {
        if ($stage === Versioned::LIVE) {
            self::$copyingToLive ??= new \WeakMap();
            self::$copyingToLive[$this->getOwner()] = true;
        }
    }

    /**
     * Versioned hook, called after writeToStage(), also when the write threw.
     */
    public function onAfterWriteToStage($stage, $forceInsert = false): void
    {
        if (self::$copyingToLive !== null) {
            unset(self::$copyingToLive[$this->getOwner()]);
        }
    }

    /**
     * The Live-stage write of a publish: it copies the draft as it is, so there is nothing new to
     * sanitize.
     *
     * Publishing goes through writeToStage(), which calls forceChange(): FileHash is flagged as
     * changed without changing. Without this the file was sanitized again during the Live write.
     * For a draft stored unsanitized before 3.0 that wrote the cleaned copy to the protected
     * store while the Live record pointed at it, so visitors could not load the published file.
     *
     * Deliberately not "the current stage is Live": front-end requests read and write in Live
     * mode, and content a front-end form replaces must still be sanitized. Drafts are sanitized
     * on every content change (upload, replace, rename to .svg, rollback).
     */
    protected function isPublishWrite(File $owner): bool
    {
        return self::$copyingToLive !== null && isset(self::$copyingToLive[$owner]);
    }

    /**
     * During validation: the record is being renamed from another extension to .svg (Name
     * changed, FileFilename not yet).
     */
    protected function becomesSvg(File $owner): bool
    {
        return $owner->isChanged('Name', DataObject::CHANGE_VALUE)
            && SVGImage::is_svg_filename((string)$owner->Name)
            && !SVGImage::is_svg_filename($owner->getFilename());
    }

    /**
     * A file renamed from another extension to .svg (e.g. notes.txt to notes.svg). Its hash does
     * not change, but it is an SVG from now on, so its content has to pass the sanitizer like an
     * upload. File::onBeforeWrite() has already moved it in the store (updateFilesystem()) when
     * this runs, so FileFilename holds the new name.
     */
    protected function becameSvg(File $owner): bool
    {
        if (!$owner->isChanged('FileFilename', DataObject::CHANGE_VALUE)) {
            return false;
        }
        $changes = $owner->getChangedFields(['FileFilename'], DataObject::CHANGE_VALUE);
        $before = $changes['FileFilename']['before'] ?? null;

        return !SVGImage::is_svg_filename(is_string($before) ? $before : null);
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
        if (SVGImage::is_svg_extension($owner->getExtension()) && !($owner instanceof SVGImage)) {
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

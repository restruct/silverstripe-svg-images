<?php

namespace Restruct\Silverstripe\SVG;

use DOMDocument;
use enshrined\svgSanitize\Sanitizer;
use Override;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBField;

/**
 * SVGImage - Extends Image to provide SVG support in SilverStripe.
 *
 * Key features:
 * - CMS thumbnail/preview support for SVG files
 * - Real SVG manipulation (resize, crop) using contao/imagine-svg
 * - SVG sanitization on upload using enshrined/svg-sanitize
 * - Dimension parsing from SVG viewBox/width/height attributes
 *
 * Unlike raster images, SVG manipulation modifies viewBox and width/height attributes
 * while preserving the vector format.
 */
class SVGImage extends Image
{
    use SVGManipulationTrait;

    /**
     * @var string
     */
    private static $table_name = 'SVGImage';

    /**
     * Enable real SVG manipulation (resize/crop) instead of returning original.
     * When false, manipulation methods return $this unchanged (legacy behavior).
     *
     * @var bool
     */
    private static $enable_svg_manipulation = true;

    /**
     * Sanitize SVG files on upload to remove potentially dangerous content.
     *
     * @var bool
     */
    private static $sanitize_on_upload = true;

    /**
     * Remove references to remote files during sanitization.
     * Prevents HTTP information leaks.
     *
     * @var bool
     */
    private static $sanitize_remove_remote_references = true;

    /**
     * Set to true to automatically migrate existing SVG files to SVGImage class on dev/build.
     *
     * @var bool
     */
    private static $auto_migrate_svg_class = false;

    /**
     * Human-readable file type description.
     *
     * @return string
     */
    #[Override]
    public function getFileType(): string
    {
        if ($this->getExtension() === 'svg') {
            return 'SVG image';
        }

        return parent::getFileType();
    }

    /**
     * Sanitize SVG content on upload.
     */
    #[Override]
    public function onBeforeWrite(): void
    {
        // Sanitization on upload happens in SVGImageExtension::onBeforeWrite(), which parent::
        // onBeforeWrite() invokes. It lives on the extension because SVGs uploaded through a
        // `has_one Image` relation are written as a plain Image, where an SVGImage override never
        // runs. The check that used to be here could never fire anyway: it required
        // $this->exists(), which is false for any record not yet in the database - i.e. exactly
        // the first write it was meant for - so no upload was ever sanitized.
        //
        // // Only sanitize on first write (new upload) and if enabled
        // if (!$this->isInDB() && $this->IsSVG() && static::config()->get('sanitize_on_upload')) {
        //     $this->sanitizeSVG();
        // }
        parent::onBeforeWrite();
    }

    /**
     * Sanitize the SVG file content.
     *
     * @return bool True if sanitization was performed
     */
    public function sanitizeSVG(): bool
    {
        return static::sanitize_file($this);
    }

    /**
     * Sanitize the content of any File record holding an SVG, SVGImage or not.
     *
     * Reads the content through the DBFile field rather than $file->exists(): File::exists()
     * also requires the record to be in the database, so it is false during the first write -
     * the upload itself.
     *
     * When the content changes, the cleaned content replaces it under the same filename, and the
     * unsanitized copy is removed from the asset store unless some record still references that
     * exact file (it cannot on a fresh upload, which is the case this exists for).
     *
     * Fails closed: content that cannot be sanitized (see sanitize_content()) is refused with a
     * ValidationException, and its bytes are removed from the asset store the same way. Up to 3.0
     * such a file was kept exactly as uploaded, so a script the sanitizer never saw ran in the
     * browser of whoever opened the file URL (#23).
     *
     * @param File $file
     * @return bool True if the content was run through the sanitizer
     * @throws \Exception A ValidationException (SilverStripe\ORM\ValidationException on
     *                    Silverstripe 5, SilverStripe\Core\Validation\ValidationException on 6)
     */
    public static function sanitize_file(File $file): bool
    {
        if ($file->getExtension() !== 'svg' || !$file->File->exists()) {
            return false;
        }

        $content = $file->File->getString();
        if (empty($content)) {
            return false;
        }

        $filename = $file->getFilename();
        $dirtyHash = $file->getHash();

        try {
            $cleanContent = static::sanitize_content(
                $content,
                (bool)static::config()->get('sanitize_remove_remote_references')
            );
        } catch (UnsafeSVGException $e) {
            # Refused: nothing may keep serving these bytes. The record is never written (the
            # exception aborts the write), but setFromString()/Upload already put the file in the
            # store, where it would otherwise stay reachable by URL.
            if ($dirtyHash && !static::tuple_is_referenced($filename, $dirtyHash)) {
                Injector::inst()->get(AssetStore::class)->delete($filename, $dirtyHash);
            }
            throw static::validation_exception($e->getMessage());
        }

        // Only update if content changed
        if ($cleanContent !== $content) {
            $file->setFromString($cleanContent, $filename);

            if ($dirtyHash && $dirtyHash !== $file->getHash() && !static::tuple_is_referenced($filename, $dirtyHash)) {
                Injector::inst()->get(AssetStore::class)->delete($filename, $dirtyHash);
            }
        }

        return true;
    }

    /**
     * Sanitize SVG markup, or refuse it.
     *
     * Refuses (throws) in two cases, both of which used to end with the input stored unchanged:
     *
     * - a DOCTYPE with an internal subset (`<!DOCTYPE svg [ ... ]>`). The sanitizer drops the
     *   DOCTYPE before parsing, so any entity declared there becomes undefined and the parse
     *   fails. Entity declarations are also how XML pulls in files and expands bombs, so they are
     *   refused up front, with a message that says why. Older Illustrator "Save As SVG" exports
     *   declare entities like this.
     * - anything the sanitizer cannot parse (enshrined/svg-sanitize returns false), for example
     *   nesting deeper than libxml's default limit of 256, which browsers render happily.
     *
     * @param string $content Raw SVG markup
     * @param bool $removeRemoteReferences Passed to the sanitizer
     * @return string The sanitized markup
     * @throws UnsafeSVGException When the markup is refused; the message is meant for the uploader
     */
    public static function sanitize_content(string $content, bool $removeRemoteReferences = true): string
    {
        if (static::has_internal_subset($content)) {
            throw new UnsafeSVGException(_t(
                __CLASS__ . '.REFUSED_INTERNAL_SUBSET',
                'This SVG declares entities in its DOCTYPE, which is not accepted for security reasons. '
                . 'Export it again without editor data (in Illustrator: switch off "Preserve Illustrator '
                . 'Editing Capabilities"), or use a PNG.'
            ));
        }

        $sanitizer = new Sanitizer();
        $sanitizer->removeRemoteReferences($removeRemoteReferences);
        $cleanContent = $sanitizer->sanitize($content);

        if ($cleanContent === false) {
            throw new UnsafeSVGException(_t(
                __CLASS__ . '.REFUSED_UNPARSABLE',
                'This SVG could not be checked for unsafe content, so it was not saved. Export it '
                . 'again from your editor, or use a PNG.'
            ));
        }

        return $cleanContent;
    }

    /**
     * Whether the markup has a DOCTYPE with an internal subset (`[ ... ]`).
     *
     * Walks the DOCTYPE's quoted literals instead of stopping at the first ">", so a ">" or "["
     * inside a quoted public/system identifier cannot hide or fake a subset. Anything that slips
     * past this still has to get through the sanitizer, which refuses what it cannot parse.
     */
    public static function has_internal_subset(string $content): bool
    {
        return (bool)preg_match(
            '/<!DOCTYPE(?:\s+(?:"[^"]*"|\'[^\']*\'|[^\s"\'>\[]+))*\s*\[/i',
            $content
        );
    }

    /**
     * A ValidationException, from whichever namespace this Silverstripe major has it in.
     *
     * Silverstripe 6 moved it from SilverStripe\ORM to SilverStripe\Core\Validation. Both accept a
     * plain message, and DataObject::write() callers on both catch their own major's class.
     */
    protected static function validation_exception(string $message): \Exception
    {
        $class = class_exists('SilverStripe\\Core\\Validation\\ValidationException')
            ? 'SilverStripe\\Core\\Validation\\ValidationException'
            : 'SilverStripe\\ORM\\ValidationException';

        return new $class($message);
    }

    /**
     * Whether any File row, on any stage or in the version history, points at this exact file.
     */
    protected static function tuple_is_referenced(string $filename, string $hash): bool
    {
        foreach (['File', 'File_Live', 'File_Versions'] as $table) {
            if (!DB::get_schema()->hasTable($table)) {
                continue;
            }
            $count = DB::prepared_query(
                "SELECT COUNT(*) FROM \"{$table}\" WHERE \"FileFilename\" = ? AND \"FileHash\" = ?",
                [$filename, $hash]
            )->value();
            if ($count > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get SVG dimensions from viewBox or width/height attributes.
     *
     * Uses getString() to support both public and protected/draft assets.
     *
     * @param string|int $dim "string" for "WxH" format, 0 for width, 1 for height
     * @return string|int|false
     */
    public function getDimensions($dim = "string")
    {
        if ($this->getExtension() !== 'svg' || !$this->exists()) {
            return parent::getDimensions($dim);
        }

        // Use getString() to support both public and protected/draft files
        $content = $this->getString();
        if (empty($content)) {
            return ($dim === "string") ? "File not found" : 0;
        }

        $doc = new DOMDocument();
        @$doc->loadXML($content); // Suppress warnings for malformed SVGs

        if (!$doc->documentElement) {
            return ($dim === "string") ? "Cannot parse SVG" : 0;
        }

        $root = $doc->documentElement;

        if ($root->hasAttribute('viewBox')) {
            $vbox = preg_split('/[\s,]+/', $root->getAttribute('viewBox'));
            $width = (float)$vbox[2] - (float)$vbox[0];
            $height = (float)$vbox[3] - (float)$vbox[1];
        } elseif ($root->hasAttribute('width')) {
            $width = (float)$root->getAttribute('width');
            $height = (float)$root->getAttribute('height');
        } else {
            return ($dim === "string") ? "Scalable (no dimensions)" : 0;
        }

        if ($dim === "string") {
            return "{$width}x{$height}";
        }

        return ($dim === 0) ? $width : $height;
    }

    /**
     * Get SVG width from viewBox or width attribute.
     *
     * @return int
     */
    public function getWidth(): int
    {
        if ($this->getExtension() === 'svg') {
            return (int)$this->getDimensions(0);
        }

        return parent::getWidth();
    }

    /**
     * Get SVG height from viewBox or height attribute.
     *
     * @return int
     */
    public function getHeight(): int
    {
        if ($this->getExtension() === 'svg') {
            return (int)$this->getDimensions(1);
        }

        return parent::getHeight();
    }

    // =========================================================================
    // SVG Manipulation Engine
    // =========================================================================
    //
    // IsSVG(), getImagineSVG(), manipulateSVG(), the manipulation overrides (Fit, FitMax,
    // ScaleWidth, ScaleHeight, Fill, FillMax, Pad) and CMSThumbnail()/StripThumbnail() come from
    // SVGManipulationTrait, shared with SVGDBFile. SVGImage used to carry its own verbatim copies,
    // which is how the 1.4.1 FillMax fix reached chained calls (SVGDBFile) but never SVGImage
    // itself. getWidth()/getHeight() stay here: SVGImage reads them from the viewBox via
    // getDimensions(), and a class's own methods take precedence over a trait's.

    /**
     * Override ThumbnailURL to return the SVG URL directly with grant access.
     *
     * This prevents the ThumbnailGenerator from trying to manipulate SVG files
     * and ensures protected/draft SVG files display correctly in the CMS.
     *
     * @param int $width
     * @param int $height
     * @return string|null
     */
    public function ThumbnailURL($width, $height)
    {
        if ($this->getExtension() === 'svg') {
            // Pass true to grant access for draft/protected files
            return $this->getURL(true);
        }

        return parent::ThumbnailURL($width, $height);
    }

    // =========================================================================
    // CMS Preview support
    // =========================================================================

    /**
     * Override existingOnly() to properly handle SVGs.
     *
     * Returns an SVGDBFile (like parent returns DBFile) so that subsequent
     * manipulation calls use our SVG-specific implementations while having
     * proper URL generation for thumbnail contexts.
     *
     * @return SVGDBFile|DBFile
     */
    public function existingOnly()
    {
        if ($this->getExtension() === 'svg') {
            $result = SVGDBFile::createFromTuple([
                'Filename' => $this->getFilename(),
                'Hash' => $this->getHash(),
                'Variant' => $this->getVariant(),
            ]);
            $result->setAllowGeneration(false);
            return $result;
        }

        return parent::existingOnly();
    }

    /**
     * Return CMS preview link. For SVGs, returns the URL directly.
     *
     * @param string|null $action
     * @return string|null
     */
    #[Override]
    public function PreviewLink($action = null): ?string
    {
        if ($this->getExtension() === 'svg') {
            if (!$this->canView()) {
                return null;
            }
            // Pass true to grant access for draft/protected files
            return $this->getURL(true);
        }

        return parent::PreviewLink($action);
    }

    // =========================================================================
    // Database migration
    // =========================================================================

    /**
     * Migrate existing SVG files to SVGImage class on dev/build.
     *
     * Only runs when auto_migrate_svg_class config is set to true.
     * Updates ClassName in File, File_Live, and File_Versions tables.
     */
    public function requireDefaultRecords(): void
    {
        parent::requireDefaultRecords();

        if (!static::config()->get('auto_migrate_svg_class')) {
            return;
        }

        $svgClassName = static::class;
        $tables = ['File', 'File_Live', 'File_Versions'];

        foreach ($tables as $table) {
            // Check if table exists (File_Live/File_Versions may not exist without full versioning)
            if (!DB::get_schema()->hasTable($table)) {
                continue;
            }

            // Query must handle NULL/empty ClassName values explicitly
            // (SQL "!= ?" doesn't match NULL values)
            $result = DB::prepared_query(
                "SELECT COUNT(*) FROM \"{$table}\" WHERE \"Name\" LIKE '%.svg' AND (\"ClassName\" IS NULL OR \"ClassName\" = '' OR \"ClassName\" != ?)",
                [$svgClassName]
            );
            $count = $result->value();

            if ($count > 0) {
                DB::prepared_query(
                    "UPDATE \"{$table}\" SET \"ClassName\" = ? WHERE \"Name\" LIKE '%.svg' AND (\"ClassName\" IS NULL OR \"ClassName\" = '' OR \"ClassName\" != ?)",
                    [$svgClassName, $svgClassName]
                );
                DB::alteration_message("Migrated {$count} SVG file(s) to {$svgClassName} in {$table}", 'changed');
            }
        }
    }

    // =========================================================================
    // SVG template helpers
    // =========================================================================

    /**
     * Return raw SVG content for inline embedding.
     *
     * Uses getString() to support both public and protected/draft assets.
     *
     * @return DBField|null
     */
    public function SVG_RAW_Inline()
    {
        if (!$this->IsSVG() || !$this->exists()) {
            return null;
        }

        // Use getString() to support both public and protected/draft files
        $content = $this->getString();
        if (!empty($content)) {
            return DBField::create_field('HTMLFragment', $content);
        }

        return null;
    }
}

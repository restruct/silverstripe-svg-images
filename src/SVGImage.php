<?php

namespace Restruct\Silverstripe\SVG;

use DOMDocument;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Storage\AssetContainer;
use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Core\Path;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBField;

/**
 * SVGImage - Extends Image to provide SVG support in SilverStripe.
 *
 * SVGs are vector graphics that don't need raster manipulation (resize, crop, etc.).
 * This class overrides image manipulation methods to return $this for SVGs,
 * allowing them to work seamlessly with SilverStripe's asset handling.
 *
 * Key features:
 * - CMS thumbnail/preview support for SVG files
 * - Dimension parsing from SVG viewBox/width/height attributes
 * - All manipulation methods (Fit, FitMax, Fill, etc.) return the original SVG
 * - Optional integration with stevie-mayhew/silverstripe-svg for template helpers
 */
class SVGImage extends Image
{
    /**
     * @var string
     */
    private static $table_name = 'SVGImage';

    /**
     * Set to true to automatically migrate existing SVG files to SVGImage class on dev/build.
     * Can be enabled via YAML config:
     *   Restruct\Silverstripe\SVG\SVGImage:
     *     auto_migrate_svg_class: true
     *
     * @var bool
     */
    private static $auto_migrate_svg_class = false;

    /**
     * Human-readable file type description.
     *
     * @return string
     */
    public function getFileType(): string
    {
        if ($this->getExtension() === 'svg') {
            return 'SVG image';
        }

        return parent::getFileType();
    }

    /**
     * Get SVG dimensions from viewBox or width/height attributes.
     *
     * @param string|int $dim "string" for "WxH" format, 0 for width, 1 for height
     * @return string|int|false
     */
    public function getDimensions($dim = "string")
    {
        if ($this->getExtension() !== 'svg' || !$this->exists()) {
            return parent::getDimensions($dim);
        }

        $filePath = Path::join(Director::publicFolder(), $this->getURL());

        if (!is_file($filePath)) {
            return ($dim === "string") ? "File not found" : 0;
        }

        $doc = new DOMDocument();
        @$doc->load($filePath); // Suppress warnings for malformed SVGs

        if (!$doc->documentElement) {
            return ($dim === "string") ? "Cannot parse SVG" : 0;
        }

        $root = $doc->documentElement;

        if ($root->hasAttribute('viewBox')) {
            $vbox = explode(' ', $root->getAttribute('viewBox'));
            $width = $vbox[2] - $vbox[0];
            $height = $vbox[3] - $vbox[1];
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

    /**
     * Check if this file is an SVG.
     *
     * @return bool
     */
    public function IsSVG(): bool
    {
        return $this->getExtension() === 'svg';
    }

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
            $result = DB::query(
                "SELECT COUNT(*) FROM \"{$table}\" WHERE \"Name\" LIKE '%.svg' AND \"ClassName\" != ?"
                , [$svgClassName]
            );
            $count = $result->value();

            if ($count > 0) {
                DB::query(
                    "UPDATE \"{$table}\" SET \"ClassName\" = ? WHERE \"Name\" LIKE '%.svg' AND \"ClassName\" != ?"
                    , [$svgClassName, $svgClassName]
                );
                DB::alteration_message("Migrated {$count} SVG file(s) to {$svgClassName} in {$table}", 'changed');
            }
        }
    }

    /**
     * Override existingOnly() to return $this for SVGs.
     *
     * ThumbnailGenerator calls existingOnly() when generation is disabled,
     * which returns a DBFile without our overrides. For SVGs, we return $this
     * so our manipulation overrides (FitMax, etc.) are used.
     *
     * @return $this|DBFile
     */
    public function existingOnly()
    {
        if ($this->getExtension() === 'svg') {
            return $this;
        }

        return parent::existingOnly();
    }

    /**
     * Return CMS preview link. For SVGs, returns the URL directly.
     *
     * @param string|null $action
     * @return string|null
     */
    public function PreviewLink($action = null): ?string
    {
        if ($this->getExtension() === 'svg') {
            if (!$this->canView()) {
                return null;
            }
            return $this->getURL();
        }

        return parent::PreviewLink($action);
    }

    // =========================================================================
    // Image manipulation overrides - SVGs don't need raster manipulation
    // =========================================================================

    /**
     * @param int $width
     * @param int $height
     * @return $this|AssetContainer
     */
    public function Fit($width, $height)
    {
        if ($this->getExtension() === 'svg') {
            return $this;
        }
        return parent::Fit($width, $height);
    }

    /**
     * @param int $width
     * @param int $height
     * @return $this|AssetContainer
     */
    public function FitMax($width, $height)
    {
        if ($this->getExtension() === 'svg') {
            return $this;
        }
        return parent::FitMax($width, $height);
    }

    /**
     * @param int $width
     * @return $this|AssetContainer
     */
    public function ScaleWidth($width)
    {
        if ($this->getExtension() === 'svg') {
            return $this;
        }
        return parent::ScaleWidth($width);
    }

    /**
     * @param int $height
     * @return $this|AssetContainer
     */
    public function ScaleHeight($height)
    {
        if ($this->getExtension() === 'svg') {
            return $this;
        }
        return parent::ScaleHeight($height);
    }

    /**
     * @param int $width
     * @param int $height
     * @return $this|AssetContainer
     */
    public function Fill($width, $height)
    {
        if ($this->getExtension() === 'svg') {
            return $this;
        }
        return parent::Fill($width, $height);
    }

    /**
     * @param int $width
     * @param int $height
     * @return $this|AssetContainer
     */
    public function FillMax($width, $height)
    {
        if ($this->getExtension() === 'svg') {
            return $this;
        }
        return parent::FillMax($width, $height);
    }

    /**
     * @param int $width
     * @param int $height
     * @param string $backgroundColor
     * @param int $transparencyPercent
     * @return $this|AssetContainer
     */
    public function Pad($width, $height, $backgroundColor = 'FFFFFF', $transparencyPercent = 0)
    {
        if ($this->getExtension() === 'svg') {
            return $this;
        }
        return parent::Pad($width, $height, $backgroundColor, $transparencyPercent);
    }

    /**
     * @return $this|AssetContainer
     */
    public function CMSThumbnail()
    {
        if ($this->getExtension() === 'svg') {
            return $this;
        }
        return parent::CMSThumbnail();
    }

    /**
     * @return $this|AssetContainer
     */
    public function StripThumbnail()
    {
        if ($this->getExtension() === 'svg') {
            return $this;
        }
        return parent::StripThumbnail();
    }

    // =========================================================================
    // SVG template helpers
    // =========================================================================

    /**
     * Get SVG template helper for advanced manipulation.
     * Requires stevie-mayhew/silverstripe-svg package.
     *
     * @param string|null $id Optional ID to limit SVG scope
     * @return SVGImage_Template|false
     */
    public function SVG($id = null)
    {
        if (!$this->IsSVG() || !class_exists(SVGImage_Template::class)) {
            return false;
        }

        $fileparts = explode(DIRECTORY_SEPARATOR, $this->getURL());
        $fileName = array_pop($fileparts);
        $svg = new SVGImage_Template($fileName, $id);
        $svg->customBasePath(Controller::join_links(
            DIRECTORY_SEPARATOR,
            Director::publicDir(),
            implode(DIRECTORY_SEPARATOR, $fileparts)
        ));

        return $svg;
    }

    /**
     * Return raw SVG content for inline embedding.
     *
     * @return DBField|null
     */
    public function SVG_RAW_Inline()
    {
        if (!$this->IsSVG()) {
            return null;
        }

        $filePath = Path::join(Director::publicFolder(), $this->getURL());

        if (is_file($filePath)) {
            return DBField::create_field('HTMLFragment', file_get_contents($filePath));
        }

        return null;
    }
}
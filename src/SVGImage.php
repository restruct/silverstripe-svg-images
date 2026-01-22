<?php

namespace Restruct\Silverstripe\SVG;

use Contao\ImagineSvg\Imagine;
use Contao\ImagineSvg\SvgBox;
use DOMDocument;
use enshrined\svgSanitize\Sanitizer;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Imagine\Image\Point;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Storage\AssetContainer;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Path;
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
 * - Optional integration with stevie-mayhew/silverstripe-svg for template helpers
 *
 * Unlike raster images, SVG manipulation modifies viewBox and width/height attributes
 * while preserving the vector format.
 */
class SVGImage extends Image
{
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
    public function onBeforeWrite(): void
    {
        parent::onBeforeWrite();

        // Only sanitize on first write (new upload) and if enabled
        if (!$this->isInDB() && $this->IsSVG() && static::config()->get('sanitize_on_upload')) {
            $this->sanitizeSVG();
        }
    }

    /**
     * Sanitize the SVG file content.
     *
     * @return bool True if sanitization was performed
     */
    public function sanitizeSVG(): bool
    {
        if (!$this->IsSVG() || !$this->exists()) {
            return false;
        }

        $content = $this->getString();
        if (empty($content)) {
            return false;
        }

        $sanitizer = new Sanitizer();
        $sanitizer->removeRemoteReferences(static::config()->get('sanitize_remove_remote_references'));

        $cleanContent = $sanitizer->sanitize($content);

        if ($cleanContent === false) {
            // Sanitization failed - file may be malformed
            return false;
        }

        // Only update if content changed
        if ($cleanContent !== $content) {
            $this->setFromString($cleanContent, $this->getFilename());
        }

        return true;
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
        @$doc->load($filePath);

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

    /**
     * Check if this file is an SVG.
     *
     * @return bool
     */
    public function IsSVG(): bool
    {
        return $this->getExtension() === 'svg';
    }

    // =========================================================================
    // SVG Manipulation Engine
    // =========================================================================

    /**
     * Get the Imagine SVG instance for this file.
     *
     * @return \Contao\ImagineSvg\Image|null
     */
    protected function getImagineSVG(): ?\Contao\ImagineSvg\Image
    {
        if (!$this->IsSVG() || !$this->exists()) {
            return null;
        }

        $filePath = Path::join(Director::publicFolder(), $this->getURL());

        if (!is_file($filePath)) {
            return null;
        }

        $imagine = new Imagine();
        return $imagine->open($filePath);
    }

    /**
     * Manipulate an SVG and store the result as a variant.
     *
     * Similar to manipulateImage() but uses contao/imagine-svg for vector manipulation.
     *
     * @param string $variant Variant name for caching
     * @param callable $callback Function that receives Imagine SVG image and returns modified image
     * @return AssetContainer|null
     */
    public function manipulateSVG(string $variant, callable $callback): ?AssetContainer
    {
        if (!$this->IsSVG() || !$this->exists()) {
            return null;
        }

        // Build variant name
        $filename = $this->getFilename();
        $hash = $this->getHash();
        $existingVariant = $this->getVariant();

        if ($existingVariant) {
            $variant = $existingVariant . '_' . $variant;
        }

        if (empty($filename) || empty($hash)) {
            return null;
        }

        /** @var AssetStore $store */
        $store = Injector::inst()->get(AssetStore::class);

        // Check if variant already exists
        if ($store->exists($filename, $hash, $variant)) {
            $tuple = [
                'Filename' => $filename,
                'Hash' => $hash,
                'Variant' => $variant,
            ];
            // Return SVGDBFile so chained manipulations continue to work
            /** @var SVGDBFile $file */
            $file = DBField::create_field(SVGDBFile::class, $tuple);
            return $file;
        }

        // Check if generation is allowed
        if (!$this->getAllowGeneration()) {
            return null;
        }

        // Get Imagine SVG instance
        $svgImage = $this->getImagineSVG();
        if (!$svgImage) {
            return null;
        }

        // Apply manipulation callback
        $result = $callback($svgImage);

        if (!$result instanceof \Contao\ImagineSvg\Image) {
            return null;
        }

        // Get manipulated SVG as string
        $svgContent = $result->get('svg');

        // Store the variant
        $tuple = $store->setFromString(
            $svgContent,
            $filename,
            $hash,
            $variant,
            ['conflict' => AssetStore::CONFLICT_USE_EXISTING]
        );

        if (!$tuple) {
            return null;
        }

        // Return SVGDBFile so chained manipulations continue to work
        /** @var SVGDBFile $file */
        $file = DBField::create_field(SVGDBFile::class, $tuple);
        return $file;
    }

    /**
     * Check if SVG manipulation is enabled.
     *
     * @return bool
     */
    protected function isSVGManipulationEnabled(): bool
    {
        return static::config()->get('enable_svg_manipulation') && class_exists(Imagine::class);
    }

    // =========================================================================
    // Image manipulation overrides
    // =========================================================================

    /**
     * Resize to fit within the given dimensions, maintaining aspect ratio.
     *
     * @param int $width
     * @param int $height
     * @return AssetContainer|null
     */
    public function Fit($width, $height)
    {
        if (!$this->IsSVG()) {
            return parent::Fit($width, $height);
        }

        if (!$this->isSVGManipulationEnabled()) {
            return $this;
        }

        $width = (int)$width;
        $height = (int)$height;
        $variant = "Fit{$width}x{$height}";

        return $this->manipulateSVG($variant, function (\Contao\ImagineSvg\Image $image) use ($width, $height) {
            return $image->thumbnail(new Box($width, $height), ImageInterface::THUMBNAIL_INSET);
        }) ?: $this;
    }

    /**
     * Resize to fit within the given dimensions, only if larger.
     *
     * @param int $width
     * @param int $height
     * @return AssetContainer|null
     */
    public function FitMax($width, $height)
    {
        if (!$this->IsSVG()) {
            return parent::FitMax($width, $height);
        }

        if (!$this->isSVGManipulationEnabled()) {
            return $this;
        }

        // Only resize if current dimensions exceed target
        $currentWidth = $this->getWidth();
        $currentHeight = $this->getHeight();

        if ($currentWidth <= $width && $currentHeight <= $height) {
            return $this;
        }

        return $this->Fit($width, $height);
    }

    /**
     * Scale to the given width, maintaining aspect ratio.
     *
     * @param int $width
     * @return AssetContainer|null
     */
    public function ScaleWidth($width)
    {
        if (!$this->IsSVG()) {
            return parent::ScaleWidth($width);
        }

        if (!$this->isSVGManipulationEnabled()) {
            return $this;
        }

        $width = (int)$width;
        $currentWidth = $this->getWidth();
        $currentHeight = $this->getHeight();

        if ($currentWidth <= 0) {
            return $this;
        }

        $height = (int)round($currentHeight * ($width / $currentWidth));
        $variant = "ScaleWidth{$width}";

        return $this->manipulateSVG($variant, function (\Contao\ImagineSvg\Image $image) use ($width, $height) {
            return $image->resize(new Box($width, $height));
        }) ?: $this;
    }

    /**
     * Scale to the given height, maintaining aspect ratio.
     *
     * @param int $height
     * @return AssetContainer|null
     */
    public function ScaleHeight($height)
    {
        if (!$this->IsSVG()) {
            return parent::ScaleHeight($height);
        }

        if (!$this->isSVGManipulationEnabled()) {
            return $this;
        }

        $height = (int)$height;
        $currentWidth = $this->getWidth();
        $currentHeight = $this->getHeight();

        if ($currentHeight <= 0) {
            return $this;
        }

        $width = (int)round($currentWidth * ($height / $currentHeight));
        $variant = "ScaleHeight{$height}";

        return $this->manipulateSVG($variant, function (\Contao\ImagineSvg\Image $image) use ($width, $height) {
            return $image->resize(new Box($width, $height));
        }) ?: $this;
    }

    /**
     * Crop and resize to fill the given dimensions exactly.
     *
     * @param int $width
     * @param int $height
     * @return AssetContainer|null
     */
    public function Fill($width, $height)
    {
        if (!$this->IsSVG()) {
            return parent::Fill($width, $height);
        }

        if (!$this->isSVGManipulationEnabled()) {
            return $this;
        }

        $width = (int)$width;
        $height = (int)$height;
        $variant = "Fill{$width}x{$height}";

        return $this->manipulateSVG($variant, function (\Contao\ImagineSvg\Image $image) use ($width, $height) {
            return $image->thumbnail(new Box($width, $height), ImageInterface::THUMBNAIL_OUTBOUND);
        }) ?: $this;
    }

    /**
     * Crop and resize to fill the given dimensions, only if larger.
     *
     * @param int $width
     * @param int $height
     * @return AssetContainer|null
     */
    public function FillMax($width, $height)
    {
        if (!$this->IsSVG()) {
            return parent::FillMax($width, $height);
        }

        if (!$this->isSVGManipulationEnabled()) {
            return $this;
        }

        $currentWidth = $this->getWidth();
        $currentHeight = $this->getHeight();

        if ($currentWidth <= $width && $currentHeight <= $height) {
            return $this;
        }

        return $this->Fill($width, $height);
    }

    /**
     * Pad to the given dimensions with background color.
     * For SVGs, we just resize to fit within bounds (padding doesn't apply to vectors).
     *
     * @param int $width
     * @param int $height
     * @param string $backgroundColor
     * @param int $transparencyPercent
     * @return AssetContainer|null
     */
    public function Pad($width, $height, $backgroundColor = 'FFFFFF', $transparencyPercent = 0)
    {
        if (!$this->IsSVG()) {
            return parent::Pad($width, $height, $backgroundColor, $transparencyPercent);
        }

        // For SVGs, Pad is equivalent to Fit (padding doesn't make sense for vectors)
        return $this->Fit($width, $height);
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
        if (!$this->IsSVG()) {
            return null;
        }

        if (!$this->isSVGManipulationEnabled()) {
            return $this;
        }

        $variant = "Crop{$x}x{$y}x{$width}x{$height}";

        return $this->manipulateSVG($variant, function (\Contao\ImagineSvg\Image $image) use ($x, $y, $width, $height) {
            return $image->crop(new Point($x, $y), new Box($width, $height));
        }) ?: $this;
    }

    /**
     * @return AssetContainer|null
     */
    public function CMSThumbnail()
    {
        if ($this->getExtension() === 'svg') {
            // For CMS thumbnails, just return self - SVGs scale nicely
            return $this;
        }
        return parent::CMSThumbnail();
    }

    /**
     * @return AssetContainer|null
     */
    public function StripThumbnail()
    {
        if ($this->getExtension() === 'svg') {
            return $this;
        }
        return parent::StripThumbnail();
    }

    // =========================================================================
    // CMS Preview support
    // =========================================================================

    /**
     * Override existingOnly() to return $this for SVGs.
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
     * Return CMS preview link.
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
    // Database migration
    // =========================================================================

    /**
     * Migrate existing SVG files to SVGImage class on dev/build.
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
                "SELECT COUNT(*) FROM \"{$table}\" WHERE \"Name\" LIKE '%.svg' AND \"ClassName\" != ?",
                [$svgClassName]
            );
            $count = $result->value();

            if ($count > 0) {
                DB::query(
                    "UPDATE \"{$table}\" SET \"ClassName\" = ? WHERE \"Name\" LIKE '%.svg' AND \"ClassName\" != ?",
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

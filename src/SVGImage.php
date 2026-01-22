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
     * Uses getString() to support both public and protected/draft assets.
     *
     * @return \Contao\ImagineSvg\Image|null
     */
    protected function getImagineSVG(): ?\Contao\ImagineSvg\Image
    {
        if (!$this->IsSVG() || !$this->exists()) {
            return null;
        }

        // Use getString() to work with both public and protected files
        $content = $this->getString();
        if (empty($content)) {
            return null;
        }

        $imagine = new Imagine();
        return $imagine->load($content);
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
        $variant = $this->variantName(__FUNCTION__, $width, $height);

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
        $variant = $this->variantName(__FUNCTION__, $width);

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
        $variant = $this->variantName(__FUNCTION__, $height);

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
        $variant = $this->variantName(__FUNCTION__, $width, $height);

        return $this->manipulateSVG($variant, function (\Contao\ImagineSvg\Image $image) use ($width, $height) {
            $currentSize = $image->getSize();
            $currentWidth = $currentSize->getWidth();
            $currentHeight = $currentSize->getHeight();

            if ($currentWidth <= 0 || $currentHeight <= 0) {
                return $image;
            }

            // Calculate scale to fill (use the larger scale to ensure we cover the target)
            $scaleX = $width / $currentWidth;
            $scaleY = $height / $currentHeight;
            $scale = max($scaleX, $scaleY);

            // Calculate the scaled dimensions
            $scaledWidth = $currentWidth * $scale;
            $scaledHeight = $currentHeight * $scale;

            // Calculate crop offset to center
            $cropX = (int)(($scaledWidth - $width) / 2 / $scale);
            $cropY = (int)(($scaledHeight - $height) / 2 / $scale);

            // Crop width/height in original coordinates
            $cropWidth = (int)($width / $scale);
            $cropHeight = (int)($height / $scale);

            // Crop to the target aspect ratio, then resize
            $image = $image->crop(new Point($cropX, $cropY), new Box($cropWidth, $cropHeight));
            return $image->resize(new Box($width, $height));
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
     * Pad to the given dimensions with transparent padding.
     *
     * For SVGs, padding is achieved by expanding the viewBox to include empty space
     * around the content, then setting width/height to the target dimensions.
     * The backgroundColor parameter is ignored for SVGs (always transparent).
     *
     * @param int $width
     * @param int $height
     * @param string $backgroundColor Ignored for SVGs
     * @param int $transparencyPercent Ignored for SVGs
     * @return AssetContainer|null
     */
    public function Pad($width, $height, $backgroundColor = 'FFFFFF', $transparencyPercent = 0)
    {
        if (!$this->IsSVG()) {
            return parent::Pad($width, $height, $backgroundColor, $transparencyPercent);
        }

        if (!$this->isSVGManipulationEnabled()) {
            return $this;
        }

        $width = (int)$width;
        $height = (int)$height;
        $variant = $this->variantName(__FUNCTION__, $width, $height);

        return $this->manipulateSVG($variant, function (\Contao\ImagineSvg\Image $image) use ($width, $height) {
            $currentSize = $image->getSize();
            $currentWidth = $currentSize->getWidth();
            $currentHeight = $currentSize->getHeight();

            if ($currentWidth <= 0 || $currentHeight <= 0) {
                return $image;
            }

            // Get the SVG DOM
            $svg = $image->getDomDocument();
            $root = $svg->documentElement;

            // Get current viewBox or create from dimensions
            $viewBox = $root->getAttribute('viewBox');
            if ($viewBox) {
                $parts = preg_split('/[\s,]+/', trim($viewBox));
                $vbX = (float)$parts[0];
                $vbY = (float)$parts[1];
                $vbWidth = (float)$parts[2];
                $vbHeight = (float)$parts[3];
            } else {
                $vbX = 0;
                $vbY = 0;
                $vbWidth = $currentWidth;
                $vbHeight = $currentHeight;
            }

            // Calculate scale to fit content within target
            $scale = min($width / $vbWidth, $height / $vbHeight);

            // Calculate the viewBox dimensions needed for the target aspect ratio
            $targetAspect = $width / $height;
            $currentAspect = $vbWidth / $vbHeight;

            if ($currentAspect > $targetAspect) {
                // Content is wider than target - add vertical padding
                $newVbWidth = $vbWidth;
                $newVbHeight = $vbWidth / $targetAspect;
                $newVbX = $vbX;
                $newVbY = $vbY - ($newVbHeight - $vbHeight) / 2;
            } else {
                // Content is taller than target - add horizontal padding
                $newVbHeight = $vbHeight;
                $newVbWidth = $vbHeight * $targetAspect;
                $newVbX = $vbX - ($newVbWidth - $vbWidth) / 2;
                $newVbY = $vbY;
            }

            // Set the new viewBox and dimensions
            $root->setAttribute('viewBox', sprintf('%s %s %s %s', $newVbX, $newVbY, $newVbWidth, $newVbHeight));
            $root->setAttribute('width', (string)$width);
            $root->setAttribute('height', (string)$height);

            return $image;
        }) ?: $this;
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

        $variant = $this->variantName(__FUNCTION__, $x, $y, $width, $height);

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

<?php

namespace Restruct\Silverstripe\SVG;

use Contao\ImagineSvg\Imagine;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Imagine\Image\Point;
use SilverStripe\Assets\Storage\AssetContainer;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\ORM\FieldType\DBField;

/**
 * Shared SVG manipulation methods for SVGImage and SVGDBFile.
 *
 * This trait provides common functionality for:
 * - Loading SVG content via Imagine
 * - Creating manipulated SVG variants
 * - Image manipulation overrides (Fit, Fill, Scale, etc.)
 */
trait SVGManipulationTrait
{
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
     * Get the Imagine SVG instance for this file.
     *
     * Uses getString() to support both public and protected/draft assets.
     * Returns null if the SVG cannot be parsed (graceful degradation).
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

        try {
            $imagine = new Imagine();
            return $imagine->load($content);
        } catch (\Exception $e) {
            // Malformed SVG - return null to trigger graceful fallback
            return null;
        }
    }

    /**
     * Check if SVG manipulation is enabled.
     *
     * @return bool
     */
    protected function isSVGManipulationEnabled(): bool
    {
        return SVGImage::config()->get('enable_svg_manipulation') && class_exists(Imagine::class);
    }

    /**
     * Manipulate an SVG and store the result as a variant.
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

        try {
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
        } catch (\Exception $e) {
            // Manipulation failed - return null to trigger graceful fallback
            return null;
        }
    }

    /**
     * Get SVG width.
     *
     * @return int
     */
    public function getWidth(): int
    {
        if ($this->IsSVG()) {
            $svgImage = $this->getImagineSVG();
            if ($svgImage) {
                return $svgImage->getSize()->getWidth();
            }
            return 0;
        }

        return parent::getWidth();
    }

    /**
     * Get SVG height.
     *
     * @return int
     */
    public function getHeight(): int
    {
        if ($this->IsSVG()) {
            $svgImage = $this->getImagineSVG();
            if ($svgImage) {
                return $svgImage->getSize()->getHeight();
            }
            return 0;
        }

        return parent::getHeight();
    }

    // =========================================================================
    // SVG manipulation overrides
    // =========================================================================

    /**
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

        $currentWidth = $this->getWidth();
        $currentHeight = $this->getHeight();

        if ($currentWidth <= $width && $currentHeight <= $height) {
            return $this;
        }

        return $this->Fit($width, $height);
    }

    /**
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
     * @return AssetContainer|null
     */
    public function CMSThumbnail()
    {
        if ($this->IsSVG()) {
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
        if ($this->IsSVG()) {
            return $this;
        }
        return parent::StripThumbnail();
    }
}

<?php

namespace Restruct\Silverstripe\SVG;

use Contao\ImagineSvg\Imagine;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Imagine\Image\Point;
use SilverStripe\Assets\Storage\AssetContainer;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Assets\Storage\DBFile;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Path;
use SilverStripe\ORM\FieldType\DBField;

/**
 * SVGDBFile - DBFile variant that supports SVG manipulation.
 *
 * When SVGImage manipulation methods return a variant, they return this class
 * instead of DBFile so that chained manipulations continue to work:
 *
 *   $image->ScaleWidth(200)->Fill(100, 100)
 *
 * Without this class, the second call would fail because DBFile doesn't
 * know how to manipulate SVGs.
 */
class SVGDBFile extends DBFile
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
            return self::create_field($tuple);
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

        return self::create_field($tuple);
    }

    /**
     * Create an SVGDBFile instance from a tuple.
     *
     * @param array $tuple
     * @return SVGDBFile
     */
    protected static function create_field(array $tuple): SVGDBFile
    {
        /** @var SVGDBFile $file */
        $file = DBField::create_field(self::class, $tuple);
        return $file;
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
        $variant = "Fit{$width}x{$height}";

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
        $variant = "ScaleWidth{$width}";

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
        $variant = "ScaleHeight{$height}";

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
        $variant = "Fill{$width}x{$height}";

        return $this->manipulateSVG($variant, function (\Contao\ImagineSvg\Image $image) use ($width, $height) {
            return $image->thumbnail(new Box($width, $height), ImageInterface::THUMBNAIL_OUTBOUND);
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

        return $this->Fit($width, $height);
    }

    /**
     * @return AssetContainer|null
     */
    public function CMSThumbnail()
    {
        if ($this->IsSVG()) {
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

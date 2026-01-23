<?php

namespace Restruct\Silverstripe\SVG;

use SilverStripe\Assets\Storage\DBFile;
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
    use SVGManipulationTrait;

    /**
     * Override getIsImage to return true for SVGs.
     *
     * DBFile::getIsImage() checks if mime type is in supported_images config,
     * but image/svg+xml is not included by default. This override ensures
     * SVGs are recognized as images for thumbnail generation.
     *
     * @return bool
     */
    public function getIsImage(): bool
    {
        if ($this->IsSVG()) {
            return true;
        }

        return parent::getIsImage();
    }

    /**
     * Create an SVGDBFile instance from a tuple.
     *
     * @param array $tuple
     * @return SVGDBFile
     */
    public static function createFromTuple(array $tuple): SVGDBFile
    {
        /** @var SVGDBFile $file */
        $file = DBField::create_field(self::class, $tuple);
        return $file;
    }

    /**
     * Override ThumbnailURL to return the SVG URL directly with grant access.
     *
     * @param int $width
     * @param int $height
     * @return string|null
     */
    public function ThumbnailURL($width, $height)
    {
        if ($this->getExtension() === 'svg') {
            return $this->getURL(true);
        }

        return parent::ThumbnailURL($width, $height);
    }

    /**
     * Return URL with grant access for protected/draft files.
     *
     * @return string|null
     */
    public function Link()
    {
        if ($this->getExtension() === 'svg') {
            return $this->getURL(true);
        }

        return parent::Link();
    }
}

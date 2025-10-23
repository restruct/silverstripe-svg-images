<?php

namespace Restruct\Silverstripe\SVG;

use SilverStripe\Assets\Image;
use DOMDocument;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Core\Path;
use SilverStripe\ORM\FieldType\DBField;

class SVGImage extends Image
{

    private static $flush = false;

    public function getFileType()
    {
        if ($this->getExtension() === 'svg') return "SVG image";

        return parent::getFileType();
    }

    public function getDimensions($dim = "string")
    {
        if ($this->getExtension() !== 'svg' || !$this->exists()) return parent::getDimensions($dim);

        if ($this->getField('Filename')) {
            $filePath = $this->getFullPath();

            // parse SVG
            $out = new DOMDocument();
            $out->load($filePath);
            if (!is_object($out) || !is_object($out->documentElement)) {
                return false;
            }
            // get dimensions from viewbox or else from width/height on root svg element
            $root = $out->documentElement;
            if ($root->hasAttribute('viewBox')) {
                $vbox = explode(' ', $root->getAttribute('viewBox'));
                $size[0] = $vbox[2] - $vbox[0];
                $size[1] = $vbox[3] - $vbox[1];
            } elseif ($root->hasAttribute('width')) {
                $size[0] = $root->getAttribute('width');
                $size[1] = $root->getAttribute('height');
            } else {
                return ($dim === "string") ? "No size set (scalable)" : 0;
            }

            // (regular logic/from Image class)
            return ($dim === "string") ? "$size[0]x$size[1]" : $size[$dim];

        }
    }

    /**
     *
     * Scale image proportionally to fit within the specified bounds
     *
     * @param integer $width The width to size within
     * @param integer $height The height to size within
     *
     * @return $this|\SilverStripe\Assets\Storage\AssetContainer
     */
    public function Fit($width, $height)
    {
        if ($this->getExtension() === 'svg') return $this;

        // else just forward to regular Image class
        return parent::Fit($width, $height);
    }

    /**
     * Override image manipulation methods to return the SVG itself
     * SVGs are scalable and don't need manipulation
     */
    public function ScaleWidth($width)
    {
        if ($this->getExtension() === 'svg') return $this;
        return parent::ScaleWidth($width);
    }

    public function ScaleHeight($height)
    {
        if ($this->getExtension() === 'svg') return $this;
        return parent::ScaleHeight($height);
    }

    public function FitMax($width, $height)
    {
        if ($this->getExtension() === 'svg') return $this;
        return parent::FitMax($width, $height);
    }

    public function Fill($width, $height)
    {
        if ($this->getExtension() === 'svg') return $this;
        return parent::Fill($width, $height);
    }

    public function Pad($width, $height, $backgroundColor = 'FFFFFF', $transparencyPercent = 0)
    {
        if ($this->getExtension() === 'svg') return $this;
        return parent::Pad($width, $height, $backgroundColor, $transparencyPercent);
    }


    /**
     * SVG files are images, just vector-based ones
     * Keep this as true so shortcodes and other image handling works
     */
    public function getIsImage()
    {
        if ($this->getExtension() === 'svg') {
            return false;
        }

        return parent::getIsImage();
    }

    /**
     * Override PreviewLink to return the SVG URL directly
     * SVG files don't need preview generation
     */
    public function PreviewLink($action = null)
    {
        if ($this->getExtension() === 'svg') {
            return $this->getURL();
        }

        return parent::PreviewLink($action);
    }

    /**
     * Override ThumbnailURL to return the SVG URL directly
     * This prevents the ThumbnailGenerator from trying to manipulate SVG files
     */
    public function ThumbnailURL($width, $height)
    {
        if ($this->getExtension() === 'svg') {
            return $this->getURL();
        }

        return parent::ThumbnailURL($width, $height);
    }

    /**
     * Override StripThumbnail to return the SVG itself
     */
    public function StripThumbnail()
    {
        if ($this->getExtension() === 'svg') {
            return $this;
        }

        return parent::StripThumbnail();
    }

    public function IsSVG()
    {
        if ($this->getExtension() === 'svg') {
            return true;
        }

        return false;
    }

    /**
     * @param null $id
     *
     * @return bool|SVGImage_Template
     */
    public function SVG($id = null)
    {
        if (!$this->IsSVG() || !class_exists(SVGImage_Template::class)) {
            return false;
        }
        $fileparts = explode(DIRECTORY_SEPARATOR, $this->getURL());
        $fileName = array_pop($fileparts);
        $svg = new SVGImage_Template($fileName, $id);

        $svg->customBasePath(Controller::join_links(DIRECTORY_SEPARATOR, Director::publicDir(), implode(DIRECTORY_SEPARATOR, $fileparts)));

        return $svg;
    }


    /**
     * @return DBField|void
     */
    public function SVG_RAW_Inline()
    {
        $filePath = Path::join(Director::publicFolder(), $this->getURL());

        if (is_file($filePath)) {
            return DBField::create_field('HTMLFragment', file_get_contents($filePath));
        }
    }

}

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
        if ( $this->getExtension() === 'svg' ) return "SVG image - good for line drawings";

        return parent::getFileType();
    }

    public function getDimensions($dim = "string")
    {
        if ( $this->getExtension() !== 'svg' || !$this->exists() ) return parent::getDimensions($dim);

        if ( $this->getField('Filename') ) {
            $filePath = $this->getFullPath();

            // parse SVG
            $out = new DOMDocument();
            $out->load($filePath);
            if ( !is_object($out) || !is_object($out->documentElement) ) {
                return false;
            }
            // get dimensions from viewbox or else from width/height on root svg element
            $root = $out->documentElement;
            if ( $root->hasAttribute('viewBox') ) {
                $vbox = explode(' ', $root->getAttribute('viewBox'));
                $size[ 0 ] = $vbox[ 2 ] - $vbox[ 0 ];
                $size[ 1 ] = $vbox[ 3 ] - $vbox[ 1 ];
            } elseif ( $root->hasAttribute('width') ) {
                $size[ 0 ] = $root->getAttribute('width');
                $size[ 1 ] = $root->getAttribute('height');
            } else {
                return ( $dim === "string" ) ? "No size set (scalable)" : 0;
            }

            // (regular logic/from Image class)
            return ( $dim === "string" ) ? "$size[0]x$size[1]" : $size[ $dim ];

        }
    }

    /**
     *
     * Scale image proportionally to fit within the specified bounds
     *
     * @param integer $width  The width to size within
     * @param integer $height The height to size within
     *
     * @return $this|\SilverStripe\Assets\Storage\AssetContainer
     */
    public function Fit($width, $height)
    {
        if ( $this->getExtension() === 'svg' ) return $this;

        // else just forward to regular Image class
        return parent::Fit($width, $height);
    }


    /**
     *
     * Return an image object representing the image in the given format.
     * This image will be generated using generateFormattedImage().
     * The generated image is cached, to flush the cache append ?flush=1 to your URL.
     *
     * Just pass the correct number of parameters expected by the working function
     *
     * @param string $format The name of the format.
     *
     *
     * @return $this|false|mixed
     */
    public function getFormattedImage($format)
    {
        if ( $this->getExtension() === 'svg' ) return $this;

        $args = func_get_args();

        if ($this->exists()) {
            $cacheFile = call_user_func_array(array($this, "cacheFilename"), $args);

            if (!file_exists(Director::baseFolder() . "/" . $cacheFile) || self::$flush) {
                call_user_func_array(array($this, "generateFormattedImage"), $args);
            }

            $cached = new Image_Cached($cacheFile, false, $this);
            return $cached;
        }
    }


    //
    // SVGTemplate integration
    //
    public function IsSVG()
    {
        if ( $this->getExtension() === 'svg' ) {
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
        if ( !$this->IsSVG() || !class_exists(SVGImage_Template::class) ) {
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
        $filePath = Path::join(Director::publicFolder() , $this->getURL());

        if ( is_file($filePath) ) {
            return DBField::create_field('HTMLFragment',file_get_contents($filePath));
        }
    }

}

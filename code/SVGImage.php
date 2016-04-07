<?php

class SVGImage extends \Image
{

    public function getFileType(){
        if($this->getExtension()=='svg') return "SVG image - good for line drawings";

        return parent::getFileType();
    }

    public function getDimensions($dim = "string") {
        if($this->getExtension()!='svg' || !$this->exists()) return parent::getDimensions($dim);

        if($this->getField('Filename')) {
            $filePath = $this->getFullPath();

            // parse SVG
            $out = new DOMDocument();
            $out->load($filePath);
            if (!is_object($out) || !is_object($out->documentElement)) {
                return false;
            }
            // get dimensions from viewbox or else from width/height on root svg element
            $root = $out->documentElement;
            if($root->hasAttribute('viewBox')){
                $vbox = explode(' ',$root->getAttribute('viewBox'));
                $size[0] = $vbox[2] - $vbox[0];
                $size[1] = $vbox[3] - $vbox[1];
            } else if($root->hasAttribute('width')) {
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
     * Return an XHTML img tag for this Image,
     * or NULL if the image file doesn't exist on the filesystem.
     *
     * @return string
     */
//    public function getTag() {
//        if($this->exists()) {
//            $url = $this->getURL();
//            $title = ($this->Title) ? $this->Title : $this->Filename;
//            if($this->Title) {
//                $title = Convert::raw2att($this->Title);
//            } else {
//                if(preg_match("/([^\/]*)\.[a-zA-Z0-9]{1,6}$/", $title, $matches)) {
//                    $title = Convert::raw2att($matches[1]);
//                }
//            }
//            return "<img src=\"$url\" alt=\"$title\" test />";
//        }
//    }

    /**
     * Scale image proportionally to fit within the specified bounds
     *
     * @param integer $width The width to size within
     * @param integer $height The height to size within
     * @return Image|null
     */
    public function Fit($width, $height) {
        if($this->getExtension()=='svg') return $this;

        // else just forward to regular Image class
        return parent::Fit($width, $height);
    }

    /**
     * Return an image object representing the image in the given format.
     * This image will be generated using generateFormattedImage().
     * The generated image is cached, to flush the cache append ?flush=1 to your URL.
     *
     * Just pass the correct number of parameters expected by the working function
     *
     * @param string $format The name of the format.
     * @return Image_Cached|null
     */
    public function getFormattedImage($format) {
        if($this->getExtension()=='svg') return $this;

        // else just forward to regular Image class
        return call_user_func_array('parent::getFormattedImage',func_get_args());
    }

}
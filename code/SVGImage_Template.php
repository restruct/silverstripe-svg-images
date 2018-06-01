<?php

namespace SilverStripeSVGImage;

use StevieMayhew\SilverStripeSVG\SVGTemplate;

// only extend if exists
if(class_exists(SVGTemplate::class)) {

    /**
     * Class SVGImage_Template
     * @package SVGImage
     */
    class SVGImage_Template extends SVGTemplate
    {

        /**
         * SVGImage_Template constructor.
         * @param string $name
         * @param string $id
         */
        public function __construct($name, $id)
        {
            return parent::__construct($name, $id);
        }

        /**
         * @param $id
         * @return $this
         */
        public function LimitID($id)
        {
            $this->id = $id;
            return $this;
        }

        /**
         * @param $folder
         * @return $this
         */
        public function addSubfolder($folder)
        {
            // prevent adding subfolders
            //$this->subfolders[] = trim($folder, DIRECTORY_SEPARATOR);
            return $this;
        }

    }

}

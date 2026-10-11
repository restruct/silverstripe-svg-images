<?php

namespace Restruct\Silverstripe\SVG\Tests\Stub;

use SilverStripe\Assets\InterventionBackend;
use SilverStripe\Assets\Storage\AssetContainer;
use SilverStripe\Dev\TestOnly;

/**
 * The site's real raster backend, plus a record of every file it was created for.
 *
 * Registered as the Image_Backend service in SVGRasterBackendTest, so a test can assert that no
 * SVG ever reached the raster backend (#24), whatever driver the machine running the suite has.
 */
class RecordingImageBackend extends InterventionBackend implements TestOnly
{
    /**
     * @var string[] Filenames this backend was constructed for, in order. Reset by the test.
     */
    public static $created_for = [];

    public function __construct(?AssetContainer $assetContainer = null)
    {
        static::$created_for[] = $assetContainer ? (string)$assetContainer->getFilename() : '(none)';
        parent::__construct($assetContainer);
    }
}

<?php

namespace Restruct\Silverstripe\SVG;

use SilverStripe\Assets\Image_Backend;
use SilverStripe\Assets\ImageBackendFactory;
use SilverStripe\Assets\Storage\AssetContainer;

/**
 * Replaces core's ImageBackendFactory (the factory of the Image_Backend service): any container
 * that holds an SVG gets an SVGImageBackend, which never decodes, instead of the site's raster
 * backend (#24).
 *
 * SVGManipulationTrait::getImageBackend() already does this for SVGImage and SVGDBFile. This
 * covers every other object that asks the Injector for a backend: a plain Image or DBFile holding
 * an SVG. The common case is an SVG uploaded into a `has_one Image` field: UploadField creates a
 * plain Image, and the upload response generates its thumbnails (FitMax) through core before
 * SVGImageExtension corrects the ClassName after the write.
 *
 * Registered in _config/config.yml on the ImageBackendFactory service, so a site's own
 * Image_Backend class (e.g. a different raster backend) is kept for everything that is not an
 * SVG. A site that replaces the Image_Backend `factory` itself bypasses this class; SVGImage and
 * SVGDBFile are still covered by the trait.
 */
class SVGImageBackendFactory extends ImageBackendFactory
{
    /**
     * Typed like Silverstripe 6's parent; Silverstripe 5's parent is untyped, and a child may add
     * a return type its parent lacks.
     *
     * @param string $service
     * @param array $params The constructor parameters; the first is the AssetContainer
     */
    public function create($service, array $params = []): ?Image_Backend
    {
        $container = reset($params);
        if ($container instanceof AssetContainer && static::holds_svg($container)) {
            return SVGImageBackend::create($container);
        }

        return parent::create($service, $params);
    }

    /**
     * By extension (any case; variants keep it) or, failing that, by the stored mime type.
     */
    public static function holds_svg(AssetContainer $container): bool
    {
        if (SVGImage::is_svg_filename($container->getFilename())) {
            return true;
        }

        if (!$container->exists()) {
            return false;
        }

        return str_starts_with(strtolower((string)$container->getMimeType()), 'image/svg');
    }
}

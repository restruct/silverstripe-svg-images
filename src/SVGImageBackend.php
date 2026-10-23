<?php

namespace Restruct\Silverstripe\SVG;

use Contao\ImagineSvg\Imagine;
use LogicException;
use SilverStripe\Assets\Image_Backend;
use SilverStripe\Assets\Storage\AssetContainer;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Core\Injector\Injectable;

/**
 * The Image_Backend an SVG gets instead of the site's raster backend: one that never decodes.
 *
 * Core's ImageManipulation hands a file to the configured Image_Backend (Intervention) for every
 * manipulation this module does not override. With the Imagick driver that rasterises the SVG on
 * the server, follows absolute local hrefs, and repeats on every render because the encode fails
 * and nothing is cached (#24). SVGManipulationTrait::getImageBackend() returns this class for
 * SVGs, so no current or future core manipulation can reach a rasteriser.
 *
 * Why not return null: Silverstripe 6's asset-admin ThumbnailGenerator calls
 * getImageBackend()->getAllowsAnimationInManipulations() without a null check on every thumbnail
 * it generates, so null fatals the Files area. This backend answers those calls and reports
 * "no image resource", which makes ImageManipulation::manipulateImage() bail out before it calls
 * any manipulation callback.
 *
 * One class serves both majors: parameters are untyped (a wider type than either interface
 * declares), and return types follow Silverstripe 6's interface, which Silverstripe 5's untyped
 * interface accepts.
 */
class SVGImageBackend implements Image_Backend
{
    use Injectable;

    /**
     * @var AssetContainer|null The SVG this backend stands in for; never read as an image
     */
    protected $container;

    /**
     * @var int Stored only so getQuality() answers what setQuality() was given
     */
    protected $quality = 100;

    public function __construct(?AssetContainer $assetContainer = null)
    {
        $this->container = $assetContainer;
    }

    /**
     * @var int[]|null [width, height], read once from the SVG markup
     */
    protected $size = null;

    /**
     * Width as the SVG itself reports it (width/height attributes, else viewBox), never by decoding.
     */
    public function getWidth(): int
    {
        return $this->getSize()[0];
    }

    public function getHeight(): int
    {
        return $this->getSize()[1];
    }

    /**
     * Read the size from the markup, not from the container: on a plain Image or DBFile (which
     * SVGImageBackendFactory also serves) the container's own getWidth() asks its backend, which
     * is this object, and would recurse.
     *
     * @return int[]
     */
    protected function getSize(): array
    {
        if ($this->size !== null) {
            return $this->size;
        }

        $this->size = [0, 0];
        if ($this->container && $this->container->exists() && class_exists(Imagine::class)) {
            try {
                $box = (new Imagine())->load((string)$this->container->getString())->getSize();
                $this->size = [(int)$box->getWidth(), (int)$box->getHeight()];
            } catch (\Throwable $e) {
                # Unreadable markup: report no size, as core does for an unreadable image
            }
        }

        return $this->size;
    }

    public function loadFromContainer(AssetContainer $assetContainer): static
    {
        $this->container = $assetContainer;
        $this->size = null;
        return $this;
    }

    /**
     * Nothing is loaded: reading a path is exactly what this backend exists to avoid.
     */
    public function loadFrom($path): static
    {
        return $this;
    }

    /**
     * Always null. ImageManipulation::manipulateImage() checks this before it runs a
     * manipulation, so every raster manipulation of an SVG ends here with "no result".
     */
    public function getImageResource(): mixed
    {
        return null;
    }

    public function setImageResource($resource): static
    {
        return $this;
    }

    /**
     * Unreachable through core (getImageResource() is null), so a call means someone bypassed
     * that check; fail loudly rather than write something that is not an image.
     */
    public function writeToStore(AssetStore $assetStore, $filename, $hash = null, $variant = null, $config = []): array
    {
        throw new LogicException(static::class . ' cannot write an image: SVGs are never rasterised');
    }

    public function writeTo($path): bool
    {
        return false;
    }

    public function setQuality($quality): static
    {
        $this->quality = (int)$quality;
        return $this;
    }

    public function getQuality(): int
    {
        return $this->quality;
    }

    # Every manipulation returns null: "this backend could not produce a result", which core
    # treats as a failed manipulation rather than an error.

    public function resize($width, $height): ?static
    {
        return null;
    }

    public function resizeRatio($width, $height, $useAsMinimum = false): ?static
    {
        return null;
    }

    public function resizeByWidth($width): ?static
    {
        return null;
    }

    public function resizeByHeight($height): ?static
    {
        return null;
    }

    public function paddedResize($width, $height, $backgroundColour = 'FFFFFF', $transparencyPercent = 0): ?static
    {
        return null;
    }

    public function croppedResize($width, $height, $position = 'center'): ?static
    {
        return null;
    }

    public function crop($top, $left, $width, $height, $position = 'center', $backgroundColour = 'FFFFFF'): ?static
    {
        return null;
    }

    # Animation API: Silverstripe 6 only, harmless extra methods on 5. An SVG has no frames to
    # drop, and ThumbnailGenerator toggles the flag around every thumbnail it makes.

    public function setAllowsAnimationInManipulations($allow): static
    {
        return $this;
    }

    public function getAllowsAnimationInManipulations(): bool
    {
        return false;
    }

    public function getIsAnimated(): bool
    {
        return false;
    }

    public function removeAnimation($position): ?static
    {
        return null;
    }
}

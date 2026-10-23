<?php

namespace Restruct\SvgBrowser;

use Restruct\Silverstripe\SVG\SVGImage;
use SilverStripe\AssetAdmin\Controller\AssetAdmin;
use SilverStripe\AssetAdmin\Forms\UploadField;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Image;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Forms\FieldList;

/**
 * BROWSER-TEST FIXTURE ONLY - the content behind the README screenshots
 * (tests/browser/screenshots/, see its README.md). A published page at /svg-showcase with a
 * has_one Image holding an SVG, and a "Showcase" folder in Files that mixes SVGs with raster
 * images. SvgShowcasePageController renders the page's vector variants.
 *
 * Never loaded by a real install: it lives under tests/browser/, which carries a _manifest_exclude
 * marker, and the browser-test runner copies it (with files/) into a scratch host's app/ before
 * dev/build. Extends SiteTree, not Page: the scratch host has no app Page class.
 *
 * @property int $HeroImageID
 * @method Image HeroImage()
 */
class SvgShowcasePage extends SiteTree
{
    # Short table name: no namespaced default.
    private static $table_name = 'SvgShowcasePage';

    private static $singular_name = 'Showcase page';

    private static $has_one = [
        'HeroImage' => Image::class,
    ];

    # Publishing the page publishes its image, so the front end can show it and its variants.
    private static $owns = ['HeroImage'];

    /** Folder in Files that the screenshots show. */
    public const FOLDER = 'Showcase';

    /** The SVG used as the page's hero image (640 x 400). */
    public const HERO_FILENAME = self::FOLDER . '/landscape.svg';

    /**
     * The same drawing as a raster JPG, drawn with GD in seedRaster(), for the "zoomed in"
     * comparison on the front end. Never made from the SVG: SVGs are never rasterised here.
     */
    public const RASTER_FILENAME = self::FOLDER . '/landscape-raster.jpg';

    public function getCMSFields()
    {
        $fields = parent::getCMSFields();
        # Right below the title, so one screenshot of the top of the form shows it.
        $fields->insertAfter(
            'MenuTitle',
            UploadField::create('HeroImage', 'Hero image')
                ->setAllowedFileCategories('image/supported')
                ->setFolderName(self::FOLDER)
        );
        return $fields;
    }

    public function requireDefaultRecords()
    {
        parent::requireDefaultRecords();

        Folder::find_or_make(self::FOLDER);

        # Vector drawings from files/showcase/, published so their URLs and variants are public.
        foreach (['landscape', 'badge', 'chart', 'pattern'] as $name) {
            $filename = self::FOLDER . "/{$name}.svg";
            if (!SVGImage::get()->filter('FileFilename', $filename)->exists()) {
                $image = SVGImage::create();
                $image->setFromLocalFile(__DIR__ . "/files/showcase/{$name}.svg", $filename);
                $image->write();
                $image->publishSingle();
            }
        }

        # Raster images next to them, so the Files screenshot shows both kinds side by side.
        if (function_exists('imagecreatetruecolor')) {
            $this->seedRaster(self::RASTER_FILENAME, 'jpg');
            $this->seedRaster(self::FOLDER . '/sunset-photo.png', 'png');
        }

        $hero = SVGImage::get()->filter('FileFilename', self::HERO_FILENAME)->first();
        if (!static::get()->filter('URLSegment', 'svg-showcase')->exists()) {
            $page = static::create([
                'Title' => 'SVG showcase',
                'URLSegment' => 'svg-showcase',
                'HeroImageID' => $hero ? $hero->ID : 0,
            ]);
            $page->write();
            $page->publishRecursive();
        }
    }

    /**
     * Draw the landscape (same shapes as files/showcase/landscape.svg) with GD and store it as a
     * published raster Image. Drawn from scratch, never converted from the SVG.
     */
    private function seedRaster(string $filename, string $type): void
    {
        $existing = Image::get()->filter('FileFilename', $filename)->first();
        if ($existing) {
            $this->generateThumbnails($existing);
            return;
        }

        $w = 640;
        $h = 400;
        $gd = imagecreatetruecolor($w, $h);

        # Sky: vertical gradient over three stops, as in the SVG.
        $stops = [[0.0, [0x1e, 0x3a, 0x8a]], [0.55, [0xf4, 0x72, 0xb6]], [1.0, [0xfd, 0xba, 0x74]]];
        for ($y = 0; $y < $h; $y++) {
            $t = $y / ($h - 1);
            for ($i = 0; $i < count($stops) - 1 && $t > $stops[$i + 1][0]; $i++);
            [$t0, $c0] = $stops[$i];
            [$t1, $c1] = $stops[$i + 1];
            $f = ($t - $t0) / ($t1 - $t0);
            $rgb = array_map(fn ($a, $b) => (int) round($a + ($b - $a) * $f), $c0, $c1);
            imageline($gd, 0, $y, $w - 1, $y, imagecolorallocate($gd, ...$rgb));
        }

        imagefilledellipse($gd, 452, 212, 124, 124, imagecolorallocate($gd, 0xfd, 0xe6, 0x8a));
        imagefilledpolygon($gd, [0, 290, 120, 150, 210, 240, 300, 120, 420, 260, 500, 190, 640, 300, 640, 400, 0, 400], imagecolorallocate($gd, 0x4c, 0x1d, 0x95));
        imagefilledpolygon($gd, [0, 320, 90, 250, 190, 300, 290, 220, 400, 310, 520, 250, 640, 320, 640, 400, 0, 400], imagecolorallocate($gd, 0x31, 0x2e, 0x81));
        imagefilledrectangle($gd, 0, 320, $w - 1, $h - 1, imagecolorallocate($gd, 0x2e, 0x1d, 0x6e));
        $glow = imagecolorallocate($gd, 0xc9, 0xa8, 0x7a);
        imagefilledrectangle($gd, 404, 334, 500, 338, $glow);
        imagefilledrectangle($gd, 420, 348, 484, 352, $glow);
        imagefilledrectangle($gd, 436, 362, 468, 366, $glow);

        ob_start();
        $type === 'png' ? imagepng($gd) : imagejpeg($gd, null, 85);
        $bytes = (string) ob_get_clean();

        $image = Image::create();
        $image->setFromString($bytes, $filename);
        $image->write();
        $image->publishSingle();
        $this->generateThumbnails($image);
    }

    /**
     * Make the Files thumbnails an upload makes. The Files gallery never generates them on display
     * (asset-admin config: "Generates: false"), so a raster image seeded from code would show an
     * empty tile. SVGs need no such step: this module's thumbnails are made from the markup.
     */
    private function generateThumbnails(Image $image): void
    {
        AssetAdmin::singleton()->generateThumbnails($image);
    }
}

<?php

namespace Restruct\SvgBrowser;

use Restruct\Silverstripe\SVG\SVGImage;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Config\Collections\MutableConfigCollectionInterface;
use SilverStripe\Core\Config\Config;

/**
 * BROWSER-TEST FIXTURE ONLY - a published front-end page at /svgb-page whose controller
 * (SvgBPageController) shows a published SVG and some of its vector variants, and the seeded
 * published SVG itself (files/svgb-clean.svg, 200 x 150).
 *
 * Never loaded by a real install: it lives under tests/browser/, which carries a _manifest_exclude
 * marker, and the browser-test runner copies it (with files/) into a scratch host's app/ before
 * dev/build. Extends SiteTree, not Page: the scratch host has no app Page class.
 */
class SvgBPage extends SiteTree
{
    # Short table name: no namespaced default.
    private static $table_name = 'SvgBPage';

    /** Filename of the seeded SVG in the asset store. */
    public const SEED_FILENAME = 'svgb/svgb-clean.svg';

    /**
     * An UNSANITIZED draft SVG with a script, as files uploaded before 3.0 (or refused-today
     * files stored before #23 was fixed) still sit in real asset stores. Draft, so it is served
     * through PHP (ProtectedFileController), where the module adds its Content-Security-Policy.
     */
    public const LEGACY_DIRTY_FILENAME = 'svgb/svgb-legacy-dirty.svg';

    public function requireDefaultRecords()
    {
        parent::requireDefaultRecords();

        # The seeded image, published, so its URL and its variants' URLs are public.
        if (!SVGImage::get()->filter('FileFilename', self::SEED_FILENAME)->exists()) {
            $image = SVGImage::create();
            $image->setFromLocalFile(__DIR__ . '/files/svgb-clean.svg', self::SEED_FILENAME);
            $image->write();
            $image->publishSingle();
        }

        if (!SVGImage::get()->filter('FileFilename', self::LEGACY_DIRTY_FILENAME)->exists()) {
            # Written with sanitizing off, which is the only way to store such a file now
            Config::withConfig(function (MutableConfigCollectionInterface $config) {
                $config->set(SVGImage::class, 'sanitize_on_upload', false);
                $image = SVGImage::create();
                $image->setFromLocalFile(__DIR__ . '/files/svgb-dirty.svg', self::LEGACY_DIRTY_FILENAME);
                $image->write();
            });
        }

        if (!static::get()->filter('URLSegment', 'svgb-page')->exists()) {
            $page = static::create(['Title' => 'SVG variants', 'URLSegment' => 'svgb-page']);
            $page->write();
            $page->publishRecursive();
        }
    }
}

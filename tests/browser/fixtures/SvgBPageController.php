<?php

namespace Restruct\SvgBrowser;

use Restruct\Silverstripe\SVG\SVGImage;
use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Versioned\Versioned;

/**
 * BROWSER-TEST FIXTURE ONLY - controller of SvgBPage. Renders its own minimal HTML (the scratch
 * host has no theme): the original and a few variants as <img> tags, each with a data-expect
 * attribute naming the size the variant should have.
 */
class SvgBPageController extends ContentController
{
    public function index()
    {
        $image = SVGImage::get()->filter('FileFilename', SvgBPage::SEED_FILENAME)->first();
        $body = '<h1>SVG variants</h1>';
        if ($image) {
            # name => [variant, expected width, expected height]
            $variants = [
                'original' => [$image, 200, 150],
                'scalewidth' => [$image->ScaleWidth(100), 100, 75],
                'fill' => [$image->Fill(80, 80), 80, 80],
                'fit' => [$image->Fit(60, 60), 60, 45],
                'pad' => [$image->Pad(100, 100), 100, 100],
                # #24: these used to go to the raster backend. ResizedImage() is what a WYSIWYG
                # image resized by an editor calls; Resampled() returns the SVG itself.
                'resized' => [$image->ResizedImage(90, 30), 90, 30],
                'resampled' => [$image->Resampled(), 200, 150],
            ];
            foreach ($variants as $name => [$variant, $w, $h]) {
                $body .= sprintf(
                    '<img id="svgb-%s" src="%s" data-expect-width="%d" data-expect-height="%d" alt="%s">',
                    $name,
                    htmlspecialchars((string) $variant->getURL()),
                    $w,
                    $h,
                    $name
                );
            }
        }

        # #23: a link to an unsanitized draft SVG (seeded the way files were stored before the
        # fix), so a spec can open it as a document and check its script is blocked.
        # It is a draft and this page renders on the live stage, so read the draft stage explicitly.
        $legacy = Versioned::get_by_stage(SVGImage::class, Versioned::DRAFT)
            ->filter('FileFilename', SvgBPage::LEGACY_DIRTY_FILENAME)->first();
        if ($legacy) {
            $body .= sprintf('<a id="svgb-legacy-dirty" href="%s">legacy</a>', htmlspecialchars((string) $legacy->getURL()));
        }

        $response = HTTPResponse::create(
            '<!DOCTYPE html><html><head><meta charset="utf-8"><title>svgb</title></head><body>'
            . $body . '</body></html>'
        );
        $response->addHeader('Content-Type', 'text/html; charset=utf-8');
        return $response;
    }
}

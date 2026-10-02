<?php

namespace Restruct\SvgBrowser;

use Restruct\Silverstripe\SVG\SVGImage;
use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\Control\HTTPResponse;

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

        $response = HTTPResponse::create(
            '<!DOCTYPE html><html><head><meta charset="utf-8"><title>svgb</title></head><body>'
            . $body . '</body></html>'
        );
        $response->addHeader('Content-Type', 'text/html; charset=utf-8');
        return $response;
    }
}

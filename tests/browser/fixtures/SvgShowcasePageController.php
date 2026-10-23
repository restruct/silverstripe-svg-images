<?php

namespace Restruct\SvgBrowser;

use SilverStripe\Assets\Image;
use SilverStripe\CMS\Controllers\ContentController;
use SilverStripe\Control\HTTPResponse;

/**
 * BROWSER-TEST FIXTURE ONLY - controller of SvgShowcasePage, the front end of the README
 * screenshot. Renders its own HTML (the scratch host has no theme): the page's SVG hero image
 * through a range of core image methods, each labelled with the call, the size of the file it
 * returned and its type, and one tiny variant of the SVG and of a raster copy blown up 5x.
 *
 * @extends ContentController<SvgShowcasePage>
 */
class SvgShowcasePageController extends ContentController
{
    public function index()
    {
        /** @var SvgShowcasePage $page */
        $page = $this->data();
        $svg = $page->HeroImage();
        $raster = Image::get()->filter('FileFilename', SvgShowcasePage::RASTER_FILENAME)->first();

        $cards = '';
        if ($svg && $svg->exists()) {
            # label => variant; every one is a new .svg file in the asset store
            $variants = [
                '$HeroImage' => $svg,
                '.ScaleWidth(240)' => $svg->ScaleWidth(240),
                '.Fill(150, 150)' => $svg->Fill(150, 150),
                '.Fill(300, 100)' => $svg->Fill(300, 100),
                '.Pad(150, 150)' => $svg->Pad(150, 150),
                '.CropRegion(380, 140, 150, 150)' => $svg->CropRegion(380, 140, 150, 150),
            ];
            foreach ($variants as $label => $variant) {
                # Each at its own size, except the 640 x 400 original: shown at half size to fit.
                $cards .= $variant === $svg
                    ? $this->card($label, $variant, 320, 200)
                    : $this->card($label, $variant, $variant->getWidth(), $variant->getHeight());
            }
        }

        $zoom = '';
        if ($svg && $svg->exists() && $raster) {
            # The same tiny variant of both, shown 5x larger than the file is: the SVG stays sharp.
            $zoom = '<section class="zoom"><h2>The same <code>.Fill(64, 40)</code>, shown 5x larger</h2><div class="row">'
                . $this->card('SVG upload', $svg->Fill(64, 40), 320, 200)
                . $this->card('JPG upload', $raster->Fill(64, 40), 320, 200)
                . '</div></section>';
        }

        $html = <<<HTML
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>SVG showcase</title>
<style>
  body { margin: 0; background: #f1f5f9; color: #0f172a; font: 15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; }
  main { padding: 32px 40px 40px; }
  h1 { font-size: 26px; margin: 0 0 4px; }
  h2 { font-size: 17px; margin: 32px 0 12px; }
  p.lead { margin: 0 0 24px; color: #475569; }
  .row { display: flex; flex-wrap: wrap; gap: 16px; align-items: flex-start; }
  figure { margin: 0; background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 12px; }
  figure .img { display: inline-block; vertical-align: top; background: #fff; background-image: linear-gradient(45deg, #e2e8f0 25%, transparent 25%, transparent 75%, #e2e8f0 75%), linear-gradient(45deg, #e2e8f0 25%, transparent 25%, transparent 75%, #e2e8f0 75%); background-size: 16px 16px; background-position: 0 0, 8px 8px; }
  figure img { display: block; }
  figcaption { margin-top: 8px; font-size: 13px; color: #475569; }
  figcaption code { display: block; color: #0f172a; font: 600 13px/1.4 ui-monospace, SFMono-Regular, Menlo, monospace; }
</style></head>
<body><main id="showcase">
<h1>One SVG upload, cut to size</h1>
<p class="lead">Core image methods called in a template on an SVG return new SVG files, resized and cropped by rewriting the vector markup.</p>
<div class="row">{$cards}</div>
{$zoom}
</main></body></html>
HTML;

        $response = HTTPResponse::create($html);
        $response->addHeader('Content-Type', 'text/html; charset=utf-8');
        return $response;
    }

    /**
     * One labelled image: the call, then the size and type of the file it returned (read from the
     * file, not assumed), shown at $showWidth x $showHeight CSS pixels.
     */
    private function card(string $label, $file, int $showWidth, int $showHeight): string
    {
        $url = (string) $file->getURL();
        $ext = strtolower(pathinfo((string) parse_url($url, PHP_URL_PATH), PATHINFO_EXTENSION));
        return sprintf(
            '<figure><span class="img"><img src="%s" width="%d" height="%d" alt="%s"></span>'
            . '<figcaption><code>%s</code>%d x %d px .%s file, %s</figcaption></figure>',
            htmlspecialchars($url),
            $showWidth,
            $showHeight,
            htmlspecialchars($label),
            htmlspecialchars($label),
            $file->getWidth(),
            $file->getHeight(),
            htmlspecialchars($ext),
            htmlspecialchars((string) $file->getSize())
        );
    }
}

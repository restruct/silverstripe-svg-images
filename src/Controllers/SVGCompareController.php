<?php

namespace Restruct\Silverstripe\SVG\Controllers;

use Restruct\Silverstripe\SVG\SVGImage;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Folder;
use SilverStripe\Assets\Image;
use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\View\ArrayData;
use SilverStripe\ORM\ArrayList;

/**
 * Development controller to compare SVG vs raster image manipulations.
 *
 * Access at: /dev/svg-compare
 *
 * Usage:
 *   /dev/svg-compare - Shows options to install test images or provide IDs
 *   /dev/svg-compare?svg=123&png=456 - Run test with specific image IDs
 *   /dev/svg-compare?install=1 - Install test images and run test
 *   /dev/svg-compare?remove=1 - Remove test images
 */
class SVGCompareController extends Controller
{
    private static $url_segment = 'dev/svg-compare';

    private static $allowed_actions = [
        'index',
    ];

    private static $test_folder = 'svg-compare-test';
    private static $test_svg_name = 'test-pub.svg';
    private static $test_png_name = 'test-pub.png';
    private static $test_svg_draft_name = 'test-draft.svg';
    private static $test_png_draft_name = 'test-draft.png';

    protected function init(): void
    {
        parent::init();

        // Only allow in dev mode or for admins
        if (!Director::isDev() && !Permission::check('ADMIN')) {
            Security::permissionFailure($this);
        }
    }

    public function index(HTTPRequest $request)
    {
        // Handle install action
        if ($request->getVar('install')) {
            try {
                $error = $this->installTestImages();
                if ($error) {
                    return $this->renderSetup($error, false);
                }
            } catch (\Exception $e) {
                return $this->renderSetup('Install failed: ' . $e->getMessage(), false);
            }
            return $this->redirect($this->Link());
        }

        // Handle remove action
        if ($request->getVar('remove')) {
            $this->removeTestImages();
            return $this->redirect($this->Link());
        }

        $svgId = $request->getVar('svg');
        $pngId = $request->getVar('png');
        $testImagesInstalled = $this->testImagesInstalled();

        // If specific IDs provided, use those
        if ($svgId && $pngId) {
            $svgImage = File::get()->byID($svgId);
            $pngImage = File::get()->byID($pngId);

            if (!$svgImage || !$pngImage) {
                return $this->renderSetup(
                    'One or both of the specified images could not be found.',
                    $testImagesInstalled
                );
            }

            return $this->renderComparison($svgImage, $pngImage, false);
        }

        // If test images are installed, use those
        if ($testImagesInstalled) {
            $svgImage = $this->getBundledTestSVG(false);
            $pngImage = $this->getBundledTestPNG(false);

            return $this->renderComparison($svgImage, $pngImage, true);
        }

        // Otherwise show setup page
        return $this->renderSetup(null, false);
    }

    /**
     * Render the setup/welcome page.
     */
    protected function renderSetup(?string $error, bool $testImagesInstalled)
    {
        return $this->customise([
            'Title' => 'SVG vs PNG Manipulation Comparison',
            'ShowSetup' => true,
            'Error' => $error,
            'TestImagesInstalled' => $testImagesInstalled,
            'InstallURL' => $this->Link('?install=1'),
        ])->renderWith(['Restruct/Silverstripe/SVG/SVGCompare']);
    }

    /**
     * Render the comparison page.
     */
    protected function renderComparison($svgImage, $pngImage, bool $usingTestImages)
    {
        $manipulations = $this->getManipulations();

        // Generate comparison data for published images
        $comparisons = ArrayList::create();
        foreach ($manipulations as $manipulation) {
            $comparison = $this->generateComparison($svgImage, $pngImage, $manipulation);
            if ($comparison) {
                $comparisons->push($comparison);
            }
        }

        // Check for draft/unpublished test images
        $draftSvg = $this->getBundledTestSVG(true);
        $draftPng = $this->getBundledTestPNG(true);
        $hasDraftImages = $draftSvg && $draftPng && $usingTestImages;

        // Generate comparison data for draft images
        $draftComparisons = ArrayList::create();
        if ($hasDraftImages) {
            foreach ($manipulations as $manipulation) {
                $comparison = $this->generateComparison($draftSvg, $draftPng, $manipulation);
                if ($comparison) {
                    $draftComparisons->push($comparison);
                }
            }
        }

        return $this->customise([
            'Title' => 'SVG vs PNG Manipulation Comparison',
            'ShowSetup' => false,
            'SVGImage' => $svgImage,
            'PNGImage' => $pngImage,
            'Comparisons' => $comparisons,
            'OriginalSVG' => $this->getImageData($svgImage),
            'OriginalPNG' => $this->getImageData($pngImage),
            'UsingTestImages' => $usingTestImages,
            'RemoveURL' => $this->Link('?remove=1'),
            // Draft image data
            'HasDraftImages' => $hasDraftImages,
            'DraftSVGImage' => $draftSvg,
            'DraftPNGImage' => $draftPng,
            'DraftComparisons' => $draftComparisons,
            'OriginalDraftSVG' => $draftSvg ? $this->getImageData($draftSvg) : null,
            'OriginalDraftPNG' => $draftPng ? $this->getImageData($draftPng) : null,
        ])->renderWith(['Restruct/Silverstripe/SVG/SVGCompare']);
    }

    /**
     * Install bundled test images to the database.
     *
     * @return string|null Error message or null on success
     */
    protected function installTestImages(): ?string
    {
        if ($this->testImagesInstalled()) {
            return null;
        }

        $folder = Folder::find_or_make(self::config()->get('test_folder'));
        $folderPath = rtrim($folder->getFilename(), '/');

        // Generate all test content from strings (no file path dependencies)
        $svgContent = $this->generateTestSVG(false);
        $svgDraftContent = $this->generateTestSVG(true);
        $pngContent = $this->generateTestPNG(false);
        $pngDraftContent = $this->generateTestPNG(true);

        if (!$pngContent || !$pngDraftContent) {
            return 'Could not generate PNG test images. Is the GD extension (ext-gd) installed?';
        }

        // Install PUBLISHED SVG
        $svg = SVGImage::create();
        $svg->setFromString($svgContent, $folderPath . '/' . self::config()->get('test_svg_name'));
        $svg->Title = 'SVG Compare Test (Published)';
        $svg->write();
        $svg->publishSingle();

        // Install PUBLISHED PNG
        $png = Image::create();
        $png->setFromString($pngContent, $folderPath . '/' . self::config()->get('test_png_name'));
        $png->Title = 'PNG Compare Test (Published)';
        $png->write();
        $png->publishSingle();

        // Install DRAFT SVG (not published)
        $svgDraft = SVGImage::create();
        $svgDraft->setFromString($svgDraftContent, $folderPath . '/' . self::config()->get('test_svg_draft_name'));
        $svgDraft->Title = 'SVG Compare Test (Draft/Unpublished)';
        $svgDraft->write();

        // Install DRAFT PNG (not published)
        $pngDraft = Image::create();
        $pngDraft->setFromString($pngDraftContent, $folderPath . '/' . self::config()->get('test_png_draft_name'));
        $pngDraft->Title = 'PNG Compare Test (Draft/Unpublished)';
        $pngDraft->write();

        return null;
    }

    /**
     * Remove test images from the database.
     */
    protected function removeTestImages(): void
    {
        $folderName = self::config()->get('test_folder');

        // Find and delete all test files
        foreach ([false, true] as $draft) {
            $svg = $this->getBundledTestSVG($draft);
            if ($svg) {
                $svg->deleteFromStage('Live');
                $svg->delete();
            }

            $png = $this->getBundledTestPNG($draft);
            if ($png) {
                $png->deleteFromStage('Live');
                $png->delete();
            }
        }

        // Delete folder if empty
        $folder = Folder::find($folderName);
        if ($folder && $folder->myChildren()->count() === 0) {
            $folder->delete();
        }
    }

    /**
     * Check if bundled test images are installed.
     */
    protected function testImagesInstalled(): bool
    {
        return $this->getBundledTestSVG(false) !== null && $this->getBundledTestPNG(false) !== null;
    }

    /**
     * Get the bundled test SVG from database.
     */
    protected function getBundledTestSVG(bool $draft = false): ?SVGImage
    {
        $fileName = $draft
            ? self::config()->get('test_svg_draft_name')
            : self::config()->get('test_svg_name');

        // Try by Name first (most reliable), then by FileFilename
        return SVGImage::get()->filter('Name', $fileName)->first()
            ?: SVGImage::get()->filter('FileFilename:EndsWith', $fileName)->first();
    }

    /**
     * Get the bundled test PNG from database.
     */
    protected function getBundledTestPNG(bool $draft = false): ?Image
    {
        $fileName = $draft
            ? self::config()->get('test_png_draft_name')
            : self::config()->get('test_png_name');

        // Try by Name first (most reliable), then by FileFilename
        return Image::get()->filter('Name', $fileName)->first()
            ?: Image::get()->filter('FileFilename:EndsWith', $fileName)->first();
    }

    /**
     * Generate SVG test image as a string.
     */
    protected function generateTestSVG(bool $draft = false): string
    {
        $bgColor = $draft ? '#8e44ad' : '#3498db';
        $text = $draft ? 'SVG Draft' : 'SVG Test';

        return <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 150" width="200" height="150">
  <rect width="200" height="150" fill="{$bgColor}"/>
  <circle cx="60" cy="75" r="40" fill="#e74c3c"/>
  <rect x="110" y="35" width="70" height="80" fill="#2ecc71" rx="5"/>
  <polygon points="145,115 110,145 180,145" fill="#f39c12"/>
  <text x="100" y="25" text-anchor="middle" font-family="Arial, sans-serif" font-size="14" font-weight="bold" fill="white">{$text}</text>
</svg>
SVG;
    }

    /**
     * Generate a PNG test image that matches the SVG.
     */
    protected function generateTestPNG(bool $draft = false): ?string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return null;
        }

        $width = 200;
        $height = 150;

        $img = imagecreatetruecolor($width, $height);
        imagealphablending($img, true);
        imagesavealpha($img, true);

        // Colors - purple for draft, blue for published
        if ($draft) {
            $bg = imagecolorallocate($img, 142, 68, 173);   // #8e44ad purple
        } else {
            $bg = imagecolorallocate($img, 52, 152, 219);   // #3498db blue
        }
        $red = imagecolorallocate($img, 231, 76, 60);
        $green = imagecolorallocate($img, 46, 204, 113);
        $orange = imagecolorallocate($img, 243, 156, 18);
        $white = imagecolorallocate($img, 255, 255, 255);

        imagefilledrectangle($img, 0, 0, $width, $height, $bg);
        imagefilledellipse($img, 60, 75, 80, 80, $red);
        imagefilledrectangle($img, 110, 35, 180, 115, $green);
        imagefilledpolygon($img, [145, 115, 110, 145, 180, 145], $orange);

        $text = $draft ? 'PNG Draft' : 'PNG Test';
        $textWidth = imagefontwidth(3) * strlen($text);
        imagestring($img, 3, (int)(($width - $textWidth) / 2), 10, $text, $white);

        ob_start();
        imagepng($img);
        $content = ob_get_clean();
        imagedestroy($img);

        return $content;
    }

    /**
     * Get the list of manipulations to test.
     */
    protected function getManipulations(): array
    {
        return [
            // Fit - scale to fit within bounds
            ['method' => 'Fit', 'args' => [150, 150], 'label' => 'Fit(150, 150)'],
            ['method' => 'Fit', 'args' => [200, 100], 'label' => 'Fit(200, 100)'],
            ['method' => 'Fit', 'args' => [100, 200], 'label' => 'Fit(100, 200)'],

            // FitMax - fit only if larger
            ['method' => 'FitMax', 'args' => [150, 150], 'label' => 'FitMax(150, 150)'],
            ['method' => 'FitMax', 'args' => [500, 500], 'label' => 'FitMax(500, 500)'],

            // Fill - crop to fill exact dimensions
            ['method' => 'Fill', 'args' => [150, 150], 'label' => 'Fill(150, 150)'],
            ['method' => 'Fill', 'args' => [200, 100], 'label' => 'Fill(200, 100)'],
            ['method' => 'Fill', 'args' => [100, 200], 'label' => 'Fill(100, 200)'],

            // FillMax - fill only if larger
            ['method' => 'FillMax', 'args' => [150, 150], 'label' => 'FillMax(150, 150)'],
            ['method' => 'FillMax', 'args' => [500, 500], 'label' => 'FillMax(500, 500)'],

            // Pad - fit with padding to exact dimensions
            ['method' => 'Pad', 'args' => [200, 200], 'label' => 'Pad(200, 200)'],
            ['method' => 'Pad', 'args' => [300, 150], 'label' => 'Pad(300, 150)'],
            ['method' => 'Pad', 'args' => [150, 300], 'label' => 'Pad(150, 300)'],

            // ScaleWidth/Height
            ['method' => 'ScaleWidth', 'args' => [150], 'label' => 'ScaleWidth(150)'],
            ['method' => 'ScaleWidth', 'args' => [300], 'label' => 'ScaleWidth(300)'],
            ['method' => 'ScaleHeight', 'args' => [100], 'label' => 'ScaleHeight(100)'],
            ['method' => 'ScaleHeight', 'args' => [200], 'label' => 'ScaleHeight(200)'],

            // Chained manipulations
            [
                'chain' => [
                    ['method' => 'ScaleWidth', 'args' => [200]],
                    ['method' => 'Fill', 'args' => [100, 100]],
                ],
                'label' => 'ScaleWidth(200)->Fill(100, 100)',
            ],
            [
                'chain' => [
                    ['method' => 'Fit', 'args' => [200, 200]],
                    ['method' => 'Pad', 'args' => [250, 250]],
                ],
                'label' => 'Fit(200, 200)->Pad(250, 250)',
            ],
            [
                'chain' => [
                    ['method' => 'Fill', 'args' => [150, 150]],
                    ['method' => 'ScaleWidth', 'args' => [100]],
                ],
                'label' => 'Fill(150, 150)->ScaleWidth(100)',
            ],
        ];
    }

    /**
     * Generate comparison data for a manipulation.
     */
    protected function generateComparison($svgImage, $pngImage, array $manipulation): ?ArrayData
    {
        $label = $manipulation['label'];

        try {
            $svgResult = $this->applyManipulation($svgImage, $manipulation);
            $svgData = $svgResult ? $this->getImageData($svgResult) : null;

            $pngResult = $this->applyManipulation($pngImage, $manipulation);
            $pngData = $pngResult ? $this->getImageData($pngResult) : null;

            return ArrayData::create([
                'Label' => $label,
                'SVG' => $svgData,
                'PNG' => $pngData,
                'IsChained' => isset($manipulation['chain']),
            ]);
        } catch (\Exception $e) {
            return ArrayData::create([
                'Label' => $label,
                'Error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Apply a manipulation (single or chained) to an image.
     */
    protected function applyManipulation($image, array $manipulation)
    {
        if (isset($manipulation['chain'])) {
            $result = $image;
            foreach ($manipulation['chain'] as $step) {
                if (!$result) {
                    return null;
                }
                $result = $result->{$step['method']}(...$step['args']);
            }
            return $result;
        }

        return $image->{$manipulation['method']}(...$manipulation['args']);
    }

    /**
     * Get display data for an image result.
     */
    protected function getImageData($image): ArrayData
    {
        $url = $image->getURL();
        $filename = basename($url);
        $width = method_exists($image, 'getWidth') ? $image->getWidth() : 0;
        $height = method_exists($image, 'getHeight') ? $image->getHeight() : 0;

        return ArrayData::create([
            'URL' => $url,
            'Filename' => $filename,
            'Width' => $width,
            'Height' => $height,
            'Dimensions' => $width && $height ? "{$width}x{$height}" : 'unknown',
            'IsSVG' => pathinfo($filename, PATHINFO_EXTENSION) === 'svg',
        ]);
    }
}

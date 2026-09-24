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
// ArrayData/ArrayList are NOT imported: they moved namespace in Silverstripe 6
// (View\ArrayData -> Model\ArrayData, ORM\ArrayList -> Model\List\ArrayList) with no alias left
// behind, so the class names are resolved per major - see arrayDataClass()/arrayListClass().

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

    private static $test_folder = 'devtest-svg';
    private static $test_svg_name = 'svgtest-pub.svg';
    private static $test_png_name = 'svgtest-pub.png';
    private static $test_svg_draft_name = 'svgtest-draft.svg';
    private static $test_png_draft_name = 'svgtest-draft.png';

    protected function init(): void
    {
        parent::init();
        // Was: "Security handled by DevelopmentAdmin middleware (CSRF protection, auth)". It is not.
        // DevelopmentAdmin only refuses a user who can see NO dev link at all, then hands the
        // request to a registered controller unchecked; and the dev-URL confirmation middleware
        // does not stop a non-admin either (SS5 passes them straight through; SS6 asks them to
        // confirm, which they can click through). So in live mode anyone who can see one dev link
        // (e.g. holding BUILDTASK_CAN_RUN) reached this page - whose ?install/?remove write to the
        // asset store. Core dev controllers guard themselves the same way (TaskRunner::canInit()).
        if (!$this->canInit()) {
            Security::permissionFailure($this);
        }
    }

    /**
     * Who may use this page: anyone in dev mode, otherwise administrators only.
     *
     * Also consulted by DevelopmentAdmin (SS5) when deciding whether to list the link on /dev.
     */
    public function canInit(): bool
    {
        return Director::isDev() || Permission::check(['ADMIN', 'ALL_DEV_ADMIN']);
    }

    /**
     * Override Link() for registered_controllers compatibility.
     * When accessed via DevelopmentAdmin, we need to return the full dev/* path.
     */
    public function Link($action = null): string
    {
        $link = '/' . self::config()->get('url_segment');
        if ($action) {
            $link .= $action;
        }
        return $link;
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
            'TestFolder' => self::config()->get('test_folder'),
        ])->renderWith(['Restruct/Silverstripe/SVG/SVGCompare']);
    }

    /**
     * Render the comparison page.
     */
    protected function renderComparison($svgImage, $pngImage, bool $usingTestImages)
    {
        $manipulations = $this->getManipulations();

        // Generate comparison data for published images
        $comparisons = static::arrayListClass()::create();
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
        $draftComparisons = static::arrayListClass()::create();
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
            'HasFocusPointModule' => $this->hasFocusPointExtension(),
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

        // Check if FocusPoint module is available
        $hasFocusPoint = $this->hasFocusPointExtension();

        // Generate all test content from strings (no file path dependencies)
        // Include crosshair in images when FocusPoint module is available
        $svgContent = $this->generateTestSVG(false, $hasFocusPoint);
        $svgDraftContent = $this->generateTestSVG(true, $hasFocusPoint);
        $pngContent = $this->generateTestPNG(false, $hasFocusPoint);
        $pngDraftContent = $this->generateTestPNG(true, $hasFocusPoint);

        if (!$pngContent || !$pngDraftContent) {
            return 'Could not generate PNG test images. Is the GD extension (ext-gd) installed?';
        }

        // FocusPoint at (180, 130) on 200x150 image - bottom right of triangle
        // Converted to -1 to 1 scale: X = (180/200)*2-1 = 0.8, Y = (130/150)*2-1 = 0.73
        $focusPointX = 0.8;
        $focusPointY = 0.73;

        // Install PUBLISHED SVG
        $svg = SVGImage::create();
        $svg->setFromString($svgContent, $folderPath . '/' . self::config()->get('test_svg_name'));
        $svg->Title = 'SVG Compare Test (Published)';
        if ($hasFocusPoint) {
            $svg->FocusPointX = $focusPointX;
            $svg->FocusPointY = $focusPointY;
        }
        $svg->write();
        $svg->publishSingle();

        // Install PUBLISHED PNG
        $png = Image::create();
        $png->setFromString($pngContent, $folderPath . '/' . self::config()->get('test_png_name'));
        $png->Title = 'PNG Compare Test (Published)';
        if ($hasFocusPoint) {
            $png->FocusPointX = $focusPointX;
            $png->FocusPointY = $focusPointY;
        }
        $png->write();
        $png->publishSingle();

        // Install DRAFT SVG (not published)
        $svgDraft = SVGImage::create();
        $svgDraft->setFromString($svgDraftContent, $folderPath . '/' . self::config()->get('test_svg_draft_name'));
        $svgDraft->Title = 'SVG Compare Test (Draft/Unpublished)';
        if ($hasFocusPoint) {
            $svgDraft->FocusPointX = $focusPointX;
            $svgDraft->FocusPointY = $focusPointY;
        }
        $svgDraft->write();

        // Install DRAFT PNG (not published)
        $pngDraft = Image::create();
        $pngDraft->setFromString($pngDraftContent, $folderPath . '/' . self::config()->get('test_png_draft_name'));
        $pngDraft->Title = 'PNG Compare Test (Draft/Unpublished)';
        if ($hasFocusPoint) {
            $pngDraft->FocusPointX = $focusPointX;
            $pngDraft->FocusPointY = $focusPointY;
        }
        $pngDraft->write();

        return null;
    }

    /**
     * Remove test images from the database and filesystem.
     */
    protected function removeTestImages(): void
    {
        $folderName = self::config()->get('test_folder');

        // Find and delete all test files (both published and draft)
        foreach ([false, true] as $draft) {
            $svg = $this->getBundledTestSVG($draft);
            if ($svg) {
                // doArchive() removes from all stages and deletes the physical file
                $svg->doArchive();
            }

            $png = $this->getBundledTestPNG($draft);
            if ($png) {
                $png->doArchive();
            }
        }

        // Delete folder only if empty (no other files inside)
        $folder = Folder::find($folderName);
        if ($folder && $folder->myChildren()->count() === 0) {
            $folder->doArchive();
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
        $folderName = self::config()->get('test_folder');
        $fileName = $draft
            ? self::config()->get('test_svg_draft_name')
            : self::config()->get('test_svg_name');

        // Filter by folder path to avoid matching files with same name elsewhere
        return SVGImage::get()
            ->filter('FileFilename:StartsWith', $folderName . '/')
            ->filter('Name', $fileName)
            ->first();
    }

    /**
     * Get the bundled test PNG from database.
     */
    protected function getBundledTestPNG(bool $draft = false): ?Image
    {
        $folderName = self::config()->get('test_folder');
        $fileName = $draft
            ? self::config()->get('test_png_draft_name')
            : self::config()->get('test_png_name');

        // Filter by folder path to avoid matching files with same name elsewhere
        return Image::get()
            ->filter('FileFilename:StartsWith', $folderName . '/')
            ->filter('Name', $fileName)
            ->first();
    }

    /**
     * Generate SVG test image as a string.
     */
    protected function generateTestSVG(bool $draft = false, bool $includeFocusPoint = false): string
    {
        $bgColor = $draft ? '#8e44ad' : '#3498db';
        $text = $draft ? 'SVG Draft' : 'SVG Test';

        // FocusPoint crosshair at bottom right of triangle (180, 130)
        $focusPointMarker = '';
        if ($includeFocusPoint) {
            $fpX = 180;
            $fpY = 130;
            $focusPointMarker = <<<FP
  <!-- FocusPoint marker (crosshair) -->
  <circle cx="{$fpX}" cy="{$fpY}" r="8" fill="none" stroke="white" stroke-width="2"/>
  <line x1="{$fpX}" y1="118" x2="{$fpX}" y2="142" stroke="white" stroke-width="2"/>
  <line x1="168" y1="{$fpY}" x2="192" y2="{$fpY}" stroke="white" stroke-width="2"/>
FP;
        }

        return <<<SVG
<?xml version="1.0" encoding="UTF-8"?>
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 150" width="200" height="150">
  <rect width="200" height="150" fill="{$bgColor}"/>
  <circle cx="60" cy="75" r="40" fill="#e74c3c"/>
  <rect x="110" y="35" width="70" height="80" fill="#2ecc71" rx="5"/>
  <polygon points="145,115 110,145 180,145" fill="#f39c12"/>
  <text x="100" y="25" text-anchor="middle" font-family="Arial, sans-serif" font-size="14" font-weight="bold" fill="white">{$text}</text>
{$focusPointMarker}
</svg>
SVG;
    }

    /**
     * Generate a PNG test image that matches the SVG.
     */
    protected function generateTestPNG(bool $draft = false, bool $includeFocusPoint = false): ?string
    {
        if (!function_exists('imagecreatetruecolor')) {
            return null;
        }

        $width = 200;
        $height = 150;
        $fpX = 180; // FocusPoint X - bottom right of triangle
        $fpY = 130; // FocusPoint Y

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

        // FocusPoint crosshair marker at bottom right of triangle
        if ($includeFocusPoint) {
            imagesetthickness($img, 2);
            imageellipse($img, $fpX, $fpY, 16, 16, $white);
            imageline($img, $fpX, $fpY - 12, $fpX, $fpY + 12, $white);
            imageline($img, $fpX - 12, $fpY, $fpX + 12, $fpY, $white);
        }

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
        $manipulations = [
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

        // Add focus methods (requires jonom/focuspoint)
        if ($this->hasFocusPointExtension()) {
            $manipulations = array_merge($manipulations, [
                ['method' => 'FocusFill', 'args' => [150, 150], 'label' => 'FocusFill(150, 150)', 'optional' => 'focuspoint'],
                ['method' => 'FocusFill', 'args' => [200, 100], 'label' => 'FocusFill(200, 100)', 'optional' => 'focuspoint'],
                ['method' => 'FocusFillMax', 'args' => [150, 150], 'label' => 'FocusFillMax(150, 150)', 'optional' => 'focuspoint'],
                ['method' => 'FocusCropWidth', 'args' => [150], 'label' => 'FocusCropWidth(150)', 'optional' => 'focuspoint'],
                ['method' => 'FocusCropHeight', 'args' => [100], 'label' => 'FocusCropHeight(100)', 'optional' => 'focuspoint'],
            ]);
        }

        return $manipulations;
    }

    /**
     * Check if the FocusPoint extension is available.
     */
    protected function hasFocusPointExtension(): bool
    {
        return class_exists(\JonoM\FocusPoint\Extensions\FocusPointImageExtension::class);
    }

    /**
     * Generate comparison data for a manipulation.
     */
    protected function generateComparison($svgImage, $pngImage, array $manipulation): ?object
    {
        $label = $manipulation['label'];

        try {
            $svgResult = $this->applyManipulation($svgImage, $manipulation);
            $svgData = $svgResult ? $this->getImageData($svgResult) : null;

            $pngResult = $this->applyManipulation($pngImage, $manipulation);
            $pngData = $pngResult ? $this->getImageData($pngResult) : null;

            return static::arrayDataClass()::create([
                'Label' => $label,
                'SVG' => $svgData,
                'PNG' => $pngData,
                'IsChained' => isset($manipulation['chain']),
                'UsesFocusPoint' => ($manipulation['optional'] ?? null) === 'focuspoint',
            ]);
        } catch (\Exception $e) {
            return static::arrayDataClass()::create([
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
    protected function getImageData($image): object
    {
        $url = $image->getURL();
        $filename = basename($url);
        $width = method_exists($image, 'getWidth') ? $image->getWidth() : 0;
        $height = method_exists($image, 'getHeight') ? $image->getHeight() : 0;

        return static::arrayDataClass()::create([
            'URL' => $url,
            'Filename' => $filename,
            'Width' => $width,
            'Height' => $height,
            'Dimensions' => $width && $height ? "{$width}x{$height}" : 'unknown',
            'IsSVG' => pathinfo($filename, PATHINFO_EXTENSION) === 'svg',
        ]);
    }

    /**
     * ArrayData class for the running Silverstripe major.
     *
     * Silverstripe 6 moved it from SilverStripe\View to SilverStripe\Model and removed the old
     * name outright, so importing either one breaks this controller on the other major.
     */
    protected static function arrayDataClass(): string
    {
        return class_exists('SilverStripe\\Model\\ArrayData')
            ? 'SilverStripe\\Model\\ArrayData'
            : 'SilverStripe\\View\\ArrayData';
    }

    /**
     * ArrayList class for the running Silverstripe major (moved to SilverStripe\Model\List in 6).
     */
    protected static function arrayListClass(): string
    {
        return class_exists('SilverStripe\\Model\\List\\ArrayList')
            ? 'SilverStripe\\Model\\List\\ArrayList'
            : 'SilverStripe\\ORM\\ArrayList';
    }
}

<?php

namespace Restruct\Silverstripe\SVG\Tasks;

use Restruct\Silverstripe\SVG\SVGImage;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;
use SilverStripe\ORM\DataObject;

/**
 * ClearSVGVariantsTask - Removes all SVG variant files from the asset store.
 *
 * This task is useful when:
 * - Upgrading from the old SVG module that didn't do real manipulation
 * - SVG manipulation settings have changed
 * - You want to regenerate all SVG variants
 *
 * Usage:
 *   vendor/bin/sake dev/tasks/ClearSVGVariantsTask
 *   vendor/bin/sake dev/tasks/ClearSVGVariantsTask confirm=1
 *
 * Run without confirm=1 for a dry run that shows what would be deleted.
 */
class ClearSVGVariantsTask extends BuildTask
{
    private static $segment = 'ClearSVGVariantsTask';

    protected $title = 'Clear SVG Variants';

    protected $description = 'Removes all SVG variant files from the asset store. Run with confirm=1 to actually delete.';

    /**
     * @param \SilverStripe\Control\HTTPRequest $request
     * @return void
     */
    public function run($request): void
    {
        $confirm = $request->getVar('confirm') === '1';
        $verbose = $request->getVar('verbose') === '1';

        echo "<h2>Clear SVG Variants Task</h2>\n";

        if (!$confirm) {
            echo "<p><strong>DRY RUN</strong> - Add <code>confirm=1</code> to actually delete variants.</p>\n";
        }

        /** @var AssetStore $store */
        $store = Injector::inst()->get(AssetStore::class);

        // Get all SVGImage records
        $svgImages = SVGImage::get()->filter('Name:EndsWith', '.svg');
        $totalImages = $svgImages->count();
        $totalVariantsDeleted = 0;
        $totalVariantsFound = 0;

        echo "<p>Found {$totalImages} SVG images in the database.</p>\n";

        if ($totalImages === 0) {
            echo "<p>No SVG images to process.</p>\n";
            return;
        }

        echo "<ul>\n";

        /** @var SVGImage $image */
        foreach ($svgImages as $image) {
            if (!$image->exists()) {
                if ($verbose) {
                    echo "<li><em>{$image->Name}</em> - File does not exist, skipping</li>\n";
                }
                continue;
            }

            $filename = $image->getFilename();
            $hash = $image->getHash();

            if (empty($filename) || empty($hash)) {
                continue;
            }

            // Find and delete variants for this file
            $variantsDeleted = $this->deleteVariantsForFile($store, $filename, $hash, $confirm, $verbose);
            $totalVariantsFound += $variantsDeleted['found'];
            $totalVariantsDeleted += $variantsDeleted['deleted'];
        }

        echo "</ul>\n";

        echo "<h3>Summary</h3>\n";
        echo "<p>Total SVG variant files found: <strong>{$totalVariantsFound}</strong></p>\n";

        if ($confirm) {
            echo "<p>Total SVG variant files deleted: <strong>{$totalVariantsDeleted}</strong></p>\n";
            echo "<p>Variants will be regenerated on next request with the new manipulation code.</p>\n";
        } else {
            echo "<p>Run with <code>confirm=1</code> to delete these variants.</p>\n";
        }
    }

    /**
     * Delete all variants for a specific file.
     *
     * @param AssetStore $store
     * @param string $filename
     * @param string $hash
     * @param bool $confirm
     * @param bool $verbose
     * @return array{found: int, deleted: int}
     */
    protected function deleteVariantsForFile(
        AssetStore $store,
        string $filename,
        string $hash,
        bool $confirm,
        bool $verbose
    ): array {
        $found = 0;
        $deleted = 0;

        // Use FlysystemAssetStore's variant listing if available
        if ($store instanceof FlysystemAssetStore) {
            // Get all variants for this file
            $variants = $this->getVariantsForFile($store, $filename, $hash);

            foreach ($variants as $variant) {
                $found++;
                if ($verbose) {
                    echo "<li>{$filename} - variant: <code>{$variant}</code>";
                }

                if ($confirm) {
                    // Delete the variant
                    $store->delete($filename, $hash, $variant);
                    $deleted++;
                    if ($verbose) {
                        echo " - <span style=\"color:green\">DELETED</span>";
                    }
                } else {
                    if ($verbose) {
                        echo " - would be deleted";
                    }
                }

                if ($verbose) {
                    echo "</li>\n";
                }
            }
        }

        return [
            'found' => $found,
            'deleted' => $deleted,
        ];
    }

    /**
     * Get all variant names for a file.
     *
     * @param FlysystemAssetStore $store
     * @param string $filename
     * @param string $hash
     * @return array<string>
     */
    protected function getVariantsForFile(
        FlysystemAssetStore $store,
        string $filename,
        string $hash
    ): array {
        $variants = [];

        // Get the filesystem and list files in the hash directory
        try {
            // Use reflection to access the protected method for getting filesystem
            $reflection = new \ReflectionClass($store);

            // Try to get the public filesystem
            if ($reflection->hasMethod('getPublicFilesystem')) {
                $method = $reflection->getMethod('getPublicFilesystem');
                $method->setAccessible(true);
                $publicFs = $method->invoke($store);

                $variants = array_merge($variants, $this->findVariantsInFilesystem($publicFs, $filename, $hash));
            }

            // Try to get the protected filesystem
            if ($reflection->hasMethod('getProtectedFilesystem')) {
                $method = $reflection->getMethod('getProtectedFilesystem');
                $method->setAccessible(true);
                $protectedFs = $method->invoke($store);

                $variants = array_merge($variants, $this->findVariantsInFilesystem($protectedFs, $filename, $hash));
            }
        } catch (\Exception $e) {
            // Fall back to checking common variant names
            $variants = $this->getCommonVariantNames($store, $filename, $hash);
        }

        return array_unique($variants);
    }

    /**
     * Find variants in a filesystem.
     *
     * @param \League\Flysystem\FilesystemOperator $filesystem
     * @param string $filename
     * @param string $hash
     * @return array<string>
     */
    protected function findVariantsInFilesystem($filesystem, string $filename, string $hash): array
    {
        $variants = [];

        // Build the path to search
        $folder = dirname($filename);
        $basename = pathinfo($filename, PATHINFO_FILENAME);
        $hashPrefix = substr($hash, 0, 10);

        // The variant path format is: folder/hashprefix/basename__variant.ext
        $searchPath = $folder . '/' . $hashPrefix;

        try {
            $listing = $filesystem->listContents($searchPath);

            foreach ($listing as $item) {
                if ($item instanceof \League\Flysystem\FileAttributes) {
                    $itemPath = $item->path();
                    $itemBasename = pathinfo($itemPath, PATHINFO_FILENAME);

                    // Check if this is a variant file (contains __ in the name)
                    if (str_contains($itemBasename, '__') && str_starts_with($itemBasename, $basename . '__')) {
                        // Extract variant name
                        $variantPart = substr($itemBasename, strlen($basename) + 2);
                        if (!empty($variantPart)) {
                            $variants[] = $variantPart;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
            // Directory doesn't exist or other error - that's fine
        }

        return $variants;
    }

    /**
     * Check for common variant names that might exist.
     *
     * @param AssetStore $store
     * @param string $filename
     * @param string $hash
     * @return array<string>
     */
    protected function getCommonVariantNames(AssetStore $store, string $filename, string $hash): array
    {
        $commonVariants = [
            // Common manipulation variants
            'Fit100x100',
            'Fit150x150',
            'Fit200x200',
            'Fit300x300',
            'Fit352x198',
            'Fill100x100',
            'Fill150x150',
            'Fill200x200',
            'Fill300x300',
            'ScaleWidth100',
            'ScaleWidth150',
            'ScaleWidth200',
            'ScaleWidth300',
            'ScaleHeight100',
            'ScaleHeight150',
            'ScaleHeight200',
            'ScaleHeight300',
            'Pad100x100',
            'Pad150x150',
            'Pad200x200',
            'Pad300x300',
            // CMS thumbnails
            'FitMax400x300',
            'FitMax104x104',
            'FitMaxWzEwNCwxMDRd',
            // Chained variants
            'Fill100x100_ScaleWidth50',
        ];

        $foundVariants = [];
        foreach ($commonVariants as $variant) {
            if ($store->exists($filename, $hash, $variant)) {
                $foundVariants[] = $variant;
            }
        }

        return $foundVariants;
    }
}

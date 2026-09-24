<?php

namespace Restruct\Silverstripe\SVG\Tasks;

use Restruct\Silverstripe\SVG\SVGImage;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\BuildTask;

/**
 * ClearSVGVariantsTask - Removes all SVG variant files from the asset store.
 *
 * This task is useful when:
 * - Upgrading from the old SVG module that didn't do real manipulation
 * - SVG manipulation settings have changed
 * - You want to regenerate all SVG variants
 *
 * Usage (Silverstripe 6):
 *   vendor/bin/sake tasks:ClearSVGVariantsTask
 *   vendor/bin/sake tasks:ClearSVGVariantsTask --confirm
 *
 * Usage (Silverstripe 5):
 *   vendor/bin/sake dev/tasks/ClearSVGVariantsTask
 *   vendor/bin/sake dev/tasks/ClearSVGVariantsTask confirm=1
 *
 * Run without confirm for a dry run that shows what would be deleted.
 *
 * The entry point (run() on SS5, execute() on SS6) and getDescription() come from
 * ClearSVGVariantsTaskEntryPoint, because BuildTask's shape differs between the two majors
 * in ways one class body cannot satisfy - see that file for why it is done this way.
 */
class ClearSVGVariantsTask extends BuildTask
{
    use ClearSVGVariantsTaskEntryPoint;

    /**
     * Silverstripe 5 URL segment (dev/tasks/ClearSVGVariantsTask). Inert config on Silverstripe 6,
     * where the trait's $commandName names the task instead.
     *
     * @config
     */
    private static $segment = 'ClearSVGVariantsTask';

    /**
     * Silverstripe 6 command name (sake tasks:ClearSVGVariantsTask). Declared here, not in the
     * trait: SS5's BuildTask has no such property, so this is a new static there, and on SS6 it
     * is a compatible redeclaration of PolyCommand::$commandName (same type, same staticness).
     */
    protected static string $commandName = 'ClearSVGVariantsTask';

    public function __construct()
    {
        parent::__construct();
        // Assigned rather than declared: BuildTask::$title is untyped on SS5 and `string` on SS6,
        // and no single redeclaration is compatible with both.
        $this->title = 'Clear SVG Variants';
    }

    /**
     * Version-neutral body of the task.
     *
     * @param bool $confirm Actually delete (false = dry run)
     * @param bool $verbose Report every variant
     * @param callable $writeln function (string $line): void - lines may carry <info>/<comment> tags
     * @return array{images: int, found: int, deleted: int}
     */
    public function clearVariants(bool $confirm, bool $verbose, callable $writeln): array
    {
        if (!$confirm) {
            $writeln('<comment>DRY RUN - Add ' . $this->confirmHint() . ' to actually delete variants.</comment>');
            $writeln('');
        }

        /** @var AssetStore $store */
        $store = Injector::inst()->get(AssetStore::class);

        // Get all SVGImage records
        $svgImages = SVGImage::get()->filter('Name:EndsWith', '.svg');
        $totalImages = $svgImages->count();
        $totalVariantsDeleted = 0;
        $totalVariantsFound = 0;

        $writeln("Found {$totalImages} SVG images in the database.");

        if ($totalImages === 0) {
            $writeln('No SVG images to process.');
            return ['images' => 0, 'found' => 0, 'deleted' => 0];
        }

        /** @var SVGImage $image */
        foreach ($svgImages as $image) {
            if (!$image->exists()) {
                if ($verbose) {
                    $writeln("<comment>{$image->Name}</comment> - File does not exist, skipping");
                }
                continue;
            }

            $filename = $image->getFilename();
            $hash = $image->getHash();

            if (empty($filename) || empty($hash)) {
                continue;
            }

            // Find and delete variants for this file
            $variantsDeleted = $this->deleteVariantsForFile($store, $filename, $hash, $confirm, $verbose, $writeln);
            $totalVariantsFound += $variantsDeleted['found'];
            $totalVariantsDeleted += $variantsDeleted['deleted'];
        }

        $writeln('');
        $writeln('<info>Summary</info>');
        $writeln("Total SVG variant files found: <comment>{$totalVariantsFound}</comment>");

        if ($confirm) {
            $writeln("Total SVG variant files deleted: <comment>{$totalVariantsDeleted}</comment>");
            $writeln('Variants will be regenerated on next request with the new manipulation code.');
        } else {
            $writeln('Run with <comment>' . $this->confirmHint() . '</comment> to delete these variants.');
        }

        return ['images' => $totalImages, 'found' => $totalVariantsFound, 'deleted' => $totalVariantsDeleted];
    }

    /**
     * Delete all variants for a specific file.
     *
     * @param AssetStore $store
     * @param string $filename
     * @param string $hash
     * @param bool $confirm
     * @param bool $verbose
     * @param callable $writeln
     * @return array{found: int, deleted: int}
     */
    protected function deleteVariantsForFile(
        AssetStore $store,
        string $filename,
        string $hash,
        bool $confirm,
        bool $verbose,
        callable $writeln
    ): array {
        $found = 0;
        $deleted = 0;

        // Use FlysystemAssetStore's variant listing if available
        if ($store instanceof FlysystemAssetStore) {
            // Get all variants for this file
            $variants = $this->getVariantsForFile($store, $filename, $hash);

            foreach ($variants as $variant) {
                $found++;
                $message = "{$filename} - variant: {$variant}";

                if ($confirm) {
                    // Delete the variant
                    $store->delete($filename, $hash, $variant);
                    $deleted++;
                    if ($verbose) {
                        $writeln("{$message} - <info>DELETED</info>");
                    }
                } else {
                    if ($verbose) {
                        $writeln("{$message} - would be deleted");
                    }
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

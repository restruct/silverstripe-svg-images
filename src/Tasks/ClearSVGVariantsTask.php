<?php

namespace Restruct\Silverstripe\SVG\Tasks;

use Restruct\Silverstripe\SVG\SVGImage;
use League\Flysystem\Filesystem;
use SilverStripe\Assets\FilenameParsing\ParsedFileID;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Assets\Storage\FileHashingService;
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
     * where $commandName names the task instead.
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
     * Delete all variants for a specific file - and only the variants.
     *
     * Each variant file is deleted individually from the filesystem that holds it.
     * AssetStore::delete() is NOT usable here: its signature is delete($filename, $hash), it takes
     * no variant, and it removes the original together with every variant. (Up to 1.4.x/2.1.x
     * this method called delete($filename, $hash, $variant); the variant argument was silently
     * dropped, so clearing a draft SVG's variants deleted the SVG itself.)
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

        // Variant lookup needs the Flysystem store's resolution strategies
        if ($store instanceof FlysystemAssetStore) {
            $hasher = Injector::inst()->get(FileHashingService::class);

            foreach ($this->getVariantsForFile($store, $filename, $hash) as [$filesystem, $parsedFileID]) {
                $found++;
                $message = "{$filename} - variant: {$parsedFileID->getVariant()}";

                if ($confirm) {
                    // Delete the variant file only, as core's own deleteFromFileStore() does per
                    // file, including dropping its cached hash
                    $filesystem->delete($parsedFileID->getFileID());
                    $hasher->invalidate($parsedFileID->getFileID(), $filesystem);
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
     * Find every variant of a file, in both the public and the protected store.
     *
     * Uses each store's own resolution strategy (FileResolutionStrategy::findVariants()), so it
     * follows however the project lays files out. The previous implementation guessed the layout
     * as `folder/hashprefix/basename__variant.ext`, which is only the protected (legacy-hash)
     * layout: variants of published files, at natural paths, were never found, and when listing
     * failed it fell back to probing a fixed list of common variant names.
     *
     * @param FlysystemAssetStore $store
     * @param string $filename
     * @param string $hash
     * @return array<array{0: Filesystem, 1: ParsedFileID}>
     */
    protected function getVariantsForFile(FlysystemAssetStore $store, string $filename, string $hash): array
    {
        $tuple = new ParsedFileID($filename, $hash);
        $stores = [
            [$store->getPublicFilesystem(), $store->getPublicResolutionStrategy()],
            [$store->getProtectedFilesystem(), $store->getProtectedResolutionStrategy()],
        ];

        $variants = [];
        foreach ($stores as [$filesystem, $strategy]) {
            foreach ($strategy->findVariants($tuple, $filesystem) as $parsedFileID) {
                // findVariants() yields the original too (empty variant); that one stays
                if ($parsedFileID->getVariant()) {
                    $variants[] = [$filesystem, $parsedFileID];
                }
            }
        }

        return $variants;
    }
}

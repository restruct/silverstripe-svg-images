<?php

namespace Restruct\Silverstripe\SVG\Tasks;

use Restruct\Silverstripe\SVG\SVGImage;
use SilverStripe\Dev\BuildTask;

/**
 * MigrateSVGImagesTask - Turns .svg files uploaded before the module was installed into SVGImage
 * records, in one go.
 *
 * Those files are plain Image/File rows, so they get none of the SVG handling (dimensions,
 * vector manipulation, sanitizing on replace) until their ClassName changes. The same migration
 * can run on every dev/build with SVGImage.auto_migrate_svg_class; this task is for doing it
 * once, on purpose, with a dry run first.
 *
 * Usage (Silverstripe 6):
 *   vendor/bin/sake tasks:MigrateSVGImagesTask
 *   vendor/bin/sake tasks:MigrateSVGImagesTask --confirm
 *
 * Usage (Silverstripe 5):
 *   vendor/bin/sake dev/tasks/MigrateSVGImagesTask
 *   vendor/bin/sake dev/tasks/MigrateSVGImagesTask confirm=1
 *
 * The entry point comes from MigrateSVGImagesTaskEntryPoint, for the reason documented in
 * ClearSVGVariantsTaskEntryPoint.
 */
class MigrateSVGImagesTask extends BuildTask
{
    use MigrateSVGImagesTaskEntryPoint;

    /**
     * Silverstripe 5 URL segment (dev/tasks/MigrateSVGImagesTask); inert on Silverstripe 6.
     *
     * @config
     */
    private static $segment = 'MigrateSVGImagesTask';

    /**
     * Silverstripe 6 command name (sake tasks:MigrateSVGImagesTask); see ClearSVGVariantsTask.
     */
    protected static string $commandName = 'MigrateSVGImagesTask';

    public function __construct()
    {
        parent::__construct();
        // Assigned rather than declared, as in ClearSVGVariantsTask
        $this->title = 'Migrate SVG files to SVGImage';
    }

    /**
     * Version-neutral body of the task.
     *
     * @param bool $confirm Actually migrate (false = dry run)
     * @param callable $writeln function (string $line): void - lines may carry <info>/<comment> tags
     * @return array<string,int> table => rows (to be) migrated
     */
    public function migrate(bool $confirm, callable $writeln): array
    {
        if (!$confirm) {
            $writeln('<comment>DRY RUN - Add ' . $this->confirmHint() . ' to actually migrate.</comment>');
            $writeln('');
        }

        $counts = SVGImage::migrate_svg_class($confirm);

        if (!$counts) {
            $writeln('Every .svg file already is an ' . SVGImage::class . '. Nothing to do.');
            return [];
        }

        foreach ($counts as $table => $count) {
            $writeln(sprintf(
                '%s: <comment>%d</comment> SVG record(s) %s',
                $table,
                $count,
                $confirm ? 'migrated' : 'would be migrated'
            ));
        }

        if ($confirm) {
            # Existing variants were made by the raster backend, if at all: offer the clean-up
            $writeln('');
            $writeln('Done. Run ClearSVGVariantsTask to regenerate any variants made before the migration.');
        } else {
            $writeln('');
            $writeln('Run with <comment>' . $this->confirmHint() . '</comment> to migrate these records.');
        }

        return $counts;
    }
}

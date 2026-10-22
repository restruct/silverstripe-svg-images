<?php

namespace Restruct\Silverstripe\SVG\Tests;

use Restruct\Silverstripe\SVG\SVGImage;
use Restruct\Silverstripe\SVG\Tasks\MigrateSVGImagesTask;
use SilverStripe\Assets\Image;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DB;

/**
 * MigrateSVGImagesTask: reports legacy .svg rows on a dry run, migrates them on confirm, and
 * leaves other images alone. Drives the shared migrate() body, as ClearSVGVariantsTaskTest does.
 */
class MigrateSVGImagesTaskTest extends SapphireTest
{
    protected $usesDatabase = true;

    private function runTask(bool $confirm): array
    {
        $lines = [];
        $counts = MigrateSVGImagesTask::create()->migrate($confirm, function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        return ['counts' => $counts, 'lines' => $lines];
    }

    /**
     * A row as it was written before the module was installed, straight to the table so no
     * extension hook corrects it.
     */
    private function insertRow(string $name): int
    {
        DB::prepared_query(
            'INSERT INTO "File" ("ClassName", "Name", "FileFilename", "Created", "LastEdited") VALUES (?, ?, ?, NOW(), NOW())',
            [Image::class, $name, 'svgtest/' . $name]
        );

        return (int)DB::get_generated_id('File');
    }

    private function classNameOf(int $id): string
    {
        return (string)DB::prepared_query('SELECT "ClassName" FROM "File" WHERE "ID" = ?', [$id])->value();
    }

    public function testIsListedWithATitle(): void
    {
        $task = MigrateSVGImagesTask::create();

        $this->assertSame('Migrate SVG files to SVGImage', $task->getTitle());
        $this->assertTrue($task->isEnabled());
    }

    public function testDryRunCountsWithoutChangingAnything(): void
    {
        $id = $this->insertRow('legacy.svg');

        $result = $this->runTask(false);

        $this->assertSame(1, $result['counts']['File'] ?? null);
        $this->assertSame(Image::class, $this->classNameOf($id));
        $this->assertStringContainsString('DRY RUN', implode("\n", $result['lines']));
    }

    public function testConfirmMigratesSvgRowsOnly(): void
    {
        $svg = $this->insertRow('legacy.svg');
        $png = $this->insertRow('photo.png');

        $result = $this->runTask(true);

        $this->assertSame(1, $result['counts']['File'] ?? null);
        $this->assertSame(SVGImage::class, $this->classNameOf($svg));
        $this->assertSame(Image::class, $this->classNameOf($png));

        // A second run finds nothing left
        $this->assertSame([], $this->runTask(true)['counts']);
    }

    /**
     * Same guard as ClearSVGVariantsTaskTest: a task option that sake already defines globally
     * makes sake refuse the task.
     */
    public function testCliOptionsDoNotCollideWithSakeGlobalOptions(): void
    {
        $task = MigrateSVGImagesTask::create();

        if (!class_exists('SilverStripe\\Cli\\Sake')) {
            $this->assertFalse(class_exists('SilverStripe\\PolyExecution\\PolyOutput'));
            return;
        }

        $global = (new \SilverStripe\Cli\Sake())->getDefinition();
        foreach ($task->getOptions() as $option) {
            $this->assertFalse($global->hasOption($option->getName()), "--{$option->getName()} is already a sake global option");
            if ($option->getShortcut()) {
                $this->assertFalse($global->hasShortcut($option->getShortcut()), "-{$option->getShortcut()} is already a sake global shortcut");
            }
        }
    }
}

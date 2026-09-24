<?php

namespace Restruct\Silverstripe\SVG\Tests;

use Restruct\Silverstripe\SVG\SVGImage;
use Restruct\Silverstripe\SVG\Tasks\ClearSVGVariantsTask;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

/**
 * ClearSVGVariantsTask: finds SVG variants, reports them on a dry run, deletes them on confirm -
 * and never touches the original file.
 *
 * The version-specific entry points (run() on SS5, execute() on SS6) are thin wrappers; the tests
 * drive the shared clearVariants() body so they mean the same thing on both majors.
 */
class ClearSVGVariantsTaskTest extends SapphireTest
{
    use SVGTestHelpers;

    protected $usesDatabase = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->activateTestAssetStore();
    }

    protected function tearDown(): void
    {
        $this->resetTestAssetStore();
        parent::tearDown();
    }

    private function runTask(bool $confirm): array
    {
        $lines = [];
        $result = ClearSVGVariantsTask::create()->clearVariants($confirm, true, function (string $line) use (&$lines): void {
            $lines[] = $line;
        });
        $result['lines'] = $lines;

        return $result;
    }

    private function store(): AssetStore
    {
        return Injector::inst()->get(AssetStore::class);
    }

    public function testIsListedWithATitle(): void
    {
        $task = ClearSVGVariantsTask::create();

        $this->assertSame('Clear SVG Variants', $task->getTitle());
        $this->assertTrue($task->isEnabled());
    }

    public function testNothingToDoIsReportedCleanly(): void
    {
        $result = $this->runTask(true);

        $this->assertSame(['images' => 0, 'found' => 0, 'deleted' => 0], array_diff_key($result, ['lines' => 1]));
    }

    private function assertVariantsClearedAndOriginalKept(SVGImage $svg): void
    {
        $scaled = $svg->ScaleWidth(100)->getVariant();
        $filled = $svg->Fill(50, 50)->getVariant();

        $result = $this->runTask(true);

        $this->assertSame(2, $result['found'], implode("\n", $result['lines']));
        $this->assertSame(2, $result['deleted']);
        $this->assertFalse($this->store()->exists($svg->getFilename(), $svg->getHash(), $scaled));
        $this->assertFalse($this->store()->exists($svg->getFilename(), $svg->getHash(), $filled));
        $this->assertTrue(
            $this->store()->exists($svg->getFilename(), $svg->getHash()),
            'the original SVG must survive clearing its variants'
        );
        $this->assertStringContainsString('<svg', (string)SVGImage::get()->byID($svg->ID)->getString());
    }

    /**
     * Regression: variants of PUBLISHED files were never found. The finder assumed the
     * protected-store layout (folder/hashprefix/name__variant.ext); published files live at
     * natural paths, so the task reported 0 and cleared nothing for them.
     */
    public function testDryRunReportsVariantsWithoutDeletingThem(): void
    {
        $svg = $this->makeSVG();
        $variant = $svg->ScaleWidth(100);
        $this->assertTrue($this->store()->exists($svg->getFilename(), $svg->getHash(), $variant->getVariant()));

        $result = $this->runTask(false);

        $this->assertSame(1, $result['images']);
        $this->assertGreaterThanOrEqual(1, $result['found'], implode("\n", $result['lines']));
        $this->assertSame(0, $result['deleted']);
        $this->assertTrue(
            $this->store()->exists($svg->getFilename(), $svg->getHash(), $variant->getVariant()),
            'a dry run must not delete anything'
        );
    }

    /**
     * Regression: the task called AssetStore::delete($filename, $hash, $variant), but delete()
     * takes no variant argument (AssetStore::delete($filename, $hash)). The third argument was
     * silently dropped, so "delete this variant" deleted the ORIGINAL file and every variant with
     * it, leaving the File record pointing at nothing.
     */
    public function testConfirmDeletesVariantsButKeepsThePublishedOriginal(): void
    {
        $svg = $this->makeSVG();
        $this->assertVariantsClearedAndOriginalKept($svg);
    }

    public function testConfirmDeletesVariantsButKeepsTheDraftOriginal(): void
    {
        // Unpublished files sit in the protected store, under a hash directory
        $svg = $this->makeSVG(null, 'draft.svg', false);
        $this->assertVariantsClearedAndOriginalKept($svg);
    }
}

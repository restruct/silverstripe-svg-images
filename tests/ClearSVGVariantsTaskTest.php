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
}

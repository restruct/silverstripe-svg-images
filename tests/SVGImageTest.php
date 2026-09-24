<?php

namespace Restruct\Silverstripe\SVG\Tests;

use Restruct\Silverstripe\SVG\SVGDBFile;
use Restruct\Silverstripe\SVG\SVGImage;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\ORM\DB;
use SilverStripe\ORM\FieldType\DBHTMLText;
use SilverStripe\Versioned\Versioned;

/**
 * Behavioural tests for SVGImage and SVGImageExtension: how an .svg becomes an SVGImage, what it
 * reports about itself, and what each config option changes.
 *
 * Written against the module's public contract so the tests keep their meaning across
 * Silverstripe majors.
 */
class SVGImageTest extends SapphireTest
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

    // -------------------------------------------------------------------------
    // Registration: the module's config is picked up
    // -------------------------------------------------------------------------

    public function testSvgExtensionIsMappedToSVGImage(): void
    {
        $this->assertSame(SVGImage::class, File::get_class_for_file_extension('svg'));
    }

    public function testSvgIsAnAllowedImageUpload(): void
    {
        $this->assertContains('svg', File::config()->get('allowed_extensions'));
        $this->assertContains('svg', File::config()->get('app_categories')['image']);
        $this->assertContains('svg', File::config()->get('app_categories')['image/supported']);
    }

    public function testSVGImageExtensionIsAppliedToFile(): void
    {
        $this->assertTrue(File::has_extension(\Restruct\Silverstripe\SVG\SVGImageExtension::class));
    }

    // -------------------------------------------------------------------------
    // What an SVGImage reports about itself
    // -------------------------------------------------------------------------

    public function testReportsItselfAsAnSVG(): void
    {
        $svg = $this->makeSVG();

        $this->assertTrue($svg->IsSVG());
        $this->assertSame('SVG image', $svg->getFileType());
    }

    public function testDimensionsComeFromTheViewBox(): void
    {
        // viewBox wins over width/height when both are present
        $svg = $this->makeSVG('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 300 120" width="30" height="12"></svg>');

        $this->assertSame(300, $svg->getWidth());
        $this->assertSame(120, $svg->getHeight());
        $this->assertSame('300x120', $svg->getDimensions());
    }

    public function testDimensionsFallBackToWidthAndHeightAttributes(): void
    {
        $svg = $this->makeSVG('<svg xmlns="http://www.w3.org/2000/svg" width="64" height="48"></svg>');

        $this->assertSame(64, $svg->getWidth());
        $this->assertSame(48, $svg->getHeight());
    }

    public function testAnSVGWithoutDimensionsReportsZeroRatherThanFailing(): void
    {
        $svg = $this->makeSVG('<svg xmlns="http://www.w3.org/2000/svg"><rect width="1" height="1"/></svg>');

        $this->assertSame(0, $svg->getWidth());
        $this->assertSame(0, $svg->getHeight());
        $this->assertSame('Scalable (no dimensions)', $svg->getDimensions());
    }

    public function testCmsThumbnailsUseTheSvgItself(): void
    {
        $svg = $this->makeSVG();

        // A raster thumbnail generator cannot read an SVG; the vector scales on its own
        $this->assertSame($svg, $svg->CMSThumbnail());
        $this->assertSame($svg, $svg->StripThumbnail());
        $this->assertStringEndsWith('.svg', $svg->ThumbnailURL(100, 100));
        $this->assertStringEndsWith('.svg', $svg->PreviewLink());
    }

    public function testRawInlineReturnsTheMarkupAsHtml(): void
    {
        $svg = $this->makeSVG();

        $inline = $svg->SVG_RAW_Inline();
        $this->assertInstanceOf(DBHTMLText::class, $inline);
        $this->assertStringContainsString('<svg', $inline->getValue());
    }

    public function testExistingOnlyNeverGeneratesVariants(): void
    {
        $svg = $this->makeSVG();

        $existing = $svg->existingOnly();
        $this->assertInstanceOf(SVGDBFile::class, $existing);

        $existing->Fit(50, 50);
        $store = Injector::inst()->get(AssetStore::class);
        $this->assertFalse(
            $store->exists($svg->getFilename(), $svg->getHash(), $svg->variantName('Fit', 50, 50)),
            'existingOnly() must not create a variant that does not exist yet'
        );
    }

    // -------------------------------------------------------------------------
    // Sanitization on upload, and its config options
    // -------------------------------------------------------------------------





    private function svgWithRemoteImage(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="10" height="10">'
            . '<image xlink:href="https://example.com/pixel.png" width="10" height="10"/></svg>';
    }

    // -------------------------------------------------------------------------
    // SVGImageExtension: an .svg written as a plain Image is corrected to SVGImage
    // -------------------------------------------------------------------------

    public function testSvgWrittenAsImageIsCorrectedToSVGImage(): void
    {
        // What an UploadField on a `has_one Image` does: it writes the relation's class
        $image = Image::create();
        $image->setFromString($this->referenceSVG(), 'svgtest/relation.svg');
        $image->write();

        $className = DB::prepared_query('SELECT "ClassName" FROM "File" WHERE "ID" = ?', [$image->ID])->value();
        $this->assertSame(SVGImage::class, $className);
        $this->assertInstanceOf(SVGImage::class, File::get()->byID($image->ID));
    }

    public function testNonSvgImagesAreLeftAlone(): void
    {
        $png = $this->makePNG();

        $className = DB::prepared_query('SELECT "ClassName" FROM "File" WHERE "ID" = ?', [$png->ID])->value();
        $this->assertSame(Image::class, $className);
    }

    // -------------------------------------------------------------------------
    // auto_migrate_svg_class
    // -------------------------------------------------------------------------

    public function testAutoMigrateIsOffByDefault(): void
    {
        $id = $this->insertLegacySvgRow();

        SVGImage::singleton()->requireDefaultRecords();

        $this->assertSame(Image::class, DB::prepared_query('SELECT "ClassName" FROM "File" WHERE "ID" = ?', [$id])->value());
    }

    public function testAutoMigrateConvertsExistingSvgRecords(): void
    {
        $id = $this->insertLegacySvgRow();
        Config::modify()->set(SVGImage::class, 'auto_migrate_svg_class', true);

        SVGImage::singleton()->requireDefaultRecords();

        $this->assertSame(SVGImage::class, DB::prepared_query('SELECT "ClassName" FROM "File" WHERE "ID" = ?', [$id])->value());
    }

    /**
     * An .svg row left over from before the module was installed, written straight to the table
     * so no extension hook gets a chance to correct it.
     */
    private function insertLegacySvgRow(): int
    {
        DB::prepared_query(
            'INSERT INTO "File" ("ClassName", "Name", "FileFilename", "Created", "LastEdited") VALUES (?, ?, ?, NOW(), NOW())',
            [Image::class, 'legacy.svg', 'svgtest/legacy.svg']
        );

        return (int)DB::get_generated_id('File');
    }

    /**
     * Regression: no upload was ever sanitized. The check ran in SVGImage::onBeforeWrite() and
     * bailed out on !$this->exists(), and File::exists() is false for a record that is not in the
     * database yet - which is every upload's first write. A <script> in an uploaded SVG was
     * stored and served as-is.
     */
    public function testScriptIsStrippedOnUpload(): void
    {
        $svg = $this->makeSVG(
            '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script><rect width="10" height="10"/></svg>'
        );

        $content = $svg->getString();
        $this->assertStringNotContainsString('<script', $content);
        $this->assertStringContainsString('<rect', $content);
    }

    public function testSanitizeOnUploadCanBeSwitchedOff(): void
    {
        Config::modify()->set(SVGImage::class, 'sanitize_on_upload', false);

        $svg = $this->makeSVG(
            '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script></svg>'
        );

        $this->assertStringContainsString('<script', $svg->getString());
    }

    public function testRemoteReferencesAreRemovedByDefault(): void
    {
        $svg = $this->makeSVG($this->svgWithRemoteImage());

        $this->assertStringNotContainsString('https://example.com/pixel.png', $svg->getString());
    }

    public function testRemoteReferenceRemovalCanBeSwitchedOff(): void
    {
        Config::modify()->set(SVGImage::class, 'sanitize_remove_remote_references', false);

        $svg = $this->makeSVG($this->svgWithRemoteImage());

        $this->assertStringContainsString('https://example.com/pixel.png', $svg->getString());
    }

    /**
     * Regression, same defect by the other route: an SVG uploaded through a `has_one Image`
     * relation is written as a plain Image, so SVGImage's onBeforeWrite() never ran for it at all.
     */
    public function testScriptIsStrippedWhenUploadedAsAPlainImage(): void
    {
        $image = Image::create();
        $image->setFromString(
            '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script></svg>',
            'svgtest/relation-upload.svg'
        );
        $image->write();

        $this->assertStringNotContainsString('<script', File::get()->byID($image->ID)->getString());
    }

    public function testReplacedFileIsSanitizedToo(): void
    {
        // "Replace file" in the CMS keeps the record and brings new content
        $svg = $this->makeSVG();
        $svg->setFromString(
            '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script></svg>',
            $svg->getFilename()
        );
        $svg->write();

        $this->assertStringNotContainsString('<script', SVGImage::get()->byID($svg->ID)->getString());
    }

    public function testUnsanitizedCopyIsNotLeftInTheAssetStore(): void
    {
        $dirty = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script></svg>';
        $dirtyHash = sha1($dirty);

        $svg = SVGImage::create();
        $svg->setFromString($dirty, 'svgtest/dirty.svg');
        $this->assertSame($dirtyHash, $svg->getHash(), 'premise: the store hashes content with sha1');
        $svg->write();

        $store = Injector::inst()->get(AssetStore::class);
        $this->assertNotSame($dirtyHash, $svg->getHash());
        $this->assertTrue($store->exists($svg->getFilename(), $svg->getHash()));
        $this->assertFalse(
            $store->exists('svgtest/dirty.svg', $dirtyHash),
            'the unsanitized upload must not stay retrievable from the store'
        );
    }
}

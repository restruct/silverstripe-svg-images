<?php

namespace Restruct\Silverstripe\SVG\Tests;

use Restruct\Silverstripe\SVG\SVGImage;
use Restruct\Silverstripe\SVG\UnsafeSVGException;
use SilverStripe\Assets\File;
use SilverStripe\Assets\Image;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Assets\Upload;
use SilverStripe\Assets\Upload_Validator;
use SilverStripe\Core\Config\Config;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;
use SilverStripe\Versioned\Versioned;

/**
 * #23: an SVG the sanitizer cannot parse used to be stored exactly as uploaded, and browsers ran
 * its script when the file URL was opened. Such files are now refused, and their bytes do not stay
 * in the asset store.
 *
 * The two payloads are the reproductions from the issue: both make enshrined/svg-sanitize return
 * false, and both run their script in Chromium when served as image/svg+xml.
 */
class SVGUnsafeUploadTest extends SapphireTest
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

    /**
     * The sanitizer drops the DOCTYPE before parsing, which leaves &x; undefined.
     */
    private function entityPayload(): string
    {
        return '<!DOCTYPE svg [<!ENTITY x "y">]>' . "\n"
            . '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script><text>&x;</text></svg>';
    }

    /**
     * Deeper than libxml's default limit of 256, which browsers accept.
     */
    private function deepPayload(): string
    {
        return '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script>'
            . str_repeat('<g>', 300) . str_repeat('</g>', 300) . '</svg>';
    }

    /**
     * Silverstripe 6 moved ValidationException out of SilverStripe\ORM.
     */
    private function validationExceptionClass(): string
    {
        return class_exists('SilverStripe\\Core\\Validation\\ValidationException')
            ? 'SilverStripe\\Core\\Validation\\ValidationException'
            : 'SilverStripe\\ORM\\ValidationException';
    }

    /**
     * Write $class with $content and assert the write was refused, no record exists, and the
     * bytes are not in the store.
     */
    private function assertRefused(string $content, string $name, string $class = SVGImage::class): string
    {
        $file = $class::create();
        $file->setFromString($content, 'svgtest/' . $name);
        $this->assertSame(sha1($content), $file->getHash(), 'premise: the store hashes content with sha1');

        $thrown = null;
        try {
            $file->write();
        } catch (\Exception $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf($this->validationExceptionClass(), $thrown, 'the write is refused');
        $this->assertSame(0, File::get()->filter('Name', $name)->count(), 'no File record');
        $this->assertFalse(
            Injector::inst()->get(AssetStore::class)->exists('svgtest/' . $name, sha1($content)),
            'the refused upload must not stay retrievable from the store'
        );

        return $thrown->getMessage();
    }

    public function testSvgWithAnInternalEntityIsRefused(): void
    {
        $message = $this->assertRefused($this->entityPayload(), 'entity.svg');

        $this->assertStringContainsString('DOCTYPE', $message, 'the uploader is told why');
    }

    public function testSvgNestedBeyondTheParserLimitIsRefused(): void
    {
        $message = $this->assertRefused($this->deepPayload(), 'deep.svg');

        $this->assertStringContainsString('could not be checked', $message);
    }

    /**
     * Same defect by the relation route: written as a plain Image, sanitized by the extension.
     */
    public function testRefusedWhenUploadedAsAPlainImage(): void
    {
        $this->assertRefused($this->entityPayload(), 'entity-image.svg', Image::class);
        $this->assertRefused($this->deepPayload(), 'deep-image.svg', Image::class);
    }

    /**
     * The upload path itself: Upload::loadIntoFile() is what AssetAdmin and every UploadField
     * use. Its write throws, which the upload field reports as the error message.
     */
    public function testRefusedThroughUpload(): void
    {
        # The file is not a real HTTP upload; the validator's documented switch for tests
        Config::modify()->set(Upload_Validator::class, 'use_is_uploaded_file', false);
        $tmp = tempnam(sys_get_temp_dir(), 'svgupload');
        file_put_contents($tmp, $this->entityPayload());
        $tmpFile = ['name' => 'uploaded.svg', 'type' => 'image/svg+xml', 'tmp_name' => $tmp,
            'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK];

        $thrown = null;
        try {
            Upload::create()->loadIntoFile($tmpFile, SVGImage::create(), 'svgtest');
        } catch (\Exception $e) {
            $thrown = $e;
        } finally {
            @unlink($tmp);
        }

        $this->assertInstanceOf($this->validationExceptionClass(), $thrown);
        $this->assertSame(0, File::get()->filter('Name', 'uploaded.svg')->count());
        $this->assertFalse(
            Injector::inst()->get(AssetStore::class)->exists('svgtest/uploaded.svg', sha1($this->entityPayload()))
        );
    }

    /**
     * "Replace file" with an unsafe SVG: refused, and the record keeps its current, safe file.
     */
    public function testRefusedReplacementKeepsTheExistingFile(): void
    {
        $svg = $this->makeSVG();
        $safeHash = $svg->getHash();

        $svg->setFromString($this->entityPayload(), $svg->getFilename());
        $thrown = null;
        try {
            $svg->write();
        } catch (\Exception $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf($this->validationExceptionClass(), $thrown);
        $stored = SVGImage::get()->byID($svg->ID);
        $this->assertSame($safeHash, $stored->getHash());
        $this->assertStringNotContainsString('<script', $stored->getString());
        $this->assertFalse(
            Injector::inst()->get(AssetStore::class)->exists($svg->getFilename(), sha1($this->entityPayload()))
        );
    }

    /**
     * Same for a draft that was never published, where no other stage keeps the file alive.
     * AssetControlExtension deletes a record's previous file in its onBeforeWrite(), so a
     * refusal raised later in the write lost the original; the refusal now happens in
     * validation, before any onBeforeWrite().
     */
    public function testRefusedReplacementOfAnUnpublishedDraftKeepsItsFile(): void
    {
        Versioned::set_stage(Versioned::DRAFT);
        $svg = $this->makeSVG(null, 'draft-only.svg', false);
        $safeHash = $svg->getHash();

        $svg->setFromString($this->entityPayload(), $svg->getFilename());
        $thrown = null;
        try {
            $svg->write();
        } catch (\Exception $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf($this->validationExceptionClass(), $thrown);
        $stored = SVGImage::get()->byID($svg->ID);
        $this->assertSame($safeHash, $stored->getHash());
        $this->assertNotEmpty($stored->getString(), 'the original file is still in the store');
        $this->assertFalse(
            Injector::inst()->get(AssetStore::class)->exists($svg->getFilename(), sha1($this->entityPayload()))
        );
    }

    /**
     * Control: a DOCTYPE without an internal subset, as many editors write it, is still accepted
     * and sanitized.
     */
    public function testExternalDoctypeWithoutSubsetIsAccepted(): void
    {
        $svg = $this->makeSVG(
            '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd">' . "\n"
            . '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script>'
            . '<style><![CDATA[rect{fill:red}]]></style><rect width="10" height="10"/></svg>',
            'external-doctype.svg'
        );

        $this->assertTrue($svg->isInDB());
        $this->assertStringNotContainsString('<script', $svg->getString());
        $this->assertStringContainsString('<rect', $svg->getString());
    }

    public function testInternalSubsetDetection(): void
    {
        $cases = [
            '<!DOCTYPE svg [<!ENTITY x "y">]><svg/>' => true,
            '<!doctype svg[<!ENTITY x "y">]><svg/>' => true,
            "<!DOCTYPE svg PUBLIC \"-//W3C//DTD SVG 1.1//EN\" \"http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd\" [\n<!ENTITY ns_svg \"http://www.w3.org/2000/svg\">\n]><svg/>" => true,
            # A ">" inside a quoted identifier must not end the DOCTYPE early
            '<!DOCTYPE svg SYSTEM "a>b" [<!ENTITY x "y">]><svg/>' => true,
            '<!DOCTYPE svg PUBLIC "-//W3C//DTD SVG 1.1//EN" "http://www.w3.org/Graphics/SVG/1.1/DTD/svg11.dtd"><svg/>' => false,
            # A "[" later in the document (CDATA) is not a subset
            '<!DOCTYPE svg PUBLIC "a" "b"><svg><style><![CDATA[a{}]]></style></svg>' => false,
            '<svg xmlns="http://www.w3.org/2000/svg"/>' => false,
        ];

        $wrong = [];
        foreach ($cases as $markup => $expected) {
            if (SVGImage::has_internal_subset($markup) !== $expected) {
                $wrong[] = ($expected ? 'missed: ' : 'false positive: ') . $markup;
            }
        }

        $this->assertSame([], $wrong);
    }

    public function testSanitizeContentRefusesWithAMessageForTheUploader(): void
    {
        $this->expectException(UnsafeSVGException::class);
        SVGImage::sanitize_content($this->deepPayload());
    }

    /**
     * sanitize_on_upload: false is the documented opt-out of sanitizing altogether; it stays an
     * opt-out of refusing too.
     */
    public function testNothingIsRefusedWhenSanitizingIsSwitchedOff(): void
    {
        Config::modify()->set(SVGImage::class, 'sanitize_on_upload', false);

        $svg = $this->makeSVG($this->entityPayload(), 'entity-unsanitized.svg');

        $this->assertTrue($svg->isInDB());
    }

    # -------------------------------------------------------------------------
    # Verifier round 1
    # -------------------------------------------------------------------------

    /**
     * Upload_Validator and class_for_file_extension lowercase the extension, so evil.SVG is an
     * accepted upload and an SVGImage. The sanitizer compared the extension case-sensitively and
     * never ran for it.
     */
    public function testUppercaseExtensionIsSanitizedOnUpload(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'svgupload');
        file_put_contents($tmp, '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script><rect width="10" height="10"/></svg>');
        Config::modify()->set(Upload_Validator::class, 'use_is_uploaded_file', false);
        $upload = Upload::create();
        try {
            $this->assertTrue($upload->loadIntoFile(
                ['name' => 'evil.SVG', 'type' => 'image/svg+xml', 'tmp_name' => $tmp, 'size' => filesize($tmp), 'error' => UPLOAD_ERR_OK],
                null,
                'svgtest'
            ), 'premise: an .SVG upload is accepted');
        } finally {
            @unlink($tmp);
        }

        $stored = File::get()->byID($upload->getFile()->ID);
        $this->assertInstanceOf(SVGImage::class, $stored);
        $this->assertStringNotContainsString('<script', $stored->getString());
        $this->assertStringContainsString('<rect', $stored->getString());
    }

    public function testUppercaseExtensionIsRefusedLikeLowercase(): void
    {
        $this->assertRefused($this->entityPayload(), 'entity-upper.SVG');
    }

    /**
     * enshrined/svg-sanitize throws a LogicException for the first two instead of returning
     * false: a 500 with the bytes left in the store. A prefixed root comes back as an empty
     * document, which used to be stored silently.
     */
    public function testInputTheSanitizerThrowsOnIsRefusedAndNotStored(): void
    {
        $selfUse = '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><g id="a"><use href="#a"/></g><script>alert(1)</script></svg>';
        $xhtmlRoot = '<html xmlns="http://www.w3.org/1999/xhtml"><body><script>alert(1)</script></body></html>';
        $prefixedRoot = '<s:svg xmlns:s="http://www.w3.org/2000/svg" width="10" height="10"><s:script>alert(1)</s:script></s:svg>';

        $this->assertRefused($selfUse, 'self-use.svg');
        $this->assertRefused($xhtmlRoot, 'xhtml-root.svg');
        $this->assertRefused($prefixedRoot, 'prefixed-root.svg');
    }

    /**
     * Renaming notes.txt to notes.svg keeps the hash, so the "content changed" check never fired:
     * the record became an SVGImage with whatever the text file held.
     */
    public function testRenamingAnotherFileToSvgSanitizesIt(): void
    {
        # Renames happen on the draft stage (File::updateFilesystem() skips Live)
        Versioned::set_stage(Versioned::DRAFT);
        $file = File::create();
        $file->setFromString(
            '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script><rect width="10" height="10"/></svg>',
            'svgtest/notes.txt'
        );
        $file->write();

        $file->Name = 'notes.svg';
        $file->write();

        $stored = File::get()->byID($file->ID);
        $this->assertSame('svgtest/notes.svg', $stored->getFilename(), 'premise: renamed');
        $this->assertStringNotContainsString('<script', $stored->getString());
    }

    public function testRenamingAnotherFileToSvgRefusesUnsafeContent(): void
    {
        Versioned::set_stage(Versioned::DRAFT);
        $file = File::create();
        $file->setFromString($this->entityPayload(), 'svgtest/entity.txt');
        $file->write();

        $file->Name = 'entity.SVG';
        $thrown = null;
        try {
            $file->write();
        } catch (\Exception $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf($this->validationExceptionClass(), $thrown);
        $this->assertSame('svgtest/entity.txt', File::get()->byID($file->ID)->getFilename(), 'the record keeps its old name');
    }

    /**
     * Publishing copies the draft as it is. It used to re-sanitize during the Live-stage write
     * (publish calls forceChange()), which left a pre-3.0 unsanitized draft published with a new
     * file in the protected store: visitors could not load it.
     */
    public function testPublishingDoesNotRewriteTheFile(): void
    {
        Config::modify()->set(SVGImage::class, 'sanitize_on_upload', false);
        $svg = $this->makeSVG(
            '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><script>alert(1)</script></svg>',
            'pre-fix.svg',
            false
        );
        $draftHash = $svg->getHash();
        Config::modify()->set(SVGImage::class, 'sanitize_on_upload', true);

        $svg = SVGImage::get()->byID($svg->ID);
        $svg->publishSingle();

        $live = Versioned::get_by_stage(SVGImage::class, Versioned::LIVE)->byID($svg->ID);
        $this->assertSame($draftHash, $live->getHash(), 'Live gets exactly the draft');
        $this->assertSame(AssetStore::VISIBILITY_PUBLIC, $live->getVisibility());
        $this->assertTrue(Injector::inst()->get(AssetStore::class)->exists($live->getFilename(), $live->getHash()));
    }

    /**
     * Rolling the draft back to an unsafe version is new content for the draft: refused.
     */
    public function testRollingBackToAnUnsafeVersionIsRefused(): void
    {
        Versioned::set_stage(Versioned::DRAFT);
        Config::modify()->set(SVGImage::class, 'sanitize_on_upload', false);
        # Published, so the unsafe file stays in the store after the draft moves on (an
        # unpublished draft's replaced file is gone, and a rollback to it restores nothing)
        $svg = $this->makeSVG($this->entityPayload(), 'rollback.svg', true);
        $unsafeVersion = $svg->Version;
        Config::modify()->set(SVGImage::class, 'sanitize_on_upload', true);

        $svg->setFromString($this->referenceSVG(), 'svgtest/rollback.svg');
        $svg->write();

        $thrown = null;
        try {
            $svg->rollbackSingle($unsafeVersion);
        } catch (\Exception $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf($this->validationExceptionClass(), $thrown);
        $this->assertStringNotContainsString('<script', SVGImage::get()->byID($svg->ID)->getString());
    }
}

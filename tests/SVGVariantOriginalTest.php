<?php

namespace Restruct\Silverstripe\SVG\Tests;

use SilverStripe\Dev\SapphireTest;
use SilverStripe\View\Parsers\ShortcodeParser;

/**
 * SVG variants must be linked to their original the way core links raster variants
 * (ImageManipulation::manipulate(): initAttributes() + setOriginal()).
 *
 * Without that, setAttribute() on a variant fatals in copyImageBackend() ("setOriginal() on
 * null"), and core's ImageShortcodeProvider calls setAttribute() on the ResizedImage() of every
 * image an editor resized in WYSIWYG content: the page 500s.
 */
class SVGVariantOriginalTest extends SapphireTest
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

    public function testResizedSvgInWysiwygContentRenders(): void
    {
        $svg = $this->makeSVG();

        $html = ShortcodeParser::get('default')->parse(
            '[image src="' . $svg->getURL() . '" id="' . $svg->ID . '" width="100" height="40" alt="logo"]'
        );

        $this->assertStringContainsString('width="100"', $html);
        $this->assertStringContainsString('height="40"', $html);
        $this->assertMatchesRegularExpression('/src="[^"]*__ResizedImage[^"]*\.svg"/', $html, 'an SVG variant');
        $this->assertStringContainsString('alt="logo"', $html);
    }

    public function testSetAttributeOnAVariantWorks(): void
    {
        $variant = $this->makeSVG()->ScaleWidth(100);

        $withAlt = $variant->setAttribute('alt', 'logo');

        $this->assertSame('logo', $withAlt->getAttribute('alt'));
        $this->assertSame('100x75', $this->sizeOf($withAlt));
    }

    public function testVariantsCarryTheOriginalsAttributesAndRecord(): void
    {
        $svg = $this->makeSVG();
        $svg->Title = 'My logo';

        $variant = $svg->setAttribute('class', 'brand')->ScaleWidth(100);
        $this->assertSame('brand', $variant->getAttribute('class'), 'attributes carried to the variant');
        $this->assertSame('My logo', $variant->Title, 'the original record answers through the variant');

        # The cached-variant path (second call) must link it the same way
        $again = $svg->setAttribute('class', 'brand')->ScaleWidth(100);
        $this->assertSame('brand', $again->getAttribute('class'));
        $this->assertSame('My logo', $again->Title);
    }
}

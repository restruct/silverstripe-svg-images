<?php

namespace Restruct\Silverstripe\SVG\Tests;

use Restruct\Silverstripe\SVG\Controllers\SVGCompareController;
use Restruct\Silverstripe\SVG\SVGImage;
use SilverStripe\Core\Kernel;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\FunctionalTest;

/**
 * /dev/svg-compare: the development page that renders SVG and raster manipulations side by side.
 *
 * Reached through DevelopmentAdmin, whose registration key differs per major (SS5
 * `registered_controllers`, SS6 `controllers`); these requests go through the real routing, so
 * they fail if either registration is wrong.
 */
class SVGCompareControllerTest extends FunctionalTest
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

    public function testSetupPageRendersForAnAdmin(): void
    {
        $this->logInWithPermission('ADMIN');

        $response = $this->get('dev/svg-compare');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('SVG vs PNG Manipulation Comparison', $response->getBody());
        $this->assertStringContainsString('Install Test Images', $response->getBody());
    }

    public function testComparisonRendersForGivenImages(): void
    {
        // Exercises the comparison path: ArrayList/ArrayData construction and every manipulation
        $this->logInWithPermission('ADMIN');
        $svg = $this->makeSVG();
        $png = $this->makePNG();

        $response = $this->get('dev/svg-compare?svg=' . $svg->ID . '&png=' . $png->ID);

        $this->assertSame(200, $response->getStatusCode());
        $body = $response->getBody();
        $this->assertStringContainsString('ScaleWidth(200)-&gt;Fill(100, 100)', $body);
        $this->assertStringNotContainsString('Error:', $body);
    }
}

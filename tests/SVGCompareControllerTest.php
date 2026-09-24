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

    /**
     * Regression: the controller relied on DevelopmentAdmin to authorise it ("Security handled by
     * DevelopmentAdmin middleware"). DevelopmentAdmin only refuses a user who can see NO dev link
     * at all, so in live mode anyone holding e.g. BUILDTASK_CAN_RUN (who can see dev/tasks) was
     * handed through - and ?install=1 / ?remove=1 write to and archive from the asset store.
     */
    public function testNonAdminIsRefusedInLiveMode(): void
    {
        $kernel = Injector::inst()->get(Kernel::class);
        $previous = $kernel->getEnvironment();
        $kernel->setEnvironment('live');

        // SS6 answers this user with a redirect to the dev-URL confirmation page first; do not
        // follow it (rendering that page only adds noise). SS5 hands the request straight through.
        $this->autoFollowRedirection = false;

        try {
            $this->logInWithPermission('BUILDTASK_CAN_RUN');
            $response = $this->get('dev/svg-compare?install=1');

            // No status-code assertion: what a refusal looks like (403, login redirect) is
            // core's business. What matters is that the page and its side effects never run.
            $this->assertStringNotContainsString('SVG vs PNG Manipulation Comparison', (string)$response->getBody());
            $this->assertSame(0, SVGImage::get()->count(), 'a refused request must not install test images');
        } finally {
            $kernel->setEnvironment($previous);
        }
    }

    /**
     * The controller's own gate, independent of whichever middleware core puts in front of it:
     * on SS6 the dev-URL confirmation step happens to stop the request above, but a confirmed
     * request would still reach the controller, so the controller must refuse by itself.
     */
    public function testCanInitRequiresAdminOutsideDevMode(): void
    {
        $kernel = Injector::inst()->get(Kernel::class);
        $previous = $kernel->getEnvironment();

        try {
            $kernel->setEnvironment('live');

            $this->logInWithPermission('BUILDTASK_CAN_RUN');
            $this->assertFalse(SVGCompareController::create()->canInit());

            $this->logInWithPermission('ADMIN');
            $this->assertTrue(SVGCompareController::create()->canInit());

            $this->logOut();
            $kernel->setEnvironment('dev');
            $this->assertTrue(SVGCompareController::create()->canInit(), 'dev mode stays open, as for core dev tools');
        } finally {
            $kernel->setEnvironment($previous);
        }
    }
}

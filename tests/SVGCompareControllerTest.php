<?php

namespace Restruct\Silverstripe\SVG\Tests;

use Restruct\Silverstripe\SVG\Controllers\SVGCompareController;
use Restruct\Silverstripe\SVG\SVGImage;
use SilverStripe\Control\Director;
use SilverStripe\Control\Middleware\ConfirmationMiddleware;
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

        // SS6 answers this user with a redirect to the dev-URL confirmation page first; SS5 hands
        // the request straight through. That redirect used to be left unfollowed here, which made
        // the test unable to fail on SS6: the request never reached the controller, so deleting
        // its guard stayed green. The confirmation step is a confirm-this-URL safeguard that a
        // user can click through, not an access check, so it is taken out of the Director's
        // middleware for this request (restored below) and the request reaches the controller on
        // both majors. Redirects are still not followed: a refusal may be a login redirect.
        $this->autoFollowRedirection = false;
        $director = Director::singleton();
        $middlewares = $director->getMiddlewares();
        $director->setMiddlewares(array_filter(
            $middlewares,
            fn ($middleware) => !$middleware instanceof ConfirmationMiddleware
        ));

        try {
            $this->logInWithPermission('BUILDTASK_CAN_RUN');
            $response = $this->get('dev/svg-compare?install=1');

            // Guards the bypass itself: if core puts another confirmation step in front of the
            // controller, this test must say so rather than silently stop reaching the guard.
            $this->assertStringNotContainsString(
                'dev/confirm',
                (string)$response->getHeader('Location'),
                'the request must reach the controller, not stop at the dev-URL confirmation'
            );

            // No status-code assertion: what a refusal looks like (403, login redirect) is
            // core's business. What matters is that the page and its side effects never run.
            $this->assertStringNotContainsString('SVG vs PNG Manipulation Comparison', (string)$response->getBody());
            $this->assertSame(0, SVGImage::get()->count(), 'a refused request must not install test images');
        } finally {
            $director->setMiddlewares($middlewares);
            $kernel->setEnvironment($previous);
        }
    }

    /**
     * The controller's own gate, independent of whichever middleware core puts in front of it:
     * on SS6 the dev-URL confirmation step stops an unconfirmed request (the test above takes it
     * out of the way), but a confirmed request still reaches the controller, so the controller
     * must refuse by itself.
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

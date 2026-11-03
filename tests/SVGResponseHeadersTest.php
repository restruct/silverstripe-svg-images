<?php

namespace Restruct\Silverstripe\SVG\Tests;

use Restruct\Silverstripe\SVG\Extensions\SVGResponseHeadersExtension;
use SilverStripe\Assets\Dev\TestAssetStore;
use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Assets\Flysystem\PublicAssetAdapter;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Injector\Injector;
use SilverStripe\Dev\SapphireTest;

/**
 * #23, defence in depth: every SVG response carries a Content-Security-Policy with `sandbox`, so
 * an SVG opened as a document cannot run script on the site's origin even if the sanitizer missed
 * something. PHP-served SVGs get it from SVGResponseHeadersExtension, Apache-served public ones
 * from the assets/.htaccess this module's template generates.
 */
class SVGResponseHeadersTest extends SapphireTest
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

    private function expectedHeaders(): array
    {
        return SVGResponseHeadersExtension::config()->get('svg_response_headers');
    }

    /**
     * Pin the policy itself, so a config change is a deliberate decision and not a slip.
     */
    public function testThePolicySandboxesAndBlocksEverythingButInlineStyles(): void
    {
        $this->assertSame(
            "default-src 'none'; style-src 'unsafe-inline'; sandbox",
            $this->expectedHeaders()['Content-Security-Policy']
        );
        $this->assertSame('nosniff', $this->expectedHeaders()['X-Content-Type-Options']);
    }

    /**
     * A draft SVG lives in the protected store and is always streamed through PHP.
     */
    public function testProtectedSvgResponseCarriesThePolicy(): void
    {
        $this->logInWithPermission('ADMIN');
        $svg = $this->makeSVG(null, 'draft.svg', false);

        $store = Injector::inst()->get(AssetStore::class);
        $response = $store->getResponseFor($store->getFileID($svg->getFilename(), $svg->getHash()));

        $this->assertSame(200, $response->getStatusCode(), 'premise: the admin is served the draft file');
        foreach ($this->expectedHeaders() as $header => $value) {
            $this->assertSame($value, $response->getHeader($header), $header);
        }
    }

    /**
     * Variants are separate files with their own URLs, and need it just as much.
     */
    public function testProtectedSvgVariantResponseCarriesThePolicy(): void
    {
        $this->logInWithPermission('ADMIN');
        $variant = $this->makeSVG(null, 'draft-variant.svg', false)->ScaleWidth(100);

        $store = Injector::inst()->get(AssetStore::class);
        $response = $store->getResponseFor(
            $store->getFileID($variant->getFilename(), $variant->getHash(), $variant->getVariant())
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('sandbox', (string)$response->getHeader('Content-Security-Policy'));
    }

    /**
     * Only SVGs: `sandbox` would break the browser's PDF viewer for a PDF, so this module adds its
     * policy to SVG responses only.
     *
     * Core itself sends `Content-Security-Policy: default-src 'none'; sandbox` on every file PHP
     * serves since silverstripe/assets 3.2.3 (silverstripe/silverstripe-assets#720, on purpose),
     * so "no CSP at all" no longer holds on Silverstripe 6. What this module controls is that its
     * own policy is not added: whatever the PNG gets is core's (null on Silverstripe 5).
     */
    public function testOtherFilesAreLeftAlone(): void
    {
        $this->logInWithPermission('ADMIN');
        $png = $this->makePNG('raster.png');

        $store = Injector::inst()->get(AssetStore::class);
        $response = $store->getResponseFor($store->getFileID($png->getFilename(), $png->getHash()));

        $this->assertSame(200, $response->getStatusCode());
        // $this->assertNull($response->getHeader('Content-Security-Policy'));
        $coreHeaders = FlysystemAssetStore::config()->get('file_response_headers') ?? [];
        $this->assertSame(
            $coreHeaders['Content-Security-Policy'] ?? null,
            $response->getHeader('Content-Security-Policy'),
            'a PNG gets core\'s policy, if any, never this module\'s'
        );
        $this->assertNotSame(
            $this->expectedHeaders()['Content-Security-Policy'],
            $response->getHeader('Content-Security-Policy')
        );
    }

    public function testDenialsAndRedirectsAreLeftAlone(): void
    {
        $response = new HTTPResponse('', 403);
        (new SVGResponseHeadersExtension())->updateResponse($response, 'svgtest/x.svg', []);

        $this->assertNull($response->getHeader('Content-Security-Policy'));
    }

    /**
     * The .htaccess written into the (test) public assets dir is the module's: core's rules plus
     * the SVG headers, with the same values the extension sends.
     */
    public function testGeneratedHtaccessSendsThePolicyForSvgs(): void
    {
        $htaccess = file_get_contents(TestAssetStore::base_path() . '/.htaccess');

        $this->assertStringContainsString('<FilesMatch "\.(?i:svg)$">', $htaccess);
        foreach ($this->expectedHeaders() as $header => $value) {
            $this->assertStringContainsString("Header set {$header} \"{$value}\"", $htaccess);
        }
    }

    /**
     * Drift guard: the module's template must render core's template, with the same data, so a
     * security change to core's rules cannot be silently lost (#32: a copy of core's rules missed
     * SS-2026-003). Core's rules come first and unchanged; the SVG block only follows them.
     *
     * Rendered through the adapter's own renderTemplate() (protected), so both templates get
     * exactly what core gives them on either Silverstripe major.
     */
    public function testHtaccessTemplateStillContainsCoresRules(): void
    {
        $adapter = new PublicAssetAdapter(TestAssetStore::base_path());
        $render = new \ReflectionMethod($adapter, 'renderTemplate');

        $core = trim($render->invoke($adapter, 'SilverStripe\\Assets\\Flysystem\\PublicAssetAdapter_HTAccess'));
        $ours = $render->invoke($adapter, 'Restruct\\Silverstripe\\SVG\\PublicAssetAdapter_HTAccess');

        $this->assertStringContainsString('RewriteRule', $core, 'premise: core template found');
        $this->assertStringContainsString($core, $ours);
        $this->assertStringStartsWith($core, ltrim($ours), 'core\'s rules come first, unchanged');
    }

    /**
     * #32: the module's template includes core's template instead of copying it. A copy can only
     * match one core release and silently drops the next security change, so any of core's rules
     * written into this template's source fails here, whatever core's current rules are.
     */
    public function testHtaccessTemplateIncludesCoresTemplateInsteadOfCopyingIt(): void
    {
        $source = file_get_contents(
            dirname(__DIR__) . '/templates/Restruct/Silverstripe/SVG/PublicAssetAdapter_HTAccess.ss'
        );

        $this->assertStringContainsString(
            '<% include SilverStripe\\Assets\\Flysystem\\PublicAssetAdapter_HTAccess %>',
            $source
        );
        # Directives that only core's part of the file has; the SVG block uses none of them
        foreach (['AddHandler', 'php_flag', 'RewriteEngine', 'RewriteRule', 'Require all denied'] as $directive) {
            $this->assertStringNotContainsString($directive, $source, "copied core directive: {$directive}");
        }
    }

    /**
     * #32 / SS-2026-003: the generated assets/.htaccess denies direct access to executable
     * scripts. Comes from core's template (silverstripe/assets 2.4.6 / 3.2.4 / 3.3.1 and later,
     * the floor composer.json requires); a module template that drops it fails here.
     */
    public function testGeneratedHtaccessDeniesExecutableScripts(): void
    {
        $htaccess = file_get_contents(TestAssetStore::base_path() . '/.htaccess');

        $this->assertStringContainsString(
            "<FilesMatch \"(?i)\\.(?:php[0-9]*|phar|phtml|pht|inc)$\">\n    Require all denied\n</FilesMatch>",
            $htaccess
        );
        $this->assertStringContainsString('<FilesMatch "\.(?i:svg)$">', $htaccess, 'premise: the module\'s file');
    }
}

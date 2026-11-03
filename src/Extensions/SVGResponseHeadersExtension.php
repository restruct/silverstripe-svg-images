<?php

namespace Restruct\Silverstripe\SVG\Extensions;

use SilverStripe\Assets\Flysystem\FlysystemAssetStore;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Core\Config\Configurable;
use SilverStripe\Core\Extension;

/**
 * Applied to FlysystemAssetStore: sends a locking-down Content-Security-Policy with every SVG that
 * PHP serves, which is every protected (draft, restricted) SVG and any public one the web server
 * hands to Silverstripe.
 *
 * Defence in depth for #23: an SVG opened as a document (a "view file" link, a new tab) is a full
 * web page on the site's origin and can run script. `sandbox` puts it in a unique origin with
 * scripts off, and `default-src 'none'` stops it loading anything, so an SVG the sanitizer missed
 * still cannot act as the logged-in viewer. Inline styles stay allowed, because ordinary SVGs from
 * design tools use them. `<img>` embedding is unaffected: images never run script anyway.
 *
 * Only for SVGs, never for every file: `sandbox` breaks the browsers' built-in PDF viewers.
 *
 * Core's own policy wins where it has one with `sandbox`: silverstripe/assets 3.2.3+ (Silverstripe
 * 6) sends `default-src 'none'; sandbox` on every file PHP serves, stricter than this one, and that
 * is left alone (#34). This module's policy is sent where core sends none (Silverstripe 5).
 *
 * Public files the web server serves directly never reach PHP. For Apache the module's
 * PublicAssetAdapter_HTAccess template adds the same headers; for nginx see the README.
 */
class SVGResponseHeadersExtension extends Extension
{
    use Configurable;

    /**
     * Headers added to every SVG response. The .htaccess template sends the same values; a test
     * keeps the two in step.
     *
     * @config
     * @var array<string, string>
     */
    private static $svg_response_headers = [
        'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        'X-Content-Type-Options' => 'nosniff',
    ];

    /**
     * Hook from FlysystemAssetStore::getResponseFor().
     *
     * @param HTTPResponse $response
     * @param string $asset The requested file ID, e.g. "folder/logo__FitWzEwMCwxMDBd.svg"
     * @param array $context
     */
    public function updateResponse($response, $asset, $context = []): void
    {
        if (!$response instanceof HTTPResponse || !static::is_svg_response($response, (string)$asset)) {
            return;
        }

        foreach ((array)static::config()->get('svg_response_headers') as $header => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            # #34: core sends its own `default-src 'none'; sandbox` on every file PHP serves since
            # silverstripe/assets 3.2.3 (silverstripe/silverstripe-assets#720). That already does
            # what this policy is for, and is stricter (no inline styles), so it is kept. A policy
            # without `sandbox` does not stop script in an SVG opened as a document: ours replaces it.
            if (strcasecmp($header, 'Content-Security-Policy') === 0
                && static::is_sandboxed((string)$response->getHeader($header))
            ) {
                continue;
            }
            $response->addHeader($header, $value);
        }
    }

    /**
     * Whether a Content-Security-Policy value has a `sandbox` directive (with or without allow-*
     * tokens, which are the project's or core's own decision).
     */
    protected static function is_sandboxed(string $policy): bool
    {
        foreach (explode(';', $policy) as $directive) {
            $name = strtolower((string)strtok(trim($directive), " \t"));
            if ($name === 'sandbox') {
                return true;
            }
        }

        return false;
    }

    /**
     * A file response for an SVG: by extension (variants keep it) or by the type the store sent.
     * Redirects, denials and 404s are left alone.
     */
    protected static function is_svg_response(HTTPResponse $response, string $asset): bool
    {
        if ($response->getStatusCode() !== 200) {
            return false;
        }

        $path = strtolower((string)parse_url($asset, PHP_URL_PATH));
        $type = strtolower((string)$response->getHeader('Content-Type'));

        return str_ends_with($path, '.svg') || str_starts_with($type, 'image/svg');
    }
}

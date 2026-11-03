<%-- Core's SilverStripe\Assets\Flysystem\PublicAssetAdapter_HTAccess, rendered in place (not
     copied), followed by headers for SVGs. Selected through PublicAssetAdapter.server_configuration
     in _config/config.yml. Up to 3.2.1 this file held a copy of core's rules, which missed core's
     SS-2026-003 hardening (silverstripe/assets 2.4.6 / 3.2.4 / 3.3.1) until synced by hand (#32).
     The include resolves core's template by its full name (after an Includes/ variant, which core
     does not have) and renders it with the same data, so core's rules reach sites without a
     release of this module. SVGResponseHeadersTest fails if this file copies core's rules again, if the rendered result
     does not start with core's own, or if the header values drift from SVGResponseHeadersExtension. --%>
<% include SilverStripe\Assets\Flysystem\PublicAssetAdapter_HTAccess %>

# SVGs opened as a document are web pages on this site's origin. Lock them down: no script, no
# external loads, inline styles only. Only .svg: "sandbox" breaks the browsers' PDF viewers.
# Added by restruct/silverstripe-svg-images; mirrors SVGResponseHeadersExtension for files PHP serves.
<IfModule mod_headers.c>
    <FilesMatch "\\.(?i:svg)$">
        Header set Content-Security-Policy "default-src 'none'; style-src 'unsafe-inline'; sandbox"
        Header set X-Content-Type-Options "nosniff"
    </FilesMatch>
</IfModule>

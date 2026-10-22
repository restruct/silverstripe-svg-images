# SVG Image support for Silverstripe (assets/uploads)

*Maintained by [Restruct](https://github.com/restruct). If this module saves you time, you can
[support ongoing maintenance](https://github.com/sponsors/restruct).*

Formerly published as `micschk/silverstripe-svg-images`: that package is abandoned, require
`restruct/silverstripe-svg-images` instead (the PHP namespace has been the same since 1.1).

This module provides comprehensive SVG support in Silverstripe's asset management system, including:

- **CMS thumbnail/preview support** for SVG files in AssetAdmin
- **Real SVG manipulation** (resize, crop) that modifies viewBox/dimensions while preserving vectors
- **SVG sanitization** on upload to remove potentially dangerous content
- **Dimension parsing** from SVG viewBox/width/height attributes
- **Automatic class handling** for SVGs uploaded through Image relations

## Version Compatibility

| Branch | Module version | Silverstripe | PHP |
|--------|----------------|--------------|-----|
| `main` | `3.x` | 5, 6 | 8.1+ (8.3+ on Silverstripe 6) |
| - (tags only) | `2.0` - `2.1` | 6 | 8.3+ |
| `ss4/5` | `1.3` - `1.4` | 4, 5 | 7.4+ |
| - (tags only) | `1.0` - `1.2` | 3 | |

`composer.json` on each branch is the source of truth for exact constraints. `3.x` replaces both
`1.x` and `2.x`; they receive no further releases. Upgrading? See [UPGRADING.md](UPGRADING.md) and
[CHANGELOG.md](CHANGELOG.md).

## Requirements and installation

- Silverstripe 5 or 6 (`silverstripe/framework` and `silverstripe/assets`), PHP 8.1+, `ext-dom`
- Optional: [jonom/focuspoint](https://github.com/jonom/silverstripe-focuspoint) and/or
  [restruct/silverstripe-focuspointcropper](https://github.com/restruct/silverstripe-focuspointcropper)
  for their SVG-aware methods (see below), `ext-gd` for the `/dev/svg-compare` test images

```bash
composer require restruct/silverstripe-svg-images
```

Then flush and build the database (`sake dev/build flush=1` on Silverstripe 5,
`sake db:build --flush` on 6). SVG uploads are allowed and handled as images from then on.

## How it works

The module configures Silverstripe to use the `SVGImage` class for `.svg` files via `class_for_file_extension`. This happens automatically for files uploaded through AssetAdmin.

### SVG uploads through Image relations

When uploading SVGs through relation fields (`has_one`, `has_many`, or `many_many` to `Image`), Silverstripe's ORM enforces the relation's class type, ignoring the `class_for_file_extension` config. This module includes `SVGImageExtension` which automatically corrects the `ClassName` to `SVGImage` after the file is written.

This happens transparently - no configuration needed.

### SVG Manipulation

Unlike raster images, SVG manipulation preserves the vector format by modifying viewBox and width/height attributes. The module uses [contao/imagine-svg](https://github.com/contao/imagine-svg) for manipulation.

**Core operations** (always available):
- `Fit($width, $height)` - Resize to fit within bounds, maintaining aspect ratio
- `FitMax($width, $height)` - Same as Fit, but only if image is larger
- `Fill($width, $height)` - Crop and resize to fill exact dimensions
- `FillMax($width, $height)` - Same as Fill, but only if image is larger
- `Pad($width, $height)` - Fit within bounds and add transparent padding to reach exact dimensions
- `ScaleWidth($width)` - Scale to specific width, maintaining aspect ratio
- `ScaleHeight($height)` - Scale to specific height, maintaining aspect ratio
- `ScaleMaxWidth($width)` - Scale to max width, only if larger
- `ScaleMaxHeight($height)` - Scale to max height, only if larger
- `CropWidth($width)` / `CropHeight($height)` - Crop to a width/height from the centre, keeping the other dimension (never enlarges)
- `CropRegion($x, $y, $width, $height)` - Crop to a region, in the original's coordinates
- `ResizedImage($width, $height)` - Resize to exactly this width and height. The drawing keeps its proportions inside that box (it is not stretched). Core calls this for an image an editor resized in WYSIWYG content
- `Resampled()` / `Quality($quality)` - Return the SVG unchanged (for compatibility with Image templates)

SVGs are kept away from the site's raster image backend (Intervention with GD or Imagick): every
manipulation above is done on the SVG markup, and other core image methods on an SVG get a backend
that cannot decode anything, so they return no variant. With the Imagick driver, an SVG would
otherwise be rasterised on the server, following any local file path it names. This covers
`SVGImage` and its variants always, and a plain `Image` or `DBFile` holding an SVG (an upload into a
`has_one Image` field, before its class is corrected) through the module's replacement of core's
`ImageBackendFactory` service. Two kinds of project config bypass that replacement for plain
`Image`/`DBFile` objects: giving the `Image_Backend` service its own `factory`, or re-mapping the
`SilverStripe\Assets\ImageBackendFactory` service to another class. A project that needs either
should extend `Restruct\Silverstripe\SVG\SVGImageBackendFactory` instead. `dev/build` prints a
notice when the `Image_Backend` service is not built by that factory.

Manipulated SVGs are stored as variants (just like raster image variants), so they're cached and only generated once.

To disable manipulation and return original SVGs unchanged (legacy behavior):

```yaml
Restruct\Silverstripe\SVG\SVGImage:
  enable_svg_manipulation: false
```

### Optional Extensions

This module provides optional extensions that are automatically applied when their corresponding modules are installed:

#### Crop Support (requires `restruct/silverstripe-focuspointcropper`)

When the FocusPointCropper module is installed, SVGs also get:

- `applyCropData($cropDataJson)` - Apply the crop region set in the CMS (uses `CropRegion()`)

(`CropRegion()`, `CropWidth()` and `CropHeight()` no longer need this module; they are core
operations, above.)

#### FocusPoint Support (requires `jonom/focuspoint`)

When the FocusPoint module is installed, these additional methods become available:

- `FocusFill($width, $height)` - Fill with focus-aware cropping
- `FocusFillMax($width, $height)` - Same as FocusFill, but only if image is larger
- `FocusCropWidth($width)` - Crop to width, centered on focus point
- `FocusCropHeight($height)` - Crop to height, centered on focus point

### SVG Sanitization

SVG files are automatically sanitized when they are written using [enshrined/svg-sanitize](https://github.com/enshrined/svg-sanitize): on upload (through AssetAdmin or a relation's upload field) and whenever a file's content is replaced. The unsanitized upload is not kept in the asset store. This removes potentially dangerous content like:
- JavaScript/event handlers
- External references (can be disabled)
- PHP tags
- Other XSS vectors

An SVG that cannot be sanitized is **refused**: the upload fails with a message, no File record is
written, and the refused bytes are deleted from the asset store unless an existing record (another
stage or version) still references that exact file. That covers a file the sanitizer cannot parse
or throws on (for example nesting deeper than 256 levels, a root element that is not `<svg>`, a
`<use>` that references its own ancestor) and any file that declares entities in its DOCTYPE
(`<!DOCTYPE svg [ ... ]>`). Older Illustrator "Save As SVG" exports do the latter; export again with
"Preserve Illustrator Editing Capabilities" switched off. Code that writes such a file gets a
`ValidationException` from `write()`. A refused replacement leaves the record with its previous
file while validation is enabled (the default), and always for `SVGImage` records. With validation
switched off (`DataObject.validation_enabled: false`, or Silverstripe 6's `skipValidation`), a plain
`Image`/`File` record holding an SVG can lose its previous file: core's `AssetControlExtension`
deletes it as replaced before the module's own `onBeforeWrite()` refuses the new one. The extension is matched in any case (`logo.SVG` too), and a
file renamed to `.svg` from another extension is checked like an upload.

Publishing fails closed: a draft SVG whose bytes are not what the sanitizer would store (stored
before sanitizing worked, or while `sanitize_on_upload` was off) is refused with "This SVG was stored
before sanitising was added; replace or re-upload it to publish". A clean draft is published as it
is. Publishing again a file that Live already serves unchanged is allowed, so pages that own such an
image can still be published; that file stays as it is (see below).

> Up to 3.0.0, a file the sanitizer could not parse was stored exactly as uploaded, script included
> (#23).

> Up to 1.4.1 and 2.1.0, sanitization never actually ran, despite being enabled by default. SVGs
> uploaded with those versions are unsanitized; see [UPGRADING.md](UPGRADING.md).

Configuration options:

```yaml
Restruct\Silverstripe\SVG\SVGImage:
  # Disable sanitization (not recommended)
  sanitize_on_upload: false

  # Keep remote references (disabled by default for security)
  sanitize_remove_remote_references: false
```

### Migrating existing SVG files

SVG files uploaded before installing this module are plain `Image`/`File` records. To migrate them
once, run the task (a dry run first, then for real):

```bash
# Silverstripe 6
vendor/bin/sake tasks:MigrateSVGImagesTask              # dry run - shows what would change
vendor/bin/sake tasks:MigrateSVGImagesTask --confirm    # actually migrate

# Silverstripe 5
vendor/bin/sake dev/tasks/MigrateSVGImagesTask
vendor/bin/sake dev/tasks/MigrateSVGImagesTask confirm=1
```

Or let every database build do it, by enabling auto-migration:

```yaml
Restruct\Silverstripe\SVG\SVGImage:
  auto_migrate_svg_class: true
```

Then build the database (`dev/build` on Silverstripe 5, `sake db:build` on 6). The migration will update the `ClassName` in `File`, `File_Live`, and `File_Versions` tables (including files with NULL or empty ClassName).

> **Note:** The migration runs via `requireDefaultRecords()`. If you use `dev/build no-populate=1`, the migration will be skipped. Run `dev/build/defaults` separately to trigger it, or run a normal `dev/build` without `no-populate`.

## Usage in templates

```html
<!-- Basic usage -->
<img src="$Image.URL" />

<!-- With manipulation (preserves vector format) -->
<img src="$Image.ScaleWidth(200).URL" />
<img src="$Image.Fill(100, 100).URL" />
<img src="$Image.Fit(300, 200).URL" />

<!-- Responsive example -->
<img src="$Image.ScaleWidth(400).URL" srcset="$Image.ScaleWidth(800).URL 2x" />

<!-- Works in mixed image/SVG contexts -->
<img src="$Image.Resampled.URL" />

<!-- FocusPoint methods (when jonom/focuspoint is installed) -->
<img src="$Image.FocusFill(400, 300).URL" />
```

### Inline SVG

```html
<!-- Add raw SVG inline -->
{$Image.SVG_RAW_Inline}

<!-- Conditional based on file type -->
<% if $Image.IsSVG %>
  {$Image.SVG_RAW_Inline}
<% else %>
  <img src="$Image.ScaleWidth(400).URL" />
<% end_if %>
```

### Inline SVG with color manipulation

If you need to manipulate SVG colors or add CSS classes for inline SVGs, consider [stevie-mayhew/silverstripe-svg](https://github.com/stevie-mayhew/silverstripe-svg). You can use it alongside this module by passing the asset path:

```html
{$SVG($Image.Filename).fill('#FF9933').extraClass('my-icon')}
```

## SVG Security

SVGs can expose attack vectors comparable to HTML/JS. This module mitigates risks through automatic sanitization, but you should still:

- Only accept SVG uploads from trusted users
- Use `<img>` tags rather than inline SVG when possible (provides more browser security)
- Keep the sanitization enabled (default)

### Content-Security-Policy for SVG files

An SVG opened on its own (a direct link, "open in new tab") is a web page on your site's origin.
As a second line of defence, SVGs are served with:

```
Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; sandbox
X-Content-Type-Options: nosniff
```

`sandbox` blocks script and puts the file in its own origin; `default-src 'none'` stops it loading
anything. `<img>` tags are not affected. The headers are sent only for `.svg`, because `sandbox`
breaks the browsers' PDF viewer.

Where the headers come from, and where they do **not** unless you add them:

- **Files Silverstripe streams through PHP** (protected and draft files, and any file the web
  server passes to Silverstripe) get them from `SVGResponseHeadersExtension`, which hooks
  `FlysystemAssetStore::getResponseFor()`. Its `svg_response_headers` config holds the values.
  A module that serves protected files another way (for example
  `restruct/silverstripe-signed-asset-urls`, or any X-Sendfile/X-Accel-Redirect offload) bypasses
  that hook, so those responses get no CSP from this module.
- **Apache**: the module selects its own `assets/.htaccess` template: core's rules plus the SVG
  headers (needs `mod_headers`). The file is regenerated on flush, so flush once after updating.
  If your project sets its own template in `PublicAssetAdapter.server_configuration`, yours wins
  and the headers are missing. Add this block to the generated file's template; in a `.ss`
  template write the backslash twice (`\\.(?i:svg)`), because the template engine turns `\\.` into
  `\.`:

```apache
<IfModule mod_headers.c>
    <FilesMatch "\.(?i:svg)$">
        Header set Content-Security-Policy "default-src 'none'; style-src 'unsafe-inline'; sandbox"
        Header set X-Content-Type-Options "nosniff"
    </FilesMatch>
</IfModule>
```

- **nginx** ignores `.htaccess`, so public SVGs are served without the headers until you add them.
  Silverstripe's documented nginx config ("File Security" in the developer docs, same for 5 and 6)
  serves assets from a prefix location, `location ^~ /assets/ { ... }`. The `^~` stops nginx from
  checking regex locations, so a separate server-level `location ~* \.svg$` never matches an
  asset. Nest the SVG location **inside** your existing `/assets/` block instead. A nested location
  does not inherit `try_files`, and `add_header` in it drops every `add_header` from the levels
  above, so repeat what you rely on:

```nginx
location ^~ /assets/ {
    sendfile on;
    try_files $uri /index.php?$query_string;

    # Dotfiles (.htaccess, .protected/) stay private; the ^~ prefix also stops a server-level
    # deny rule from matching here, so repeat it first inside this block
    location ~ /\. {
        deny all;
    }

    location ~* \.svg$ {
        add_header Content-Security-Policy "default-src 'none'; style-src 'unsafe-inline'; sandbox" always;
        add_header X-Content-Type-Options "nosniff" always;
        # Protected, draft and missing files still go to Silverstripe, which sends the headers itself
        try_files $uri /index.php?$query_string;
    }
}
```

  (The developer docs write the fallback as `index.php?$query_string`; use the form your config
  already has.) Without a `^~ /assets/` block, put the same inner `location` at server level, before
  any other regex location that matches `.svg` (such as a static-file cache block): nginx uses the
  first regex location that matches.

Check it with `curl -I https://example.com/assets/<some-file>.svg`.

### SVGs stored before the upgrade

The module does not re-sanitize files that are already in the asset store, and does not touch
files that are already published. Up to 3.0.0 a file the sanitizer could not parse was stored as
uploaded, and before 3.0.0 nothing was sanitized at all (see [UPGRADING.md](UPGRADING.md)). The
headers above protect those files when opened directly, where they are sent. If untrusted users
could upload SVGs, find them and replace or re-upload them. This lists every SVG, on either stage,
whose stored bytes are not what the sanitizer would store (read-only; run it in a dev task, a
`sake` script or a test console):

```php
use Restruct\Silverstripe\SVG\SVGImage;
use SilverStripe\Assets\File;
use SilverStripe\Versioned\Versioned;

foreach ([Versioned::DRAFT, Versioned::LIVE] as $stage) {
    $files = Versioned::get_by_stage(File::class, $stage)->filter('FileFilename:EndsWith:nocase', '.svg');
    foreach ($files as $file) {
        $content = $file->File->exists() ? $file->File->getString() : null;
        if ($content !== null && !SVGImage::is_sanitize_stable($content)) {
            echo "{$stage}\t#{$file->ID}\t{$file->getFilename()}\n";
        }
    }
}
```

To fix one, replace the file in the CMS (or re-upload it) and publish: the new upload is
sanitized, or refused with a reason.

For more information on SVG security risks, see [OWASP SVG Security Cheat Sheet](https://cheatsheetseries.owasp.org/cheatsheets/SVG_Security_Cheat_Sheet.html).

## Configuration reference

```yaml
Restruct\Silverstripe\SVG\SVGImage:
  # Enable real SVG manipulation (resize/crop)
  enable_svg_manipulation: true

  # Sanitize SVGs on upload
  sanitize_on_upload: true

  # Remove remote references during sanitization
  sanitize_remove_remote_references: true

  # Auto-migrate existing SVG files on dev/build
  auto_migrate_svg_class: false
```

## Development Tools

### SVG vs PNG Comparison Tool

A visual comparison tool is available at `/dev/svg-compare` (in dev mode, or for users with `ADMIN` or `ALL_DEV_ADMIN` permission) to verify that SVG manipulations behave consistently with PNG manipulations.

![SVG vs PNG Comparison Tool](https://raw.githubusercontent.com/restruct/silverstripe-svg-images/main/docs/svg-compare-test.png)

The tool:
- Compares all manipulation methods (Fit, Fill, Pad, Scale, etc.) side-by-side for SVG and PNG
- Tests both published and draft/protected assets
- Shows FocusPoint methods when `jonom/focuspoint` is installed
- Includes bundled test images or accepts custom image IDs
- Displays badges and legends explaining each manipulation type

### Clear SVG Variants Task

To clear all generated SVG variant files (useful after upgrading or when manipulation settings change):

```bash
# Silverstripe 6
vendor/bin/sake tasks:ClearSVGVariantsTask              # dry run - shows what would be deleted
vendor/bin/sake tasks:ClearSVGVariantsTask --confirm    # actually delete variants
vendor/bin/sake tasks:ClearSVGVariantsTask --confirm -v # with a line per file

# Silverstripe 5
vendor/bin/sake dev/tasks/ClearSVGVariantsTask
vendor/bin/sake dev/tasks/ClearSVGVariantsTask confirm=1
vendor/bin/sake dev/tasks/ClearSVGVariantsTask confirm=1 verbose=1
```

Only variants are deleted; the original SVGs stay. Variants will be regenerated on next request
using the current manipulation settings.

## Running the tests

The suite in `tests/` needs a Silverstripe host project with `silverstripe/recipe-testing`, and
the module installed through a **symlinked** path repository (`tests/` is `export-ignore`d, so a
normal install has no tests). `.github/workflows/ci.yml` builds exactly such a host for
Silverstripe 5 and 6 and is the reference. `jonom/focuspoint` must be installed for the
focus-point tests to run instead of skipping.

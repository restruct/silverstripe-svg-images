# Changelog

## 3.2.0 (unreleased)

### Changed

- **An SVG's size now comes from its `width`/`height` attributes first, and from the `viewBox`
  only when they are missing or relative (e.g. `100%`).** This is how browsers size an SVG and
  how contao/imagine-svg (which does every resize and crop) always read it. Before, the viewBox
  won: `viewBox="0 0 200 100" width="300" height="150"` reported 200x100 although it is shown at
  300x150, and its variants were computed from 300x150. Templates that print `$Width`/`$Height`
  of such an SVG now get the shown size. SVGs without width/height are unaffected.

### Fixed

- **SVGs whose `viewBox` does not start at `0 0` reported the wrong size.** The width was
  computed as `width - min-x` (and the height likewise), so `viewBox="10 10 100 50"` reported
  90x40, and every manipulation scaled from that ratio (`ScaleWidth(300)` gave 300x133 instead of
  300x150).
- **`$Image.Width` on an `SVGImage` whose file is missing from the store was a fatal error**
  (`getDimensions()` deferred to a parent method that does not exist). It now reports 0, as for an
  SVG without dimensions.

### Added

- `MigrateSVGImagesTask`: migrates `.svg` records uploaded before the module was installed to
  `SVGImage` once, with a dry run first, as an alternative to `auto_migrate_svg_class` on every
  database build. `SVGImage::migrate_svg_class()` is the shared implementation.
- An SVG that cannot be parsed is logged (one warning per file per request) instead of silently
  reporting no dimensions.
- PHPUnit coverage for event-handler attributes and `javascript:` links in uploads (until now
  covered by the browser suite only), and a static-analysis (PHPStan) job in CI.

## 3.1.0 (2026-10-11)

### Security

- **SVGs the sanitizer cannot parse are refused instead of stored as uploaded (#23).** Such a file
  skipped sanitizing entirely, and a browser opening its URL ran its script on the site's origin
  (stored XSS for any role that can upload an SVG). Inputs that did this: an entity declared in the
  DOCTYPE, nesting deeper than 256 levels, a non-`<svg>` root and a self-referencing `<use>` (the
  last two made the sanitizer throw: a 500 with the bytes left in the store). They are now refused
  during validation with a message in the Files area and in upload fields, and a
  `ValidationException` for code that writes files. The refused bytes are deleted from the asset
  store unless a record still references them, and the record keeps its previous file (with
  validation switched off this holds for `SVGImage` records only; see the README). A DOCTYPE
  with an internal subset is refused up front; this includes older Illustrator "Save As SVG"
  exports. `sanitize_on_upload: false` still switches all of this off.
- **Uppercase extensions (`logo.SVG`) were never sanitized.** Upload validation and the class
  mapping lowercase the extension, so such a file became an `SVGImage`, but every SVG check in this
  module compared the extension case-sensitively. All checks are now case-insensitive.
- **A file renamed to `.svg` from another extension is now sanitized** (its hash does not change,
  so it used to skip the check).
- SVG responses now carry `Content-Security-Policy: default-src 'none'; style-src
  'unsafe-inline'; sandbox` and `X-Content-Type-Options: nosniff`, so an SVG opened as a document
  cannot run script even if the sanitizer misses something, or the file was stored before this
  release. Sent for files Silverstripe streams through PHP (protected and draft files), and by the
  generated `assets/.htaccess` on Apache (flush once to regenerate it). **Not covered without your
  action:** nginx (add the nested location from the README), a project with its own `.htaccess`
  template (add the README's `FilesMatch` block), and protected files served by another module such
  as `restruct/silverstripe-signed-asset-urls` or an X-Sendfile/X-Accel offload.
- **Publishing fails closed.** A draft SVG whose bytes are not what the sanitizer would store
  (stored before sanitizing worked, or with `sanitize_on_upload` off) is refused: "This SVG was
  stored before sanitising was added; replace or re-upload it to publish". Re-publishing a file Live
  already serves unchanged is allowed, so pages that own it can still be published.
- SVGs already in the asset store, and already published, are not re-sanitized or changed. The
  README has a read-only snippet (`SVGImage::is_sanitize_stable()`) that lists them.

### Fixed

- **`Resampled()`, `ResizedImage()` and `Quality()` no longer hand SVGs to the raster image
  backend (#24).** With Intervention's Imagick driver the SVG was rasterised on the server on every
  render (following local file paths it names) and the failure was never cached. `Resampled()` and
  `Quality()` now return the SVG, as the README already claimed, and `ResizedImage()` (used for
  images resized in WYSIWYG content) returns an SVG variant of the requested size. `SVGImage` and
  its variants always get a backend that cannot decode, and the module replaces core's
  `ImageBackendFactory` service so a plain `Image` or `DBFile` holding an SVG gets one too (an SVG
  uploaded into a `has_one Image` field made thumbnails through the raster backend before its
  class was corrected). A site that sets its own `factory` for `Image_Backend`, or re-maps the
  `ImageBackendFactory` service, keeps the trait's protection only; `dev/build` prints a notice
  then.
- `setAttribute()` on an SVG variant fatalled ("setOriginal() on null"), and with it every page
  showing an SVG resized in WYSIWYG content. Variants now keep their original and its attributes,
  as core's do.
- Publishing a clean draft SVG no longer sanitizes it again during the Live write, which could
  leave the published record pointing at a file in the protected store that visitors could not
  load.

## 3.0.0 (2026-09-24)

One line for Silverstripe 5 and 6 (PHP 8.1+), replacing both `1.x` (Silverstripe 4/5, branch
`ss4/5`) and `2.x` (Silverstripe 6). It is the 1.4.1 feature set plus the Silverstripe 6 port from
2.x. Silverstripe 4 is no longer supported; stay on `1.4.x` there.

See [UPGRADING.md](UPGRADING.md).

### Security

- **SVG sanitization now actually runs.** Since 1.3.1 `sanitize_on_upload` defaulted to `true`,
  but no upload was ever sanitized: the check bailed out on the very first write it was meant for,
  never ran for SVGs uploaded through a `has_one Image` relation, and ignored replaced files. It
  now runs on every upload and content replacement, for every file class, and the unsanitized
  upload is removed from the asset store. **SVGs already stored are not re-sanitized.**
- **`/dev/svg-compare` checks access itself** (dev mode, or `ADMIN` / `ALL_DEV_ADMIN`). It relied
  on `DevelopmentAdmin`, which does not check access to a registered controller, so in live mode a
  user with any dev permission (e.g. `BUILDTASK_CAN_RUN`) could open it and write test files to
  the asset store.
- `enshrined/svg-sanitize` raised to `^0.22 || ^1`: every release below 0.22 is covered by advisory
  PKSA-4g5g-4rkv-myqs (#19).

### Fixed

- **`ClearSVGVariantsTask` deleted originals.** With `confirm=1` / `--confirm` it deleted each draft
  SVG it found variants for, variants and original together, leaving the File record pointing at
  nothing. It also never found the variants of published files. It now deletes only variants, on
  both stores.
- `ClearSVGVariantsTask` could not be run through `sake` on Silverstripe 6 ("An option named
  verbose already exists"). Use the global `-v`.
- `FillMax()` on an `SVGImage` now matches core. The 1.4.1 fix only reached chained variants.
- `CropWidth()` / `CropHeight()` work on SVGs. In 1.4.x core's raster crop ran instead and returned
  nothing for every SVG.
- `ScaleMaxWidth()` / `ScaleMaxHeight()` work on SVGs. They were documented as available, but
  core's raster versions ran and returned nothing for every SVG.
- An SVG uploaded through a relation and published in the same request is now an `SVGImage` on
  the live stage too, not a plain `Image`.
- `/dev/svg-compare` no longer fatals on Silverstripe 6.

### Changed

- `CropRegion()`, `CropWidth()` and `CropHeight()` are core operations on every SVG. In 1.4.x they
  needed `restruct/silverstripe-focuspointcropper`; `applyCropData()` still does.
- The optional crop/focus-point extensions extend `Core\Extension` instead of `DataExtension`.
- Licence is MIT, as declared by 2.1.0 (earlier releases declared BSD-3-Clause), and a `LICENSE`
  file with the MIT text is added.

### Removed

- `ClearSVGVariantsTask::findVariantsInFilesystem()` and `getCommonVariantNames()` (protected),
  and the SS6 task-level `--verbose` option (the global `-v` / `--verbose` still works).
  `getVariantsForFile()` returns `[filesystem, ParsedFileID]` pairs, and
  `deleteVariantsForFile()` takes a `callable $writeln` instead of 2.x's `PolyOutput`. Only
  affects code that subclasses the task.

### Tests and CI

- First test suite (53 tests), run on Silverstripe 5 and 6 in GitHub Actions, including the
  optional `jonom/focuspoint` integration.

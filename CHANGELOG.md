# Changelog

## Unreleased

### Security

- **SVGs the sanitizer cannot parse are refused instead of stored as uploaded (#23).** Such a file
  skipped sanitizing entirely, and a browser opening its URL ran its script on the site's origin
  (stored XSS for any role that can upload an SVG). Two inputs did this reliably: an entity declared
  in the DOCTYPE, and nesting deeper than 256 levels. Both are now refused with a message in the
  Files area and in upload fields, a `ValidationException` for code that writes files, and the
  uploaded bytes are removed from the asset store. A DOCTYPE with an internal subset is refused up
  front; this includes older Illustrator "Save As SVG" exports. `sanitize_on_upload: false` still
  switches all of this off.
- Every SVG response now carries `Content-Security-Policy: default-src 'none'; style-src
  'unsafe-inline'; sandbox` and `X-Content-Type-Options: nosniff`, so an SVG opened as a document
  cannot run script even if the sanitizer misses something, or the file was stored before this
  release. Sent by PHP for protected/draft files, and by the generated `assets/.htaccess` on
  Apache (flush once to regenerate it). **nginx sites must add a location block**: see the README.
- SVGs already in the asset store are not re-sanitized; there is no task for it.

### Fixed

- **`Resampled()`, `ResizedImage()` and `Quality()` no longer hand SVGs to the raster image
  backend (#24).** With Intervention's Imagick driver the SVG was rasterised on the server on every
  render (following local file paths it names) and the failure was never cached. `Resampled()` and
  `Quality()` now return the SVG, as the README already claimed, and `ResizedImage()` (used for
  images resized in WYSIWYG content) returns an SVG variant of the requested size. An SVG now
  always gets a backend that cannot decode, so no other core image method can rasterise it either.

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

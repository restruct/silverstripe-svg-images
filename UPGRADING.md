# Upgrading

## To 3.0

3.0 supports Silverstripe 5 and 6 from one line (PHP 8.1+), and replaces both `1.x` and `2.x`.
Silverstripe 4 projects stay on `1.4.x`.

```bash
composer require restruct/silverstripe-svg-images:^3
```

Then flush and build the database. Template and PHP calls are unchanged; the class names,
config keys and their defaults are the same as in 1.4.1 and 2.1.0.

### Check your existing SVGs (both lines)

Sanitization never ran before 3.0, although it was on by default. 3.0 sanitizes every SVG that is
uploaded or replaced from now on, but **does not touch SVGs already in the asset store**. If
untrusted users could upload SVGs, review those files or re-upload them.

### Behaviour you may notice

- Uploaded SVGs are stored sanitized: scripts, event handlers and (by default) remote references
  are removed. Set `sanitize_on_upload: false` to keep the old, unsanitized behaviour.
- `FillMax()` on an SVG image now gives the same size as core does for a raster image. In 1.x and
  2.x it could return the image unchanged or upscaled.
- `CropWidth()` and `CropHeight()` now return a cropped SVG instead of nothing.
- `/dev/svg-compare` needs dev mode, or `ADMIN` / `ALL_DEV_ADMIN` permission.
- `ClearSVGVariantsTask` deletes only variants. In 1.x and 2.x `confirm=1` also deleted the
  original of every draft SVG with variants; if you ran it, check for File records whose file is
  missing.

### From 1.x

- `CropRegion()`, `CropWidth()` and `CropHeight()` no longer need
  `restruct/silverstripe-focuspointcropper`.
- On Silverstripe 6 the task is run as `sake tasks:ClearSVGVariantsTask --confirm -v`.
- The licence is MIT (1.x declared BSD-3-Clause), with a `LICENSE` file in the package.

### From 2.x

- If you subclass `ClearSVGVariantsTask`: `findVariantsInFilesystem()` and
  `getCommonVariantNames()` are gone, and `deleteVariantsForFile()` takes a
  `callable $writeln` instead of a `PolyOutput`. See the [changelog](CHANGELOG.md).
- `--verbose` is no longer a task option, so sake runs the task again; the global `-v` gives the
  same output.
- The licence stays MIT, as in 2.1.0; the package now also ships a `LICENSE` file.

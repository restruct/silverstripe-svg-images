# Porting SVG Manipulation Feature to SilverStripe 6

This document summarizes the changes made to `restruct/silverstripe-svg-images` in the `ss4/5` branch that need to be ported to the `main` branch (SS6).

## Overview

The ss4/5 branch adds complete SVG manipulation support:
- Real SVG manipulation (Fit, Fill, Pad, ScaleWidth, ScaleHeight, CropRegion) using `contao/imagine-svg`
- SVG sanitization on upload using `enshrined/svg-sanitize`
- Defensive error handling for malformed SVGs
- Dev tools for testing

## New Dependencies (composer.json)

Add these to the SS6 composer.json:

```json
{
  "require": {
    "silverstripe/cms": "^6",
    "contao/imagine-svg": "^1.0",
    "enshrined/svg-sanitize": "^0.20",
    "ext-dom": "*"
  },
  "suggest": {
    "ext-gd": "Required for generating PNG test images in /dev/svg-compare"
  }
}
```

## New Files to Add

### 1. `src/SVGDBFile.php`
A DBFile subclass that includes SVG manipulation methods. Used for returning manipulated SVG variants so chained manipulations work correctly.

Key method:
- `createFromTuple()` - Static factory method
- Uses `SVGManipulationTrait` for manipulation methods

### 2. `src/SVGManipulationTrait.php`
Shared trait with all SVG manipulation logic. Used by both `SVGImage` and `SVGDBFile`.

Key methods:
- `getImagineSVG()` - Loads SVG via contao/imagine-svg with try/catch for malformed SVGs
- `manipulateSVG($variant, $callback)` - Core manipulation engine, stores variants
- `isSVGManipulationEnabled()` - Checks config flag
- Overrides: `Fit()`, `FitMax()`, `Fill()`, `FillMax()`, `Pad()`, `ScaleWidth()`, `ScaleHeight()`, `CMSThumbnail()`, `StripThumbnail()`
- `getWidth()`, `getHeight()` - Get dimensions via Imagine

### 3. `src/Controllers/SVGCompareController.php`
Dev tool at `/dev/svg-compare` for visual comparison of SVG vs PNG manipulations.

Features:
- Generates test SVG and PNG images dynamically (from string, no file paths needed)
- Tests all manipulation methods side-by-side
- Tests both published and draft/protected assets
- Bootstrap 5 UI

### 4. `src/Tasks/ClearSVGVariantsTask.php`
Build task to clear generated SVG variant files.

Usage:
```bash
vendor/bin/sake dev/tasks/ClearSVGVariantsTask           # Dry run
vendor/bin/sake dev/tasks/ClearSVGVariantsTask confirm=1  # Actually delete
```

### 5. `templates/Restruct/Silverstripe/SVG/SVGCompare.ss`
Template for the comparison tool. Uses Bootstrap 5 CDN.

### 6. `.gitignore`
Add:
```
composer.lock
public/
```

### 7. `tests/fixtures/test-image.svg` and `test-image-draft.svg`
Test SVG files (simple colored rectangles with text).

## Changes to Existing Files

### `src/SVGImage.php`

**New config options:**
```php
private static $enable_svg_manipulation = true;
private static $sanitize_on_upload = true;
private static $sanitize_remove_remote_references = true;
```

**New imports:**
```php
use Contao\ImagineSvg\Imagine;
use enshrined\svgSanitize\Sanitizer;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Imagine\Image\Point;
use SilverStripe\Assets\Storage\AssetStore;
use SilverStripe\Core\Injector\Injector;
```

**New methods:**
- `onBeforeWrite()` - Calls `sanitizeSVG()` on first write if enabled
- `sanitizeSVG()` - Sanitizes SVG content using enshrined/svg-sanitize
- `getImagineSVG()` - Returns Imagine SVG instance with try/catch for graceful degradation
- `manipulateSVG($variant, $callback)` - Core manipulation engine
- `isSVGManipulationEnabled()` - Check config
- `CropRegion($x, $y, $width, $height)` - New SVG-only crop method

**Modified methods:**
All manipulation methods (Fit, FitMax, Fill, FillMax, Pad, ScaleWidth, ScaleHeight) now:
1. Check `isSVGManipulationEnabled()`
2. If enabled, use `manipulateSVG()` with Imagine callbacks
3. If disabled or fails, return `$this` (graceful fallback)

**Modified `existingOnly()`:**
Returns `SVGDBFile` instead of plain DBFile for SVGs, so manipulation methods work in thumbnail contexts.

**Modified `getDimensions()`:**
Uses `preg_split('/[\s,]+/', ...)` instead of `explode(' ', ...)` for viewBox parsing (handles comma-separated values).

### `_config/config.yml`

Add route for dev controller:
```yaml
SilverStripe\Control\Director:
  rules:
    'dev/svg-compare': 'Restruct\Silverstripe\SVG\Controllers\SVGCompareController'
```

### `README.md`

Complete rewrite with:
- SVG Manipulation section
- SVG Sanitization section
- Note about `no-populate` affecting `requireDefaultRecords()` migration
- Configuration reference
- Development Tools section (svg-compare, ClearSVGVariantsTask)
- Updated requirements

## SS6-Specific Considerations for Porting

1. **PHP 8.3 Attributes**: SS6 main branch uses `#[Override]` attributes. Add these to overridden methods.

2. **Namespace changes**: Verify all SilverStripe class imports still exist in SS6.

3. **API changes**: Test that these still work:
   - `$this->getString()` on File/Image
   - `$this->setFromString()`
   - `$this->getAllowGeneration()`
   - `$this->variantName()`
   - `AssetStore::CONFLICT_USE_EXISTING`
   - `DBField::create_field()`

4. **Rector**: The SS6 branch ran Rector. After porting, run Rector again to ensure code style consistency.

## Key Implementation Details

### Fill() Fix
The Imagine library's `thumbnail()` with `THUMBNAIL_OUTBOUND` didn't work correctly for SVGs. We implemented manual crop/resize calculation:

```php
// Calculate scale to fill (use the larger scale)
$scaleX = $width / $currentWidth;
$scaleY = $height / $currentHeight;
$scale = max($scaleX, $scaleY);

// Calculate crop offset to center
$cropX = (int)(($scaledWidth - $width) / 2 / $scale);
$cropY = (int)(($scaledHeight - $height) / 2 / $scale);

// Crop then resize
$image = $image->crop(new Point($cropX, $cropY), new Box($cropWidth, $cropHeight));
return $image->resize(new Box($width, $height));
```

### Pad() Implementation
SVG padding works by expanding the viewBox, not adding actual padding pixels:

```php
// Calculate new viewBox dimensions for target aspect ratio
$targetAspect = $width / $height;
$currentAspect = $vbWidth / $vbHeight;

if ($currentAspect > $targetAspect) {
    // Content is wider - add vertical padding
    $newVbHeight = $vbWidth / $targetAspect;
    $newVbY = $vbY - ($newVbHeight - $vbHeight) / 2;
} else {
    // Content is taller - add horizontal padding
    $newVbWidth = $vbHeight * $targetAspect;
    $newVbX = $vbX - ($newVbWidth - $vbWidth) / 2;
}

$root->setAttribute('viewBox', sprintf('%s %s %s %s', $newVbX, $newVbY, $newVbWidth, $newVbHeight));
```

### Graceful Degradation
All manipulation methods wrap Imagine calls in try/catch and return `$this` on failure:

```php
try {
    $imagine = new Imagine();
    return $imagine->load($content);
} catch (\Exception $e) {
    // Malformed SVG - return null to trigger graceful fallback
    return null;
}
```

## Deleted Files

- `src/SVGImage_Template.php` - Removed stevie-mayhew/silverstripe-svg integration wrapper (no longer needed, users can use that package separately if desired)

## Testing

After porting:
1. Run `dev/build flush=1`
2. Visit `/dev/svg-compare` to test all manipulations visually
3. Test both published and draft SVG assets
4. Test with malformed SVG to verify graceful degradation
5. Verify sanitization works on upload

## Reference

Source branch: `ss4/5` at commit `bb4c16b` (tag `1.3.2`)
Feature branch: `feature/svg-manipulation`
Repository: https://github.com/restruct/silverstripe-svg-images

---

## Architectural Differences: SS4/5 vs SS6 (Updated 2026-01-23 19:32)

The SS4/5 and SS6 versions take fundamentally different approaches to SVG handling:

### SS6 Approach
- `SVGImage::getIsImage()` returns `false` for SVGs
- Uses `SVGShortcodeExtension` to compensate and handle image shortcodes for SVGs
- `getImageBackend()` returns a dummy backend class to prevent null pointer errors in ThumbnailGenerator
- `SVGFileExtension` uses `onBeforeWrite()` to change ClassName

### SS4/5 Approach
- `SVGDBFile::getIsImage()` returns `true` for SVGs (keeps them as "images")
- No shortcode extension needed since SVGs are treated as images
- `ThumbnailURL()` and `PreviewLink()` overrides handle thumbnail generation directly
- `SVGImageExtension` uses `onAfterWrite()` with direct DB query (see below)

### Why the approaches differ
SS6's ThumbnailGenerator and asset handling have different internal behavior. SS4/5 could keep SVGs as images throughout the stack, while SS6 marked them as non-images and compensated with the shortcode extension.

### Recommendation: Evaluate SS4/5 approach for SS6

The SS4/5 "keep as image" approach (`getIsImage() => true`) appears cleaner:
- SVGs work naturally everywhere images are expected
- No need for compensating code (shortcode extension)
- Simpler codebase, less surface area for bugs

The SS6 approach (`getIsImage() => false` + `SVGShortcodeExtension`) adds complexity to work around problems that shouldn't exist if SVGs are just treated as images.

**When porting:** Consider adopting the SS4/5 approach in SS6:
1. Have `SVGImage::getIsImage()` return `true` (or not override it at all)
2. Remove `SVGShortcodeExtension` if no longer needed
3. Test thoroughly - shortcodes, thumbnails, AssetAdmin, UploadFields

If there was a specific SS6 issue that required `getIsImage() => false`, document it. Otherwise, the simpler SS4/5 approach may be preferable.

---

## SS4/5 Fixes (2026-01-23) - Release 1.3.2

These fixes were made to the SS4/5 branch and may need consideration for SS6:

### 1. SVGImageExtension - Fix ClassName for Image Relation Uploads

**Problem:** When uploading SVGs through relation fields (`has_one`, `has_many`, or `many_many` to `Image`), SilverStripe's ORM enforces the relation's class type. Even though `class_for_file_extension` correctly maps `svg` to `SVGImage`, the framework creates/saves as `Image` class because the relation is defined as `Image::class`.

**Initial attempt (didn't work):**
```php
// onBeforeWrite - sets ClassName but ORM overwrites it
public function onBeforeWrite(): void
{
    if ($owner->getExtension() === 'svg' && !($owner instanceof SVGImage)) {
        $owner->ClassName = SVGImage::class; // Gets ignored/overwritten
    }
}
```

**Solution (SS4/5):** Use `onAfterWrite()` with direct DB query:
```php
// src/SVGImageExtension.php
public function onAfterWrite(): void
{
    $owner = $this->getOwner();

    if ($owner->getExtension() === 'svg' && !($owner instanceof SVGImage)) {
        DB::prepared_query(
            'UPDATE "File" SET "ClassName" = ? WHERE "ID" = ?',
            [SVGImage::class, $owner->ID]
        );
    }
}
```

**Config:**
```yaml
SilverStripe\Assets\File:
  extensions:
    - Restruct\Silverstripe\SVG\SVGImageExtension
```

**SS6 consideration:** The SS6 version uses `onBeforeWrite()` in `SVGFileExtension`. Test if this actually works in SS6 for relation uploads, or if the same `onAfterWrite()` + DB query approach is needed.

### 2. Migration Query - Handle NULL/Empty ClassName

**Problem:** The migration query used `ClassName != ?` which doesn't match NULL values in MySQL. Files with NULL or empty ClassName weren't being migrated.

**Before:**
```php
DB::prepared_query(
    "SELECT COUNT(*) FROM \"{$table}\" WHERE \"Name\" LIKE '%.svg' AND \"ClassName\" != ?",
    [$svgClassName]
);
```

**After:**
```php
DB::prepared_query(
    "SELECT COUNT(*) FROM \"{$table}\" WHERE \"Name\" LIKE '%.svg' AND (\"ClassName\" IS NULL OR \"ClassName\" = '' OR \"ClassName\" != ?)",
    [$svgClassName]
);
```

**SS6 consideration:** Check if SS6 migration has the same issue. The query pattern should be updated there too.

### 3. SVGDBFile - ThumbnailURL and Link for Protected Files

Added methods to handle protected/draft file access:

```php
// src/SVGDBFile.php
public function ThumbnailURL($width, $height)
{
    if ($this->getExtension() === 'svg') {
        return $this->getURL(true); // grant=true for protected access
    }
    return parent::ThumbnailURL($width, $height);
}

public function Link()
{
    if ($this->getExtension() === 'svg') {
        return $this->getURL(true);
    }
    return parent::Link();
}
```

**SS6 consideration:** Check if these methods exist and work correctly in SS6's `SVGDBFile` or equivalent.

### 4. getString() for Draft/Protected SVG Access

Changed `getDimensions()` and `SVG_RAW_Inline()` to use `$this->getString()` instead of constructing file paths manually. This ensures draft/protected files are accessible.

**Before:**
```php
$filePath = Path::join(Director::publicFolder(), $this->getURL());
$content = file_get_contents($filePath);
```

**After:**
```php
$content = $this->getString();
```

**SS6 consideration:** Verify SS6 uses `getString()` for file content access, not manual path construction.

---

## Files Changed in SS4/5 Release 1.3.2

| File | Change |
|------|--------|
| `src/SVGImageExtension.php` | **NEW** - Fixes ClassName for relation uploads via `onAfterWrite()` + DB query |
| `src/SVGImage.php` | Fixed migration query for NULL/empty ClassName; uses `getString()` for file access |
| `src/SVGDBFile.php` | Added `ThumbnailURL()`, `Link()`, `getIsImage()` for protected file handling |
| `_config/config.yml` | Added SVGImageExtension to File extensions |
| `README.md` | Updated with relation handling docs, current API |

---

## Testing Checklist After SS6 Port

1. **Relation upload test:** Upload SVG through `has_one Image` field (e.g., UploadField for Image relation)
   - Verify ClassName is `SVGImage` not `Image`
   - Verify thumbnail shows in CMS immediately (no re-publish needed)

2. **Migration test:** Create SVG files with NULL/empty ClassName in database
   - Run `dev/build` with `auto_migrate_svg_class: true`
   - Verify all SVGs get `SVGImage` ClassName

3. **Draft file test:** Upload SVG but don't publish
   - Verify thumbnail shows in AssetAdmin
   - Verify `SVG_RAW_Inline` works in preview

4. **Shortcode test (SS6 specific):** Insert SVG via TinyMCE image shortcode
   - Verify image renders on frontend

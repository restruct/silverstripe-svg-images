# Technical Documentation

This document explains the implementation decisions, problems encountered, and solutions in this SVG module. It serves as a reference for understanding why things are implemented in specific ways.

## Architecture Overview

### Core Classes

| Class | Purpose |
|-------|---------|
| `SVGImage` | Main File subclass for SVG files. Extends `Image` to inherit all image behavior. |
| `SVGDBFile` | DBFile subclass returned by manipulation methods. Enables chained manipulations. |
| `SVGImageExtension` | Extension on `File` to fix ClassName for SVGs uploaded through Image relations. |
| `SVGManipulationTrait` | Shared manipulation logic (currently unused, kept for potential future refactoring). |

### Design Decision: SVGs as Images

We treat SVGs as images throughout the stack:

- `SVGImage` extends `Image` (not `File`)
- `SVGDBFile::getIsImage()` returns `true` for SVGs
- No special shortcode handling needed

**Why this matters:** Earlier SS6 implementations tried marking SVGs as non-images (`getIsImage() => false`) and compensating with `SVGShortcodeExtension`. This added complexity and edge cases. By keeping SVGs as images, they work naturally everywhere images are expected.

---

## Problem: ClassName Not Set for Relation Uploads

### The Issue

When uploading SVGs through relation fields (`has_one Image`, `has_many Image`, etc.), SilverStripe's ORM enforces the relation's class type. Even though `class_for_file_extension` correctly maps `.svg` to `SVGImage`, the framework creates/saves as `Image` class.

This happens because the ORM constructs objects based on the relation type, not the file extension.

### What Didn't Work

**Attempt 1: `onBeforeWrite()` to set ClassName**

```php
public function onBeforeWrite(): void
{
    if ($owner->getExtension() === 'svg' && !($owner instanceof SVGImage)) {
        $owner->ClassName = SVGImage::class;
    }
}
```

**Result:** The ORM overwrites the ClassName after our extension runs. The change is lost.

### What Works

**Solution: `onAfterWrite()` with direct DB query**

```php
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

**Why it works:** By the time `onAfterWrite()` runs, the record exists in the database. The direct DB query bypasses the ORM's class enforcement, and subsequent loads will use the correct class.

**Trade-off:** The object in memory during that request is still an `Image` instance. The correct `SVGImage` class takes effect on next load. This is acceptable because:
- Thumbnails regenerate on next request anyway
- The CMS reloads after upload

---

## Problem: Protected/Draft Files Not Accessible

### The Issue

Methods like `getDimensions()` and `SVG_RAW_Inline()` originally constructed file paths manually:

```php
$filePath = Path::join(Director::publicFolder(), $this->getURL());
$content = file_get_contents($filePath);
```

This fails for:
- Draft (unpublished) files stored in `.protected/`
- Files with access restrictions

### Solution

Use `getString()` which respects the asset store's access control:

```php
$content = $this->getString();
```

**Applied to:**
- `getDimensions()` - parsing SVG dimensions
- `SVG_RAW_Inline()` - returning raw SVG content
- `getImagineSVG()` - loading SVG for manipulation

**Also:** `ThumbnailURL()` and `Link()` methods use `getURL(true)` where `true` grants access to protected files.

---

## Problem: Migration Query Misses NULL ClassName

### The Issue

The migration query to update existing SVG files used:

```sql
WHERE "Name" LIKE '%.svg' AND "ClassName" != ?
```

In MySQL/MariaDB, `NULL != 'value'` evaluates to `NULL` (not `TRUE`), so rows with NULL ClassName were skipped.

### Solution

Handle NULL and empty values explicitly:

```sql
WHERE "Name" LIKE '%.svg' AND ("ClassName" IS NULL OR "ClassName" = '' OR "ClassName" != ?)
```

---

## SVG Manipulation Implementation

### Why contao/imagine-svg?

The [contao/imagine-svg](https://github.com/contao/imagine-svg) library provides an Imagine-compatible interface for SVG manipulation. It modifies SVG attributes (viewBox, width, height) rather than rasterizing.

### Fill() - Manual Implementation Required

**Problem:** Imagine's `thumbnail()` with `THUMBNAIL_OUTBOUND` mode didn't work correctly for SVGs.

**Solution:** Manual crop/resize calculation:

```php
// Calculate scale to fill (use the larger scale to ensure coverage)
$scaleX = $width / $currentWidth;
$scaleY = $height / $currentHeight;
$scale = max($scaleX, $scaleY);

// Calculate crop offset to center
$cropX = (int)(($scaledWidth - $width) / 2 / $scale);
$cropY = (int)(($scaledHeight - $height) / 2 / $scale);

// Crop dimensions in original coordinates
$cropWidth = (int)($width / $scale);
$cropHeight = (int)($height / $scale);

// Crop then resize
$image = $image->crop(new Point($cropX, $cropY), new Box($cropWidth, $cropHeight));
return $image->resize(new Box($width, $height));
```

### Pad() - ViewBox Expansion

**How it works:** Instead of adding actual padding pixels, we expand the viewBox to include empty space around the content:

```php
$targetAspect = $width / $height;
$currentAspect = $vbWidth / $vbHeight;

if ($currentAspect > $targetAspect) {
    // Content is wider than target - add vertical padding
    $newVbHeight = $vbWidth / $targetAspect;
    $newVbY = $vbY - ($newVbHeight - $vbHeight) / 2;
} else {
    // Content is taller than target - add horizontal padding
    $newVbWidth = $vbHeight * $targetAspect;
    $newVbX = $vbX - ($newVbWidth - $vbWidth) / 2;
}

$root->setAttribute('viewBox', "$newVbX $newVbY $newVbWidth $newVbHeight");
```

The `backgroundColor` parameter is ignored for SVGs - padding is always transparent.

### Graceful Degradation

All manipulation methods wrap Imagine calls in try/catch:

```php
try {
    $imagine = new Imagine();
    return $imagine->load($content);
} catch (\Exception $e) {
    return null; // Triggers fallback to return $this unchanged
}
```

**Benefits:**
- Malformed SVGs don't break the site
- The original file is returned as fallback
- No exceptions bubble up to the user

---

## SVGDBFile: Enabling Chained Manipulations

### The Problem

When you call `$image->ScaleWidth(200)`, it returns a `DBFile` (or subclass) representing the manipulated variant. If you then call `->Fill(100, 100)` on that result, the `DBFile` class doesn't know how to manipulate SVGs.

### The Solution

Return `SVGDBFile` instead of plain `DBFile`:

```php
// In manipulateSVG()
$file = DBField::create_field(SVGDBFile::class, $tuple);
return $file;
```

`SVGDBFile` includes all the SVG manipulation methods, so chained calls work:

```php
$image->ScaleWidth(400)->Fill(200, 200)->Pad(300, 300);
```

### existingOnly() Override

The `existingOnly()` method is used in thumbnail contexts. We override it to return `SVGDBFile`:

```php
public function existingOnly()
{
    if ($this->getExtension() === 'svg') {
        return SVGDBFile::createFromTuple([...]);
    }
    return parent::existingOnly();
}
```

---

## SilverStripe 6 Compatibility

### BuildTask Changes

SS6 changed the BuildTask API significantly:

| SS4/5 | SS6 |
|-------|-----|
| `private static $title` | `protected string $title` |
| `private static $description` | `protected static string $description` |
| `public function run($request)` | `protected function execute(InputInterface $input, PolyOutput $output): int` |
| `echo "message"` | `$output->writeln("message")` |
| `$request->getVar('confirm')` | `$input->getOption('confirm')` |

### PHP 8 Attributes

SS6 uses `#[Override]` attributes on overridden methods for clarity:

```php
#[Override]
public function getIsImage(): bool
{
    // ...
}
```

### Return Type Requirements

SS6 enforces return types more strictly. For example, `DBFile::Link()` requires `string` return type:

```php
#[Override]
public function Link(): string
{
    if ($this->getExtension() === 'svg') {
        return $this->getURL(true) ?: '';
    }
    return parent::Link();
}
```

---

## Removed Components

### SVGShortcodeExtension

**What it did:** Registered a custom shortcode handler for `[image]` shortcodes to handle SVG files specially.

**Why removed:** With SVGs properly treated as images (`getIsImage() => true`), the standard image shortcode handler works fine. The extension was compensating for a problem that no longer exists.

### SVGImage_Template

**What it did:** Integration wrapper for [stevie-mayhew/silverstripe-svg](https://github.com/stevie-mayhew/silverstripe-svg).

**Why removed:** Users can install that package separately if needed. Removing the tight coupling simplifies our codebase.

### Injector Configuration for Image Class

**What it did:** Allowed configuring `SVGImage` as the default `Image` class via Injector.

**Why removed:** The `SVGImageExtension` now handles ClassName correction automatically. The Injector approach had conflicts with other modules and required framework hacks.

---

## Testing Checklist

### Relation Upload Test
1. Create a DataObject with `has_one Image` relation
2. Upload an SVG through the UploadField
3. Verify: ClassName in database is `SVGImage`, not `Image`
4. Verify: Thumbnail displays immediately in CMS

### Draft File Test
1. Upload SVG but don't publish
2. Verify: Thumbnail shows in AssetAdmin
3. Verify: `$Image.SVG_RAW_Inline` works in CMS preview

### Migration Test
1. Insert SVG file record with NULL or empty ClassName
2. Enable `auto_migrate_svg_class: true`
3. Run `dev/build`
4. Verify: ClassName updated to `SVGImage`

### Manipulation Test
1. Visit `/dev/svg-compare`
2. Compare SVG manipulations with PNG equivalents
3. Test with both published and draft assets

### Malformed SVG Test
1. Upload an SVG with invalid XML
2. Verify: No fatal errors
3. Verify: Original file displayed as fallback

---

## Dependencies

| Package | Purpose |
|---------|---------|
| `contao/imagine-svg` | SVG manipulation (resize, crop) via Imagine interface |
| `enshrined/svg-sanitize` | Remove dangerous content from SVGs on upload |
| `ext-dom` | XML/DOM manipulation for viewBox parsing |

**Optional:** `ext-gd` for PNG generation in the comparison tool.

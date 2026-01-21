# SVG Image support for SilverStripe 4/5 (assets/uploads)

This module provides SVG support in SilverStripe's asset management system. SVG files uploaded via AssetAdmin are handled by the `SVGImage` class, which ensures proper CMS thumbnail/preview display and bypasses raster image manipulation (since SVGs are vector graphics).

## Features

- CMS thumbnail and preview support for SVG files in AssetAdmin
- Dimension parsing from SVG `viewBox` or `width`/`height` attributes
- All image manipulation methods (Fit, Fill, ScaleWidth, etc.) return the original SVG
- Optional integration with [stevie-mayhew/silverstripe-svg](https://github.com/stevie-mayhew/silverstripe-svg) for advanced template helpers

## Installation

```bash
composer require restruct/silverstripe-svg-images
```

## How it works

The module configures SilverStripe to use the `SVGImage` class for `.svg` files via `class_for_file_extension`. This happens automatically for files uploaded through AssetAdmin.

### Migrating existing SVG files

If you have existing SVG files in your database that were uploaded before installing this module, they may have the wrong `ClassName` stored. To automatically migrate them on `dev/build`, enable the config flag:

```yaml
Restruct\Silverstripe\SVG\SVGImage:
  auto_migrate_svg_class: true
```

Then run `dev/build`. The migration will update the `ClassName` in `File`, `File_Live`, and `File_Versions` tables.

### Using SVGImage for has_one Image relations

By default, `has_one` relations to `Image` will instantiate the `Image` class directly. To have SVG files load as `SVGImage` in these relations, you can configure the Injector:

```yaml
SilverStripe\Core\Injector\Injector:
  SilverStripe\Assets\Image:
    class: Restruct\Silverstripe\SVG\SVGImage
```

Note: This approach may conflict with other modules that also use the Injector for Image (e.g., FocusedImage).

Alternative approaches:
- Use `many_many` relations with `UploadField::setAllowedMaxFileNumber(1)`
- Set the `has_one` relation type to `SVGImage` directly

## Usage

In SilverStripe templates, treat SVG images as you would normal images (minus resizing):

```html
<!-- Add SVG as image -->
<img src="$Image.URL" />

<!-- Responsive example with Foundation interchange -->
<img src="$Image_Mobile.URL" data-interchange="[$Image_Desktop.URL, medium]" />
```

### Inline SVG

```html
<!-- Add raw SVG inline (the SVG file content will be inserted) -->
{$Image.SVG_RAW_Inline}

<!-- Test for SVG and fallback to regular image methods -->
<% if $Image.IsSVG %> {$Image.SVG_RAW_Inline} <% else %> $Image.SetWidth(1200) <% end_if %>
```

### Advanced SVG helpers (requires stevie-mayhew/silverstripe-svg)

Additional helper functions for width, height, size, fill & adding extra classes are exposed by the `.SVG` method:

```html
<!-- Add inline SVG (slightly sanitized) -->
{$Image.SVG}

<!-- Change width -->
{$Image.SVG.width(200)}

<!-- Change height -->
{$Image.SVG.height(200)}

<!-- Change size (width and height) -->
{$Image.SVG.size(100,100)}

<!-- Change fill -->
{$Image.SVG.fill('#FF9933')}

<!-- Add class -->
{$Image.SVG.extraClass('awesome-svg')}
```

These options are chainable:

```html
{$Image.SVG.fill('#45FABD').width(200).height(100).extraClass('awesome-svg')}
```

## SVG Security

SVGs can expose attack vectors comparable to HTML/JS, with limited browser protection. Potential risks include:
- Script execution (in inline SVGs)
- XML External Entity (XXE) attacks
- Billion laughs (XML bomb)

**General rule**: Only work with trusted SVGs from trusted users. SVGs loaded through an `<img>` tag provide more security (e.g., no script execution) than inline SVG code.

### Sanitization

There is no fully reliable way to sanitize untrusted SVGs in PHP. Options include:
- [DOMPurify](https://github.com/cure53/DOMPurify) - Browser/JS based, thorough protection
- [svg-sanitizer](https://github.com/darylldoyle/svg-sanitizer) - PHP based, uses XML parsing
- [SVG Sanitizer](https://github.com/alnorris/SVG-Sanitizer) - PHP based alternative

For a thorough listing of known attack vectors, see [defusedxml documentation](https://pypi.org/project/defusedxml/#php).

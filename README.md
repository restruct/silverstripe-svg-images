# SVG Image support for Silverstripe (assets/uploads)
This works as-is with any files added via the AssetAdmin and many_many relations to 'File/Image(/SVGImage)'.

This module exposes the SVG template helpers/methods of the stevie-mayhew/silverstripe-svg module if that's 
installed (recommended by composer). See 'Usage'.

# SVG Security
SVGs may expose *a lot* of possible attack vectors, most of which are widely known and unpatched. Basically you should consider SVG a browser-executable format comparable to HTML/JS, but with virtually no exploit-protection built into browsers. In some circumstances, eg when parsing XML server side, SVGs could also pose server side risks like file inclusion (XML External Entity attack), fork bombs (Billion laughs) and probably dozens more. See 'Security considerations'.

As a general rule of thumb, only work with trusted SVGs (created & uploaded by trusted users). SVGs loaded through an img tag provide a bit more security (eg no script execution) than inline SVG code.

## Fresh codebases:
Best option is to resort to many_manys with UploadField::setAllowedMaxFileNumber(1), since File/Upload tries
to instantiate the relation's appointed classname for has_ones and so will resort to Image instead of SVGImage.

OR simply tell the injector to use the SVGImage class instead of Image, see Yaml config below (falls back to Image 
class for regular images).

OR (probably undesirable) set the has_one relation to 'SVGImage' subclass.

## Options for existing codebases/sites (or modules):
You may simply change the relation to point to SVGImage class if possible (existing relations may have to be re-added?)

OR Add the following config to have UploadFields for has_one pointing to 'Image' load as SVGImage for .svg files
(this is another approach then resorting to many_manys, which may interfere with other modules like FocusedImage
which also uses injector for Image)

```yml
SilverStripe\Core\Injector\Injector:
 SilverStripe\Assets\Image:
   class: Restruct\Silverstripe\SVG\SVGImage
```

## Usage
In a SilverStripe template simply treat as you would treat a normal image (minus the formatting/scaling functionality).
For scaling/adding classes etc, this module integrates SVG template helpers (stevie-mayhew/silverstripe-svg module required).

```html
<!-- add svg as image -->
<img src="$Image.URL" />

<!-- or, for example when using Foundation interchange to have separate/responsive versions: -->
<img src="$Image_Mobile.URL" data-interchange="[$Image_Desktop.URL, medium]" />
```

```html
<!-- add inline svg (the raw SVG file will be inserted, see the .SVG helper for more subtle inlining) -->
{$Image.SVG_RAW_Inline}

<!-- Test for SVG and fallback to regular image methods, for example when the image may be multiple formats (eg SVG/PNG/JPG) -->
<% if $Image.IsSVG %> {$Image.SVG_RAW_Inline} <% else %> $Image.SetWidth(1200) <% end_if %>


```

Additional helper functions for width, height, size, fill & adding extra classes are exposed by the '.SVG' method.
(Requires additional module: [stevie-mayhew/silverstripe-svg](https://github.com/stevie-mayhew/silverstripe-svg)

```html
<!-- add inline svg (slightly sanitized) -->
{$Image.SVG}

<!-- add a part of an SVG by ID inline -->
{$Image.SVG.LimitID('ParticularID')}
```


```html
<!-- change width -->
{$Image.SVG.width(200)}

<!-- change height -->
{$Image.SVG.height(200)}

<!-- change size (width and height) -->
{$Image.SVG.size(100,100)}

<!-- change fill -->
{$Image.SVG.fill('#FF9933')}

<!-- add class -->
{$Image.SVG.extraClass('awesome-svg')}

```

These options are also chainable.

```html
{$SVG('name').fill('#45FABD').width(200).height(100).extraClass('awesome-svg')}
```

## Security considerations
Currently I don't know of any way to fully sanitize untrusted SVGs. Regular expressions are not suitable for the job and any PHP XML parsers are vulnerable to at least some attack vectors (like file inclusion). Here's a [thorough listing of known attack vectors](https://pypi.org/project/defusedxml/#php). 

[DOMPurify](https://github.com/cure53/DOMPurify) is a browser/JS based library that seems to do a pretty good job (but it's JS/NodeJS, not PHP). PHP based libraries which provide some protection but use (possibly dangerous) XML parsing are [svg-sanitizer](https://github.com/darylldoyle/svg-sanitizer) & [SVG Sanitizer](https://github.com/alnorris/SVG-Sanitizer).


### SVG cropping & additional manipulations (to be added to this module)

http://www.silverstrip.es/blog/svg-in-the-silverstripe-backend/

Cropping can basically be done using viewBox, combined with svg width/height attr (all optional)
PHP SVG class (Imagemagick): https://github.com/oscarotero/imagecow
Simple rendering SVG>JPG/PNG: http://stackoverflow.com/questions/10289686/rendering-an-svg-file-to-a-png-or-jpeg-in-php

PHP Cairo (PECL, not really an option): http://php.net/manual/en/class.cairosvgsurface.php

PHP SVG Iconizr (CLI CSS/SVG/PNG sprite generator): https://github.com/jkphl/iconizr

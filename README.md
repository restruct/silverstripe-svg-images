# SVG Image support for Silverstripe (assets/uploads)
This works as-is with any files added via the AssetAdmin and many_many relations to 'File/Image(/SVGImage)'.

Template methods like scaling images etc will not work on SVG's (yet) though, best to use CSS for now 
(eg. width: 100%/150px; height: auto.

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
Injector:
  Image:
    class: SVGImage
```

## Allowing SVG in scaffolded UploadFields

Scaffolded UploadFields to 'Image' may need to be told to allow SVG images as well (currently fixed in master):

```php
$field->setAllowedFileCategories('image');
```

It's also possible to temporarily hack the framework /Framework/model/fieldtypes/ForeignKey around line 33 to make 
scaffolded has_one UploadFields for Image relations allow SVGs (temporarily because this is currently fixed in master).

```php
    ...
    if($hasOneClass && singleton($hasOneClass) instanceof Image) {
        $field = new UploadField($relationName, $title);
        // CHANGE:
        //$field->getValidator()->setAllowedExtensions(array('jpg', 'jpeg', 'png', 'gif'));
        // TO:
        $field->setAllowedFileCategories('image');
    } else ...
```

### Other pointers regarding SVG in Silverstripe:
http://www.silverstrip.es/blog/svg-in-the-silverstripe-backend/
https://github.com/stevie-mayhew/silverstripe-svg
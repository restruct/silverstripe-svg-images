<?php

namespace Restruct\SvgBrowser;

use SilverStripe\Assets\Image;
use SilverStripe\ORM\DataObject;

/**
 * BROWSER-TEST FIXTURE ONLY - a record with a has_one Image, edited in SvgBAdmin, so a spec can
 * upload an SVG through a relation's UploadField.
 *
 * Never loaded by a real install: it lives under tests/browser/, which carries a _manifest_exclude
 * marker, and the browser-test runner copies it into a scratch host's app/ before dev/build.
 * Written to load on both Silverstripe 5 and 6.
 *
 * @property string $Title
 * @method Image Image()
 */
class SvgBRecord extends DataObject
{
    # Short table name: no namespaced default.
    private static $table_name = 'SvgBRecord';

    private static $singular_name = 'SVG record';

    private static $db = [
        'Title' => 'Varchar(255)',
    ];

    private static $has_one = [
        'Image' => Image::class,
    ];

    # Publish the image with the record would need Versioned; the CMS preview works on drafts.
    private static $owns = ['Image'];

    # The GridField shows which class the stored file ended up as: SVGImageExtension must turn the
    # relation's Image into an SVGImage after the upload.
    private static $summary_fields = [
        'Title' => 'Title',
        'ImageClass' => 'Image class',
    ];

    public function getImageClass(): string
    {
        $image = $this->Image();
        return $image && $image->exists() ? $image->ClassName : '(none)';
    }

    public function requireDefaultRecords()
    {
        parent::requireDefaultRecords();

        # A fresh record per run (its earlier uploads go with it): the specs upload into it.
        foreach (static::get() as $old) {
            $old->delete();
        }
        static::create(['Title' => 'Upload target'])->write();
    }
}

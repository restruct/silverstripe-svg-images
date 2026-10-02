<?php

namespace Restruct\SvgBrowser;

use SilverStripe\Admin\ModelAdmin;

/**
 * BROWSER-TEST FIXTURE ONLY - /admin/svgb, lists SvgBRecord. (See SvgBRecord for why this never
 * loads in a real install.)
 */
class SvgBAdmin extends ModelAdmin
{
    private static $url_segment = 'svgb';

    private static $menu_title = 'SVG browser test';

    private static $managed_models = [
        'records' => ['dataClass' => SvgBRecord::class, 'title' => 'Records'],
    ];
}

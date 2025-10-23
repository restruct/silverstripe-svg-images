<?php

use Restruct\Silverstripe\SVG\SVGShortcodeExtension;
use SilverStripe\View\Parsers\ShortcodeParser;

// Register custom shortcode handler for SVG images
ShortcodeParser::get('default')->register('image', [SVGShortcodeExtension::class, 'handle_image_shortcode']);
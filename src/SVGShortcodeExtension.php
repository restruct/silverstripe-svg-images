<?php

namespace Restruct\Silverstripe\SVG;

use SilverStripe\Core\Extension;
use SilverStripe\View\Parsers\ShortcodeParser;

/**
 * Extension to handle SVG files in image shortcodes
 * SVG files are treated as images in shortcodes even though getIsImage() returns false
 */
class SVGShortcodeExtension extends Extension
{
    /**
     * Custom shortcode handler for images that includes SVG support
     */
    public static function handle_image_shortcode($arguments, $content, $parser, $tagName)
    {
        // Get file ID from arguments
        if (!isset($arguments['id']) || !$arguments['id']) {
            return '';
        }

        // Load the file
        $file = \SilverStripe\Assets\File::get()->byID($arguments['id']);
        if (!$file || !$file->exists()) {
            return '<img alt="Image not found" class="' . ($arguments['class'] ?? '') . '">';
        }

        // Check if it's an SVG file or a regular image
        $isSVG = $file instanceof SVGImage && $file->getExtension() === 'svg';
        $isImage = $file->getIsImage();

        // If it's not an SVG and not an image, return empty
        if (!$isSVG && !$isImage) {
            return '';
        }

        // Build the img tag
        $src = $file->getURL();
        $alt = $file->Title ?: pathinfo($file->Name, PATHINFO_FILENAME);
        $class = $arguments['class'] ?? '';
        $width = $arguments['width'] ?? '';
        $height = $arguments['height'] ?? '';

        $attrs = [
            'src' => $src,
            'alt' => $alt,
        ];

        if ($class) {
            $attrs['class'] = $class;
        }
        if ($width) {
            $attrs['width'] = $width;
        }
        if ($height) {
            $attrs['height'] = $height;
        }

        // Build attribute string
        $attrString = '';
        foreach ($attrs as $key => $value) {
            $attrString .= ' ' . $key . '="' . htmlspecialchars($value) . '"';
        }

        return '<img' . $attrString . '>';
    }

    /**
     * Register custom shortcode handler for images that includes SVG support
     * This method is kept for backward compatibility
     */
    public static function register_shortcode()
    {
        ShortcodeParser::get('default')->register('image', function ($arguments, $content, $parser, $tagName) {
            // Get file ID from arguments
            if (!isset($arguments['id']) || !$arguments['id']) {
                return '';
            }

            // Load the file
            $file = \SilverStripe\Assets\File::get()->byID($arguments['id']);
            if (!$file || !$file->exists()) {
                return '<img alt="Image not found" class="' . ($arguments['class'] ?? '') . '">';
            }

            // Check if it's an SVG file or a regular image
            $isSVG = $file instanceof SVGImage && $file->getExtension() === 'svg';
            $isImage = $file->getIsImage();

            // If it's not an SVG and not an image, return empty
            if (!$isSVG && !$isImage) {
                return '';
            }

            // Build the img tag
            $src = $file->getURL();
            $alt = $file->Title ?: pathinfo($file->Name, PATHINFO_FILENAME);
            $class = $arguments['class'] ?? '';
            $width = $arguments['width'] ?? '';
            $height = $arguments['height'] ?? '';

            $attrs = [
                'src' => $src,
                'alt' => $alt,
            ];

            if ($class) {
                $attrs['class'] = $class;
            }
            if ($width) {
                $attrs['width'] = $width;
            }
            if ($height) {
                $attrs['height'] = $height;
            }

            // Build attribute string
            $attrString = '';
            foreach ($attrs as $key => $value) {
                $attrString .= ' ' . $key . '="' . htmlspecialchars($value) . '"';
            }

            return '<img' . $attrString . '>';
        });
    }
}
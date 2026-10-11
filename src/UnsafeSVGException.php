<?php

namespace Restruct\Silverstripe\SVG;

use RuntimeException;

/**
 * Thrown by SVGImage::sanitize_content() when SVG markup is refused instead of sanitized.
 *
 * Kept separate from ValidationException on purpose: that class lives in a different namespace on
 * Silverstripe 5 and 6, and sanitize_content() is also meant for callers outside a DataObject
 * write (a task, an import). SVGImage::sanitize_file() turns it into the ValidationException that
 * aborts the write. The message is written for the uploader.
 */
class UnsafeSVGException extends RuntimeException
{
}

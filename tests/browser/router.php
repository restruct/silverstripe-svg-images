<?php
# Router for PHP's built-in server: serve existing static files (incl. vendor-exposed symlinks)
# and hand everything else to Silverstripe's public/index.php front controller.
$uri = urldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$docroot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? __DIR__, '/');
if (strpos($uri, '..') === false && $uri !== '/' && is_file($docroot . $uri) && basename($uri) !== 'index.php') {
    return false; // let the built-in server serve it with its own mime handling
}
require $docroot . '/index.php';

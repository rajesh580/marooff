<?php
// PHP built-in dev server router shim.
// - If the request targets an existing file in public/, let PHP serve it directly (returns false).
// - Otherwise delegate to public/index.php (the CI4 front controller).
//
// Usage:
//   php -S 127.0.0.1:8080 -t public router-dev.php

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = __DIR__ . DIRECTORY_SEPARATOR . 'public' . str_replace('/', DIRECTORY_SEPARATOR, $path);

if ($path !== '/' && is_file($file)) {
    // Tell the built-in server to serve the file with its real MIME type.
    return false;
}

require __DIR__ . '/public/index.php';

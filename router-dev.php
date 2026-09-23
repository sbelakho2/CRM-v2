<?php
// Dev router for the PHP built-in server: serve static files from public/,
// fall back to the Symfony front controller.
$path = __DIR__ . '/public' . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (PHP_SAPI === 'cli-server' && is_file($path)) {
    return false;
}
require __DIR__ . '/public/index.php';

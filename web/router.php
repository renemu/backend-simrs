<?php

declare(strict_types=1);

// Router script for PHP built-in server inside Docker container.
// Serves existing static files directly and routes all API paths to web/index.php.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$file = __DIR__ . ($path ?: '/');

if ($path !== '/' && is_file($file)) {
    return false;
}

require __DIR__ . '/index.php';

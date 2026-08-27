<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Serve existing files directly.
if ($path !== '/' && file_exists(__DIR__ . $path)) {
    return false;
}

if (preg_match('#^/f/([A-Za-z]{5})$#', $path, $matches)) {
    $_GET['id'] = $matches[1];
    require __DIR__ . '/folder.php';
    return true;
}

if (preg_match('#^/s/([a-z0-9]{5})$#', $path, $matches)) {
    $_GET['id'] = $matches[1];
    require __DIR__ . '/file.php';
    return true;
}

return false;

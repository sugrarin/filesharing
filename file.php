<?php
require_once __DIR__ . '/security_headers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/share_pages.php';
send_security_headers('share');

$fileId = $_GET['id'] ?? '';
if (!preg_match('/^[a-z0-9]{5}$/', $fileId)) {
    http_response_code(404);
    readfile('404.html');
    exit;
}

$pdo = getDB();
$stmt = $pdo->prepare("
    SELECT id, name, original_name, extension
    FROM files
    WHERE id = ?
");
$stmt->execute([$fileId]);
$file = $stmt->fetch();

if (!$file) {
    http_response_code(404);
    readfile('404.html');
    exit;
}

$ext = strtolower((string)$file['extension']);
$filePath = __DIR__ . '/s/' . $file['id'] . '.' . $file['extension'];
if (!is_file($filePath)) {
    // try lowercase extension on disk for legacy mixed-case rows
    $alt = __DIR__ . '/s/' . $file['id'] . '.' . $ext;
    if (is_file($alt)) {
        $filePath = $alt;
    } else {
        http_response_code(404);
        readfile('404.html');
        exit;
    }
}

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
echo sharePageHtml($file);

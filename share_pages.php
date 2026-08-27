<?php

function sharePageHtml($file) {
    $title = $file['name'] ?: ($file['original_name'] ?? '');
    $directUrl = '/s/' . rawurlencode($file['id'] . '.' . $file['extension']);
    $escapedTitle = htmlspecialchars((string)$title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $escapedDirectUrl = htmlspecialchars($directUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    return '<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . $escapedTitle . '</title>
    <meta name="robots" content="noindex, nofollow">
    <meta property="og:title" content="' . $escapedTitle . '">
    <meta name="twitter:title" content="' . $escapedTitle . '">
    <style>
        html, body, iframe {
            width: 100%;
            height: 100%;
            margin: 0;
        }
        iframe {
            display: block;
            border: 0;
        }
    </style>
</head>
<body>
    <iframe src="' . $escapedDirectUrl . '" title="' . $escapedTitle . '"></iframe>
    <noscript><a href="' . $escapedDirectUrl . '">' . $escapedTitle . '</a></noscript>
</body>
</html>';
}

function writeSharePage($file, $uploadDir) {
    if (!preg_match('/^[a-z0-9]{5}$/', $file['id'] ?? '')) {
        return false;
    }

    $shareDir = rtrim($uploadDir, '/') . '/' . $file['id'];
    if (!is_dir($shareDir) && !mkdir($shareDir, 0755, true)) {
        return false;
    }

    return file_put_contents($shareDir . '/index.html', sharePageHtml($file), LOCK_EX) !== false;
}

function deleteSharePage($fileId, $uploadDir) {
    if (!preg_match('/^[a-z0-9]{5}$/', $fileId)) {
        return;
    }

    $shareDir = rtrim($uploadDir, '/') . '/' . $fileId;
    $indexPath = $shareDir . '/index.html';
    if (is_file($indexPath)) {
        unlink($indexPath);
    }
    if (is_dir($shareDir)) {
        @rmdir($shareDir);
    }
}

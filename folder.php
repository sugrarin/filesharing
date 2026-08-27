<?php
require_once __DIR__ . '/security_headers.php';
require_once __DIR__ . '/db.php';
send_security_headers('share');

$shareId = $_GET['id'] ?? '';
if (!preg_match('/^[A-Za-z]{5}$/', $shareId)) {
    http_response_code(404);
    readfile('404.html');
    exit;
}

$pdo = getDB();
$stmtShare = $pdo->prepare("
    SELECT fs.id, c.id AS category_id, c.name AS category_name
    FROM folder_shares fs
    JOIN categories c ON fs.category_id = c.id
    WHERE fs.id = ?
");
$stmtShare->execute([$shareId]);
$share = $stmtShare->fetch();

if (!$share) {
    http_response_code(404);
    readfile('404.html');
    exit;
}

// Parent folder share includes files from direct child categories.
$stmtFiles = $pdo->prepare("
    SELECT id, name, extension, size
    FROM files
    WHERE category_id = ?
       OR category_id IN (SELECT id FROM categories WHERE parent_id = ?)
    ORDER BY upload_date DESC
");
$stmtFiles->execute([$share['category_id'], $share['category_id']]);
$files = $stmtFiles->fetchAll();
$totalSize = 0;
foreach ($files as $file) {
    $totalSize += (int)$file['size'];
}

function formatSize($bytes) {
    $kb = 1024;
    $mb = $kb * 1024;
    $gb = $mb * 1024;
    if ($bytes >= $gb) {
        return round($bytes / $gb, 1) . ' GB';
    }
    if ($bytes >= $mb) {
        return round($bytes / $mb, 1) . ' MB';
    }
    if ($bytes >= $kb) {
        return round($bytes / $kb, 1) . ' KB';
    }
    return $bytes . ' B';
}

function fileIconNumber($fileId) {
    $hash = 0;
    $len = strlen($fileId);
    for ($i = 0; $i < $len; $i++) {
        $hash = (($hash << 5) - $hash) + ord($fileId[$i]);
        $hash = $hash & 0xFFFFFFFF;
    }
    $iconNumber = abs($hash % 4) + 1;
    return $iconNumber;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($share['category_name']); ?></title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="stylesheet" href="/style.css?v=<?php echo htmlspecialchars(asset_version('style.css'), ENT_QUOTES); ?>">
    <link rel="icon" href="/favicon.ico">
</head>
<body class="share-body">
    <div class="share-page">
        <header class="share-header">
            <div>
                <h1 class="share-title"><?php echo htmlspecialchars($share['category_name']); ?></h1>
                <p class="share-subtitle">
                    Files: <?php echo count($files); ?> · Total size: <?php echo formatSize($totalSize); ?>
                </p>
            </div>
        </header>

        <div class="share-grid">
            <?php if (count($files) === 0): ?>
                <div class="share-empty">No files in this folder</div>
            <?php else: ?>
                <?php foreach ($files as $file): ?>
                    <?php
                        $iconNumber = fileIconNumber($file['id']);
                        $fileName = htmlspecialchars($file['name']);
                        $fileLink = '/s/' . $file['id'] . '/';
                    ?>
                    <a class="share-card" href="<?php echo $fileLink; ?>" target="_blank" rel="noopener">
                        <div class="share-card-icon">
                            <img src="/icons/file-<?php echo $iconNumber; ?>.png" alt="File" class="file-icon-img">
                        </div>
                        <div class="share-card-name"><?php echo $fileName; ?></div>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

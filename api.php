<?php
require_once __DIR__ . '/security_headers.php';
send_security_headers('api');
header('Content-Type: application/json; charset=UTF-8');

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/share_pages.php';

if (!is_admin_authenticated()) {
    json_error('Unauthorized', 401);
}

define('UPLOAD_DIR', __DIR__ . '/s');
define('MAX_FILE_SIZE', 100 * 1024 * 1024);
define('MAX_JSON_BODY_BYTES', 64 * 1024);
define('MAX_NAME_LENGTH', 255);
define('MAX_CATEGORY_NAME_LENGTH', 120);

/** @var array<string, string[]> */
const ALLOWED_EXTENSIONS = [
    'pdf'  => ['application/pdf', 'application/x-pdf'],
    'doc'  => ['application/msword', 'application/octet-stream'],
    'docx' => [
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/zip',
        'application/octet-stream',
    ],
    'odp'  => ['application/vnd.oasis.opendocument.presentation', 'application/zip', 'application/octet-stream'],
    'pptm' => [
        'application/vnd.ms-powerpoint.presentation.macroEnabled.12',
        'application/vnd.ms-powerpoint',
        'application/zip',
        'application/octet-stream',
    ],
    'jpg'  => ['image/jpeg'],
    'jpeg' => ['image/jpeg'],
    'png'  => ['image/png'],
    'zip'  => ['application/zip', 'application/x-zip-compressed', 'application/octet-stream'],
    'mp4'  => ['video/mp4', 'application/octet-stream'],
    'mov'  => ['video/quicktime', 'application/octet-stream'],
];

if (!file_exists(UPLOAD_DIR)) {
    mkdir(UPLOAD_DIR, 0755, true);
}

function json_response(array $data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error($message, $code = 400) {
    json_response(['success' => false, 'error' => $message], $code);
}

function randomId($characters, $length = 5) {
    $id = '';
    $max = strlen($characters) - 1;
    for ($i = 0; $i < $length; $i++) {
        $id .= $characters[random_int(0, $max)];
    }
    return $id;
}

function getUniqueId($pdo, $table, $characters) {
    static $allowedTables = ['files' => true, 'folder_shares' => true];
    if (!isset($allowedTables[$table])) {
        throw new InvalidArgumentException('Invalid id table');
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE id = ?");
    for ($i = 0; $i < 100; $i++) {
        $id = randomId($characters);
        $stmt->execute([$id]);
        if ((int)$stmt->fetchColumn() === 0) {
            return $id;
        }
    }
    return null;
}

function getUniqueFileId($pdo) {
    return getUniqueId($pdo, 'files', 'abcdefghijklmnopqrstuvwxyz0123456789');
}

function getUniqueFolderId($pdo) {
    return getUniqueId($pdo, 'folder_shares', 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ');
}

function is_valid_file_id($id) {
    return is_string($id) && preg_match('/^[a-z0-9]{5}$/', $id) === 1;
}

function normalize_extension($originalName) {
    $ext = strtolower(pathinfo((string)$originalName, PATHINFO_EXTENSION));
    if ($ext === '' || !preg_match('/^[a-z0-9]{1,10}$/', $ext)) {
        return null;
    }
    if ($ext === 'jpeg') {
        // keep jpeg as allowed key; storage uses as-is from whitelist keys
    }
    return $ext;
}

function validate_uploaded_file(array $file) {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        json_error('Error uploading file', 400);
    }
    if (($file['size'] ?? 0) > MAX_FILE_SIZE) {
        json_error('File too large', 413);
    }
    if (($file['size'] ?? 0) <= 0) {
        json_error('Empty file', 400);
    }
    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        json_error('Invalid upload', 400);
    }

    $extension = normalize_extension($file['name'] ?? '');
    if ($extension === null || !isset(ALLOWED_EXTENSIONS[$extension])) {
        json_error('File type not allowed', 400);
    }

    $detectedMime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $detectedMime = (string)finfo_file($finfo, $file['tmp_name']);
            finfo_close($finfo);
        }
    }

    $allowedMimes = ALLOWED_EXTENSIONS[$extension];
    // When finfo is unavailable or returns empty, fall back to extension whitelist only.
    if ($detectedMime !== '' && !in_array($detectedMime, $allowedMimes, true)) {
        json_error('File content does not match extension', 400);
    }

    return $extension;
}

function resolve_upload_path($fileId, $extension) {
    if (!is_valid_file_id($fileId)) {
        json_error('Invalid file ID', 400);
    }
    $extension = strtolower((string)$extension);
    if (!preg_match('/^[a-z0-9]{1,10}$/', $extension)) {
        json_error('Invalid extension', 400);
    }

    $uploadRoot = realpath(UPLOAD_DIR);
    if ($uploadRoot === false) {
        if (!mkdir(UPLOAD_DIR, 0755, true) && !is_dir(UPLOAD_DIR)) {
            json_error('Failed to create upload directory', 500);
        }
        $uploadRoot = realpath(UPLOAD_DIR);
    }
    if ($uploadRoot === false) {
        json_error('Upload directory is not accessible', 500);
    }

    $filePath = $uploadRoot . DIRECTORY_SEPARATOR . $fileId . '.' . $extension;
    $parent = dirname($filePath);
    if (realpath($parent) !== $uploadRoot) {
        json_error('Invalid file path', 400);
    }
    return $filePath;
}

function file_payload_from_row(array $row) {
    return [
        'id' => $row['id'],
        'name' => $row['name'],
        'originalName' => $row['original_name'],
        'extension' => $row['extension'],
        'size' => (int)$row['size'],
        'uploadDate' => $row['upload_date'],
        'modified' => (bool)$row['modified'],
        'replacementDate' => $row['replacement_date'] ?? null,
        'category' => $row['category_name'] ?? 'All files',
        'compression' => $row['compression'] ?? 'none',
    ];
}

function pdf_gs_path() {
    static $path = null;
    if ($path !== null) {
        return $path;
    }
    $path = '';
    if (!function_exists('shell_exec') || !function_exists('exec')) {
        return $path;
    }
    $disabled = array_map('trim', explode(',', (string)ini_get('disable_functions')));
    if (in_array('shell_exec', $disabled, true) || in_array('exec', $disabled, true)) {
        return $path;
    }
    $found = @trim((string)shell_exec('which gs 2>/dev/null'));
    if ($found === '') {
        foreach (['/usr/bin/gs', '/usr/local/bin/gs', '/opt/homebrew/bin/gs'] as $candidate) {
            if (file_exists($candidate)) {
                $found = $candidate;
                break;
            }
        }
    }
    $path = $found;
    return $path;
}

function should_compress_pdf($filePath) {
    if (strtolower(pathinfo($filePath, PATHINFO_EXTENSION)) !== 'pdf') {
        return false;
    }
    $size = @filesize($filePath);
    if ($size === false || $size <= 0 || $size > 40 * 1024 * 1024) {
        return false;
    }
    return pdf_gs_path() !== '';
}

function set_file_compression($fileId, $status, $size = null) {
    try {
        $pdo = getDB();
        if ($size === null) {
            $stmt = $pdo->prepare('UPDATE files SET compression = ? WHERE id = ?');
            $stmt->execute([$status, $fileId]);
        } else {
            $stmt = $pdo->prepare('UPDATE files SET compression = ?, size = ? WHERE id = ?');
            $stmt->execute([$status, (int)$size, $fileId]);
        }
    } catch (Throwable $e) {
        error_log('File Sharing compression status error: ' . $e->getMessage());
    }
}

function schedule_pdf_compression($filePath, $fileId) {
    if (!should_compress_pdf($filePath)) {
        return false;
    }
    set_file_compression($fileId, 'pending');
    register_shutdown_function(function () use ($filePath, $fileId) {
        ignore_user_abort(true);
        if (function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }
        if (!is_file($filePath)) {
            set_file_compression($fileId, 'skipped');
            return;
        }
        $compressed = compressPdf($filePath);
        clearstatcache(true, $filePath);
        $newSize = @filesize($filePath);
        if ($compressed && $newSize !== false) {
            set_file_compression($fileId, 'done', $newSize);
            return;
        }
        set_file_compression($fileId, 'skipped');
    });
    return true;
}

function compressPdf($filePath) {
    $gsPath = pdf_gs_path();
    if ($gsPath === '') {
        return false;
    }

    $size = @filesize($filePath);
    if ($size !== false && $size > 40 * 1024 * 1024) {
        return false;
    }

    $tempFile = $filePath . '.tmp.pdf';
    $cmd = sprintf(
        '%s -sDEVICE=pdfwrite -dCompatibilityLevel=1.4 -dPDFSETTINGS=/ebook -dNOPAUSE -dQUIET -dBATCH -dColorImageResolution=150 -dGrayImageResolution=150 -dMonoImageResolution=150 -sOutputFile=%s %s',
        escapeshellcmd($gsPath),
        escapeshellarg($tempFile),
        escapeshellarg($filePath)
    );

    $output = [];
    $returnCode = 1;
    exec($cmd, $output, $returnCode);

    if ($returnCode === 0 && file_exists($tempFile)) {
        $originalSize = filesize($filePath);
        $newSize = filesize($tempFile);

        if ($newSize > 0 && $newSize < $originalSize) {
            rename($tempFile, $filePath);
            return true;
        }
        unlink($tempFile);
        return false;
    }

    if (file_exists($tempFile)) {
        unlink($tempFile);
    }
    return false;
}

function listCategories($pdo) {
    $stmt = $pdo->query("
        SELECT c.id, c.name, c.parent_id, p.name AS parent_name
        FROM categories c
        LEFT JOIN categories p ON c.parent_id = p.id
        ORDER BY c.name ASC
    ");
    $rows = $stmt->fetchAll();

    $byParent = [];
    foreach ($rows as $row) {
        $key = $row['parent_id'] === null ? 'root' : (string)$row['parent_id'];
        if (!isset($byParent[$key])) {
            $byParent[$key] = [];
        }
        $byParent[$key][] = $row;
    }

    $result = [];
    $roots = $byParent['root'] ?? [];

    usort($roots, function ($a, $b) {
        if ($a['name'] === 'All files') return -1;
        if ($b['name'] === 'All files') return 1;
        return strcasecmp($a['name'], $b['name']);
    });

    foreach ($roots as $root) {
        $result[] = [
            'name' => $root['name'],
            'parent' => null
        ];
        $children = $byParent[(string)$root['id']] ?? [];
        usort($children, function ($a, $b) {
            return strcasecmp($a['name'], $b['name']);
        });
        foreach ($children as $child) {
            $result[] = [
                'name' => $child['name'],
                'parent' => $root['name']
            ];
        }
    }

    return $result;
}

function getCategoryIdsWithChildren($pdo, $categoryId) {
    $ids = [(int)$categoryId];
    $stmt = $pdo->prepare("SELECT id FROM categories WHERE parent_id = ?");
    $stmt->execute([$categoryId]);
    while ($row = $stmt->fetch()) {
        $ids[] = (int)$row['id'];
    }
    return $ids;
}

function listFiles($pdo) {
    $stmt = $pdo->query("
        SELECT f.*, c.name as category_name
        FROM files f
        LEFT JOIN categories c ON f.category_id = c.id
        ORDER BY f.upload_date DESC
    ");
    $files = [];
    while ($row = $stmt->fetch()) {
        $files[] = file_payload_from_row($row);
    }
    return $files;
}

function getSharedCategoryNames($pdo) {
    $stmt = $pdo->query("
        SELECT c.name
        FROM folder_shares fs
        JOIN categories c ON fs.category_id = c.id
        ORDER BY c.name ASC
    ");
    $shared = [];
    while ($row = $stmt->fetch()) {
        $shared[] = $row['name'];
    }
    return $shared;
}

function require_string($value, $field, $maxLen = MAX_NAME_LENGTH) {
    if (!is_string($value)) {
        json_error("Invalid field: {$field}", 400);
    }
    $value = trim($value);
    if ($value === '') {
        json_error("Not specified: {$field}", 400);
    }
    if (mb_strlen($value) > $maxLen) {
        json_error("Value too long: {$field}", 400);
    }
    return $value;
}

function require_file_id_param($value) {
    if (!is_valid_file_id($value)) {
        json_error('Invalid file ID', 400);
    }
    return $value;
}

// --- Request bootstrap ---

$contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
$isMultipart = isset($_SERVER['CONTENT_TYPE']) && stripos($_SERVER['CONTENT_TYPE'], 'multipart/form-data') !== false;
if (!$isMultipart && $contentLength > MAX_JSON_BODY_BYTES) {
    json_error('Request too large', 413);
}

$jsonInput = file_get_contents('php://input', false, null, 0, MAX_JSON_BODY_BYTES + 1);
if ($jsonInput !== false && strlen($jsonInput) > MAX_JSON_BODY_BYTES && !$isMultipart) {
    json_error('Request too large', 413);
}

$input = [];
if ($jsonInput !== false && $jsonInput !== '' && !$isMultipart) {
    $decoded = json_decode($jsonInput, true);
    if (!is_array($decoded)) {
        // Allow empty body for non-JSON posts; reject malformed JSON.
        if (trim($jsonInput) !== '') {
            json_error('Invalid JSON', 400);
        }
    } else {
        $input = $decoded;
    }
}

$action = $_GET['action'] ?? $_POST['action'] ?? ($input['action'] ?? null);
if (!is_string($action) || $action === '') {
    json_error('No action specified', 400);
}

$allowedActions = [
    'list' => 'GET',
    'categories' => 'GET',
    'file_status' => 'GET',
    'folder_share' => 'POST',
    'folder_unshare' => 'POST',
    'upload' => 'POST',
    'replace' => 'POST',
    'rename' => 'POST',
    'delete' => 'POST',
    'update_category' => 'POST',
    'category_create' => 'POST',
    'category_rename' => 'POST',
    'category_delete' => 'POST',
];

if (!isset($allowedActions[$action])) {
    json_error('Unknown action', 400);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$expectedMethod = $allowedActions[$action];
if ($expectedMethod === 'GET' && !in_array($method, ['GET', 'HEAD'], true)) {
    json_error('Method not supported', 405);
}
if ($expectedMethod === 'POST' && $method !== 'POST') {
    json_error('Method not supported', 405);
}

if ($method === 'POST') {
    require_valid_csrf_token('ajax', $action, $input);
}

$pdo = getDB();

try {
    switch ($action) {
        case 'list':
            json_response([
                'success' => true,
                'files' => listFiles($pdo),
                'categories' => listCategories($pdo),
                'sharedCategories' => getSharedCategoryNames($pdo)
            ]);

        case 'file_status':
            $statusId = require_file_id_param($_GET['id'] ?? null);
            $stmtStatus = $pdo->prepare("
                SELECT f.*, c.name as category_name
                FROM files f
                LEFT JOIN categories c ON f.category_id = c.id
                WHERE f.id = ?
            ");
            $stmtStatus->execute([$statusId]);
            $statusRow = $stmtStatus->fetch();
            if (!$statusRow) {
                json_error('File not found', 404);
            }
            json_response([
                'success' => true,
                'file' => file_payload_from_row($statusRow)
            ]);

        case 'folder_share':
            $categoryName = require_string($input['category'] ?? $_POST['category'] ?? null, 'category', MAX_CATEGORY_NAME_LENGTH);
            if ($categoryName === 'All files') {
                json_error('Invalid folder', 400);
            }

            $stmtCat = $pdo->prepare("SELECT id FROM categories WHERE name = ?");
            $stmtCat->execute([$categoryName]);
            $catRow = $stmtCat->fetch();
            if (!$catRow) {
                json_error('Folder not found', 404);
            }

            $catId = $catRow['id'];
            $stmtExisting = $pdo->prepare("SELECT id FROM folder_shares WHERE category_id = ?");
            $stmtExisting->execute([$catId]);
            $existing = $stmtExisting->fetch();
            if ($existing) {
                json_response(['success' => true, 'shareId' => $existing['id']]);
            }

            $shareId = getUniqueFolderId($pdo);
            if (!$shareId) {
                json_error('Failed to create link', 500);
            }

            $createdAt = date('c');
            $stmtInsert = $pdo->prepare("INSERT INTO folder_shares (id, category_id, created_at) VALUES (?, ?, ?)");
            $stmtInsert->execute([$shareId, $catId, $createdAt]);
            audit_log('folder_share', $shareId, $categoryName);

            json_response(['success' => true, 'shareId' => $shareId]);

        case 'folder_unshare':
            $categoryName = require_string($input['category'] ?? $_POST['category'] ?? null, 'category', MAX_CATEGORY_NAME_LENGTH);

            $stmtDel = $pdo->prepare("
                DELETE FROM folder_shares
                WHERE category_id = (SELECT id FROM categories WHERE name = ?)
            ");
            $stmtDel->execute([$categoryName]);
            audit_log('folder_unshare', null, $categoryName);

            json_response(['success' => true]);

        case 'upload':
            if (!isset($_FILES['file'])) {
                json_error('No file selected', 400);
            }

            $file = $_FILES['file'];
            $extension = validate_uploaded_file($file);

            $fileId = null;
            if (isset($_POST['id']) && $_POST['id'] !== '') {
                $requestedId = require_file_id_param($_POST['id']);
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM files WHERE id = ?");
                $stmt->execute([$requestedId]);
                if ((int)$stmt->fetchColumn() > 0) {
                    json_error('A file with this ID already exists, please try again', 409);
                }
                $fileId = $requestedId;
            } else {
                $fileId = getUniqueFileId($pdo);
            }

            if (!$fileId) {
                json_error('Failed to generate ID', 500);
            }

            $originalName = mb_substr((string)$file['name'], 0, MAX_NAME_LENGTH);
            $filePath = resolve_upload_path($fileId, $extension);

            if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                json_error('Failed to save file', 500);
            }

            $finalSize = filesize($filePath);

            $categoryName = isset($_POST['category']) ? trim((string)$_POST['category']) : 'All files';
            if ($categoryName === '' || mb_strlen($categoryName) > MAX_CATEGORY_NAME_LENGTH) {
                $categoryName = 'All files';
            }

            $stmtCat = $pdo->prepare("SELECT id FROM categories WHERE name = ?");
            $stmtCat->execute([$categoryName]);
            $catId = $stmtCat->fetchColumn();

            if (!$catId) {
                $stmtCat->execute(['All files']);
                $catId = $stmtCat->fetchColumn();
                $categoryName = 'All files';
            }

            $uploadDate = date('c');

            $stmtInsert = $pdo->prepare("
                INSERT INTO files (id, name, original_name, extension, size, upload_date, modified, category_id)
                VALUES (?, ?, ?, ?, ?, ?, 0, ?)
            ");
            $stmtInsert->execute([$fileId, $originalName, $originalName, $extension, $finalSize, $uploadDate, $catId]);

            $fileData = [
                'id' => $fileId,
                'name' => $originalName,
                'originalName' => $originalName,
                'extension' => $extension,
                'size' => $finalSize,
                'uploadDate' => $uploadDate,
                'modified' => false,
                'category' => $categoryName
            ];

            writeSharePage($fileData, UPLOAD_DIR);
            $compressing = schedule_pdf_compression($filePath, $fileId);
            $fileData['compression'] = $compressing ? 'pending' : 'none';
            audit_log('upload', $fileId, $originalName);

            json_response([
                'success' => true,
                'file' => $fileData,
                'compression' => $fileData['compression']
            ]);

        case 'replace':
            if (!isset($_FILES['file']) || !isset($_POST['id'])) {
                json_error('Insufficient data', 400);
            }

            $file = $_FILES['file'];
            $fileId = require_file_id_param($_POST['id']);
            $extension = validate_uploaded_file($file);

            $stmt = $pdo->prepare("SELECT * FROM files WHERE id = ?");
            $stmt->execute([$fileId]);
            $currentFile = $stmt->fetch();

            if (!$currentFile) {
                json_error('File not found', 404);
            }

            $originalName = mb_substr((string)$file['name'], 0, MAX_NAME_LENGTH);
            $filePath = resolve_upload_path($fileId, $extension);

            $oldExtension = strtolower((string)$currentFile['extension']);
            if (!move_uploaded_file($file['tmp_name'], $filePath)) {
                json_error('Failed to save file', 500);
            }

            // Keep the previous file until the replacement has been saved successfully.
            if ($oldExtension !== $extension) {
                $uploadRoot = realpath(UPLOAD_DIR);
                if ($uploadRoot !== false && preg_match('/^[a-z0-9]{1,10}$/', $oldExtension)) {
                    $oldFilePath = $uploadRoot . DIRECTORY_SEPARATOR . $fileId . '.' . $oldExtension;
                    if (is_file($oldFilePath)) {
                        unlink($oldFilePath);
                    }
                }
            }

            $finalSize = filesize($filePath);
            $replacementDate = date('c');

            $stmtUpdate = $pdo->prepare("
                UPDATE files
                SET name = ?, extension = ?, size = ?, modified = 1, replacement_date = ?
                WHERE id = ?
            ");
            $stmtUpdate->execute([$originalName, $extension, $finalSize, $replacementDate, $fileId]);

            $stmtCat = $pdo->prepare("SELECT name FROM categories WHERE id = ?");
            $stmtCat->execute([$currentFile['category_id']]);
            $catName = $stmtCat->fetchColumn() ?: 'All files';

            $responseData = [
                'id' => $currentFile['id'],
                'name' => $originalName,
                'originalName' => $currentFile['original_name'],
                'extension' => $extension,
                'size' => (int)$finalSize,
                'uploadDate' => $currentFile['upload_date'],
                'modified' => true,
                'replacementDate' => $replacementDate,
                'category' => $catName
            ];

            writeSharePage($responseData, UPLOAD_DIR);
            $compressing = schedule_pdf_compression($filePath, $fileId);
            $responseData['compression'] = $compressing ? 'pending' : 'none';
            audit_log('replace', $fileId, $originalName);

            json_response([
                'success' => true,
                'file' => $responseData,
                'compression' => $responseData['compression']
            ]);

        case 'rename':
            $fileId = require_file_id_param($input['id'] ?? null);
            $newName = require_string($input['name'] ?? null, 'name');

            $stmt = $pdo->prepare("UPDATE files SET name = ? WHERE id = ?");
            $stmt->execute([$newName, $fileId]);

            if ($stmt->rowCount() === 0) {
                $check = $pdo->prepare("SELECT 1 FROM files WHERE id = ?");
                $check->execute([$fileId]);
                if (!$check->fetchColumn()) {
                    json_error('File not found', 404);
                }
            }

            $stmtFile = $pdo->prepare("SELECT id, name, original_name, extension FROM files WHERE id = ?");
            $stmtFile->execute([$fileId]);
            $renamedFile = $stmtFile->fetch();
            if ($renamedFile) {
                writeSharePage($renamedFile, UPLOAD_DIR);
            }
            audit_log('rename', $fileId, $newName);

            json_response(['success' => true]);

        case 'delete':
            $fileId = require_file_id_param($input['id'] ?? null);

            $stmt = $pdo->prepare("SELECT * FROM files WHERE id = ?");
            $stmt->execute([$fileId]);
            $file = $stmt->fetch();

            if (!$file) {
                json_error('File not found', 404);
            }

            $uploadRoot = realpath(UPLOAD_DIR);
            if ($uploadRoot !== false) {
                $filePath = $uploadRoot . DIRECTORY_SEPARATOR . $file['id'] . '.' . $file['extension'];
                if (is_file($filePath)) {
                    unlink($filePath);
                }
            }

            $stmtDel = $pdo->prepare("DELETE FROM files WHERE id = ?");
            $stmtDel->execute([$fileId]);
            deleteSharePage($fileId, UPLOAD_DIR);
            audit_log('delete', $fileId, $file['name'] ?? null);

            json_response(['success' => true]);

        case 'update_category':
            $fileId = require_file_id_param($input['id'] ?? null);
            $categoryName = require_string($input['category'] ?? null, 'category', MAX_CATEGORY_NAME_LENGTH);

            $stmtCat = $pdo->prepare("SELECT id FROM categories WHERE name = ?");
            $stmtCat->execute([$categoryName]);
            $catId = $stmtCat->fetchColumn();

            if (!$catId) {
                json_error('Category not found', 404);
            }

            $stmtUpdate = $pdo->prepare("UPDATE files SET category_id = ? WHERE id = ?");
            $stmtUpdate->execute([$catId, $fileId]);

            if ($stmtUpdate->rowCount() === 0) {
                $check = $pdo->prepare("SELECT 1 FROM files WHERE id = ?");
                $check->execute([$fileId]);
                if (!$check->fetchColumn()) {
                    json_error('File not found', 404);
                }
            }
            audit_log('update_category', $fileId, $categoryName);

            json_response(['success' => true]);

        case 'categories':
            json_response([
                'success' => true,
                'categories' => listCategories($pdo),
                'sharedCategories' => getSharedCategoryNames($pdo)
            ]);

        case 'category_create':
            $name = require_string($input['name'] ?? null, 'name', MAX_CATEGORY_NAME_LENGTH);
            $parentName = isset($input['parent']) ? trim((string)$input['parent']) : '';
            $parentId = null;

            $stmtCheck = $pdo->prepare("SELECT 1 FROM categories WHERE name = ?");
            $stmtCheck->execute([$name]);
            if ($stmtCheck->fetchColumn()) {
                json_error('Category already exists', 409);
            }

            if ($parentName !== '') {
                if (mb_strlen($parentName) > MAX_CATEGORY_NAME_LENGTH) {
                    json_error('Parent name too long', 400);
                }
                if ($parentName === 'All files') {
                    json_error('Cannot create a subcategory here', 400);
                }

                $stmtParent = $pdo->prepare("SELECT id, parent_id FROM categories WHERE name = ?");
                $stmtParent->execute([$parentName]);
                $parentRow = $stmtParent->fetch();
                if (!$parentRow) {
                    json_error('Parent category not found', 404);
                }
                if ($parentRow['parent_id'] !== null) {
                    json_error('Subcategories can only be created under top-level categories', 400);
                }
                $parentId = (int)$parentRow['id'];
            }

            $stmtIns = $pdo->prepare("INSERT INTO categories (name, parent_id) VALUES (?, ?)");
            $stmtIns->execute([$name, $parentId]);
            audit_log('category_create', null, $name);

            json_response([
                'success' => true,
                'categories' => listCategories($pdo),
                'sharedCategories' => getSharedCategoryNames($pdo)
            ]);

        case 'category_rename':
            $oldName = require_string($input['oldName'] ?? null, 'oldName', MAX_CATEGORY_NAME_LENGTH);
            $newName = require_string($input['newName'] ?? null, 'newName', MAX_CATEGORY_NAME_LENGTH);
            // null = leave parent unchanged; string (incl. '') = set parent (empty = top-level)
            $parentProvided = array_key_exists('parent', $input);
            $parentName = null;
            if ($parentProvided) {
                if ($input['parent'] === null || $input['parent'] === '') {
                    $parentName = '';
                } else {
                    $parentName = trim((string)$input['parent']);
                }
            }

            if ($oldName === 'All files') {
                json_error('Cannot edit this category', 400);
            }

            $stmtCat = $pdo->prepare("SELECT id, parent_id FROM categories WHERE name = ?");
            $stmtCat->execute([$oldName]);
            $catRow = $stmtCat->fetch();
            if (!$catRow) {
                json_error('Category not found', 404);
            }
            $catId = (int)$catRow['id'];

            if ($newName !== $oldName) {
                $stmtCheck = $pdo->prepare("SELECT 1 FROM categories WHERE name = ?");
                $stmtCheck->execute([$newName]);
                if ($stmtCheck->fetchColumn()) {
                    json_error('A category with this name already exists', 409);
                }
            }

            $newParentId = $catRow['parent_id'] !== null ? (int)$catRow['parent_id'] : null;
            if ($parentProvided) {
                if ($parentName === '') {
                    $newParentId = null;
                } else {
                    if (mb_strlen($parentName) > MAX_CATEGORY_NAME_LENGTH) {
                        json_error('Parent name too long', 400);
                    }
                    if ($parentName === 'All files') {
                        json_error('Cannot link to this category', 400);
                    }
                    if ($parentName === $oldName || $parentName === $newName) {
                        json_error('A category cannot be linked to itself', 400);
                    }

                    $stmtHasChildren = $pdo->prepare("SELECT 1 FROM categories WHERE parent_id = ? LIMIT 1");
                    $stmtHasChildren->execute([$catId]);
                    if ($stmtHasChildren->fetchColumn()) {
                        json_error('This category has subcategories — unlink or delete them first', 400);
                    }

                    $stmtParent = $pdo->prepare("SELECT id, parent_id FROM categories WHERE name = ?");
                    $stmtParent->execute([$parentName]);
                    $parentRow = $stmtParent->fetch();
                    if (!$parentRow) {
                        json_error('Parent category not found', 404);
                    }
                    if ($parentRow['parent_id'] !== null) {
                        json_error('Subcategories can only be linked to top-level categories', 400);
                    }
                    $newParentId = (int)$parentRow['id'];
                }
            }

            $stmtUpd = $pdo->prepare("UPDATE categories SET name = ?, parent_id = ? WHERE id = ?");
            $stmtUpd->execute([$newName, $newParentId, $catId]);
            audit_log('category_rename', null, $oldName . ' -> ' . $newName . ($parentProvided ? ' (parent updated)' : ''));

            json_response([
                'success' => true,
                'categories' => listCategories($pdo),
                'files' => listFiles($pdo),
                'sharedCategories' => getSharedCategoryNames($pdo)
            ]);

        case 'category_delete':
            $name = require_string($input['name'] ?? null, 'name', MAX_CATEGORY_NAME_LENGTH);

            if ($name === 'All files') {
                json_error('Cannot delete this category', 400);
            }

            $stmtCat = $pdo->prepare("SELECT id FROM categories WHERE name = ?");
            $stmtCat->execute([$name]);
            $catId = $stmtCat->fetchColumn();

            if (!$catId) {
                json_error('Category not found', 404);
            }

            $stmtDef = $pdo->query("SELECT id FROM categories WHERE name = 'All files'");
            $defId = $stmtDef->fetchColumn();

            $idsToClear = getCategoryIdsWithChildren($pdo, $catId);
            if ($defId) {
                $placeholders = implode(',', array_fill(0, count($idsToClear), '?'));
                $stmtReassign = $pdo->prepare("UPDATE files SET category_id = ? WHERE category_id IN ($placeholders)");
                $stmtReassign->execute(array_merge([$defId], $idsToClear));
            }

            $stmtDelChildren = $pdo->prepare("DELETE FROM categories WHERE parent_id = ?");
            $stmtDelChildren->execute([$catId]);

            $stmtDel = $pdo->prepare("DELETE FROM categories WHERE id = ?");
            $stmtDel->execute([$catId]);
            audit_log('category_delete', null, $name);

            json_response([
                'success' => true,
                'categories' => listCategories($pdo),
                'files' => listFiles($pdo),
                'sharedCategories' => getSharedCategoryNames($pdo)
            ]);

        default:
            json_error('Unknown action', 400);
    }
} catch (PDOException $e) {
    error_log('File Sharing API database error: ' . $e->getMessage());
    json_error('Database error', 500);
}

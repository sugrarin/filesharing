<?php

define('DB_FILE', __DIR__ . '/data/database.sqlite');
define('DB_SCHEMA_VERSION', 3);

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        $dir = dirname(DB_FILE);
        if (!file_exists($dir)) {
            mkdir($dir, 0700, true);
        }

        $pdo = new PDO('sqlite:' . DB_FILE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        // WAL improves concurrent read/write; ignore failure on read-only FS.
        try {
            $pdo->exec('PRAGMA journal_mode = WAL');
        } catch (PDOException $e) {
            // keep default journal mode
        }

        ensure_database_schema($pdo);
    }
    return $pdo;
}

function ensure_database_schema(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_meta (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL
    )");

    $stmt = $pdo->prepare("SELECT value FROM schema_meta WHERE key = 'version'");
    $stmt->execute();
    $current = $stmt->fetchColumn();
    $version = $current === false ? 0 : (int)$current;

    if ($version >= DB_SCHEMA_VERSION) {
        return;
    }

    $pdo->beginTransaction();
    try {
        if ($version < 1) {
            migrate_to_v1($pdo);
            $version = 1;
        }
        if ($version < 2) {
            migrate_to_v2($pdo);
            $version = 2;
        }
        if ($version < 3) {
            migrate_to_v3($pdo);
            $version = 3;
        }

        $upsert = $pdo->prepare("
            INSERT INTO schema_meta (key, value) VALUES ('version', ?)
            ON CONFLICT(key) DO UPDATE SET value = excluded.value
        ");
        $upsert->execute([(string)DB_SCHEMA_VERSION]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function migrate_to_v1(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT UNIQUE NOT NULL,
        parent_id INTEGER,
        FOREIGN KEY (parent_id) REFERENCES categories(id) ON DELETE CASCADE
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS files (
        id TEXT PRIMARY KEY,
        name TEXT NOT NULL,
        original_name TEXT NOT NULL,
        extension TEXT NOT NULL,
        size INTEGER NOT NULL,
        upload_date TEXT NOT NULL,
        modified INTEGER DEFAULT 0,
        replacement_date TEXT,
        category_id INTEGER,
        FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS folder_shares (
        id TEXT PRIMARY KEY,
        category_id INTEGER UNIQUE NOT NULL,
        created_at TEXT NOT NULL,
        FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE CASCADE
    )");

    // Legacy installs: add columns if tables predated this schema.
    ensure_column($pdo, 'files', 'replacement_date', 'ALTER TABLE files ADD COLUMN replacement_date TEXT');
    ensure_column($pdo, 'categories', 'parent_id', 'ALTER TABLE categories ADD COLUMN parent_id INTEGER REFERENCES categories(id) ON DELETE CASCADE');

    $stmt = $pdo->query("SELECT COUNT(*) FROM categories");
    if ((int)$stmt->fetchColumn() === 0) {
        $pdo->exec("INSERT INTO categories (name) VALUES ('All files')");
    }
}

function migrate_to_v2(PDO $pdo) {
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_files_category_id ON files(category_id)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_files_upload_date ON files(upload_date DESC)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_categories_parent_id ON categories(parent_id)");
}

function migrate_to_v3(PDO $pdo) {
    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        action TEXT NOT NULL,
        target_id TEXT,
        detail TEXT,
        ip TEXT,
        created_at TEXT NOT NULL
    )");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_audit_log_created_at ON audit_log(created_at DESC)");
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_audit_log_action ON audit_log(action)");
}

function ensure_column(PDO $pdo, $table, $column, $alterSql) {
    $columns = $pdo->query("PRAGMA table_info(" . $table . ")")->fetchAll();
    foreach ($columns as $col) {
        if ($col['name'] === $column) {
            return;
        }
    }
    $pdo->exec($alterSql);
}

/**
 * Append an audit event. Failures are logged but never break the main request.
 */
function audit_log($action, $targetId = null, $detail = null) {
    try {
        $pdo = getDB();
        $stmt = $pdo->prepare("
            INSERT INTO audit_log (action, target_id, detail, ip, created_at)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            (string)$action,
            $targetId !== null ? (string)$targetId : null,
            $detail !== null ? (string)$detail : null,
            $_SERVER['REMOTE_ADDR'] ?? null,
            date('c'),
        ]);
    } catch (Throwable $e) {
        error_log('File Sharing audit log error: ' . $e->getMessage());
    }
}

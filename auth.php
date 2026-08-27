<?php

require_once __DIR__ . '/db.php';

// config.php is optional. If it supplies a valid hash, it overrides the
// built-in bootstrap password for a new installation and must never be committed.
$configFile = __DIR__ . '/config.php';
if (is_file($configFile)) {
    require_once $configFile;
}

// Used only while creating the first administrator record. Change this
// immediately after installation through the password-change screen.
define('DEFAULT_ADMIN_PASSWORD_HASH', '$2y$12$dwBe.GdusDyRi997/rC4te2oSwb04ll2VZ9B99ML38M3ETagQ32JG');

define('ADMIN_SESSION_COOKIE_LIFETIME', 30 * 24 * 60 * 60);
define('ADMIN_SESSION_IDLE_TIMEOUT', 14 * 24 * 60 * 60);
define('ADMIN_SESSION_ABSOLUTE_TIMEOUT', 30 * 24 * 60 * 60);
define('ADMIN_SESSION_REGENERATE_INTERVAL', 24 * 60 * 60);
define('ADMIN_LOGIN_RATE_LIMIT_WINDOW', 30 * 60);
define('ADMIN_LOGIN_RATE_LIMIT_MAX_FAILURES', 5);
define('ADMIN_LOGIN_RATE_LIMIT_BLOCK_SECONDS', 30 * 60);

/**
 * Store session files inside the project, not in the shared host /tmp.
 * Shared hosting often GC's /tmp daily (or other sites use a short
 * session.gc_maxlifetime on the same save_path), which forces re-login
 * even when cookie lifetime is weeks.
 */
function admin_session_save_path() {
    $path = __DIR__ . '/data/sessions';
    if (!is_dir($path)) {
        @mkdir($path, 0700, true);
    }
    if (is_dir($path) && is_writable($path)) {
        return $path;
    }
    return null;
}

function start_admin_session() {
    if (session_status() !== PHP_SESSION_NONE) {
        return;
    }

    $savePath = admin_session_save_path();
    if ($savePath !== null) {
        session_save_path($savePath);
    }

    ini_set('session.gc_maxlifetime', (string)ADMIN_SESSION_COOKIE_LIFETIME);
    ini_set('session.cookie_lifetime', (string)ADMIN_SESSION_COOKIE_LIFETIME);
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    // Probability of GC on request; with our own path this only touches our files.
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');

    $cookieParams = session_get_cookie_params();
    $isSecure = is_request_https();

    session_set_cookie_params([
        'lifetime' => ADMIN_SESSION_COOKIE_LIFETIME,
        'path' => $cookieParams['path'] ?? '/',
        'domain' => $cookieParams['domain'] ?? '',
        'secure' => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

/**
 * Re-issue the session cookie with full lifetime after regenerate_id.
 * Some PHP/host combos otherwise leave a session-cookie (expires on browser close)
 * or a short-lived cookie after ID rotation.
 */
function refresh_admin_session_cookie() {
    if (headers_sent() || !ini_get('session.use_cookies')) {
        return;
    }

    $params = session_get_cookie_params();
    $lifetime = (int)($params['lifetime'] ?? ADMIN_SESSION_COOKIE_LIFETIME);
    setcookie(session_name(), session_id(), [
        'expires' => $lifetime > 0 ? time() + $lifetime : 0,
        'path' => $params['path'] ?? '/',
        'domain' => $params['domain'] ?? '',
        'secure' => !empty($params['secure']),
        'httponly' => true,
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
}

/**
 * Detect HTTPS. X-Forwarded-Proto is trusted only when REMOTE_ADDR is a local/proxy hop
 * or TRUSTED_PROXIES (comma-separated IPs) contains it.
 */
function is_request_https() {
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    $forwarded = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
    if ($forwarded === '' || strtolower($forwarded) !== 'https') {
        return false;
    }

    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    $trusted = ['127.0.0.1', '::1'];
    if (defined('TRUSTED_PROXIES') && is_string(TRUSTED_PROXIES) && TRUSTED_PROXIES !== '') {
        foreach (explode(',', TRUSTED_PROXIES) as $ip) {
            $ip = trim($ip);
            if ($ip !== '') {
                $trusted[] = $ip;
            }
        }
    }

    return in_array($remote, $trusted, true);
}

/**
 * Prefer bcrypt on shared hosting: Argon2id (esp. m=65536) is often
 * unavailable or fails under low memory_limit, so password_verify always
 * returns false for a correct password.
 */
function admin_password_algorithm() {
    return PASSWORD_BCRYPT;
}

function admin_password_hash_options() {
    return ['cost' => 12];
}

function admin_auth_db() {
    return getDB();
}

/**
 * Return the password hash used to bootstrap a new installation.
 * FILESHARE_ADMIN_PASSWORD_HASH from config.php or the environment overrides
 * the bundled DemoPassword hash.
 */
function initial_admin_password_hash() {
    $hash = defined('FILESHARE_ADMIN_PASSWORD_HASH')
        ? FILESHARE_ADMIN_PASSWORD_HASH
        : getenv('FILESHARE_ADMIN_PASSWORD_HASH');

    $info = is_string($hash) ? password_get_info($hash) : [];
    if (!is_string($hash) || $hash === '' || empty($info['algo'])) {
        return DEFAULT_ADMIN_PASSWORD_HASH;
    }

    return $hash;
}

function ensure_admin_auth_tables() {
    $pdo = admin_auth_db();

    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_auth (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        password_hash TEXT NOT NULL,
        admin_session_version INTEGER NOT NULL DEFAULT 1,
        updated_at TEXT NOT NULL
    )");

    $columns = $pdo->query("PRAGMA table_info(admin_auth)")->fetchAll();
    $hasAdminSessionVersion = false;
    $hasLegacySessionVersion = false;
    foreach ($columns as $column) {
        if ($column['name'] === 'admin_session_version') {
            $hasAdminSessionVersion = true;
        }
        if ($column['name'] === 'session_version') {
            $hasLegacySessionVersion = true;
        }
    }

    if (!$hasAdminSessionVersion) {
        $pdo->exec("ALTER TABLE admin_auth ADD COLUMN admin_session_version INTEGER NOT NULL DEFAULT 1");
        if ($hasLegacySessionVersion) {
            $pdo->exec("UPDATE admin_auth SET admin_session_version = session_version");
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS admin_login_rate_limits (
        rate_key TEXT PRIMARY KEY,
        failed_count INTEGER NOT NULL DEFAULT 0,
        first_failed_at INTEGER NOT NULL DEFAULT 0,
        blocked_until INTEGER NOT NULL DEFAULT 0,
        updated_at INTEGER NOT NULL DEFAULT 0
    )");

    $stmt = $pdo->query("SELECT COUNT(*) FROM admin_auth WHERE id = 1");
    if ((int)$stmt->fetchColumn() === 0) {
        $stmt = $pdo->prepare("INSERT INTO admin_auth (id, password_hash, admin_session_version, updated_at) VALUES (1, ?, 1, ?)");
        $stmt->execute([initial_admin_password_hash(), date('c')]);
    }
}

function get_admin_auth_record() {
    ensure_admin_auth_tables();
    $stmt = admin_auth_db()->query("SELECT password_hash, admin_session_version FROM admin_auth WHERE id = 1");
    return $stmt->fetch();
}

function update_admin_password_hash($passwordHash) {
    ensure_admin_auth_tables();
    $stmt = admin_auth_db()->prepare("UPDATE admin_auth SET password_hash = ?, updated_at = ? WHERE id = 1");
    $stmt->execute([$passwordHash, date('c')]);
}

function update_admin_password($password) {
    ensure_admin_auth_tables();
    $hash = password_hash($password, admin_password_algorithm(), admin_password_hash_options());
    $stmt = admin_auth_db()->prepare("
        UPDATE admin_auth
        SET password_hash = ?, admin_session_version = admin_session_version + 1, updated_at = ?
        WHERE id = 1
    ");
    $stmt->execute([$hash, date('c')]);
    clear_admin_auth_state();
}

function get_admin_session_version() {
    $record = get_admin_auth_record();
    return (int)$record['admin_session_version'];
}

function revoke_admin_sessions($keepCurrentSession = false) {
    ensure_admin_auth_tables();
    $stmt = admin_auth_db()->prepare("UPDATE admin_auth SET admin_session_version = admin_session_version + 1, updated_at = ? WHERE id = 1");
    $stmt->execute([date('c')]);

    if ($keepCurrentSession && !empty($_SESSION['authenticated'])) {
        $_SESSION['admin_session_version'] = get_admin_session_version();
    }
}

function admin_login_rate_key() {
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function get_admin_login_rate_limit_status($rateKey = null) {
    ensure_admin_auth_tables();
    $rateKey = $rateKey ?? admin_login_rate_key();
    $now = time();

    $stmt = admin_auth_db()->prepare("SELECT failed_count, first_failed_at, blocked_until FROM admin_login_rate_limits WHERE rate_key = ?");
    $stmt->execute([$rateKey]);
    $row = $stmt->fetch();

    if (!$row) {
        return ['failed_count' => 0, 'blocked_until' => 0, 'is_blocked' => false];
    }

    $firstFailedAt = (int)$row['first_failed_at'];
    $blockedUntil = (int)$row['blocked_until'];

    if ($blockedUntil > $now) {
        return [
            'failed_count' => (int)$row['failed_count'],
            'blocked_until' => $blockedUntil,
            'is_blocked' => true,
        ];
    }

    if ($firstFailedAt > 0 && $firstFailedAt + ADMIN_LOGIN_RATE_LIMIT_WINDOW < $now) {
        reset_admin_login_rate_limit($rateKey);
        return ['failed_count' => 0, 'blocked_until' => 0, 'is_blocked' => false];
    }

    return [
        'failed_count' => (int)$row['failed_count'],
        'blocked_until' => 0,
        'is_blocked' => false,
    ];
}

function reset_admin_login_rate_limit($rateKey = null) {
    ensure_admin_auth_tables();
    $rateKey = $rateKey ?? admin_login_rate_key();
    $stmt = admin_auth_db()->prepare("DELETE FROM admin_login_rate_limits WHERE rate_key = ?");
    $stmt->execute([$rateKey]);
}

function prune_admin_login_rate_limits() {
    static $pruned = false;
    if ($pruned) {
        return;
    }
    $pruned = true;
    try {
        $cutoff = time() - (ADMIN_LOGIN_RATE_LIMIT_WINDOW * 4);
        $stmt = admin_auth_db()->prepare("
            DELETE FROM admin_login_rate_limits
            WHERE blocked_until < ? AND updated_at < ?
        ");
        $stmt->execute([time(), $cutoff]);
    } catch (Throwable $e) {
        // non-fatal
    }
}

function record_admin_login_failure($rateKey = null) {
    ensure_admin_auth_tables();
    prune_admin_login_rate_limits();
    $rateKey = $rateKey ?? admin_login_rate_key();
    $now = time();

    $stmt = admin_auth_db()->prepare("SELECT failed_count, first_failed_at FROM admin_login_rate_limits WHERE rate_key = ?");
    $stmt->execute([$rateKey]);
    $row = $stmt->fetch();

    if (!$row || (int)$row['first_failed_at'] + ADMIN_LOGIN_RATE_LIMIT_WINDOW < $now) {
        $failedCount = 1;
        $firstFailedAt = $now;
    } else {
        $failedCount = (int)$row['failed_count'] + 1;
        $firstFailedAt = (int)$row['first_failed_at'];
    }

    $blockedUntil = $failedCount >= ADMIN_LOGIN_RATE_LIMIT_MAX_FAILURES
        ? $now + ADMIN_LOGIN_RATE_LIMIT_BLOCK_SECONDS
        : 0;

    $stmt = admin_auth_db()->prepare("
        INSERT OR REPLACE INTO admin_login_rate_limits (rate_key, failed_count, first_failed_at, blocked_until, updated_at)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$rateKey, $failedCount, $firstFailedAt, $blockedUntil, $now]);
}

function delay_failed_admin_login() {
    usleep(350000);
}

function clear_admin_auth_state() {
    unset(
        $_SESSION['authenticated'],
        $_SESSION['auth_login_at'],
        $_SESSION['auth_last_activity_at'],
        $_SESSION['auth_last_regenerated_at'],
        $_SESSION['admin_session_version']
    );
}

function is_admin_authenticated() {
    if (empty($_SESSION['authenticated']) || $_SESSION['authenticated'] !== true) {
        return false;
    }

    $now = time();
    $loginAt = (int)($_SESSION['auth_login_at'] ?? 0);
    $lastActivityAt = (int)($_SESSION['auth_last_activity_at'] ?? 0);
    $lastRegeneratedAt = (int)($_SESSION['auth_last_regenerated_at'] ?? 0);
    $sessionVersion = (int)($_SESSION['admin_session_version'] ?? 0);

    if (
        $loginAt <= 0
        || $lastActivityAt <= 0
        || $loginAt + ADMIN_SESSION_ABSOLUTE_TIMEOUT < $now
        || $lastActivityAt + ADMIN_SESSION_IDLE_TIMEOUT < $now
        || $sessionVersion !== get_admin_session_version()
    ) {
        clear_admin_auth_state();
        return false;
    }

    if ($lastRegeneratedAt + ADMIN_SESSION_REGENERATE_INTERVAL < $now) {
        session_regenerate_id(true);
        $_SESSION['auth_last_regenerated_at'] = $now;
        refresh_admin_session_cookie();
    }

    $_SESSION['auth_last_activity_at'] = $now;
    return true;
}

function authenticate($password) {
    $rateKey = admin_login_rate_key();
    $rateStatus = get_admin_login_rate_limit_status($rateKey);

    if ($rateStatus['is_blocked']) {
        delay_failed_admin_login();
        audit_log('login_blocked', null, $rateKey);
        return false;
    }

    $record = get_admin_auth_record();
    $passwordHash = $record['password_hash'];

    if (!password_verify($password, $passwordHash)) {
        record_admin_login_failure($rateKey);
        delay_failed_admin_login();
        audit_log('login_failed', null, $rateKey);
        return false;
    }

    if (password_needs_rehash($passwordHash, admin_password_algorithm(), admin_password_hash_options())) {
        update_admin_password_hash(password_hash($password, admin_password_algorithm(), admin_password_hash_options()));
    }

    reset_admin_login_rate_limit($rateKey);
    session_regenerate_id(true);
    refresh_admin_session_cookie();

    $now = time();
    $_SESSION['authenticated'] = true;
    $_SESSION['auth_login_at'] = $now;
    $_SESSION['auth_last_activity_at'] = $now;
    $_SESSION['auth_last_regenerated_at'] = $now;
    $_SESSION['admin_session_version'] = (int)$record['admin_session_version'];
    audit_log('login_ok', null, $rateKey);

    return true;
}

function logout() {
    $_SESSION = [];

    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}

function require_admin_access() {
    if (!is_admin_authenticated()) {
        header('Location: login.php');
        exit;
    }
}

function csrf_token($action) {
    if (!isset($_SESSION['_csrf_tokens']) || !is_array($_SESSION['_csrf_tokens'])) {
        $_SESSION['_csrf_tokens'] = [];
    }

    if (empty($_SESSION['_csrf_tokens'][$action])) {
        $_SESSION['_csrf_tokens'][$action] = bin2hex(random_bytes(32));
    }

    return $_SESSION['_csrf_tokens'][$action];
}

function request_header($name) {
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    return $_SERVER[$serverKey] ?? null;
}

function current_request_host() {
    $host = $_SERVER['HTTP_HOST'] ?? $_SERVER['SERVER_NAME'] ?? '';
    $host = strtolower($host);
    $host = preg_replace('/:\d+$/', '', $host);
    return rtrim($host, '.');
}

function url_host($url) {
    $host = parse_url($url, PHP_URL_HOST);
    return $host ? rtrim(strtolower($host), '.') : '';
}

function request_has_valid_same_host_source() {
    $source = $_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '';
    if ($source === '') {
        return false;
    }

    return url_host($source) === current_request_host();
}

function csrf_token_from_request($payload = null) {
    $headerToken = request_header('X-CSRF-Token');
    if (is_string($headerToken) && $headerToken !== '') {
        return $headerToken;
    }

    if (isset($_POST['csrf_token']) && is_string($_POST['csrf_token'])) {
        return $_POST['csrf_token'];
    }

    if (is_array($payload) && isset($payload['csrf_token']) && is_string($payload['csrf_token'])) {
        return $payload['csrf_token'];
    }

    return '';
}

function require_valid_csrf_token($type, $action, $payload = null) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !request_has_valid_same_host_source()) {
        http_response_code(403);
        exit('Forbidden');
    }

    $expectedToken = csrf_token($action);
    $providedToken = csrf_token_from_request($payload);

    if (!is_string($providedToken) || !hash_equals($expectedToken, $providedToken)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

function isAuthenticated() {
    return is_admin_authenticated();
}

function requireAuth() {
    require_admin_access();
}

start_admin_session();
ensure_admin_auth_tables();

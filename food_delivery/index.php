<?php
/**
 * Food Delivery Management System
 * Server-authoritative PHP application with hardened authentication.
 */

declare(strict_types=1);

const SESSION_COOKIE = 'fd_admin_sid';
const QR_PAYLOAD_SEPARATOR = '|';

function parse_env_file(string $path): array {
    $values = [];
    if (!is_readable($path)) {
        return $values;
    }
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return $values;
    }
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }
        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }
        $key = trim(substr($line, 0, $pos));
        $value = trim(substr($line, $pos + 1));
        if (strlen($value) > 1 && $value[0] === '"' && str_ends_with($value, '"')) {
            $value = str_replace('\\"', '"', substr($value, 1, -1));
        } elseif (strlen($value) > 1 && $value[0] === "'" && str_ends_with($value, "'")) {
            $value = substr($value, 1, -1);
        }
        if ($key !== '') {
            $values[$key] = $value;
        }
    }
    return $values;
}

function env_values(): array {
    static $values = null;
    if ($values === null) {
        $values = parse_env_file(__DIR__ . '/.env');
    }
    return $values;
}

function cfg(string $key, string $default = ''): string {
    $values = env_values();
    if (!isset($values[$key])) {
        return $default;
    }
    $value = trim((string)$values[$key]);
    return $value === '' ? $default : $value;
}

function cfg_int(string $key, int $default): int {
    $value = cfg($key, '');
    return is_numeric($value) ? (int)$value : $default;
}

date_default_timezone_set(cfg('APP_TIMEZONE', 'Asia/Tehran'));

function env_file_path(): string {
    return __DIR__ . '/.env';
}

function env_set(string $key, string $value): bool {
    $path = env_file_path();
    if (!is_writable(__DIR__)) {
        return false;
    }
    $value = str_replace(["\r", "\n"], ' ', $value);
    $lines = is_readable($path) ? file($path, FILE_IGNORE_NEW_LINES) : [];
    if ($lines === false) {
        $lines = [];
    }
    $pattern = '/^\s*(export\s+)?' . preg_quote($key, '/') . '\s*=/';
    $found = false;
    foreach ($lines as $index => $line) {
        if (preg_match($pattern, (string)$line)) {
            $lines[$index] = $key . '=' . $value;
            $found = true;
            break;
        }
    }
    if (!$found) {
        $lines[] = $key . '=' . $value;
    }
    $written = file_put_contents($path, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX);
    return $written !== false;
}

function app_title(): string {
    return cfg('APP_TITLE', 'سامانه مدیریت تحویل غذا');
}

function db_file(): string {
    $name = cfg('DB_FILE', 'food_delivery.sqlite');
    if ($name === '' || $name[0] === '/' || preg_match('#^[A-Za-z]:[\\/]#', $name)) {
        return $name;
    }
    return __DIR__ . '/' . $name;
}

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(self), microphone=(), geolocation=(), interest-cohort=()');
header('Cross-Origin-Opener-Policy: same-origin');
header('Cross-Origin-Resource-Policy: same-origin');
header('Content-Security-Policy: default-src \'self\'; base-uri \'none\'; object-src \'none\'; frame-ancestors \'none\'; form-action \'self\'; script-src \'self\'; style-src \'self\'; img-src \'self\' data: blob:; font-src \'self\'; connect-src \'self\'; media-src \'self\' blob:');
if (is_https()) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}
header_remove('X-Powered-By');

function u_len(string $value): int {
    $parts = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
    return $parts === false ? strlen($value) : count($parts);
}

function u_substr(string $value, int $start, int $length): string {
    $parts = preg_split('//u', $value, -1, PREG_SPLIT_NO_EMPTY);
    if ($parts === false) {
        return substr($value, $start, $length);
    }
    return implode('', array_slice($parts, $start, $length));
}

function is_https(): bool {
    $https = (string)($_SERVER['HTTPS'] ?? '');
    if ($https !== '' && strtolower($https) !== 'off') {
        return true;
    }
    if ((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https') {
        return true;
    }
    return (string)($_SERVER['SERVER_PORT'] ?? '') === '443';
}

function base_path(): string {
    $dir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
    $dir = rtrim($dir, '/');
    return $dir === '' ? '/' : $dir;
}

session_name(SESSION_COOKIE);
session_set_cookie_params([
    'lifetime' => 0,
    'path' => base_path(),
    'domain' => '',
    'secure' => is_https(),
    'httponly' => true,
    'samesite' => 'Strict',
]);
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.use_trans_sid', '0');
ini_set('session.gc_maxlifetime', (string) cfg_int('SESSION_ABSOLUTE_TIMEOUT', 28800));
ini_set('session.cookie_httponly', '1');
session_start();

header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $pdo = new PDO('sqlite:' . db_file(), null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('PRAGMA journal_mode = WAL');
    $pdo->exec('PRAGMA synchronous = NORMAL');
    $pdo->exec('PRAGMA busy_timeout = 5000');
    return $pdo;
}

function table_columns(string $table): array {
    $rows = db()->query('PRAGMA table_info(' . $table . ')')->fetchAll();
    $cols = [];
    foreach ($rows as $row) {
        $cols[] = (string)$row['name'];
    }
    return $cols;
}

function init_database(): void {
    $pdo = db();
    $pdo->exec('CREATE TABLE IF NOT EXISTS admins (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        username TEXT UNIQUE NOT NULL,
        password_hash TEXT NOT NULL,
        full_name TEXT NOT NULL,
        role TEXT NOT NULL DEFAULT \'delivery_admin\',
        created_at TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS attendees (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        full_name TEXT NOT NULL,
        received_food INTEGER NOT NULL DEFAULT 0,
        delivered_by_id INTEGER DEFAULT NULL,
        delivered_by_name TEXT DEFAULT NULL,
        delivered_at TEXT DEFAULT NULL,
        created_at TEXT NOT NULL,
        updated_at TEXT DEFAULT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS settings (
        key TEXT PRIMARY KEY,
        value TEXT NOT NULL
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS login_attempts (
        ident TEXT PRIMARY KEY,
        fails INTEGER NOT NULL DEFAULT 0,
        first_at INTEGER NOT NULL,
        locked_until INTEGER NOT NULL DEFAULT 0
    )');
    $pdo->exec('CREATE TABLE IF NOT EXISTS scan_log (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        uid TEXT DEFAULT NULL,
        full_name TEXT DEFAULT NULL,
        status TEXT NOT NULL,
        admin_id INTEGER DEFAULT NULL,
        admin_name TEXT DEFAULT NULL,
        created_at TEXT NOT NULL
    )');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_attendees_delivery ON attendees(delivered_by_id, received_food)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_scan_log_created ON scan_log(created_at DESC)');

    $adminCols = table_columns('admins');
    foreach (['last_login_at' => 'TEXT', 'failed_attempts' => 'INTEGER NOT NULL DEFAULT 0', 'locked_until' => 'TEXT'] as $col => $def) {
        if (!in_array($col, $adminCols, true)) {
            $pdo->exec('ALTER TABLE admins ADD COLUMN ' . $col . ' ' . $def);
        }
    }
    $attendeeCols = table_columns('attendees');
    if (!in_array('updated_at', $attendeeCols, true)) {
        $pdo->exec('ALTER TABLE attendees ADD COLUMN updated_at TEXT DEFAULT NULL');
    }
}

function drop_legacy_columns(): void {
    $pdo = db();
    $attendeeCols = table_columns('attendees');
    if (in_array('code', $attendeeCols, true)) {
        $pdo->exec('BEGIN');
        $pdo->exec('ALTER TABLE attendees RENAME TO attendees_old');
        $pdo->exec('CREATE TABLE attendees (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            full_name TEXT NOT NULL,
            received_food INTEGER NOT NULL DEFAULT 0,
            delivered_by_id INTEGER DEFAULT NULL,
            delivered_by_name TEXT DEFAULT NULL,
            delivered_at TEXT DEFAULT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT DEFAULT NULL
        )');
        $pdo->exec('INSERT INTO attendees (id, full_name, received_food, delivered_by_id, delivered_by_name, delivered_at, created_at, updated_at)
                    SELECT id, full_name, received_food, delivered_by_id, delivered_by_name, delivered_at, created_at, updated_at FROM attendees_old');
        $pdo->exec('DROP TABLE attendees_old');
        $pdo->exec('CREATE INDEX IF NOT EXISTS idx_attendees_delivery ON attendees(delivered_by_id, received_food)');
        $pdo->exec("UPDATE sqlite_sequence SET seq = (SELECT COALESCE(MAX(id), 0) FROM attendees) WHERE name = 'attendees'");
        $pdo->exec('COMMIT');
    }
    $scanCols = table_columns('scan_log');
    if (in_array('code', $scanCols, true)) {
        $pdo->exec('ALTER TABLE scan_log RENAME COLUMN code TO uid');
    }
    $settingsCols = table_columns('settings');
    if (in_array('attendee_codes_randomized', $settingsCols, true)) {
        $pdo->exec("DELETE FROM settings WHERE key = 'attendee_codes_randomized'");
    }
}

function get_setting(string $key, ?string $default = null): ?string {
    $stmt = db()->prepare('SELECT value FROM settings WHERE key = ? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? $default : (string)$value;
}

function set_setting(string $key, string $value): void {
    $stmt = db()->prepare('INSERT INTO settings (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
    $stmt->execute([$key, $value]);
}

function apply_admin_credentials(string $username, string $password, string $fullName): void {
    $pdo = db();
    $conflicts = $pdo->prepare("SELECT id FROM admins WHERE LOWER(username) = LOWER(?) AND role <> 'super_admin'");
    $conflicts->execute([$username]);
    foreach ($conflicts->fetchAll() as $row) {
        $rename = $pdo->prepare('UPDATE admins SET username = ? WHERE id = ?');
        $rename->execute([$username . '_' . (int)$row['id'], (int)$row['id']]);
    }
    $stmt = $pdo->prepare("SELECT id FROM admins WHERE role = 'super_admin' LIMIT 1");
    $stmt->execute();
    $id = $stmt->fetchColumn();
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if ($id) {
        $upd = $pdo->prepare("UPDATE admins SET username = ?, password_hash = ?, full_name = ?, role = 'super_admin' WHERE id = ?");
        $upd->execute([$username, $hash, $fullName, (int)$id]);
    } else {
        $ins = $pdo->prepare("INSERT INTO admins (username, password_hash, full_name, role, created_at) VALUES (?, ?, ?, 'super_admin', ?)");
        $ins->execute([$username, $hash, $fullName, date('Y-m-d H:i:s')]);
    }
}

function admin_fingerprint(string $username, string $password, string $fullName): string {
    return hash('sha256', $username . "\0" . $password . "\0" . $fullName);
}

function sync_admin_from_env(): void {
    $username = cfg('ADMIN_USERNAME', 'admin');
    $password = cfg('ADMIN_PASSWORD', 'admin');
    $fullName = cfg('ADMIN_NAME', 'مدیر سیستم');
    $fingerprint = admin_fingerprint($username, $password, $fullName);
    $stored = get_setting('admin_env_fingerprint');
    if ($stored === $fingerprint) {
        return;
    }
    $env = env_values();
    if ($stored === null && get_setting('super_admin_seeded') === '1' && !isset($env['ADMIN_USERNAME'])) {
        set_setting('admin_env_fingerprint', $fingerprint);
        return;
    }
    apply_admin_credentials($username, $password, $fullName);
    set_setting('super_admin_seeded', '1');
    set_setting('admin_env_fingerprint', $fingerprint);
}

function qr_secret(): string {
    $fromEnv = cfg('HMAC_SECRET', '');
    if ($fromEnv !== '') {
        return $fromEnv;
    }
    $secret = get_setting('qr_secret');
    if ($secret === null || strlen($secret) < 32) {
        $secret = bin2hex(random_bytes(32));
        set_setting('qr_secret', $secret);
    }
    return $secret;
}

function qr_payload(int $userId): string {
    $sig = strtoupper(substr(hash_hmac('sha256', (string)$userId, qr_secret()), 0, 8));
    return (string)$userId . QR_PAYLOAD_SEPARATOR . $sig;
}

function verify_qr_payload(string $raw): int {
    $raw = trim($raw);
    if ($raw === '') {
        throw new RuntimeException('شناسه خالی است.');
    }
    if (str_contains($raw, 'CONF2026')) {
        throw new RuntimeException('قالب کد QR قدیمی است؛ کارت را با نسخهٔ جدید تولید کنید.');
    }
    $parts = explode(QR_PAYLOAD_SEPARATOR, $raw);
    if (count($parts) !== 2) {
        throw new RuntimeException('قالب شناسه کاربر و امضا نامعتبر است.');
    }
    $userIdText = trim($parts[0]);
    $sig = strtoupper(trim($parts[1]));
    if (!is_valid_user_id($userIdText)) {
        throw new RuntimeException('شناسه کاربر معتبر نیست.');
    }
    $userId = (int)$userIdText;
    $expected = strtoupper(substr(hash_hmac('sha256', (string)$userId, qr_secret()), 0, 8));
    if (!hash_equals($expected, $sig)) {
        throw new RuntimeException('امضای امنیتی نامعتبر است.');
    }
    return $userId;
}

function is_valid_user_id(string $userId): bool {
    return (bool)preg_match('/^[1-9][0-9]*$/', $userId);
}

function normalize_username(string $username): string {
    return trim(preg_replace('/\s+/', ' ', $username) ?? '');
}

function current_admin(): ?array {
    $admin = $_SESSION['admin'] ?? null;
    if (!is_array($admin) || empty($admin['id'])) {
        return null;
    }
    $now = time();
    if ((int)($admin['login_at'] ?? 0) + cfg_int('SESSION_ABSOLUTE_TIMEOUT', 28800) < $now) {
        destroy_session();
        return null;
    }
    if ((int)($admin['seen_at'] ?? 0) + cfg_int('SESSION_IDLE_TIMEOUT', 7200) < $now) {
        destroy_session();
        return null;
    }
    $_SESSION['admin']['seen_at'] = $now;
    return $admin;
}

function destroy_session(): void {
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Strict',
        ]);
    }
    session_destroy();
}

function client_ip(): string {
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

function throttle_key(string $scope, string $value): string {
    return hash('sha256', $scope . '|' . $value);
}

function throttle_state(string $ident): ?array {
    $stmt = db()->prepare('SELECT fails, first_at, locked_until FROM login_attempts WHERE ident = ? LIMIT 1');
    $stmt->execute([$ident]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function throttle_lock_seconds(string $ident): int {
    $row = throttle_state($ident);
    if (!$row) {
        return 0;
    }
    $lockedUntil = (int)($row['locked_until'] ?? 0);
    if ($lockedUntil > time()) {
        return $lockedUntil - time();
    }
    if ($lockedUntil > 0) {
        $stmt = db()->prepare('UPDATE login_attempts SET locked_until = 0 WHERE ident = ?');
        $stmt->execute([$ident]);
    }
    return 0;
}

function throttle_fail(string $ident, int $limit): void {
    $now = time();
    $row = throttle_state($ident);
    if (!$row || ($now - (int)$row['first_at']) > cfg_int('LOGIN_FAIL_WINDOW', 900)) {
        $stmt = db()->prepare('INSERT INTO login_attempts (ident, fails, first_at, locked_until) VALUES (?, 1, ?, 0) ON CONFLICT(ident) DO UPDATE SET fails = 1, first_at = excluded.first_at, locked_until = 0');
        $stmt->execute([$ident, $now]);
        return;
    }
    $fails = (int)$row['fails'] + 1;
    $lockedUntil = $fails >= $limit ? $now + cfg_int('LOGIN_LOCK_TIME', 900) : 0;
    $stmt = db()->prepare('UPDATE login_attempts SET fails = ?, locked_until = ? WHERE ident = ?');
    $stmt->execute([$fails, $lockedUntil, $ident]);
}

function throttle_reset(string $ident): void {
    $stmt = db()->prepare('DELETE FROM login_attempts WHERE ident = ?');
    $stmt->execute([$ident]);
}

function stats_for(?int $adminId): array {
    $stmt = db()->prepare('SELECT COUNT(*) AS total,
        COALESCE(SUM(received_food), 0) AS delivered,
        COALESCE(SUM(CASE WHEN received_food = 1 AND delivered_by_id = :me THEN 1 ELSE 0 END), 0) AS mine
        FROM attendees');
    $stmt->execute(['me' => $adminId ?? -1]);
    $row = $stmt->fetch() ?: [];
    $total = (int)($row['total'] ?? 0);
    $delivered = (int)($row['delivered'] ?? 0);
    return [
        'total' => $total,
        'delivered' => $delivered,
        'remaining' => max(0, $total - $delivered),
        'mine' => (int)($row['mine'] ?? 0),
    ];
}

function attendees_payload(): array {
    $rows = db()->query('SELECT id, full_name, received_food, delivered_by_name, delivered_at FROM attendees ORDER BY id ASC')->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'id' => (int)$row['id'],
            'full_name' => (string)$row['full_name'],
            'received_food' => (int)$row['received_food'],
            'delivered_by_name' => $row['delivered_by_name'] !== null ? (string)$row['delivered_by_name'] : null,
            'delivered_at' => $row['delivered_at'] !== null ? (string)$row['delivered_at'] : null,
        ];
    }
    return $out;
}

function admins_payload(): array {
    $rows = db()->query("SELECT a.id, a.username, a.full_name, a.role, a.created_at, a.last_login_at,
        (SELECT COUNT(*) FROM attendees att WHERE att.delivered_by_id = a.id AND att.received_food = 1) AS delivered_count
        FROM admins a ORDER BY a.id ASC")->fetchAll();
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'id' => (int)$row['id'],
            'username' => (string)$row['username'],
            'full_name' => (string)$row['full_name'],
            'role' => (string)$row['role'],
            'created_at' => (string)$row['created_at'],
            'last_login_at' => $row['last_login_at'] !== null ? (string)$row['last_login_at'] : null,
            'delivered_count' => (int)$row['delivered_count'],
        ];
    }
    return $out;
}

function recent_scans_payload(bool $superAdmin, int $adminId): array {
    if ($superAdmin) {
        $rows = db()->query('SELECT uid, full_name, status, admin_name, created_at FROM scan_log ORDER BY id DESC LIMIT 8')->fetchAll();
    } else {
        $stmt = db()->prepare('SELECT uid, full_name, status, admin_name, created_at FROM scan_log WHERE admin_id = ? ORDER BY id DESC LIMIT 8');
        $stmt->execute([$adminId]);
        $rows = $stmt->fetchAll();
    }
    $out = [];
    foreach ($rows as $row) {
        $out[] = [
            'uid' => $row['uid'] !== null ? (string)$row['uid'] : '',
            'full_name' => (string)$row['full_name'],
            'status' => (string)$row['status'],
            'admin_name' => $row['admin_name'] !== null ? (string)$row['admin_name'] : '',
            'time' => substr((string)$row['created_at'], 11, 8),
        ];
    }
    return $out;
}

function log_scan(string $status, ?string $uid, ?string $fullName, ?array $admin): void {
    $stmt = db()->prepare('INSERT INTO scan_log (uid, full_name, status, admin_id, admin_name, created_at) VALUES (?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $uid,
        $fullName,
        $status,
        $admin['id'] ?? null,
        $admin['full_name'] ?? null,
        date('Y-m-d H:i:s'),
    ]);
}

function json_out(array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function read_json_body(): array {
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        throw new RuntimeException('بدنه درخواست نامعتبر است.');
    }
    return $data;
}

function require_csrf(): void {
    $header = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if ($header === '') {
        $header = (string)($_POST['csrf_token'] ?? '');
    }
    if ($header === '') {
        $body = read_json_body();
        $header = (string)($body['csrf_token'] ?? '');
    }
    if ($header === '' || !hash_equals((string)($_SESSION['csrf_token'] ?? ''), $header)) {
        json_out(['ok' => false, 'error' => 'نشست امنیتی نامعتبر است. صفحه را تازه‌سازی کنید.'], 419);
    }
}

function xlsx_escape(string $value): string {
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $value) ?? $value;
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function xlsx_column_name(int $index): string {
    $name = '';
    $index++;
    while ($index > 0) {
        $rem = ($index - 1) % 26;
        $name = chr(65 + $rem) . $name;
        $index = intdiv($index - 1, 26);
    }
    return $name;
}

function xlsx_build(string $sheetName, array $rows): string {
    $sheetName = preg_replace('/[\\\\\/\?\*\[\]:]/u', '', $sheetName) ?: 'Sheet1';
    $sheetName = u_substr($sheetName, 0, 31);
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
        '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' .
        '<sheetViews><sheetView rightToLeft="1" workbookViewId="0"/></sheetViews><sheetData>';
    foreach ($rows as $r => $row) {
        $xml .= '<row r="' . ($r + 1) . '">';
        foreach (array_values($row) as $c => $cell) {
            $ref = xlsx_column_name((int)$c) . ($r + 1);
            $text = xlsx_escape((string)$cell);
            $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t xml:space="preserve">' . $text . '</t></is></c>';
        }
        $xml .= '</row>';
    }
    $xml .= '</sheetData></worksheet>';

    $files = [
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' .
            '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>' .
            '<Default Extension="xml" ContentType="application/xml"/>' .
            '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' .
            '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>' .
            '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' .
            '</Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' .
            '<sheets><sheet name="' . xlsx_escape($sheetName) . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' .
            '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' .
            '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>' .
            '</Relationships>',
        'xl/worksheets/sheet1.xml' => $xml,
    ];

    $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
    if ($tmp === false) {
        return '';
    }
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        unlink($tmp);
        return '';
    }
    foreach ($files as $name => $content) {
        $zip->addFromString($name, $content);
    }
    $zip->close();
    $binary = file_get_contents($tmp);
    unlink($tmp);
    return $binary === false ? '' : $binary;
}

function xlsx_read_names(string $path): array {
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('فایل اکسل قابل خواندن نیست.');
    }
    $shared = [];
    $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
    if ($sharedXml !== false) {
        $xml = simplexml_load_string($sharedXml);
        if ($xml !== false) {
            foreach ($xml->si as $si) {
                $text = '';
                if (isset($si->t)) {
                    $text = (string)$si->t;
                } elseif (isset($si->r)) {
                    foreach ($si->r as $run) {
                        $text .= (string)$run->t;
                    }
                }
                $shared[] = $text;
            }
        }
    }
    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    if ($sheetXml === false) {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string)$zip->getNameIndex($i);
            if (str_starts_with($entry, 'xl/worksheets/') && str_ends_with($entry, '.xml')) {
                $sheetXml = $zip->getFromName($entry);
                break;
            }
        }
    }
    $zip->close();
    if ($sheetXml === '') {
        throw new RuntimeException('فایل اکسل فاقد برگه داده است.');
    }
    $xml = simplexml_load_string($sheetXml);
    if ($xml === false) {
        throw new RuntimeException('ساختار فایل اکسل معتبر نیست.');
    }
    $names = [];
    foreach ($xml->sheetData->row as $row) {
        $name = '';
        foreach ($row->c as $cell) {
            $type = (string)($cell['t'] ?? '');
            $value = '';
            if ($type === 'inlineStr') {
                $value = isset($cell->is->t) ? (string)$cell->is->t : '';
            } elseif ($type === 's') {
                $index = (int)(string)$cell->v;
                $value = $shared[$index] ?? '';
            } else {
                $value = (string)($cell->v ?? '');
            }
            $value = trim($value);
            if ($value !== '') {
                $name = $value;
                break;
            }
        }
        if ($name === '') {
            continue;
        }
        if (stripos($name, 'نام') !== false && count($names) === 0) {
            continue;
        }
        $names[] = $name;
    }
    return $names;
}

init_database();
sync_admin_from_env();
drop_legacy_columns();
$currentAdmin = current_admin();
$isSuperAdmin = $currentAdmin !== null && $currentAdmin['role'] === 'super_admin';

$apiAction = (string)($_GET['api'] ?? '');
if ($apiAction !== '') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        switch ($apiAction) {
            case 'login':
                require_csrf();
                $body = read_json_body();
                $username = normalize_username((string)($body['username'] ?? ''));
                $password = (string)($body['password'] ?? '');
                $ipIdent = throttle_key('ip', client_ip());
                $userIdent = throttle_key('user', strtolower($username));
                $ipLock = throttle_lock_seconds($ipIdent);
                $userLock = throttle_lock_seconds($userIdent);
                if ($ipLock > 0 || $userLock > 0) {
                    $wait = max($ipLock, $userLock);
                    json_out(['ok' => false, 'error' => 'تلاش‌های ناموفق زیاد بوده است. لطفاً ' . ceil($wait / 60) . ' دقیقه دیگر تلاش کنید.'], 429);
                }
                if ($username === '' || $password === '') {
                    json_out(['ok' => false, 'error' => 'نام کاربری و رمز عبور را وارد کنید.'], 400);
                }
                $stmt = db()->prepare('SELECT * FROM admins WHERE LOWER(username) = LOWER(?) LIMIT 1');
                $stmt->execute([$username]);
                $admin = $stmt->fetch();
                $valid = $admin && password_verify($password, (string)$admin['password_hash']);
                if ($valid && (int)($admin['locked_until'] ?? 0) > time()) {
                    $valid = false;
                }
                if (!$valid) {
                    throttle_fail($userIdent, cfg_int('LOGIN_FAIL_LIMIT', 5));
                    throttle_fail($ipIdent, cfg_int('IP_FAIL_LIMIT', 30));
                    usleep(300000);
                    json_out(['ok' => false, 'error' => 'نام کاربری یا رمز عبور اشتباه است.'], 401);
                }
                if (password_needs_rehash((string)$admin['password_hash'], PASSWORD_DEFAULT)) {
                    $rehash = db()->prepare('UPDATE admins SET password_hash = ? WHERE id = ?');
                    $rehash->execute([password_hash($password, PASSWORD_DEFAULT), (int)$admin['id']]);
                }
                throttle_reset($userIdent);
                throttle_reset($ipIdent);
                session_regenerate_id(true);
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                $now = time();
                $_SESSION['admin'] = [
                    'id' => (int)$admin['id'],
                    'username' => (string)$admin['username'],
                    'full_name' => (string)$admin['full_name'],
                    'role' => (string)$admin['role'],
                    'login_at' => $now,
                    'seen_at' => $now,
                ];
                $upd = db()->prepare('UPDATE admins SET last_login_at = ?, failed_attempts = 0, locked_until = NULL WHERE id = ?');
                $upd->execute([date('Y-m-d H:i:s'), (int)$admin['id']]);
                json_out(['ok' => true, 'admin' => [
                    'id' => (int)$admin['id'],
                    'username' => (string)$admin['username'],
                    'full_name' => (string)$admin['full_name'],
                    'role' => (string)$admin['role'],
                ]]);

            case 'logout':
                require_csrf();
                destroy_session();
                json_out(['ok' => true]);

            case 'state':
                if ($currentAdmin === null) {
                    json_out(['ok' => true, 'authenticated' => false]);
                }
                $payload = [
                    'ok' => true,
                    'authenticated' => true,
                    'admin' => [
                        'id' => (int)$currentAdmin['id'],
                        'username' => (string)$currentAdmin['username'],
                        'full_name' => (string)$currentAdmin['full_name'],
                        'role' => (string)$currentAdmin['role'],
                    ],
                    'stats' => stats_for((int)$currentAdmin['id']),
                    'recent_scans' => recent_scans_payload($isSuperAdmin, (int)$currentAdmin['id']),
                ];
                if ($isSuperAdmin) {
                    $payload['attendees'] = attendees_payload();
                    $payload['admins'] = admins_payload();
                }
                json_out($payload);

            case 'scan':
                require_csrf();
                if ($currentAdmin === null) {
                    json_out(['ok' => false, 'error' => 'ابتدا وارد سامانه شوید.'], 403);
                }
                $windowStart = (int)($_SESSION['scan_window'] ?? 0);
                $scans = (int)($_SESSION['scan_count'] ?? 0);
                if ($windowStart < time() - cfg_int('SCAN_WINDOW', 60)) {
                    $windowStart = time();
                    $scans = 0;
                }
                $scans++;
                if ($scans > cfg_int('SCAN_LIMIT', 45)) {
                    json_out(['ok' => false, 'error' => 'تعداد درخواست‌ها بیش از حد مجاز است.'], 429);
                }
                $_SESSION['scan_count'] = $scans;
                $_SESSION['scan_window'] = $windowStart;
                $body = read_json_body();
                $raw = (string)($body['qr'] ?? '');
                try {
                    $userId = verify_qr_payload($raw);
                } catch (RuntimeException $e) {
                    log_scan('invalid', null, null, $currentAdmin);
                    json_out(['ok' => true, 'result' => [
                        'status' => 'invalid',
                        'message' => $e->getMessage(),
                    ], 'stats' => stats_for((int)$currentAdmin['id']), 'recent_scans' => recent_scans_payload($isSuperAdmin, (int)$currentAdmin['id'])]);
                }
                $stmt = db()->prepare('SELECT * FROM attendees WHERE id = ? LIMIT 1');
                $stmt->execute([$userId]);
                $attendee = $stmt->fetch();
                if (!$attendee) {
                    log_scan('not_found', (string)$userId, null, $currentAdmin);
                    json_out(['ok' => true, 'result' => [
                        'status' => 'not_found',
                        'message' => 'کاربر (' . $userId . ') در سیستم یافت نشد.',
                    ], 'stats' => stats_for((int)$currentAdmin['id']), 'recent_scans' => recent_scans_payload($isSuperAdmin, (int)$currentAdmin['id'])]);
                }
                if ((int)$attendee['received_food'] === 1) {
                    log_scan('already_delivered', (string)$userId, (string)$attendee['full_name'], $currentAdmin);
                    json_out(['ok' => true, 'result' => [
                        'status' => 'already_delivered',
                        'message' => 'این فرد قبلاً غذا دریافت کرده است.',
                        'attendee' => [
                            'id' => (int)$attendee['id'],
                            'full_name' => (string)$attendee['full_name'],
                        ],
                        'delivered_by_name' => $attendee['delivered_by_name'] !== null ? (string)$attendee['delivered_by_name'] : 'نامشخص',
                        'delivered_at' => $attendee['delivered_at'] !== null ? substr((string)$attendee['delivered_at'], -8) : '---',
                    ], 'stats' => stats_for((int)$currentAdmin['id']), 'recent_scans' => recent_scans_payload($isSuperAdmin, (int)$currentAdmin['id'])]);
                }
                $nowTime = date('Y-m-d H:i:s');
                $upd = db()->prepare('UPDATE attendees SET received_food = 1, delivered_by_id = ?, delivered_by_name = ?, delivered_at = ?, updated_at = ? WHERE id = ? AND received_food = 0');
                $upd->execute([(int)$currentAdmin['id'], (string)$currentAdmin['full_name'], $nowTime, $nowTime, (int)$attendee['id']]);
                if ($upd->rowCount() === 0) {
                    $stmt = db()->prepare('SELECT delivered_by_name, delivered_at FROM attendees WHERE id = ? LIMIT 1');
                    $stmt->execute([(int)$attendee['id']]);
                    $fresh = $stmt->fetch() ?: $attendee;
                    log_scan('already_delivered', (string)$userId, (string)$attendee['full_name'], $currentAdmin);
                    json_out(['ok' => true, 'result' => [
                        'status' => 'already_delivered',
                        'message' => 'این فرد هم‌اکنون توسط ایستگاه دیگری ثبت شد.',
                        'attendee' => [
                            'id' => (int)$attendee['id'],
                            'full_name' => (string)$attendee['full_name'],
                        ],
                        'delivered_by_name' => $fresh['delivered_by_name'] !== null ? (string)$fresh['delivered_by_name'] : 'نامشخص',
                        'delivered_at' => $fresh['delivered_at'] !== null ? substr((string)$fresh['delivered_at'], -8) : '---',
                    ], 'stats' => stats_for((int)$currentAdmin['id']), 'recent_scans' => recent_scans_payload($isSuperAdmin, (int)$currentAdmin['id'])]);
                }
                log_scan('ok', (string)$userId, (string)$attendee['full_name'], $currentAdmin);
                json_out(['ok' => true, 'result' => [
                    'status' => 'ok',
                    'message' => 'تحویل غذا با موفقیت ثبت شد.',
                    'attendee' => [
                        'id' => (int)$attendee['id'],
                        'full_name' => (string)$attendee['full_name'],
                    ],
                    'delivered_by_name' => (string)$currentAdmin['full_name'],
                    'delivered_at' => substr($nowTime, -8),
                ], 'stats' => stats_for((int)$currentAdmin['id']), 'recent_scans' => recent_scans_payload($isSuperAdmin, (int)$currentAdmin['id'])]);

            case 'attendee_search':
                require_csrf();
                if ($currentAdmin === null) {
                    json_out(['ok' => false, 'error' => 'ابتدا وارد سامانه شوید.'], 403);
                }
                $body = read_json_body();
                $q = trim(preg_replace('/\s+/u', ' ', (string)($body['q'] ?? '')) ?? '');
                if (u_len($q) < 2) {
                    json_out(['ok' => true, 'results' => []]);
                }
                $stmt = db()->prepare('SELECT id, full_name, received_food, delivered_by_name, delivered_at FROM attendees
                    WHERE full_name LIKE ? ORDER BY received_food ASC, id ASC LIMIT 8');
                $stmt->execute(['%' . $q . '%']);
                $results = [];
                foreach ($stmt->fetchAll() as $row) {
                    $results[] = [
                        'id' => (int)$row['id'],
                        'full_name' => (string)$row['full_name'],
                        'received_food' => (int)$row['received_food'],
                        'delivered_by_name' => $row['delivered_by_name'] !== null ? (string)$row['delivered_by_name'] : null,
                        'delivered_at' => $row['delivered_at'] !== null ? substr((string)$row['delivered_at'], -8) : null,
                    ];
                }
                json_out(['ok' => true, 'results' => $results]);

            case 'manual_deliver':
                require_csrf();
                if ($currentAdmin === null) {
                    json_out(['ok' => false, 'error' => 'ابتدا وارد سامانه شوید.'], 403);
                }
                $body = read_json_body();
                $attendeeId = (int)($body['id'] ?? 0);
                $stmt = db()->prepare('SELECT * FROM attendees WHERE id = ? LIMIT 1');
                $stmt->execute([$attendeeId]);
                $attendee = $stmt->fetch();
                if (!$attendee) {
                    json_out(['ok' => false, 'error' => 'این شناسه در سیستم یافت نشد.'], 404);
                }
                if ((int)$attendee['received_food'] === 1) {
                    json_out(['ok' => false, 'error' => 'این فرد قبلاً غذا دریافت کرده است.'], 409);
                }
                $nowTime = date('Y-m-d H:i:s');
                $upd = db()->prepare('UPDATE attendees SET received_food = 1, delivered_by_id = ?, delivered_by_name = ?, delivered_at = ?, updated_at = ? WHERE id = ? AND received_food = 0');
                $upd->execute([(int)$currentAdmin['id'], (string)$currentAdmin['full_name'], $nowTime, $nowTime, $attendeeId]);
                if ($upd->rowCount() === 0) {
                    json_out(['ok' => false, 'error' => 'این فرد هم‌اکنون توسط ایستگاه دیگری ثبت شد.'], 409);
                }
                log_scan('manual', (string)$attendeeId, (string)$attendee['full_name'], $currentAdmin);
                json_out(['ok' => true, 'result' => [
                    'status' => 'ok',
                    'message' => 'تحویل دستی (بدون ژتون) با موفقیت ثبت شد.',
                    'attendee' => [
                        'id' => (int)$attendee['id'],
                        'full_name' => (string)$attendee['full_name'],
                    ],
                    'delivered_by_name' => (string)$currentAdmin['full_name'],
                    'delivered_at' => substr($nowTime, -8),
                ], 'stats' => stats_for((int)$currentAdmin['id']), 'recent_scans' => recent_scans_payload($isSuperAdmin, (int)$currentAdmin['id'])]);

            case 'badge_payloads':
                require_csrf();
                if (!$isSuperAdmin) {
                    json_out(['ok' => false, 'error' => 'دسترسی غیرمجاز است.'], 403);
                }
                $rows = db()->query('SELECT id FROM attendees ORDER BY id ASC')->fetchAll();
                $payloads = [];
                foreach ($rows as $row) {
                    $payloads[(int)$row['id']] = qr_payload((int)$row['id']);
                }
                json_out(['ok' => true, 'payloads' => $payloads]);

            case 'add_attendee':
                require_csrf();
                if (!$isSuperAdmin) {
                    json_out(['ok' => false, 'error' => 'دسترسی غیرمجاز است.'], 403);
                }
                $body = read_json_body();
                $fullName = trim(preg_replace('/\s+/u', ' ', (string)($body['full_name'] ?? '')) ?? '');
                if ($fullName === '' || u_len($fullName) > 120) {
                    json_out(['ok' => false, 'error' => 'نام و نام خانوادگی را وارد کنید.'], 400);
                }
                $stmt = db()->prepare('INSERT INTO attendees (full_name, received_food, created_at) VALUES (?, 0, ?)');
                $stmt->execute([$fullName, date('Y-m-d H:i:s')]);
                $newId = (int)db()->lastInsertId();
                json_out(['ok' => true, 'attendee' => [
                    'id' => $newId,
                    'full_name' => $fullName,
                    'received_food' => 0,
                    'delivered_by_name' => null,
                    'delivered_at' => null,
                ], 'attendees' => attendees_payload(), 'stats' => stats_for((int)$currentAdmin['id'])]);

            case 'delete_attendee':
                require_csrf();
                if (!$isSuperAdmin) {
                    json_out(['ok' => false, 'error' => 'دسترسی غیرمجاز است.'], 403);
                }
                $body = read_json_body();
                $stmt = db()->prepare('DELETE FROM attendees WHERE id = ?');
                $stmt->execute([(int)($body['id'] ?? 0)]);
                json_out(['ok' => true, 'attendees' => attendees_payload(), 'stats' => stats_for((int)$currentAdmin['id'])]);

            case 'delete_all_attendees':
                require_csrf();
                if (!$isSuperAdmin) {
                    json_out(['ok' => false, 'error' => 'دسترسی غیرمجاز است.'], 403);
                }
                db()->exec('DELETE FROM attendees');
                db()->exec('DELETE FROM scan_log');
                json_out(['ok' => true, 'attendees' => [], 'stats' => stats_for((int)$currentAdmin['id'])]);

            case 'reset_food_statuses':
                require_csrf();
                if (!$isSuperAdmin) {
                    json_out(['ok' => false, 'error' => 'دسترسی غیرمجاز است.'], 403);
                }
                db()->exec('UPDATE attendees SET received_food = 0, delivered_by_id = NULL, delivered_by_name = NULL, delivered_at = NULL, updated_at = NULL');
                db()->exec('DELETE FROM scan_log');
                json_out(['ok' => true, 'attendees' => attendees_payload(), 'stats' => stats_for((int)$currentAdmin['id'])]);

            case 'import_excel':
                require_csrf();
                if (!$isSuperAdmin) {
                    json_out(['ok' => false, 'error' => 'دسترسی غیرمجاز است.'], 403);
                }
                if (empty($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
                    json_out(['ok' => false, 'error' => 'فایلی ارسال نشده است.'], 400);
                }
                if ((int)($_FILES['file']['size'] ?? 0) > cfg_int('MAX_UPLOAD_BYTES', 2097152)) {
                    json_out(['ok' => false, 'error' => 'حجم فایل بیش از حد مجاز است.'], 400);
                }
                $names = xlsx_read_names((string)$_FILES['file']['tmp_name']);
                if (!$names) {
                    json_out(['ok' => false, 'error' => 'نامی در فایل یافت نشد.'], 400);
                }
                $pdo = db();
                $pdo->beginTransaction();
                $insert = $pdo->prepare('INSERT INTO attendees (full_name, received_food, created_at) VALUES (?, 0, ?)');
                $existing = [];
                foreach ($pdo->query('SELECT full_name FROM attendees') as $row) {
                    $existing[(string)$row['full_name']] = true;
                }
                $added = 0;
                $skipped = 0;
                foreach ($names as $name) {
                    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
                    if ($name === '' || u_len($name) > 120) {
                        continue;
                    }
                    if (isset($existing[$name])) {
                        $skipped++;
                        continue;
                    }
                    $insert->execute([$name, date('Y-m-d H:i:s')]);
                    $existing[$name] = true;
                    $added++;
                }
                $pdo->commit();
                json_out(['ok' => true, 'added' => $added, 'skipped' => $skipped, 'attendees' => attendees_payload(), 'stats' => stats_for((int)$currentAdmin['id'])]);

            case 'export_excel':
                require_csrf();
                if (!$isSuperAdmin) {
                    json_out(['ok' => false, 'error' => 'دسترسی غیرمجاز است.'], 403);
                }
                $rows = [['ردیف', 'شناسه', 'نام و نام خانوادگی', 'وضعیت غذا', 'تحویل‌دهنده', 'زمان تحویل']];
                $items = db()->query('SELECT id, full_name, received_food, delivered_by_name, delivered_at FROM attendees ORDER BY id ASC')->fetchAll();
                foreach ($items as $i => $item) {
                    $rows[] = [
                        $i + 1,
                        (string)$item['id'],
                        (string)$item['full_name'],
                        (int)$item['received_food'] === 1 ? 'تحویل شده' : 'غذا نگرفته',
                        $item['delivered_by_name'] !== null ? (string)$item['delivered_by_name'] : '---',
                        $item['delivered_at'] !== null ? substr((string)$item['delivered_at'], -8) : '---',
                    ];
                }
                $binary = xlsx_build('گزارش تحویل', $rows);
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="food_delivery_report.xlsx"');
                header('Content-Length: ' . strlen($binary));
                echo $binary;
                exit;

            case 'sample_excel':
                require_csrf();
                if (!$isSuperAdmin) {
                    json_out(['ok' => false, 'error' => 'دسترسی غیرمجاز است.'], 403);
                }
                $rows = [
                    ['نام و نام خانوادگی'],
                    ['امیرحسین محمدی'],
                    ['سارا کریمی'],
                    ['محمدرضا حسینی'],
                    ['فاطمه جعفری'],
                ];
                $binary = xlsx_build('نفرات', $rows);
                header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
                header('Content-Disposition: attachment; filename="sample_attendees.xlsx"');
                header('Content-Length: ' . strlen($binary));
                echo $binary;
                exit;

            case 'add_admin':
                require_csrf();
                if (!$isSuperAdmin) {
                    json_out(['ok' => false, 'error' => 'دسترسی غیرمجاز است.'], 403);
                }
                $body = read_json_body();
                $fullName = trim(preg_replace('/\s+/u', ' ', (string)($body['full_name'] ?? '')) ?? '');
                $username = normalize_username((string)($body['username'] ?? ''));
                $password = (string)($body['password'] ?? '');
                if ($fullName === '' || u_len($fullName) > 120) {
                    json_out(['ok' => false, 'error' => 'نام و نام خانوادگی را وارد کنید.'], 400);
                }
                if (!preg_match('/^[A-Za-z0-9._-]{3,32}$/', $username)) {
                    json_out(['ok' => false, 'error' => 'نام کاربری باید ۳ تا ۳۲ حرف انگلیسی، عدد یا _ - . باشد.'], 400);
                }
                if (u_len($password) < cfg_int('MIN_STAFF_PASSWORD_LENGTH', 6)) {
                    json_out(['ok' => false, 'error' => 'رمز عبور باید حداقل ۶ کاراکتر باشد.'], 400);
                }
                $check = db()->prepare('SELECT id FROM admins WHERE LOWER(username) = LOWER(?) LIMIT 1');
                $check->execute([$username]);
                if ($check->fetch()) {
                    json_out(['ok' => false, 'error' => 'این نام کاربری قبلاً ثبت شده است.'], 409);
                }
                $stmt = db()->prepare('INSERT INTO admins (username, password_hash, full_name, role, created_at) VALUES (?, ?, ?, \'delivery_admin\', ?)');
                $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT), $fullName, date('Y-m-d H:i:s')]);
                json_out(['ok' => true, 'admins' => admins_payload()]);

            case 'delete_admin':
                require_csrf();
                if (!$isSuperAdmin) {
                    json_out(['ok' => false, 'error' => 'دسترسی غیرمجاز است.'], 403);
                }
                $body = read_json_body();
                $stmt = db()->prepare('DELETE FROM admins WHERE id = ? AND role <> \'super_admin\'');
                $stmt->execute([(int)($body['id'] ?? 0)]);
                json_out(['ok' => true, 'admins' => admins_payload()]);

            case 'change_password':
                require_csrf();
                if ($currentAdmin === null) {
                    json_out(['ok' => false, 'error' => 'ابتدا وارد سامانه شوید.'], 403);
                }
                $body = read_json_body();
                $current = (string)($body['current_password'] ?? '');
                $next = (string)($body['new_password'] ?? '');
                $confirm = (string)($body['confirm_password'] ?? '');
                if (u_len($next) < cfg_int('MIN_PASSWORD_LENGTH', 5)) {
                    json_out(['ok' => false, 'error' => 'رمز عبور جدید باید حداقل ۶ کاراکتر باشد.'], 400);
                }
                if ($next !== $confirm) {
                    json_out(['ok' => false, 'error' => 'تکرار رمز عبور مطابقت ندارد.'], 400);
                }
                $stmt = db()->prepare('SELECT password_hash FROM admins WHERE id = ?');
                $stmt->execute([(int)$currentAdmin['id']]);
                $hash = (string)($stmt->fetchColumn() ?: '');
                if (!password_verify($current, $hash)) {
                    json_out(['ok' => false, 'error' => 'رمز عبور فعلی نادرست است.'], 403);
                }
                $upd = db()->prepare('UPDATE admins SET password_hash = ? WHERE id = ?');
                $upd->execute([password_hash($next, PASSWORD_DEFAULT), (int)$currentAdmin['id']]);
                $envUpdated = env_set('ADMIN_PASSWORD', $next);
                if ($envUpdated) {
                    set_setting('admin_env_fingerprint', admin_fingerprint(
                        cfg('ADMIN_USERNAME', 'admin'),
                        $next,
                        cfg('ADMIN_NAME', 'مدیر سیستم')
                    ));
                }
                json_out(['ok' => true, 'env_updated' => $envUpdated]);

            default:
                json_out(['ok' => false, 'error' => 'درخواست نامعتبر است.'], 404);
        }
    } catch (Throwable $e) {
        json_out(['ok' => false, 'error' => $e->getMessage()], 400);
    }
}

$bootstrap = [
    'csrf' => (string)$_SESSION['csrf_token'],
    'authenticated' => $currentAdmin !== null,
    'admin' => $currentAdmin !== null ? [
        'id' => (int)$currentAdmin['id'],
        'username' => (string)$currentAdmin['username'],
        'full_name' => (string)$currentAdmin['full_name'],
        'role' => (string)$currentAdmin['role'],
    ] : null,
    'stats' => $currentAdmin !== null ? stats_for((int)$currentAdmin['id']) : null,
    'attendees' => $isSuperAdmin ? attendees_payload() : [],
    'admins' => $isSuperAdmin ? admins_payload() : [],
    'recent_scans' => $currentAdmin !== null ? recent_scans_payload($isSuperAdmin, (int)$currentAdmin['id']) : [],
];

function e(string $value): string {
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function first_letter(string $value): string {
    $value = trim($value);
    if ($value === '') {
        return '؟';
    }
    if (preg_match('/^./u', $value, $match)) {
        return $match[0];
    }
    return substr($value, 0, 1);
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="csrf-token" content="<?= e((string)$_SESSION['csrf_token']) ?>">
    <meta name="referrer" content="strict-origin-when-cross-origin">
    <meta name="robots" content="noindex, nofollow">
    <title><?= e(app_title()) ?></title>
    <link rel="icon" href="data:,">
    <link rel="preload" href="assets/fonts/IRANSansFaNum.woff" as="font" type="font/woff" crossorigin>
    <link rel="stylesheet" href="assets/app.css">
    <link rel="stylesheet" href="assets/theme.css">
    <script src="assets/app.js" defer></script>
</head>
<body class="min-h-screen flex flex-col bg-slate-50 text-slate-900">

<div id="toastContainer" class="fixed top-4 left-4 right-4 sm:left-auto sm:right-6 sm:w-80 z-50 flex flex-col gap-2 pointer-events-none no-print"></div>

<?php if ($currentAdmin === null): ?>
    <div id="loginScreen" class="min-h-screen w-full flex items-center justify-center p-4 bg-slate-100/70">
        <div class="bg-white border border-slate-200 rounded-2xl max-w-sm w-full p-6 sm:p-7 shadow-sm space-y-4">
            <div class="text-center space-y-1">
                <div class="w-12 h-12 rounded-xl bg-emerald-600 flex items-center justify-center text-white font-bold text-lg mx-auto shadow-sm">QR</div>
                <h2 class="text-base font-bold text-slate-900 pt-1"><?= e(app_title()) ?></h2>
                <p class="text-xs text-slate-500">جهت دسترسی به سیستم، لطفاً وارد حساب خود شوید.</p>
            </div>
            <form id="mainLoginForm" class="space-y-3 pt-1" novalidate>
                <div>
                    <label for="loginUsername" class="block text-xs text-slate-700 mb-1 font-medium">نام کاربری:</label>
                    <input type="text" id="loginUsername" required dir="ltr" autocomplete="username" autocapitalize="none" spellcheck="false" class="w-full bg-slate-50 border border-slate-200 focus:border-emerald-500 rounded-lg px-3 py-2 text-xs outline-none">
                </div>
                <div>
                    <label for="loginPassword" class="block text-xs text-slate-700 mb-1 font-medium">رمز عبور:</label>
                    <input type="password" id="loginPassword" required dir="ltr" autocomplete="current-password" class="w-full bg-slate-50 border border-slate-200 focus:border-emerald-500 rounded-lg px-3 py-2 text-xs outline-none">
                </div>
                <p id="loginError" class="hidden text-xs text-rose-600 bg-rose-50 border border-rose-200 rounded-lg px-3 py-2"></p>
                <button type="submit" id="loginSubmitBtn" class="w-full py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold shadow-sm transition">
                    ورود به سامانه
                </button>
            </form>
        </div>
    </div>
<?php else: ?>
    <div id="appContainer" class="min-h-screen flex flex-col">
        <header class="bg-white border-b border-slate-200 sticky top-0 z-40 no-print">
            <div class="max-w-5xl mx-auto px-3 sm:px-4 py-2.5 sm:py-3 flex items-center justify-between gap-2">
                <div class="flex items-center gap-2 sm:gap-2.5 min-w-0">
                    <div class="w-9 h-9 shrink-0 rounded-lg bg-emerald-600 flex items-center justify-center text-white font-bold text-sm">QR</div>
                    <div class="min-w-0">
                        <h1 class="font-bold text-sm sm:text-base text-slate-900 leading-5 truncate">سامانه تحویل غذا</h1>
                        <p class="hidden sm:block text-xs text-slate-500 leading-4 truncate">کنترل سهمیه غذا و صدور ژتون</p>
                    </div>
                </div>
                <div class="relative shrink-0">
                    <button type="button" id="btnUserMenu" aria-haspopup="true" aria-expanded="false" class="flex items-center gap-1.5 sm:gap-2 bg-slate-100 border border-slate-200 rounded-full pl-1.5 sm:pl-2 pr-2.5 sm:pr-3 py-1 sm:py-1.5 text-xs max-w-[42vw] sm:max-w-none">
                        <span id="userAvatar" class="w-6 h-6 shrink-0 rounded-full bg-emerald-600 text-white flex items-center justify-center text-[11px] font-bold"><?= e(first_letter((string)$currentAdmin['full_name'])) ?></span>
                        <span id="currentUserName" class="font-semibold text-slate-800 truncate"><?= e((string)$currentAdmin['full_name']) ?></span>
                        <svg class="w-3.5 h-3.5 shrink-0 text-slate-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.17l3.71-3.94a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd"/></svg>
                    </button>
                    <div id="userMenu" class="hidden absolute left-0 mt-2 w-60 max-w-[80vw] bg-white border border-slate-200 rounded-xl shadow-lg p-1.5 z-50">
                        <div class="px-2.5 py-2 mb-1 border-b border-slate-100">
                            <p class="text-xs font-bold text-slate-800 truncate"><?= e((string)$currentAdmin['full_name']) ?></p>
                            <span id="currentUserRoleTag" class="mt-1 inline-block text-[11px] px-1.5 py-0.5 rounded font-medium <?= $isSuperAdmin ? 'bg-emerald-100 text-emerald-800' : 'bg-amber-100 text-amber-800' ?>"><?= $isSuperAdmin ? 'ادمین اصلی' : 'ادمین تحویل (فقط اسکنر)' ?></span>
                        </div>
                        <button type="button" id="btnChangePassword" class="w-full text-right px-2.5 py-2 rounded-lg text-xs font-medium text-slate-700 hover:bg-slate-100 transition">تغییر رمز عبور</button>
                        <button type="button" id="btnLogout" class="w-full text-right px-2.5 py-2 rounded-lg text-xs font-medium text-rose-600 hover:bg-rose-50 transition">خروج از حساب</button>
                    </div>
                </div>
            </div>
            <nav class="max-w-5xl mx-auto px-1 sm:px-4 flex gap-1 border-t border-slate-100 overflow-x-auto no-scrollbar" id="mainNavTabs">
                <button type="button" id="tabBtn_scanner" data-tab="scanner" class="tab-btn px-3 sm:px-4 py-2 sm:py-2.5 text-xs sm:text-sm font-semibold border-b-2 border-emerald-600 text-emerald-700 whitespace-nowrap transition"><span class="sm:hidden">اسکنر</span><span class="hidden sm:inline">اسکنر تحویل غذا</span></button>
                <?php if ($isSuperAdmin): ?>
                    <button type="button" id="tabBtn_attendees" data-tab="attendees" class="tab-btn px-3 sm:px-4 py-2 sm:py-2.5 text-xs sm:text-sm font-medium border-b-2 border-transparent text-slate-600 hover:text-slate-900 whitespace-nowrap transition"><span class="sm:hidden">شرکت‌کنندگان</span><span class="hidden sm:inline">شرکت‌کنندگان و اکسل</span></button>
                    <button type="button" id="tabBtn_badges" data-tab="badges" class="tab-btn px-3 sm:px-4 py-2 sm:py-2.5 text-xs sm:text-sm font-medium border-b-2 border-transparent text-slate-600 hover:text-slate-900 whitespace-nowrap transition"><span class="sm:hidden">ژتون‌ها</span><span class="hidden sm:inline">چاپ ژتون و خروجی ZIP</span></button>
                    <button type="button" id="tabBtn_admins" data-tab="admins" class="tab-btn px-3 sm:px-4 py-2 sm:py-2.5 text-xs sm:text-sm font-medium border-b-2 border-transparent text-slate-600 hover:text-slate-900 whitespace-nowrap transition"><span class="sm:hidden">ادمین‌ها</span><span class="hidden sm:inline">ادمین‌های تحویل غذا</span></button>
                <?php endif; ?>
            </nav>
        </header>

        <main class="flex-1 max-w-5xl w-full mx-auto p-4 sm:p-6 no-print">

            <section id="section_scanner" class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-12 gap-4 items-start">
                    <div class="md:col-span-5 bg-white border border-slate-200 rounded-xl p-4 shadow-sm space-y-3">
                        <div class="flex items-center justify-between">
                            <span class="text-xs font-bold text-slate-700">دوربین اسکنر موبایل</span>
                            <button type="button" id="btnSoundToggle" class="text-xs text-slate-600 hover:text-slate-900 flex items-center gap-1">
                                <span id="soundIcon">🔊</span>
                                <span id="soundLabel">صدا: فعال</span>
                            </button>
                        </div>

                        <div class="relative w-full aspect-square max-h-64 mx-auto rounded-lg overflow-hidden bg-slate-100 border border-slate-200 flex flex-col items-center justify-center">
                            <video id="qrVideo" class="w-full h-full object-cover hidden" playsinline muted></video>
                            <canvas id="qrScanCanvas" class="hidden"></canvas>
                            <div id="scannerOverlayFrame" class="absolute inset-6 border border-emerald-500 rounded-lg pointer-events-none hidden">
                                <div class="laser-line"></div>
                            </div>
                            <div id="cameraPlaceholder" class="text-center p-4 space-y-2">
                                <div class="w-12 h-12 rounded-full bg-slate-200 flex items-center justify-center text-xl mx-auto text-slate-500">📷</div>
                                <p class="text-xs text-slate-500">کارت را مقابل دوربین بگیرید</p>
                                <button type="button" id="btnStartCamera" class="px-3.5 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-medium text-xs shadow-sm transition">روشن کردن دوربین</button>
                            </div>
                        </div>

                        <div id="cameraActiveControls" class="hidden flex gap-2">
                            <button type="button" id="btnStopCamera" class="flex-1 py-1.5 rounded-lg border border-slate-300 text-xs text-slate-700 hover:bg-slate-100">خاموش کردن دوربین</button>
                            <button type="button" id="btnSwitchCamera" class="px-3 py-1.5 rounded-lg border border-slate-300 text-xs text-slate-700 hover:bg-slate-100">چرخش لنز</button>
                        </div>

                        <form id="manualScanForm" class="pt-2 border-t border-slate-100 flex gap-2">
                            <input type="text" id="manualCodeInput" placeholder="لینک ژتون یا شناسه کاربر | امضا" dir="ltr" autocomplete="off" class="flex-1 bg-slate-50 border border-slate-200 focus:border-emerald-500 rounded-lg px-2.5 py-1.5 text-xs outline-none">
                            <button type="submit" class="px-3 py-1.5 rounded-lg bg-slate-800 hover:bg-slate-700 text-white text-xs font-medium">ثبت</button>
                        </form>

                        <div class="pt-2 border-t border-slate-100 space-y-2">
                            <button type="button" id="btnToggleManualDeliver" class="w-full py-1.5 rounded-lg border border-amber-300 text-amber-800 hover:bg-amber-50 text-xs font-medium transition">تحویل دستی (بدون ژتون)</button>
                            <div id="manualDeliverPanel" class="hidden space-y-2">
                                <input type="text" id="manualDeliverSearch" placeholder="جستجوی نام فرد..." autocomplete="off" class="w-full bg-slate-50 border border-slate-200 focus:border-amber-500 rounded-lg px-2.5 py-1.5 text-xs outline-none">
                                <div id="manualDeliverResults" class="max-h-44 overflow-y-auto space-y-1 pr-1"></div>
                            </div>
                        </div>
                    </div>

                    <div class="md:col-span-7 space-y-4">
                        <div id="scanResultBox" class="bg-white border border-slate-200 rounded-xl p-5 shadow-sm transition">
                            <div class="flex items-start gap-3">
                                <div id="scanResultIcon" class="w-12 h-12 rounded-xl bg-slate-100 flex items-center justify-center text-2xl shrink-0">📡</div>
                                <div class="flex-1">
                                    <div id="scanResultBadge" class="inline-block text-[11px] font-semibold text-slate-500 mb-1">آخرین نتیجه</div>
                                    <h3 id="scanResultTitle" class="text-base font-bold text-slate-800">در انتظار اسکن ژتون…</h3>
                                    <p id="scanResultSubtitle" class="text-xs text-slate-500 mt-1">نتیجهٔ آخرین اسکن همین‌جا نمایش داده می‌شود.</p>
                                    <div id="scanResultDetailsGrid" class="hidden mt-4 pt-3 border-t border-slate-200 grid grid-cols-1 sm:grid-cols-2 gap-2 text-xs">
                                        <div class="bg-white/80 p-2.5 rounded border border-inherit">
                                            <span class="text-slate-500 block text-[11px]">نام شخص:</span>
                                            <strong id="scanDetailAttendee" class="text-slate-900 mt-0.5 block text-sm">---</strong>
                                        </div>
                                        <div class="bg-white/80 p-2.5 rounded border border-inherit">
                                            <span id="scanDetailAdminLabel" class="text-slate-500 block text-[11px]">تحویل داده شده توسط:</span>
                                            <strong id="scanDetailAdminName" class="text-slate-900 mt-0.5 block">---</strong>
                                            <span id="scanDetailTime" class="text-slate-500 text-[11px] block mt-0.5" dir="ltr">---</span>
                                        </div>
                                        <div class="bg-white/80 p-2.5 rounded border border-inherit">
                                            <span class="text-slate-500 block text-[11px]">شناسه کاربر:</span>
                                            <strong id="scanDetailUserId" class="text-slate-900 mt-0.5 block text-sm" dir="ltr">---</strong>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-4 gap-2">
                            <div class="bg-white border border-slate-200 rounded-xl p-2.5 shadow-sm">
                                <span class="text-[11px] text-slate-500">کل افراد</span>
                                <div class="text-base sm:text-xl font-bold text-slate-800 mt-0.5" id="statCardTotal">0</div>
                            </div>
                            <div class="bg-white border border-emerald-200 rounded-xl p-2.5 shadow-sm">
                                <span class="text-[11px] text-emerald-700 font-medium">تحویل شده</span>
                                <div class="text-base sm:text-xl font-bold text-emerald-600 mt-0.5" id="statCardDelivered">0</div>
                            </div>
                            <div class="bg-white border border-amber-200 rounded-xl p-2.5 shadow-sm">
                                <span class="text-[11px] text-amber-700 font-medium">باقی‌مانده</span>
                                <div class="text-base sm:text-xl font-bold text-amber-600 mt-0.5" id="statCardRemaining">0</div>
                            </div>
                            <div class="bg-white border border-slate-200 rounded-xl p-2.5 shadow-sm">
                                <span class="text-[11px] text-slate-500">تحویل‌های من</span>
                                <div class="text-base sm:text-xl font-bold text-slate-700 mt-0.5" id="statCardMyCount">0</div>
                            </div>
                        </div>

                        <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm space-y-3">
                            <div class="flex items-center justify-between">
                                <h4 class="text-xs font-bold text-slate-700">اسکن‌های اخیر</h4>
                                <span class="text-[11px] text-slate-400">به‌روزرسانی آنی</span>
                            </div>
                            <div id="recentScansList" class="space-y-1.5 max-h-56 overflow-y-auto pr-1"></div>
                        </div>
                    </div>
                </div>
            </section>

            <?php if ($isSuperAdmin): ?>
            <section id="section_attendees" class="hidden space-y-4">
                <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <h2 class="text-sm font-bold text-slate-800">لیست افراد و آپلود اکسل</h2>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <label for="excelUploadInput" class="cursor-pointer px-3 py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white font-medium text-xs transition shadow-sm">آپلود فایل اکسل (فقط نام افراد)</label>
                            <input type="file" id="excelUploadInput" accept=".xlsx,.xls" class="hidden">
                            <button type="button" id="btnSampleExcel" class="px-3 py-1.5 rounded-lg border border-slate-300 hover:bg-slate-50 text-slate-700 text-xs font-medium transition">دانلود فایل نمونه</button>
                            <button type="button" id="btnOpenDeleteAll" class="px-3 py-1.5 rounded-lg border border-rose-200 text-rose-600 hover:bg-rose-50 text-xs font-medium transition">حذف همه</button>
                        </div>
                    </div>
                    <form id="addAttendeeForm" class="pt-3 border-t border-slate-100 flex gap-2 items-end">
                        <div class="flex-1">
                            <label for="newAttName" class="block text-[11px] text-slate-600 mb-0.5">نام و نام خانوادگی فرد *</label>
                            <input type="text" id="newAttName" required placeholder="مثلاً: علی رضایی" class="w-full bg-slate-50 border border-slate-200 focus:border-emerald-500 rounded-lg px-2.5 py-1.5 text-xs outline-none">
                        </div>
                        <button type="submit" class="py-1.5 px-4 rounded-lg bg-slate-800 hover:bg-slate-700 text-white text-xs font-medium transition">افزودن فرد</button>
                    </form>
                </div>

                <div class="bg-white border border-slate-200 rounded-xl p-4 shadow-sm space-y-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="flex items-center gap-1">
                            <button type="button" id="filterBtn_all" data-filter="all" class="filter-btn px-2.5 py-1 rounded-lg text-xs font-semibold bg-emerald-600 text-white">همه (<span id="countFilterAll">0</span>)</button>
                            <button type="button" id="filterBtn_received" data-filter="received" class="filter-btn px-2.5 py-1 rounded-lg text-xs font-medium text-slate-600 hover:bg-slate-100">تحویل شده (<span id="countFilterReceived">0</span>)</button>
                            <button type="button" id="filterBtn_pending" data-filter="pending" class="filter-btn px-2.5 py-1 rounded-lg text-xs font-medium text-slate-600 hover:bg-slate-100">غذا نگرفته (<span id="countFilterPending">0</span>)</button>
                        </div>
                        <div class="flex items-center gap-2">
                            <input type="text" id="attendeeSearchInput" placeholder="جستجوی نام..." class="bg-slate-50 border border-slate-200 focus:border-emerald-500 rounded-lg px-2.5 py-1 text-xs outline-none w-44">
                            <button type="button" id="btnExportExcel" class="px-2.5 py-1 rounded-lg border border-slate-300 hover:bg-slate-50 text-slate-700 text-xs font-medium">خروجی اکسل</button>
                        </div>
                    </div>
                    <div class="overflow-x-auto rounded-lg border border-slate-200">
                        <table class="w-full text-right text-xs">
                            <thead class="bg-slate-50 text-slate-600 border-b border-slate-200">
                                <tr>
                                    <th class="py-2.5 px-3 font-semibold w-14">ردیف</th>
                                    <th class="py-2.5 px-3 font-semibold">نام و نام خانوادگی</th>
                                    <th class="py-2.5 px-3 font-semibold text-center">وضعیت تحویل</th>
                                    <th class="py-2.5 px-3 font-semibold">تحویل توسط</th>
                                    <th class="py-2.5 px-3 font-semibold">زمان تحویل</th>
                                    <th class="py-2.5 px-3 font-semibold text-center w-28">عملیات</th>
                                </tr>
                            </thead>
                            <tbody id="attendeesTableBody" class="divide-y divide-slate-100"></tbody>
                        </table>
                    </div>
                </div>
            </section>

            <section id="section_badges" class="hidden space-y-4">
                <div id="zipProgressBarBox" class="hidden bg-emerald-50 border border-emerald-200 rounded-xl p-3 space-y-1.5">
                    <div class="flex items-center justify-between text-xs text-emerald-800 font-medium">
                        <span id="zipProgressText">در حال ایجاد فایل ZIP...</span>
                        <span id="zipProgressPercent">0%</span>
                    </div>
                    <div class="w-full h-2 bg-emerald-200 rounded-full overflow-hidden">
                        <div id="zipProgressFill" class="h-full bg-emerald-600 transition-all duration-150 progress-zero"></div>
                    </div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-12 gap-4 items-start">
                    <div class="md:col-span-5 bg-white border border-slate-200 rounded-xl p-4 shadow-sm space-y-3">
                        <h3 class="text-sm font-bold text-slate-800">صدور و چاپ ژتون غذا</h3>
                        <div>
                            <label for="badgeAttendeeSelect" class="block text-xs text-slate-600 mb-1">انتخاب فرد برای پیش‌نمایش تکی:</label>
                            <select id="badgeAttendeeSelect" class="w-full bg-slate-50 border border-slate-200 rounded-lg px-2.5 py-1.5 text-xs outline-none"></select>
                        </div>
                        <div class="pt-2 border-t border-slate-100 space-y-2">
                            <span class="text-xs font-semibold text-slate-700 block">دانلود تکی:</span>
                            <button type="button" id="btnSingleBadge" class="w-full py-1.5 px-3 rounded-lg bg-slate-800 hover:bg-slate-700 text-white text-xs font-medium transition text-center">دانلود تصویر ژتون (PNG)</button>
                        </div>
                        <div class="pt-2 border-t border-slate-100 space-y-2">
                            <span class="text-xs font-semibold text-slate-700 block">خروجی عمده همه افراد به ترتیب:</span>
                            <button type="button" id="btnAllBadgesZip" class="w-full py-2 px-3 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold shadow-sm transition">دانلود فایل ZIP عکس همه ژتون‌ها به ترتیب</button>
                            <button type="button" id="btnPrintBadges" class="w-full py-1.5 px-3 rounded-lg border border-slate-300 hover:bg-slate-50 text-slate-700 text-xs font-medium transition">چاپ مستقیم در برگه A4</button>
                        </div>
                    </div>
                    <div class="md:col-span-7 bg-white border border-slate-200 rounded-xl p-4 shadow-sm flex flex-col items-center">
                        <span class="text-xs font-bold text-slate-700 mb-3" id="badgePreviewIndexTag">پیش‌نمایش ژتون غذا</span>
                        <canvas id="singleBadgeCanvas" width="720" height="960" class="w-full max-w-[280px] h-auto border border-slate-200 rounded-lg shadow-sm"></canvas>
                    </div>
                </div>
            </section>

            <section id="section_admins" class="hidden space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-12 gap-4 items-start">
                    <div class="md:col-span-5 bg-white border border-slate-200 rounded-xl p-4 shadow-sm space-y-3">
                        <h3 class="text-sm font-bold text-slate-800">تعریف ادمین تحویل غذا</h3>
                        <form id="addAdminForm" class="space-y-2.5">
                            <div>
                                <label for="newAdminFullName" class="block text-xs text-slate-600 mb-0.5">نام و نام خانوادگی *</label>
                                <input type="text" id="newAdminFullName" required placeholder="مثلاً: علی رضایی" class="w-full bg-slate-50 border border-slate-200 focus:border-emerald-500 rounded-lg px-2.5 py-1.5 text-xs outline-none">
                            </div>
                            <div>
                                <label for="newAdminUsername" class="block text-xs text-slate-600 mb-0.5">نام کاربری *</label>
                                <input type="text" id="newAdminUsername" required placeholder="staff1" dir="ltr" autocapitalize="none" spellcheck="false" class="w-full bg-slate-50 border border-slate-200 focus:border-emerald-500 rounded-lg px-2.5 py-1.5 text-xs outline-none">
                            </div>
                            <div>
                                <label for="newAdminPassword" class="block text-xs text-slate-600 mb-0.5">رمز عبور *</label>
                                <input type="password" id="newAdminPassword" required minlength="6" placeholder="حداقل ۶ کاراکتر" dir="ltr" autocomplete="new-password" class="w-full bg-slate-50 border border-slate-200 focus:border-emerald-500 rounded-lg px-2.5 py-1.5 text-xs outline-none">
                            </div>
                            <button type="submit" class="w-full py-1.5 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition">ثبت ادمین تحویل</button>
                        </form>
                    </div>
                    <div class="md:col-span-7 bg-white border border-slate-200 rounded-xl p-4 shadow-sm space-y-3">
                        <h3 class="text-sm font-bold text-slate-800">لیست ادمین‌های تحویل و آمار ثبت</h3>
                        <div id="adminsListContainer" class="space-y-2"></div>
                    </div>
                </div>
            </section>
            <?php endif; ?>

        </main>
    </div>

    <div id="deleteAllModal" class="fixed inset-0 bg-slate-900/40 z-50 hidden flex items-center justify-center p-4 no-print">
        <div class="bg-white border border-slate-200 rounded-xl max-w-sm w-full p-5 shadow-lg space-y-3">
            <h3 class="text-sm font-bold text-slate-800">حذف یا بازنشانی لیست</h3>
            <p class="text-xs text-slate-600">عملیات مورد نظر خود را انتخاب کنید:</p>
            <div class="space-y-2 pt-1">
                <button type="button" id="btnDeleteAllAttendees" class="w-full py-2 rounded-lg bg-rose-600 hover:bg-rose-700 text-white text-xs font-semibold transition">حذف تمام افراد</button>
                <button type="button" id="btnResetFoodStatuses" class="w-full py-1.5 rounded-lg border border-slate-300 text-slate-700 hover:bg-slate-50 text-xs font-medium transition">فقط صفر کردن وضعیت تحویل غذا</button>
                <button type="button" id="btnCloseDeleteAll" class="w-full py-1.5 rounded-lg text-slate-500 hover:bg-slate-100 text-xs transition">انصراف</button>
            </div>
        </div>
    </div>

    <div id="passwordModal" class="fixed inset-0 bg-slate-900/40 z-50 hidden flex items-center justify-center p-4 no-print">
        <div class="bg-white border border-slate-200 rounded-xl max-w-sm w-full p-5 shadow-lg space-y-3">
            <h3 class="text-sm font-bold text-slate-800">تغییر رمز عبور</h3>
            <form id="changePasswordForm" class="space-y-2.5">
                <div>
                    <label for="currentPasswordInput" class="block text-xs text-slate-600 mb-0.5">رمز عبور فعلی</label>
                    <input type="password" id="currentPasswordInput" required dir="ltr" autocomplete="current-password" class="w-full bg-slate-50 border border-slate-200 focus:border-emerald-500 rounded-lg px-2.5 py-1.5 text-xs outline-none">
                </div>
                <div>
                    <label for="newPasswordInput" class="block text-xs text-slate-600 mb-0.5">رمز عبور جدید</label>
                    <input type="password" id="newPasswordInput" required minlength="6" dir="ltr" autocomplete="new-password" class="w-full bg-slate-50 border border-slate-200 focus:border-emerald-500 rounded-lg px-2.5 py-1.5 text-xs outline-none">
                </div>
                <div>
                    <label for="confirmPasswordInput" class="block text-xs text-slate-600 mb-0.5">تکرار رمز عبور جدید</label>
                    <input type="password" id="confirmPasswordInput" required minlength="6" dir="ltr" autocomplete="new-password" class="w-full bg-slate-50 border border-slate-200 focus:border-emerald-500 rounded-lg px-2.5 py-1.5 text-xs outline-none">
                </div>
                <p id="passwordError" class="hidden text-xs text-rose-600 bg-rose-50 border border-rose-200 rounded-lg px-3 py-2"></p>
                <div class="flex gap-2 pt-1">
                    <button type="submit" class="flex-1 py-2 rounded-lg bg-emerald-600 hover:bg-emerald-700 text-white text-xs font-semibold transition">ثبت رمز جدید</button>
                    <button type="button" id="btnClosePasswordModal" class="px-4 py-2 rounded-lg border border-slate-300 text-slate-700 text-xs font-medium">انصراف</button>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<div id="printSheetContainer" class="hidden"></div>
<script id="bootstrapData" type="application/json"><?= json_encode($bootstrap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
</body>
</html>

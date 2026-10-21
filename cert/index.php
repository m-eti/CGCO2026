<?php
/**
 * Conference certificate & proceedings download portal.
 */

session_start();

// --- Configuration ---
header("X-Frame-Options: SAMEORIGIN");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");

define('EXCEL_FILE', __DIR__ . '/participants.xlsx');
define('ARTICLES_FILE', __DIR__ . '/articles.zip');
define('CUSTOM_FONT_PATH', 'assets/custom-font.ttf');
define('CUSTOM_TEMPLATE_PATH', 'assets/certificate-template.png');
define('SESSION_TTL', 1800);             // اعتبار نشست کاربر: ۳۰ دقیقه
define('MAX_ATTEMPTS', 7);              // حداکثر تلاش ناموفق در هر بازه ۱۰ دقیقه‌ای

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['attempts'])) {
    $_SESSION['attempts'] = 0;
    $_SESSION['last_attempt_time'] = time();
}

if (time() - $_SESSION['last_attempt_time'] > 600) {
    $_SESSION['attempts'] = 0;
}

// --- Helpers ---
function normalizeDigits(string $input): string {
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $arabic  = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    $input = str_replace($persian, $english, $input);
    $input = str_replace($arabic, $english, $input);
    return preg_replace('/[^0-9]/', '', trim($input));
}

// Normalizes phone input: 09123456789 / 9123456789 / +989123456789 -> 09123456789
function normalizePhone(string $input): string {
    $digits = normalizeDigits($input);

    if (strlen($digits) === 12 && str_starts_with($digits, '98')) {
        $digits = substr($digits, 2);
    }
    if (strlen($digits) === 10 && str_starts_with($digits, '9')) {
        $digits = '0' . $digits;
    }
    return $digits;
}

function columnIndex(string $column): int {
    $index = 0;
    $length = strlen($column);
    for ($i = 0; $i < $length; $i++) {
        $index = $index * 26 + (ord($column[$i]) - 64);
    }
    return $index - 1;
}

function readXlsxFile(string $filePath): array {
    if (!file_exists($filePath)) {
        return [];
    }

    $zip = new ZipArchive();
    if ($zip->open($filePath) !== true) {
        return [];
    }

    $sharedStrings = [];
    if (($sharedXml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $xml = simplexml_load_string($sharedXml);
        if ($xml && isset($xml->si)) {
            foreach ($xml->si as $val) {
                if (isset($val->t)) {
                    $sharedStrings[] = (string)$val->t;
                } elseif (isset($val->r)) {
                    $text = '';
                    foreach ($val->r as $run) {
                        $text .= (string)$run->t;
                    }
                    $sharedStrings[] = $text;
                } else {
                    $sharedStrings[] = '';
                }
            }
        }
    }

    $rows = [];
    if (($sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml')) !== false) {
        $xml = simplexml_load_string($sheetXml);
        if ($xml && isset($xml->sheetData->row)) {
            foreach ($xml->sheetData->row as $row) {
                $rowData = [];
                foreach ($row->c as $cell) {
                    $attr = $cell->attributes();
                    $ref  = isset($attr['r']) ? (string)$attr['r'] : '';
                    $type = isset($attr['t']) ? (string)$attr['t'] : '';
                    $value = '';

                    if ($type === 's') {
                        $idx = (int)$cell->v;
                        $value = $sharedStrings[$idx] ?? '';
                    } elseif ($type === 'inlineStr') {
                        $value = isset($cell->is->t) ? (string)$cell->is->t : '';
                    } else {
                        $value = isset($cell->v) ? (string)$cell->v : '';
                    }

                    $colIndex = ($ref !== '' && preg_match('/^([A-Z]+)/', $ref, $m))
                        ? columnIndex($m[1])
                        : count($rowData);

                    $rowData[$colIndex] = trim($value);
                }
                if (!empty(array_filter($rowData, static fn($v) => $v !== '' && $v !== null))) {
                    ksort($rowData);
                    $rows[] = $rowData;
                }
            }
        }
    }

    $zip->close();
    return $rows;
}

// Maps xlsx columns by their header titles.
function detectColumnMap(array $header): array {
    $default = ['phone' => 0, 'name' => 1, 'paper_title' => 2, 'cert_code' => 3];
    $found = ['phone' => null, 'name' => null, 'paper_title' => null, 'cert_code' => null];

    $titles = [];
    foreach ($header as $i => $title) {
        $title = str_replace(['ي', 'ك'], ['ی', 'ک'], (string)$title);
        $titles[$i] = trim(preg_replace('/\s+/u', ' ', $title));
    }

    foreach ($titles as $i => $title) {
        if ($found['phone'] === null && strpos($title, 'شماره') !== false) {
            $found['phone'] = $i;
            continue;
        }
        if ($found['cert_code'] === null && (strpos($title, 'گواهی') !== false || strpos($title, 'شناسه') !== false)) {
            $found['cert_code'] = $i;
            continue;
        }
        if ($found['paper_title'] === null && (strpos($title, 'عنوان') !== false || strpos($title, 'مقاله') !== false)) {
            $found['paper_title'] = $i;
            continue;
        }
        if ($found['name'] === null && strpos($title, 'نام') !== false) {
            $found['name'] = $i;
            continue;
        }
    }

    foreach ($default as $key => $position) {
        if ($found[$key] === null) {
            $found[$key] = $position;
        }
    }

    return $found;
}

// Reads the participants list from the xlsx file.
function loadParticipants(): array {
    $rows = readXlsxFile(EXCEL_FILE);
    if (empty($rows)) {
        return [];
    }

    $header = array_shift($rows);
    $map = detectColumnMap($header);

    $participants = [];
    $fallbackIndex = 0;

    foreach ($rows as $row) {
        $fallbackIndex++;

        $phone = normalizePhone((string)($row[$map['phone']] ?? ''));
        if ($phone === '') {
            continue;
        }

        $certCode = trim((string)($row[$map['cert_code']] ?? ''));
        if ($certCode === '') {
            $certCode = 'ICCG-2026-' . (100 + $fallbackIndex);
        }

        $participants[] = [
            'phone'       => $phone,
            'name'        => trim((string)($row[$map['name']] ?? '')),
            'paper_title' => trim((string)($row[$map['paper_title']] ?? '')),
            'cert_code'   => $certCode,
        ];
    }

    return $participants;
}

function findParticipantByPhone(array $participants, string $phone): ?array {
    foreach ($participants as $participant) {
        if ($participant['phone'] === $phone) {
            return $participant;
        }
    }
    return null;
}

// Returns the signed-in participant while the session is valid.
function currentParticipant(): ?array {
    if (empty($_SESSION['participant']['phone'])) {
        return null;
    }
    if ((time() - (int)($_SESSION['participant']['time'] ?? 0)) > SESSION_TTL) {
        unset($_SESSION['participant']);
        return null;
    }
    return $_SESSION['participant'];
}

function currentPath(): string {
    $path = strtok((string)($_SERVER['REQUEST_URI'] ?? 'index.php'), '?');
    if (!preg_match('#^[A-Za-z0-9_\-./]*$#', (string)$path) || $path === '') {
        return 'index.php';
    }
    return (string)$path;
}

function redirectToSelf(): void {
    header('Location: ' . currentPath(), true, 303);
    exit;
}

// --- Request handling ---
$error       = '';
$postedPhone = '';
$action      = (string)($_POST['action'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'نشست امنیتی نامعتبر است. لطفاً صفحه را تازه‌سازی نموده و دوباره تلاش کنید.';
    } elseif ($action === 'logout') {
        unset($_SESSION['participant']);
        $_SESSION['attempts'] = 0;
        $_SESSION['last_attempt_time'] = time();
        redirectToSelf();
    } elseif ($action === 'login') {
        $postedPhone = normalizePhone((string)($_POST['phone'] ?? ''));

        if ($_SESSION['attempts'] >= MAX_ATTEMPTS) {
            $error = 'تعداد دفعات تلاش بیش از حد مجاز است. لطفاً پس از ۱۰ دقیقه مجدداً اقدام فرمایید.';
        } elseif (!preg_match('/^09[0-9]{9}$/', $postedPhone)) {
            $error = 'لطفاً شماره تلفن همراه معتبر (۱۱ رقم با پیش‌شماره 09) وارد نمایید.';
        } else {
            $participants = loadParticipants();

            if (empty($participants)) {
                $error = 'پایگاه داده مشارکت‌کنندگان موقتاً در دسترس نیست یا فایل اطلاعات یافت نشد.';
            } else {
                $found = findParticipantByPhone($participants, $postedPhone);

                if (!$found) {
                    $_SESSION['attempts']++;
                    $_SESSION['last_attempt_time'] = time();
                    $error = 'شماره تلفن همراه وارد شده در فهرست شرکت‌کنندگان یافت نشد.';
                } else {
                    $_SESSION['attempts'] = 0;
                    $_SESSION['last_attempt_time'] = time();
                    $_SESSION['participant'] = $found + ['time' => time()];
                    redirectToSelf();
                }
            }
        }
    }
}

$participant = currentParticipant();

// Active view: options menu, certificate or articles.
$allowedViews = ['', 'certificate', 'articles'];
$requestedView = (string)($_GET['view'] ?? '');
$view = in_array($requestedView, $allowedViews, true) ? $requestedView : '';

$hasCustomTemplate = file_exists(__DIR__ . '/' . CUSTOM_TEMPLATE_PATH);
$hasCustomFont     = file_exists(__DIR__ . '/' . CUSTOM_FONT_PATH);
$articlesAvailable = file_exists(ARTICLES_FILE);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>سامانه دریافت گواهی و مقالات | کنفرانس بین‌المللی بازی‌های رایانه‌ای</title>
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet" type="text/css" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <style>
        @font-face {
            font-family: 'CertificateCustomFont';
            src: url('<?= htmlspecialchars(CUSTOM_FONT_PATH) ?>') format('truetype'),
                 local('Vazirmatn');
            font-weight: bold;
        }

        :root {
            --bg-page: #f8fafc;
            --surface: #ffffff;
            --primary: #0f172a;
            --accent: #1e3a8a;
            --text-primary: #1e293b;
            --text-secondary: #475569;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --border-focus: #3b82f6;
            --danger-bg: #fef2f2;
            --danger-text: #991b1b;
            --danger-border: #fecaca;
            --success-bg: #ecfdf5;
            --success-text: #065f46;
            --success-border: #a7f3d0;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Vazirmatn', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        }

        body {
            background-color: var(--bg-page);
            color: var(--text-primary);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 32px 16px;
        }

        .page {
            width: 100%;
            max-width: 1080px;
            display: flex;
            flex-direction: column;
            gap: 18px;
        }

        .card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
            padding: 28px 30px;
        }

        .header-section {
            text-align: center;
            border-bottom: 1px solid var(--border);
            padding-bottom: 20px;
        }

        .sub-title-badge {
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--accent);
            letter-spacing: 0.5px;
            margin-bottom: 6px;
            text-transform: uppercase;
        }

        .header-section h1 {
            font-size: 1.35rem;
            font-weight: 800;
            color: var(--primary);
            line-height: 1.6;
            margin-bottom: 6px;
        }

        .header-section p {
            color: var(--text-secondary);
            font-size: 0.88rem;
            line-height: 1.5;
        }

        /* Login */
        .login-card {
            max-width: 460px;
            margin: 0 auto;
            width: 100%;
        }

        .welcome-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            padding: 18px 24px;
        }

        .welcome-text span {
            display: block;
            font-size: 0.78rem;
            color: var(--text-muted);
            margin-bottom: 2px;
        }

        .welcome-text strong {
            font-size: 1rem;
            color: var(--primary);
            font-weight: 700;
        }

        .welcome-text small {
            display: block;
            margin-top: 3px;
            font-size: 0.8rem;
            color: var(--text-secondary);
        }

        .btn-logout {
            background: #f8fafc;
            border: 1px solid #cbd5e1;
            color: #1e293b;
            border-radius: 8px;
            padding: 9px 16px;
            font-size: 0.84rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s ease, border-color 0.15s ease;
        }

        .btn-logout:hover {
            background: #e2e8f0;
        }

        .panels {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(330px, 1fr));
            gap: 18px;
            align-items: start;
        }

        .panels.has-certificate {
            grid-template-columns: minmax(0, 1.45fr) minmax(0, 1fr);
        }

        /* Options menu */
        .options-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(290px, 1fr));
            gap: 18px;
        }

        .option-card {
            display: block;
            text-decoration: none;
            color: inherit;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 26px 24px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
            transition: transform 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
        }

        .option-card:hover {
            transform: translateY(-2px);
            border-color: #cbd5e1;
            box-shadow: 0 10px 20px -8px rgba(15, 23, 42, 0.18);
        }

        .option-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 46px;
            height: 46px;
            border-radius: 12px;
            background: #eff6ff;
            margin-bottom: 14px;
        }

        .option-icon svg {
            width: 24px;
            height: 24px;
            stroke: var(--accent);
        }

        .option-card h3 {
            font-size: 1rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 8px;
        }

        .option-card p {
            font-size: 0.84rem;
            color: var(--text-secondary);
            line-height: 1.8;
        }

        .option-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 16px;
            font-size: 0.85rem;
            font-weight: 700;
            color: var(--accent);
        }

        .option-link svg {
            width: 16px;
            height: 16px;
            stroke: currentColor;
        }

        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--text-muted);
            text-decoration: none;
            margin-bottom: 14px;
        }

        .btn-back:hover {
            color: var(--text-primary);
        }

        .btn-back svg {
            width: 16px;
            height: 16px;
            stroke: currentColor;
        }

        .panel-title {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 1rem;
            font-weight: 700;
            color: var(--primary);
            margin-bottom: 6px;
        }

        .panel-title svg {
            flex: 0 0 auto;
            width: 20px;
            height: 20px;
            stroke: var(--accent);
        }

        .panel-desc {
            color: var(--text-secondary);
            font-size: 0.84rem;
            line-height: 1.7;
            margin-bottom: 18px;
        }

        .alert-box {
            background: var(--danger-bg);
            border: 1px solid var(--danger-border);
            color: var(--danger-text);
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 0.85rem;
            margin-bottom: 20px;
            line-height: 1.6;
        }

        .success-box {
            background: var(--success-bg);
            border: 1px solid var(--success-border);
            color: var(--success-text);
            padding: 12px 16px;
            border-radius: 8px;
            font-size: 0.85rem;
            margin-bottom: 20px;
            line-height: 1.7;
        }

        .form-group {
            margin-bottom: 18px;
        }

        .form-group label {
            display: block;
            font-size: 0.84rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 6px;
        }

        .form-control {
            width: 100%;
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 11px 14px;
            font-size: 0.95rem;
            color: var(--text-primary);
            direction: ltr;
            text-align: center;
            letter-spacing: 0.5px;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--border-focus);
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.12);
        }

        .form-control::placeholder {
            color: #94a3b8;
        }

        .form-hint {
            font-size: 0.76rem;
            color: var(--text-muted);
            margin-top: 6px;
            line-height: 1.7;
        }

        .btn-primary {
            width: 100%;
            background: var(--accent);
            color: #ffffff;
            border: none;
            border-radius: 8px;
            padding: 12px;
            font-size: 0.92rem;
            font-weight: 600;
            cursor: pointer;
            transition: background 0.15s ease;
            margin-top: 6px;
        }

        .btn-primary:hover {
            background: #172554;
        }

        .btn-download {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            background: #0f172a;
            color: #ffffff;
            border: none;
            border-radius: 8px;
            padding: 12px;
            font-size: 0.92rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.15s ease;
        }

        .btn-download:hover {
            background: #1e293b;
        }

        .btn-download svg {
            width: 18px;
            height: 18px;
            stroke: currentColor;
        }

        /* Certificate details */
        .info-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 12px;
            background: #f1f5f9;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 14px 18px;
            margin-bottom: 20px;
        }

        .info-cell span {
            display: block;
            font-size: 0.78rem;
            color: var(--text-muted);
            margin-bottom: 2px;
        }

        .info-cell strong {
            font-size: 0.9rem;
            color: var(--text-primary);
            font-weight: 600;
        }

        .canvas-container {
            width: 100%;
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.04);
            margin-bottom: 20px;
        }

        canvas {
            width: 100%;
            height: auto;
            display: block;
        }

        .action-buttons {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }

        .btn-action {
            flex: 1 1 180px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 10px 14px;
            border-radius: 6px;
            font-size: 0.88rem;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: background 0.15s ease, border-color 0.15s ease;
            border: 1px solid transparent;
        }

        .btn-action-pdf {
            background: #0f172a;
            color: #ffffff;
        }
        .btn-action-pdf:hover { background: #1e293b; }

        .btn-action-png {
            background: #f8fafc;
            border-color: #cbd5e1;
            color: #1e293b;
        }
        .btn-action-png:hover { background: #e2e8f0; }

        .btn-action-jpg {
            background: #f8fafc;
            border-color: #cbd5e1;
            color: #1e293b;
        }
        .btn-action-jpg:hover { background: #e2e8f0; }

        .footer-note {
            text-align: center;
            font-size: 0.78rem;
            color: var(--text-muted);
        }

        @media (max-width: 860px) {
            .panels.has-certificate {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 600px) {
            .card { padding: 22px 18px; }
            .header-section h1 { font-size: 1.2rem; }
        }
    </style>
</head>
<body>

<div class="page">

    <div class="card header-section">
        <div class="sub-title-badge">دبیرخانه کنفرانس بین‌المللی بازی‌های رایانه‌ای</div>
        <h1>دریافت الکترونیکی گواهی و مقالات</h1>
        <p>
            <?php if ($participant): ?>
                گزینه‌های در دسترس برای شما فعال شده است.
            <?php else: ?>
                برای ورود به سامانه، شماره تلفن همراه ثبت‌شده خود را وارد فرمایید.
            <?php endif; ?>
        </p>
    </div>

    <?php if (!$participant): ?>

        <!-- Login -->
        <div class="card login-card">
            <h2 class="panel-title">
                <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M12 13a4 4 0 1 0 0-8 4 4 0 0 0 0 8z"></path>
                    <path d="M4 21v-1a6 6 0 0 1 6-6h4a6 6 0 0 1 6 6v1"></path>
                </svg>
                ورود به سامانه
            </h2>
            <p class="panel-desc">پس از ورود، گزینه‌های «دریافت گواهی» و «دریافت مجموعه مقالات» برای شما نمایش داده می‌شود.</p>

            <?php if ($error): ?>
                <div class="alert-box"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
            <?php endif; ?>

            <form method="POST" action="" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="login">

                <div class="form-group">
                    <label for="login_phone">شماره تلفن همراه</label>
                    <input type="tel" id="login_phone" name="phone" class="form-control"
                           placeholder="09120000000" maxlength="13" required autofocus
                           value="<?= htmlspecialchars($postedPhone, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="form-hint">شماره همراهی که هنگام ارسال مقاله ثبت کرده‌اید — نیازی به وارد کردن کد ملی نیست.</div>
                </div>

                <button type="submit" class="btn-primary">ورود</button>
            </form>
        </div>

    <?php else: ?>

        <!-- Signed-in user -->
        <div class="card welcome-bar">
            <div class="welcome-text">
                <span>خوش آمدید</span>
                <strong><?= htmlspecialchars($participant['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                <small dir="ltr"><?= htmlspecialchars($participant['phone'], ENT_QUOTES, 'UTF-8') ?></small>
            </div>
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="logout">
                <button type="submit" class="btn-logout">خروج از سامانه</button>
            </form>
        </div>

        <?php if ($view === ''): ?>

            <!-- Options menu -->
            <div class="options-grid">

                <a class="option-card" href="<?= htmlspecialchars(currentPath() . '?view=certificate', ENT_QUOTES, 'UTF-8') ?>">
                    <span class="option-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <circle cx="12" cy="8" r="6"></circle>
                            <path d="M8.2 13.5 7 22l5-3 5 3-1.2-8.5"></path>
                        </svg>
                    </span>
                    <h3>دریافت گواهی ارائه مقاله</h3>
                    <p>مشاهده و دانلود گواهی الکترونیکی با نام شما در قالب PDF یا تصویر با کیفیت بالا.</p>
                    <span class="option-link">
                        مشاهده و دریافت
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m9 18 6-6-6-6"></path>
                        </svg>
                    </span>
                </a>

                <a class="option-card" href="<?= htmlspecialchars(currentPath() . '?view=articles', ENT_QUOTES, 'UTF-8') ?>">
                    <span class="option-icon">
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M4 4h6l2 3h8v13H4z"></path>
                            <path d="M9 13h6"></path>
                            <path d="M9 17h4"></path>
                        </svg>
                    </span>
                    <h3>دریافت مجموعه مقالات</h3>
                    <p>دانلود یکجای مجموعه کامل مقالات ارائه‌شده در کنفرانس به‌صورت فایل فشرده articles.zip.</p>
                    <span class="option-link">
                        مشاهده و دریافت
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                            <path d="m9 18 6-6-6-6"></path>
                        </svg>
                    </span>
                </a>

            </div>

        <?php elseif ($view === 'certificate'): ?>

            <!-- Certificate -->
            <section class="card">
                <a class="btn-back" href="<?= htmlspecialchars(currentPath(), ENT_QUOTES, 'UTF-8') ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m15 18-6-6 6-6"></path>
                    </svg>
                    بازگشت به گزینه‌ها
                </a>
                <h2 class="panel-title">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="8" r="6"></circle>
                        <path d="M8.2 13.5 7 22l5-3 5 3-1.2-8.5"></path>
                    </svg>
                    دریافت گواهی ارائه مقاله
                </h2>
                <p class="panel-desc">گواهی الکترونیکی ارائه مقاله با نام شما صادر شده است؛ می‌توانید آن را مشاهده و دانلود نمایید.</p>

                <div class="info-grid">
                    <div class="info-cell">
                        <span>پژوهشگر گرامی:</span>
                        <strong><?= htmlspecialchars($participant['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                    <div class="info-cell">
                        <span>شناسه اصالت گواهی:</span>
                        <strong dir="ltr"><?= htmlspecialchars($participant['cert_code'], ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                    <div class="info-cell" style="grid-column: 1 / -1;">
                        <span>عنوان مقاله:</span>
                        <strong><?= htmlspecialchars($participant['paper_title'], ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                </div>

                <div class="canvas-container">
                    <canvas id="certCanvas" width="2000" height="1414"></canvas>
                </div>

                <div class="action-buttons">
                    <button type="button" class="btn-action btn-action-pdf" onclick="downloadPDF()">
                        دانلود گواهی (نسخه چاپی PDF)
                    </button>
                    <button type="button" class="btn-action btn-action-png" onclick="downloadImage('png')">
                        تصویر با کیفیت بالا (PNG)
                    </button>
                    <button type="button" class="btn-action btn-action-jpg" onclick="downloadImage('jpeg')">
                        تصویر استاندارد (JPG)
                    </button>
                </div>

                <script>
                    const participantData = {
                        name: <?= json_encode($participant['name'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                        paperTitle: <?= json_encode($participant['paper_title'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                        certCode: <?= json_encode($participant['cert_code'], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
                        hasCustomTemplate: <?= json_encode($hasCustomTemplate) ?>,
                        customTemplateUrl: <?= json_encode(CUSTOM_TEMPLATE_PATH) ?>
                    };

                    const canvas = document.getElementById('certCanvas');
                    const ctx = canvas.getContext('2d');

                    function getWrappedLines(context, text, maxWidth) {
                        const words = text.trim().split(/\s+/);
                        const lines = [];
                        let currentLine = '';

                        for (let i = 0; i < words.length; i++) {
                            const word = words[i];
                            const testLine = currentLine ? currentLine + ' ' + word : word;
                            const metrics = context.measureText(testLine);

                            if (metrics.width > maxWidth && currentLine !== '') {
                                lines.push(currentLine);
                                currentLine = word;
                            } else {
                                currentLine = testLine;
                            }
                        }
                        if (currentLine) {
                            lines.push(currentLine);
                        }
                        return lines;
                    }

                    function drawAutoFitSingleLine(context, text, x, y, maxWidth, initialFontSize, minFontSize, fontFamily, fontWeight = 'bold') {
                        let fontSize = initialFontSize;
                        context.font = `${fontWeight} ${fontSize}px ${fontFamily}`;

                        while (context.measureText(text).width > maxWidth && fontSize > minFontSize) {
                            fontSize -= 1;
                            context.font = `${fontWeight} ${fontSize}px ${fontFamily}`;
                        }

                        if (context.measureText(text).width > maxWidth) {
                            const lines = getWrappedLines(context, text, maxWidth);
                            const lineHeight = fontSize * 1.35;
                            const startY = y - ((lines.length - 1) * lineHeight) / 2;
                            lines.forEach((line, idx) => {
                                context.fillText(line, x, startY + (idx * lineHeight));
                            });
                        } else {
                            context.fillText(text, x, y);
                        }
                    }

                    function drawAutoFitMultiLine(context, text, x, centerY, maxWidth, maxHeight, initialFontSize, minFontSize, maxLines, fontFamily, fontWeight = 'bold') {
                        let fontSize = initialFontSize;
                        let lines = [];
                        let lineHeight = fontSize * 1.55;

                        while (fontSize >= minFontSize) {
                            context.font = `${fontWeight} ${fontSize}px ${fontFamily}`;
                            lineHeight = fontSize * 1.55;
                            lines = getWrappedLines(context, text, maxWidth);

                            const totalHeight = lines.length * lineHeight;
                            const anyLineOverflow = lines.some(line => context.measureText(line).width > maxWidth);

                            if (lines.length <= maxLines && totalHeight <= maxHeight && !anyLineOverflow) {
                                break;
                            }
                            fontSize -= 1;
                        }

                        const totalBlockHeight = (lines.length - 1) * lineHeight;
                        const startY = centerY - (totalBlockHeight / 2);

                        lines.forEach((line, index) => {
                            context.fillText(line, x, startY + (index * lineHeight));
                        });
                    }

                    // Fallback template when no custom image is available.
                    function drawDefaultTemplate() {
                        ctx.fillStyle = '#fafaf9';
                        ctx.fillRect(0, 0, canvas.width, canvas.height);

                        ctx.strokeStyle = '#1e3a8a';
                        ctx.lineWidth = 6;
                        ctx.strokeRect(60, 60, canvas.width - 120, canvas.height - 120);

                        ctx.strokeStyle = '#94a3b8';
                        ctx.lineWidth = 1.5;
                        ctx.strokeRect(74, 74, canvas.width - 148, canvas.height - 148);

                        ctx.direction = 'rtl';
                        ctx.textAlign = 'center';

                        ctx.fillStyle = '#1e3a8a';
                        ctx.font = 'bold 44px CertificateCustomFont, Vazirmatn';
                        ctx.fillText('کنفرانس بین‌المللی بازی‌های رایانه‌ای', canvas.width / 2, 220);

                        ctx.fillStyle = '#64748b';
                        ctx.font = '600 22px Vazirmatn, sans-serif';
                        ctx.fillText('INTERNATIONAL CONFERENCE ON COMPUTER GAMES (ICCG 2026)', canvas.width / 2, 270);

                        ctx.fillStyle = '#0f172a';
                        ctx.font = '900 58px CertificateCustomFont, Vazirmatn';
                        ctx.fillText('گواهی ارائه مقاله و حضور', canvas.width / 2, 395);

                        ctx.fillStyle = '#475569';
                        ctx.font = '400 32px CertificateCustomFont, Vazirmatn';
                        ctx.fillText('بدین‌وسیله گواهی می‌شود پژوهشگر گرامی جناب آقای / سرکار خانم:', canvas.width / 2, 520);

                        ctx.fillText('مقاله علمی-پژوهشی خود را با عنوان:', canvas.width / 2, 735);

                        ctx.fillStyle = '#64748b';
                        ctx.font = '400 28px CertificateCustomFont, Vazirmatn';
                        ctx.fillText('در این کنفرانس بین‌المللی با موفقیت ارائه نموده است.', canvas.width / 2, 1040);

                        ctx.fillStyle = '#334155';
                        ctx.font = 'bold 26px CertificateCustomFont, Vazirmatn';
                        ctx.fillText('دبیر علمی کنفرانس', 520, 1200);
                        ctx.fillText('رئیس کنفرانس', canvas.width - 520, 1200);
                    }

                    function writeParticipantText() {
                        ctx.direction = 'rtl';
                        ctx.textAlign = 'center';
                        ctx.textBaseline = 'middle';

                        const fontStack = 'CertificateCustomFont, Vazirmatn';

                        // Participant name
                        ctx.fillStyle = '#1e3a8a';
                        drawAutoFitSingleLine(
                            ctx,
                            participantData.name,
                            canvas.width / 2,
                            625,
                            1500,
                            56,
                            26,
                            fontStack,
                            'bold'
                        );

                        // Paper title
                        ctx.fillStyle = '#0f172a';
                        drawAutoFitMultiLine(
                            ctx,
                            '« ' + participantData.paperTitle + ' »',
                            canvas.width / 2,
                            875,
                            1450,
                            200,
                            42,
                            22,
                            3,
                            fontStack,
                            'bold'
                        );

                        // Certificate ID
                        ctx.direction = 'ltr';
                        ctx.fillStyle = '#64748b';
                        ctx.font = '600 22px monospace';
                        ctx.fillText('Certificate ID: ' + participantData.certCode, canvas.width / 2, 1290);
                    }

                    async function renderCertificate() {
                        if (document.fonts && document.fonts.ready) {
                            await document.fonts.ready;
                        }

                        if (participantData.hasCustomTemplate) {
                            const img = new Image();
                            img.onload = function() {
                                ctx.drawImage(img, 0, 0, canvas.width, canvas.height);
                                writeParticipantText();
                            };
                            img.onerror = function() {
                                drawDefaultTemplate();
                                writeParticipantText();
                            };
                            img.src = participantData.customTemplateUrl;
                        } else {
                            drawDefaultTemplate();
                            writeParticipantText();
                        }
                    }

                    function downloadImage(format) {
                        const mime = format === 'jpeg' ? 'image/jpeg' : 'image/png';
                        const ext  = format === 'jpeg' ? 'jpg' : 'png';
                        const dataUrl = canvas.toDataURL(mime, 0.98);
                        const link = document.createElement('a');
                        link.download = `Certificate-${participantData.certCode}.${ext}`;
                        link.href = dataUrl;
                        link.click();
                    }

                    function downloadPDF() {
                        const { jsPDF } = window.jspdf;
                        const pdf = new jsPDF({
                            orientation: 'landscape',
                            unit: 'mm',
                            format: 'a4'
                        });
                        const imgData = canvas.toDataURL('image/jpeg', 0.98);
                        pdf.addImage(imgData, 'JPEG', 0, 0, 297, 210);
                        pdf.save(`Certificate-${participantData.certCode}.pdf`);
                    }

                    window.addEventListener('DOMContentLoaded', renderCertificate);
                </script>
            </section>

        <?php else: ?>

            <!-- Proceedings -->
            <section class="card">
                <a class="btn-back" href="<?= htmlspecialchars(currentPath(), ENT_QUOTES, 'UTF-8') ?>">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <path d="m15 18-6-6 6-6"></path>
                    </svg>
                    بازگشت به گزینه‌ها
                </a>
                <h2 class="panel-title">
                    <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M4 4h6l2 3h8v13H4z"></path>
                        <path d="M9 13h6"></path>
                        <path d="M9 17h4"></path>
                    </svg>
                    دریافت مجموعه مقالات
                </h2>
                <p class="panel-desc">مجموعه کامل مقالات ارائه‌شده در کنفرانس (فایل articles.zip) به‌صورت یکجا و تنها برای شرکت‌کنندگان ثبت‌شده قابل دریافت است.</p>

                <?php if ($articlesAvailable): ?>
                    <div class="success-box">
                        شماره تلفن شما در فهرست شرکت‌کنندگان تأیید شد؛ دسترسی به فایل مقالات فعال است.
                        <br>
                        این دسترسی تا ۳۰ دقیقه اعتبار دارد.
                    </div>

                    <a class="btn-download" href="download-articles.php" download>
                        <svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 3v12"></path>
                            <path d="m7 11 5 5 5-5"></path>
                            <path d="M5 20h14"></path>
                        </svg>
                        دانلود مقالات (articles.zip)
                    </a>
                <?php else: ?>
                    <div class="alert-box">فایل مقالات در حال حاضر روی سرور قرار ندارد.</div>
                <?php endif; ?>
            </section>

        <?php endif; ?>

    <?php endif; ?>

    <div class="footer-note">
        دبیرخانه کنفرانس بین‌المللی بازی‌های رایانه‌ای — تمامی حقوق محفوظ است.
    </div>

</div>

</body>
</html>

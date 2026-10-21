<?php
/**
 * Streams articles.zip to signed-in participants only.
 * Direct access to the zip is blocked by .htaccess.
 */

session_start();

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

define('ARTICLES_FILE', __DIR__ . '/assets/articles.zip');
define('EXCEL_FILE', __DIR__ . '/participants.xlsx');
define('ARTICLES_ACCESS_TTL', 1800);   // اعتبار دسترسی: ۳۰ دقیقه

function denyDownload(string $message): void {
    http_response_code(403);
    header('Content-Type: text/html; charset=UTF-8');
    echo '<!DOCTYPE html><html lang="fa" dir="rtl"><head><meta charset="UTF-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
       . '<title>دسترسی غیرمجاز</title>'
       . '<link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet" type="text/css" />'
       . '<style>body{font-family:Vazirmatn,sans-serif;background:#f8fafc;color:#1e293b;display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:24px}'
       . '.box{background:#fff;border:1px solid #e2e8f0;border-radius:12px;max-width:460px;padding:28px;text-align:center;box-shadow:0 4px 6px -1px rgba(0,0,0,.05)}'
       . 'h1{font-size:1.1rem;margin-bottom:10px;color:#0f172a}p{font-size:.87rem;line-height:1.8;color:#475569;margin-bottom:18px}'
       . 'a{display:inline-block;background:#1e3a8a;color:#fff;text-decoration:none;border-radius:8px;padding:11px 22px;font-size:.9rem;font-weight:600}'
       . 'a:hover{background:#172554}</style></head><body><div class="box">'
       . '<h1>دسترسی به فایل مقالات مجاز نیست</h1><p>' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>'
       . '<a href="index.php">بازگشت به سامانه</a></div></body></html>';
    exit;
}

function normalizeDigits(string $input): string {
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $arabic  = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

    $input = str_replace($persian, $english, $input);
    $input = str_replace($arabic, $english, $input);
    return preg_replace('/[^0-9]/', '', trim($input));
}

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

// Checks whether the phone exists in the participants list.
function phoneExistsInParticipants(string $phone): bool {
    if (!file_exists(EXCEL_FILE)) {
        return false;
    }

    $zip = new ZipArchive();
    if ($zip->open(EXCEL_FILE) !== true) {
        return false;
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

    $phoneColumn = 0;
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
                        $value = $sharedStrings[(int)$cell->v] ?? '';
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

    if (empty($rows)) {
        return false;
    }

    $header = array_shift($rows);
    foreach ($header as $i => $title) {
        $title = str_replace(['ي', 'ك'], ['ی', 'ک'], (string)$title);
        if (strpos($title, 'شماره') !== false) {
            $phoneColumn = $i;
            break;
        }
    }

    foreach ($rows as $row) {
        if (normalizePhone((string)($row[$phoneColumn] ?? '')) === $phone) {
            return true;
        }
    }

    return false;
}

// --- Access control ---
if (empty($_SESSION['participant']['phone'])) {
    denyDownload('ابتدا شماره تلفن همراه خود را در سامانه وارد نمایید.');
}

if ((time() - (int)($_SESSION['participant']['time'] ?? 0)) > ARTICLES_ACCESS_TTL) {
    unset($_SESSION['participant']);
    denyDownload('اعتبار دسترسی شما به پایان رسیده است. لطفاً مجدداً وارد سامانه شوید.');
}

$authorizedPhone = normalizePhone((string)$_SESSION['participant']['phone']);

if (!phoneExistsInParticipants($authorizedPhone)) {
    unset($_SESSION['participant']);
    denyDownload('شماره تلفن همراه شما در فهرست شرکت‌کنندگان یافت نشد.');
}

if (!file_exists(ARTICLES_FILE)) {
    denyDownload('فایل مقالات در حال حاضر روی سرور قرار ندارد.');
}

// --- File delivery ---
$fileSize = filesize(ARTICLES_FILE);
$fileName = 'articles.zip';

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $fileName . '"; filename*=UTF-8\'\'' . rawurlencode($fileName));
header('Content-Length: ' . $fileSize);
header('Content-Transfer-Encoding: binary');

while (ob_get_level() > 0) {
    ob_end_clean();
}

readfile(ARTICLES_FILE);
exit;

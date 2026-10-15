<?php
/**
 * سامانه رسمی دریافت گواهی کنفرانس بین‌المللی بازی‌های رایانه‌ای
 * نسخه روشن، شرکتی و آکادمیک (Minimal, Clean & Professional Light Theme)
 */

session_start();

// ==================== تنظیمات امنیتی و هدرها ====================
header("X-Frame-Options: SAMEORIGIN");
header("X-Content-Type-Options: nosniff");
header("Referrer-Policy: strict-origin-when-cross-origin");

define('EXCEL_FILE', __DIR__ . '/participants.xlsx');
define('CUSTOM_FONT_PATH', 'assets/custom-font.ttf');
define('CUSTOM_TEMPLATE_PATH', 'assets/certificate-template.png');

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

// ==================== توابع کمکی ====================
function normalizeDigits(string $input): string {
    $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
    $arabic  = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
    $english = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];
    
    $input = str_replace($persian, $english, $input);
    $input = str_replace($arabic, $english, $input);
    return preg_replace('/[^0-9]/', '', trim($input));
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
                    $type = isset($attr['t']) ? (string)$attr['t'] : '';
                    $value = '';

                    if ($type === 's') {
                        $idx = (int)$cell->v;
                        $value = $sharedStrings[$idx] ?? '';
                    } elseif ($type === 'inlineStr') {
                        $value = (string)$cell->is->t;
                    } else {
                        $value = (string)$cell->v;
                    }
                    $rowData[] = trim($value);
                }
                if (!empty(array_filter($rowData))) {
                    $rows[] = $rowData;
                }
            }
        }
    }

    $zip->close();
    return $rows;
}

// ==================== پردازش فرم ====================
$error = '';
$participant = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_SESSION['attempts'] >= 7) {
        $error = 'تعداد دفعات تلاش بیش از حد مجاز است. لطفاً پس از ۱۰ دقیقه مجدداً اقدام فرمایید.';
    } elseif (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $error = 'نشست امنیتی نامعتبر است. لطفاً صفحه را تازه‌سازی نموده و دوباره تلاش کنید.';
    } else {
        $nationalId = normalizeDigits($_POST['national_id'] ?? '');
        $phone      = normalizeDigits($_POST['phone'] ?? '');

        if (strlen($nationalId) < 8 || strlen($nationalId) > 10) {
            $error = 'لطفاً کد ملی معتبر (۱۰ رقم) وارد نمایید.';
        } elseif (!preg_match('/^09[0-9]{9}$/', $phone)) {
            $error = 'لطفاً شماره تلفن همراه معتبر (۱۱ رقم با پیش‌شماره 09) وارد نمایید.';
        } else {
            $data = readXlsxFile(EXCEL_FILE);

            if (empty($data)) {
                $error = 'پایگاه داده گواهی‌ها موقتاً در دسترس نیست یا فایل اطلاعات یافت نشد.';
            } else {
                for ($i = 1; $i < count($data); $i++) {
                    $row = $data[$i];
                    $rowNatId = normalizeDigits($row[0] ?? '');
                    $rowPhone = normalizeDigits($row[1] ?? '');

                    $natMatch = ($rowNatId === $nationalId) || (ltrim($rowNatId, '0') === ltrim($nationalId, '0') && !empty($nationalId));
                    $phoneMatch = ($rowPhone === $phone) || (ltrim($rowPhone, '0') === ltrim($phone, '0') && !empty($phone));

                    if ($natMatch && $phoneMatch) {
                        $participant = [
                            'national_id' => $nationalId,
                            'phone'       => $phone,
                            'name'        => htmlspecialchars($row[2] ?? '', ENT_QUOTES, 'UTF-8'),
                            'paper_title' => htmlspecialchars($row[3] ?? '', ENT_QUOTES, 'UTF-8'),
                            'cert_code'   => htmlspecialchars($row[4] ?? ('ICCG-2026-' . (100 + $i)), ENT_QUOTES, 'UTF-8'),
                        ];
                        break;
                    }
                }

                if (!$participant) {
                    $_SESSION['attempts']++;
                    $_SESSION['last_attempt_time'] = time();
                    $error = 'اطلاعاتی با این مشخصات یافت نشد. لطفاً از صحت کد ملی و شماره همراه اطمینان حاصل فرمایید.';
                } else {
                    $_SESSION['attempts'] = 0;
                }
            }
        }
    }
}

$hasCustomTemplate = file_exists(__DIR__ . '/' . CUSTOM_TEMPLATE_PATH);
$hasCustomFont     = file_exists(__DIR__ . '/' . CUSTOM_FONT_PATH);
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>سامانه دریافت گواهی | کنفرانس بین‌المللی بازی‌های رایانه‌ای</title>
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
            --primary: #0f172a;       /* سورمه‌ای بسیار رسمی و سنگین */
            --accent: #1e3a8a;        /* رنگ شاخص آکادمیک */
            --text-primary: #1e293b;
            --text-secondary: #475569;
            --text-muted: #64748b;
            --border: #e2e8f0;
            --border-focus: #3b82f6;
            --danger-bg: #fef2f2;
            --danger-text: #991b1b;
            --danger-border: #fecaca;
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
            justify-content: center;
            padding: 32px 16px;
        }

        .main-card {
            width: 100%;
            max-width: <?= $participant ? '980px' : '460px' ?>;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
            padding: 36px 32px;
            transition: max-width 0.3s ease;
        }

        .header-section {
            text-align: center;
            margin-bottom: 28px;
            border-bottom: 1px solid var(--border);
            padding-bottom: 24px;
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

        /* جزئیات استعلام */
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

        .btn-action-secondary {
            background: transparent;
            border-color: transparent;
            color: var(--text-muted);
            flex: 1 1 100%;
            margin-top: 4px;
        }
        .btn-action-secondary:hover {
            color: var(--text-primary);
            background: #f1f5f9;
        }

        .footer-note {
            margin-top: 24px;
            text-align: center;
            font-size: 0.78rem;
            color: var(--text-muted);
        }

        @media (max-width: 600px) {
            .main-card { padding: 24px 18px; }
            .header-section h1 { font-size: 1.2rem; }
        }
    </style>
</head>
<body>

<div class="main-card">
    <div class="header-section">
        <div class="sub-title-badge">دبیرخانه کنفرانس بین‌المللی بازی‌های رایانه‌ای</div>
        <h1>دریافت الکترونیکی گواهی ارائه مقاله</h1>
        <p>لطفاً جهت استعلام و صدور گواهی، مشخصات هویتی ثبت‌شده را وارد فرمایید.</p>
    </div>

    <?php if ($error): ?>
        <div class="alert-box"><?= $error ?></div>
    <?php endif; ?>

    <?php if (!$participant): ?>
        <form method="POST" action="" autocomplete="off">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
            
            <div class="form-group">
                <label for="national_id">کد ملی</label>
                <input type="text" id="national_id" name="national_id" class="form-control" 
                       placeholder="۱۰ رقم بدون خط تیره" maxlength="10" required 
                       value="<?= htmlspecialchars($_POST['national_id'] ?? '') ?>">
            </div>

            <div class="form-group">
                <label for="phone">شماره تلفن همراه</label>
                <input type="tel" id="phone" name="phone" class="form-control" 
                       placeholder="09120000000" maxlength="11" required
                       value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>">
            </div>

            <button type="submit" class="btn-primary">استعلام و صدور گواهی</button>
        </form>
    <?php else: ?>
        <div class="info-grid">
            <div class="info-cell">
                <span>پژوهشگر گرامی:</span>
                <strong><?= $participant['name'] ?></strong>
            </div>
            <div class="info-cell">
                <span>شناسه اصالت گواهی:</span>
                <strong dir="ltr"><?= $participant['cert_code'] ?></strong>
            </div>
            <div class="info-cell" style="grid-column: 1 / -1;">
                <span>عنوان مقاله:</span>
                <strong><?= $participant['paper_title'] ?></strong>
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
            <a href="<?= strtok($_SERVER["REQUEST_URI"], '?') ?>" class="btn-action btn-action-secondary">
                بازگشت و استعلام مجدد
            </a>
        </div>

        <script>
            const participantData = {
                name: <?= json_encode($participant['name'], JSON_UNESCAPED_UNICODE) ?>,
                paperTitle: <?= json_encode($participant['paper_title'], JSON_UNESCAPED_UNICODE) ?>,
                certCode: <?= json_encode($participant['cert_code'], JSON_UNESCAPED_UNICODE) ?>,
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

            // قالب پیش‌فرض آکادمیک و کلاسیک (در صورت نبود تصویر اختصاصی)
            function drawDefaultTemplate() {
                // زمینه سفید گرم رسمی
                ctx.fillStyle = '#fafaf9';
                ctx.fillRect(0, 0, canvas.width, canvas.height);

                // قاب و کادر آکادمیک
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

                // نام شرکت‌کننده
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

                // عنوان مقاله
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

                // شناسه گواهی
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
    <?php endif; ?>

    <div class="footer-note">
        دبیرخانه کنفرانس بین‌المللی بازی‌های رایانه‌ای — تمامی حقوق محفوظ است.
    </div>
</div>

</body>
</html>

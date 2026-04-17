<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../assets/lib/Database.php';
require_once __DIR__ . '/../../app/includes/settings.php';
require_once __DIR__ . '/../../app/includes/auth.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

$apiKey = trim((string)($_GET['api_key'] ?? ''));
$requestedMode = strtolower(trim((string)($_GET['mode'] ?? '')));
if (!in_array($requestedMode, ['', 'app', 'standalone'], true)) {
    $requestedMode = '';
}
$configuredApiKeySetting = sf_get_setting('xibo_summary_api_key', null);
$configuredApiKey = $configuredApiKeySetting === null ? '' : trim((string)$configuredApiKeySetting);
if ($configuredApiKeySetting === null) {
    $configuredApiKey = trim((string)(getenv('XIBO_SUMMARY_API_KEY') ?: ''));
}

$user = sf_current_user();
$isAuthenticated = $user !== null;
$hasValidApiKey = $apiKey !== '' && $configuredApiKey !== '' && hash_equals($configuredApiKey, $apiKey);
$isStandaloneMode = $requestedMode === 'standalone' || (!$isAuthenticated && $hasValidApiKey);

if (!$isAuthenticated && !$hasValidApiKey) {
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Unauthorized';
    exit;
}

$pdo = Database::getInstance();
$allowedLangs = ['fi', 'sv', 'en', 'it', 'el'];
$requestedLang = strtolower(trim((string)($_GET['lang'] ?? 'fi')));
$uiLang = in_array($requestedLang, $allowedLangs, true) ? $requestedLang : 'fi';

$stmt = $pdo->prepare("\n    SELECT\n        f.id,\n        f.translation_group_id,\n        f.lang,\n        f.title,\n        f.site,\n        f.type,\n        f.occurred_at,\n        f.created_at\n    FROM sf_flashes f\n    WHERE f.state = 'published'\n      AND (f.display_expires_at IS NULL OR f.display_expires_at > NOW())\n      AND f.display_removed_at IS NULL\n    ORDER BY\n        COALESCE(f.translation_group_id, f.id) ASC,\n        CASE\n            WHEN f.lang = :preferred_lang THEN 0\n            WHEN f.lang = 'en' THEN 1\n            WHEN f.id = COALESCE(f.translation_group_id, f.id) THEN 2\n            ELSE 3\n        END ASC,\n        COALESCE(f.occurred_at, f.created_at) DESC,\n        f.id DESC\n");
$stmt->execute([':preferred_lang' => $uiLang]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$groups = [];
foreach ($rows as $row) {
    $row['sort_ts'] = strtotime((string)($row['occurred_at'] ?? $row['created_at'] ?? '')) ?: 0;
    $groupId = !empty($row['translation_group_id']) ? (int)$row['translation_group_id'] : (int)$row['id'];
    $rowLang = strtolower(trim((string)($row['lang'] ?? '')));
    if ($rowLang === $uiLang) {
        $row['lang_priority'] = 0;
    } elseif ($rowLang === 'fi') {
        $row['lang_priority'] = 1;
    } else {
        $row['lang_priority'] = 2;
    }
    if (
        !isset($groups[$groupId])
        || (int)$row['lang_priority'] < (int)($groups[$groupId]['lang_priority'] ?? PHP_INT_MAX)
    ) {
        $groups[$groupId] = $row;
    }
}

$selectedRows = array_values($groups);
usort($selectedRows, static function (array $a, array $b): int {
    return ((int)($b['sort_ts'] ?? 0)) <=> ((int)($a['sort_ts'] ?? 0));
});

$flashes = array_map(static function (array $row): array {
    $eventDateRaw = trim((string)($row['occurred_at'] ?? ''));
    $formattedDate = '';
    if ($eventDateRaw !== '') {
        $timestamp = strtotime($eventDateRaw);
        if ($timestamp !== false) {
            $formattedDate = date('d.m.Y H:i', $timestamp);
        }
    }

    return [
        'title' => trim((string)($row['title'] ?? '')),
        'site_name' => trim((string)($row['site'] ?? '')),
        'type' => trim((string)($row['type'] ?? '')),
        'lang' => trim((string)($row['lang'] ?? '')),
        'event_date' => $formattedDate,
    ];
}, $selectedRows);

$viewTexts = [
    'fi' => [
        'title' => 'Aktiiviset SafetyFlashit',
        'summary' => 'Koontinäkymä',
        'col_title' => 'Otsikko',
        'col_site' => 'Työmaa',
        'col_type' => 'Tyyppi',
        'col_event_date' => 'Tapahtuma-aika',
        'empty' => 'Ei aktiivisia SafetyFlasheja',
        'page' => 'Sivu',
        'of' => '/',
    ],
    'sv' => [
        'title' => 'Aktiva SafetyFlashar',
        'summary' => 'Sammanfattning',
        'col_title' => 'Rubrik',
        'col_site' => 'Arbetsplats',
        'col_type' => 'Typ',
        'col_event_date' => 'Händelsetid',
        'empty' => 'Inga aktiva SafetyFlashar',
        'page' => 'Sida',
        'of' => '/',
    ],
    'en' => [
        'title' => 'Active SafetyFlashes',
        'summary' => 'Summary view',
        'col_title' => 'Title',
        'col_site' => 'Site',
        'col_type' => 'Type',
        'col_event_date' => 'Event time',
        'empty' => 'No active SafetyFlashes',
        'page' => 'Page',
        'of' => '/',
    ],
    'it' => [
        'title' => 'SafetyFlash attivi',
        'summary' => 'Vista riepilogo',
        'col_title' => 'Titolo',
        'col_site' => 'Sito',
        'col_type' => 'Tipo',
        'col_event_date' => 'Ora evento',
        'empty' => 'Nessun SafetyFlash attivo',
        'page' => 'Pagina',
        'of' => '/',
    ],
    'el' => [
        'title' => 'Ενεργά SafetyFlash',
        'summary' => 'Προβολή σύνοψης',
        'col_title' => 'Τίτλος',
        'col_site' => 'Εργοτάξιο',
        'col_type' => 'Τύπος',
        'col_event_date' => 'Ώρα συμβάντος',
        'empty' => 'Δεν υπάρχουν ενεργά SafetyFlash',
        'page' => 'Σελίδα',
        'of' => '/',
    ],
];
$viewI18n = $viewTexts[$uiLang] ?? $viewTexts['fi'];

$typeLabels = [
    'red' => [
        'fi' => 'Ensitiedote',
        'sv' => 'Första underrättelse',
        'en' => 'First release',
        'it' => 'Primo comunicato',
        'el' => 'Πρώτη ανακοίνωση',
    ][$uiLang] ?? 'First release',
    'yellow' => [
        'fi' => 'Vaaratilanne / läheltä piti',
        'sv' => 'Farlig situation / nära på',
        'en' => 'Dangerous situation / near miss',
        'it' => 'Situazione pericolosa',
        'el' => 'Επικίνδυνη κατάσταση',
    ][$uiLang] ?? 'Dangerous situation',
    'green' => [
        'fi' => 'Tutkintatiedote',
        'sv' => 'Utredningsrapport',
        'en' => 'Investigation report',
        'it' => 'Rapporto di indagine',
        'el' => 'Έκθεση έρευνας',
    ][$uiLang] ?? 'Investigation report',
];

$backgroundPath = trim((string)sf_get_setting('xibo_summary_background_image', ''));
$baseUrl = rtrim((string)($config['base_url'] ?? ''), '/');
$appBaseUrl = $baseUrl;
$summaryPathSuffix = '/xibo/safetyflash-summary';
if ($appBaseUrl !== '' && substr($appBaseUrl, -strlen($summaryPathSuffix)) === $summaryPathSuffix) {
    $appBaseUrl = substr($appBaseUrl, 0, -strlen($summaryPathSuffix));
}
$base = $appBaseUrl;
$config['base_url'] = $base;
$backgroundUrl = '';
if ($backgroundPath !== '') {
    $normalizedBackgroundPath = strtolower($backgroundPath);
    $isAbsoluteHttp = strpos($normalizedBackgroundPath, 'http://') === 0 || strpos($normalizedBackgroundPath, 'https://') === 0 || strpos($normalizedBackgroundPath, '//') === 0;
    $isDataUri = strpos($normalizedBackgroundPath, 'data:') === 0;
    if ($isAbsoluteHttp || $isDataUri) {
        $backgroundUrl = $backgroundPath;
    } elseif ($base !== '') {
        $backgroundUrl = $base . '/' . ltrim($backgroundPath, '/');
    } else {
        $backgroundUrl = '/' . ltrim($backgroundPath, '/');
    }
}
$activeFlashCount = count($flashes);
$rotationSeconds = 15;
$standaloneParams = [
    'mode' => 'standalone',
    'lang' => $uiLang,
];
if ($configuredApiKey !== '') {
    $standaloneParams['api_key'] = $configuredApiKey;
}
$standaloneUrl = ($base !== '' ? rtrim($base, '/') : '') . '/xibo/safetyflash-summary/?' . http_build_query($standaloneParams, '', '&', PHP_QUERY_RFC3986);

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="<?= htmlspecialchars($uiLang, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SafetyFlash koonti</title>
<?php if (!$isStandaloneMode): ?>
    <link rel="stylesheet" href="<?= sf_asset_url('assets/css/nav.css', $base) ?>">
    <link rel="stylesheet" href="<?= sf_asset_url('assets/css/global.css', $base) ?>">
    <link rel="stylesheet" href="<?= sf_asset_url('assets/css/layout.css', $base) ?>">
    <link rel="stylesheet" href="<?= sf_asset_url('assets/css/skeleton.css', $base) ?>">
    <link rel="stylesheet" href="<?= sf_asset_url('assets/css/modals.css', $base) ?>">
<?php endif; ?>
    <style>
        html,
        body {
            margin: 0;
            font-family: "Segoe UI", Roboto, Arial, sans-serif;
            color: #0f172a;
        }

        body.sf-xibo-standalone {
            width: 100%;
            height: 100%;
            overflow: hidden;
            background: #020617;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .sf-stage {
            width: 1920px;
            height: 1080px;
        }

        body.sf-xibo-standalone .sf-stage {
            transform-origin: center center;
            transform: scale(min(calc(100vw / 1920), calc(100vh / 1080)));
        }

        .sf-xibo-summary-container {
            min-height: calc(100vh - 72px);
            box-sizing: border-box;
            padding: 24px;
            background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
        }
        .sf-xibo-summary-shell {
            max-width: 1440px;
            margin: 0 auto;
        }
        .sf-xibo-page-header {
            margin-bottom: 16px;
            gap: 0;
            flex-direction: column;
            align-items: flex-start;
        }
        .sf-xibo-page-description {
            margin: 8px 0 0;
            color: #475569;
            font-size: 1rem;
            max-width: 72ch;
        }
        .sf-xibo-preview-card {
            border-radius: 18px;
            border: 1px solid #dbe4ee;
            background: #ffffff;
            box-shadow: 0 10px 26px rgba(15, 23, 42, 0.08);
            padding: 18px;
        }
        .sf-xibo-preview-label {
            margin: 0 0 12px;
            color: #1e293b;
            font-size: 1.05rem;
            font-weight: 700;
        }
        .sf-xibo-preview-frame {
            position: relative;
            width: 100%;
            aspect-ratio: 16 / 9;
            border-radius: 12px;
            border: 1px solid #cbd5e1;
            background: linear-gradient(135deg, #e2e8f0 0%, #f8fafc 100%);
            overflow: hidden;
            container-type: inline-size;
        }
        .sf-xibo-preview-frame .sf-stage {
            position: absolute;
            inset: 0 auto auto 0;
            transform-origin: top left;
            transform: scale(1);
            transform: scale(min(1, calc(100cqw / 1920)));
        }

        .sf-xibo-preview-info {
            margin-top: 14px;
            display: grid;
            gap: 10px;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        }
        .sf-xibo-meta-item {
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #f8fafc;
            padding: 10px 12px;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.06);
        }
        .sf-xibo-meta-label {
            display: block;
            color: #64748b;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            margin-bottom: 4px;
        }
        .sf-xibo-meta-value {
            margin: 0;
            color: #0f172a;
            font-size: 0.95rem;
            font-weight: 600;
            overflow-wrap: anywhere;
            word-break: break-word;
        }
        .sf-xibo-meta-value a {
            color: #2563eb;
            text-decoration: none;
        }
        .sf-xibo-meta-value a:hover {
            text-decoration: underline;
        }

        .sf-summary {
            position: relative;
            width: 1920px;
            height: 1080px;
            box-sizing: border-box;
            padding: 56px 64px;
            background-color: #ffffff;
            background-image: none;
            background-size: cover;
            background-position: center;
            overflow: hidden;
        }
        .sf-summary::before {
            display: none;
        }
        .sf-summary.sf-summary--with-background::before {
            display: none;
        }
        .sf-summary-inner {
            position: relative;
            z-index: 1;
            height: 100%;
            display: flex;
            flex-direction: column;
            background: transparent;
            border-radius: 0;
            padding: 26px 30px;
            box-shadow: none;
        }
        .sf-table-head,
        .sf-row {
            display: grid;
            grid-template-columns: 2fr 1.2fr 0.9fr 1fr;
            column-gap: 18px;
            align-items: center;
        }
        .sf-table-head {
            font-size: 20px;
            font-weight: 700;
            color: #334155;
            margin-bottom: 14px;
            padding: 0 14px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .sf-list {
            flex: 1;
            display: grid;
            gap: 14px;
            align-content: start;
        }
        .sf-row {
            min-height: 112px;
            padding: 18px 20px;
            border-radius: 16px;
            border: 1px solid #dbe4ee;
            border-left: 8px solid transparent;
            background: rgba(255, 255, 255, 0.95);
            box-shadow: 0 12px 28px rgba(15, 23, 42, 0.11);
        }
        .sf-row--red {
            border-left-color: #b91c1c;
        }
        .sf-row--yellow {
            border-left-color: #b45309;
        }
        .sf-row--green {
            border-left-color: #15803d;
        }
        .sf-type {
            font-weight: 700;
        }
        .sf-type--red {
            color: #b91c1c;
        }
        .sf-type--yellow {
            color: #b45309;
        }
        .sf-type--green {
            color: #15803d;
        }
        .sf-type--default {
            color: #334155;
        }
        .sf-cell {
            font-size: 28px;
            line-height: 1.25;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .sf-empty {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 680px;
            border-radius: 20px;
            border: 1px dashed #cbd5e1;
            background: rgba(255, 255, 255, 0.88);
            font-size: 38px;
            color: #475569;
            font-weight: 600;
        }
        .sf-footer {
            margin-top: 16px;
            display: flex;
            justify-content: flex-end;
            color: #64748b;
            font-size: 24px;
            font-weight: 600;
        }
    </style>
</head>
<body class="<?= $isStandaloneMode ? 'sf-xibo-standalone' : '' ?>">
<?php if (!$isStandaloneMode): ?>
<?php require_once __DIR__ . '/../../assets/lib/sf_terms.php'; ?>
<?php require_once __DIR__ . '/../../app/includes/header.php'; ?>
<div class="sf-container sf-xibo-summary-container" id="sfContainer">
    <div class="sf-xibo-summary-shell">
        <div class="sf-page-header sf-xibo-page-header">
            <h1 class="sf-page-title">SafetyFlash-koonti</h1>
            <p class="sf-xibo-page-description">Tässä näkymässä voit esikatsella Xibo-näytölle jaettavaa SafetyFlash-koontia. Näet listan ulkoasun, sivutuksen ja taustakuvan ennen varsinaista julkaisua.</p>
        </div>
        <div class="sf-xibo-preview-card">
            <p class="sf-xibo-preview-label">Xibo-näytön esikatselu</p>
            <div class="sf-xibo-preview-frame">
<?php endif; ?>
<div class="sf-stage">
    <div class="sf-summary" id="sfSummaryRoot">
        <div class="sf-summary-inner">
            <div class="sf-table-head">
                <div><?= htmlspecialchars($viewI18n['col_title'], ENT_QUOTES, 'UTF-8') ?></div>
                <div><?= htmlspecialchars($viewI18n['col_site'], ENT_QUOTES, 'UTF-8') ?></div>
                <div><?= htmlspecialchars($viewI18n['col_type'], ENT_QUOTES, 'UTF-8') ?></div>
                <div><?= htmlspecialchars($viewI18n['col_event_date'], ENT_QUOTES, 'UTF-8') ?></div>
            </div>

            <div class="sf-list" id="sfSummaryList"></div>

            <div class="sf-footer">
                <span id="sfPageIndicator">Sivu 1 / 1</span>
            </div>
        </div>
    </div>
</div>
<?php if (!$isStandaloneMode): ?>
            </div>
            <div class="sf-xibo-preview-info">
                <div class="sf-xibo-meta-item">
                    <span class="sf-xibo-meta-label">Aktiivisia SafetyFlasheja</span>
                    <p class="sf-xibo-meta-value"><?= (int)$activeFlashCount ?></p>
                </div>
                <div class="sf-xibo-meta-item">
                    <span class="sf-xibo-meta-label">Autokierto</span>
                    <p class="sf-xibo-meta-value"><?= (int)$rotationSeconds ?> sekuntia / sivu</p>
                </div>
                <div class="sf-xibo-meta-item">
                    <span class="sf-xibo-meta-label">Xibo standalone-URL</span>
                    <p class="sf-xibo-meta-value"><a href="<?= htmlspecialchars($standaloneUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($standaloneUrl, ENT_QUOTES, 'UTF-8') ?></a></p>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>
<?php endif; ?>

<script>
(() => {
    'use strict';

    const flashes = <?= json_encode($flashes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const backgroundUrl = <?= json_encode($backgroundUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const i18n = <?= json_encode($viewI18n, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const typeLabels = <?= json_encode($typeLabels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const isStandaloneMode = <?= $isStandaloneMode ? 'true' : 'false' ?>;
    const itemsPerPage = 6;
    const totalPages = Math.max(1, Math.ceil(flashes.length / itemsPerPage));
    let currentPage = 0;

    const root = document.getElementById('sfSummaryRoot');
    const list = document.getElementById('sfSummaryList');
    const indicator = document.getElementById('sfPageIndicator');
    const previewFrame = isStandaloneMode ? null : document.querySelector('.sf-xibo-preview-frame');

    if (backgroundUrl) {
        try {
            const resolvedBackgroundUrl = new URL(backgroundUrl, window.location.origin);
            if (resolvedBackgroundUrl.protocol === 'http:' || resolvedBackgroundUrl.protocol === 'https:' || resolvedBackgroundUrl.protocol === 'data:') {
                root.style.backgroundImage = `url("${resolvedBackgroundUrl.href.replace(/"/g, '%22')}")`;
                root.classList.add('sf-summary--with-background');
            }
        } catch (error) {}
    }

    const supportsContainerQueryUnits = window.CSS
        && CSS.supports
        && CSS.supports('width', '1cqw')
        && CSS.supports('transform', 'scale(calc(100cqw / 1920))');
    if (previewFrame && !supportsContainerQueryUnits) {
        const stage = previewFrame.querySelector('.sf-stage');
        let resizeRaf = 0;
        const syncPreviewScale = () => {
            if (!stage) {
                return;
            }
            const scale = Math.min(1, previewFrame.clientWidth / 1920);
            stage.style.transform = `scale(${scale})`;
        };
        const handleResize = () => {
            if (resizeRaf) {
                return;
            }
            resizeRaf = window.requestAnimationFrame(() => {
                resizeRaf = 0;
                syncPreviewScale();
            });
        };
        syncPreviewScale();
        window.addEventListener('resize', handleResize);
    }

    const escapeHtml = (value) => String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const getTypePresentation = (typeValue) => {
        const rawType = String(typeValue || '').trim();
        const normalized = rawType.toLowerCase();
        const knownTypes = {
            red: { label: typeLabels.red || rawType, className: 'sf-type--red', rowClass: 'sf-row--red' },
            yellow: { label: typeLabels.yellow || rawType, className: 'sf-type--yellow', rowClass: 'sf-row--yellow' },
            green: { label: typeLabels.green || rawType, className: 'sf-type--green', rowClass: 'sf-row--green' },
        };
        return knownTypes[normalized] || { label: rawType || '-', className: 'sf-type--default', rowClass: '' };
    };

    const renderPage = () => {
        if (!flashes.length) {
            list.innerHTML = `<div class="sf-empty">${escapeHtml(i18n.empty || 'No active SafetyFlashes')}</div>`;
            indicator.textContent = `${i18n.page || 'Page'} 1 ${i18n.of || '/'} 1`;
            return;
        }

        const start = currentPage * itemsPerPage;
        const pageItems = flashes.slice(start, start + itemsPerPage);
        list.innerHTML = pageItems.map((flash) => {
            const type = getTypePresentation(flash.type);
            return `
            <div class="sf-row ${type.rowClass}">
                <div class="sf-cell">${escapeHtml(flash.title)}</div>
                <div class="sf-cell">${escapeHtml(flash.site_name)}</div>
                <div class="sf-cell sf-type ${type.className}">${escapeHtml(type.label)}</div>
                <div class="sf-cell">${escapeHtml(flash.event_date)}</div>
            </div>
            `;
        }).join('');

        indicator.textContent = `${i18n.page || 'Page'} ${currentPage + 1} ${i18n.of || '/'} ${totalPages}`;
    };

    renderPage();

    if (totalPages > 1) {
        window.setInterval(() => {
            currentPage = (currentPage + 1) % totalPages;
            renderPage();
        }, 15000);
    }
})();
</script>
</body>
</html>

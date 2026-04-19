<?php
declare(strict_types=1);
const SUMMARY_NEW_BADGE_DAYS = 5;

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

$stmt = $pdo->prepare("\n    SELECT\n        f.id,\n        f.translation_group_id,\n        f.lang,\n        f.title,\n        f.site,\n        f.type,\n        f.occurred_at,\n        f.created_at\n    FROM sf_flashes f\n    WHERE f.state = 'published'\n      AND (f.display_expires_at IS NULL OR f.display_expires_at > NOW())\n      AND f.display_removed_at IS NULL\n    ORDER BY\n        COALESCE(f.translation_group_id, f.id) ASC,\n        COALESCE(f.occurred_at, f.created_at) DESC,\n        f.id DESC\n");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$groups = [];
foreach ($rows as $row) {
    $row['sort_ts'] = strtotime((string)($row['occurred_at'] ?? $row['created_at'] ?? '')) ?: 0;
    $groupId = !empty($row['translation_group_id']) ? (int)$row['translation_group_id'] : (int)$row['id'];
    $rowLanguage = strtolower(trim((string)($row['lang'] ?? '')));
    $row['lang'] = $rowLanguage;
    if (!isset($groups[$groupId])) {
        $groups[$groupId] = [
            'rows' => [],
            'base_lang' => '',
            'base_row' => null,
            'group_sort_ts' => 0,
        ];
    }

    $groups[$groupId]['rows'][] = $row;
    $groups[$groupId]['group_sort_ts'] = max((int)$groups[$groupId]['group_sort_ts'], (int)$row['sort_ts']);

    $isBaseRow = empty($row['translation_group_id']) || (int)$row['id'] === (int)$row['translation_group_id'];
    if ($isBaseRow) {
        $groups[$groupId]['base_row'] = $row;
        if ($rowLanguage !== '') {
            $groups[$groupId]['base_lang'] = $rowLanguage;
        }
    }
}

$selectedRows = [];
foreach ($groups as $group) {
    $rowsInGroup = $group['rows'];
    if (!$rowsInGroup) {
        continue;
    }

    $rowsByLang = [];
    foreach ($rowsInGroup as $groupRow) {
        $groupRowLang = (string)($groupRow['lang'] ?? '');
        if ($groupRowLang !== '' && !isset($rowsByLang[$groupRowLang])) {
            $rowsByLang[$groupRowLang] = $groupRow;
        }
    }

    $baseLang = (string)($group['base_lang'] ?? '');
    $baseRow = $group['base_row'] ?? null;
    $selected = null;
    if (isset($rowsByLang[$uiLang])) {
        $selected = $rowsByLang[$uiLang];
    } elseif (is_array($baseRow)) {
        $selected = $baseRow;
    } elseif ($baseLang !== '' && isset($rowsByLang[$baseLang])) {
        $selected = $rowsByLang[$baseLang];
    } else {
        $selected = $rowsInGroup[0];
    }

    $selectedWithSortTs = $selected;
    $selectedWithSortTs['sort_ts'] = max((int)($selected['sort_ts'] ?? 0), (int)($group['group_sort_ts'] ?? 0));
    $selectedRows[] = $selectedWithSortTs;
}
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

    $freshnessDateRaw = trim((string)($row['created_at'] ?? ''));
    if ($freshnessDateRaw === '') {
        $freshnessDateRaw = $eventDateRaw;
    }
    $freshnessTimestamp = $freshnessDateRaw !== '' ? strtotime($freshnessDateRaw) : false;
    $freshnessWindowSeconds = SUMMARY_NEW_BADGE_DAYS * 24 * 60 * 60;
    $isNew = $freshnessTimestamp !== false && $freshnessTimestamp >= (time() - $freshnessWindowSeconds);

    return [
        'title' => trim((string)($row['title'] ?? '')),
        'site_name' => trim((string)($row['site'] ?? '')),
        'type' => trim((string)($row['type'] ?? '')),
        'lang' => trim((string)($row['lang'] ?? '')),
        'event_date' => $formattedDate,
        'is_new' => $isNew,
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
        'pagination_prev' => 'Edellinen',
        'pagination_next' => 'Seuraava',
        'preview_languages' => 'Kieliversiot',
        'standalone_lang_note' => 'Vaihda <code>lang=</code>-parametrilla maakohtainen kieliversio (%s).',
        'new_badge' => 'UUSI',
        'published_tag' => 'Julkaistu',
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
        'pagination_prev' => 'Föregående',
        'pagination_next' => 'Nästa',
        'preview_languages' => 'Språkversioner',
        'standalone_lang_note' => 'Byt landspecifik språkversion med parametern <code>lang=</code> (%s).',
        'new_badge' => 'NY',
        'published_tag' => 'Publicerad',
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
        'pagination_prev' => 'Previous',
        'pagination_next' => 'Next',
        'preview_languages' => 'Language versions',
        'standalone_lang_note' => 'Change country-specific language version with the <code>lang=</code> parameter (%s).',
        'new_badge' => 'NEW',
        'published_tag' => 'Published',
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
        'pagination_prev' => 'Precedente',
        'pagination_next' => 'Successiva',
        'preview_languages' => 'Versioni lingua',
        'standalone_lang_note' => 'Cambia la versione linguistica per paese con il parametro <code>lang=</code> (%s).',
        'new_badge' => 'NUOVO',
        'published_tag' => 'Pubblicato',
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
        'pagination_prev' => 'Προηγούμενη',
        'pagination_next' => 'Επόμενη',
        'preview_languages' => 'Γλωσσικές εκδόσεις',
        'standalone_lang_note' => 'Αλλάξτε γλωσσική έκδοση ανά χώρα με την παράμετρο <code>lang=</code> (%s).',
        'new_badge' => 'ΝΕΟ',
        'published_tag' => 'Δημοσιευμένο',
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
        'fi' => 'Vaaratilanne',
        'sv' => 'Farlig situation',
        'en' => 'Dangerous situation',
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
$langCodeList = implode('/', array_map(static function (string $langCode): string {
    return strtoupper($langCode);
}, $allowedLangs));
$standaloneLangNote = sprintf(
    (string)($viewI18n['standalone_lang_note'] ?? 'Change country-specific language version with the <code>lang=</code> parameter (%s).'),
    htmlspecialchars($langCodeList, ENT_QUOTES, 'UTF-8')
);
$previewAppUrls = [];
$previewStandaloneUrls = [];
foreach ($allowedLangs as $langCode) {
    $previewAppUrls[$langCode] = ($base !== '' ? rtrim($base, '/') : '') . '/xibo/safetyflash-summary/?' . http_build_query([
        'mode' => 'app',
        'lang' => $langCode,
    ], '', '&', PHP_QUERY_RFC3986);

    $langStandaloneParams = [
        'mode' => 'standalone',
        'lang' => $langCode,
    ];
    if ($configuredApiKey !== '') {
        $langStandaloneParams['api_key'] = $configuredApiKey;
    }
    $previewStandaloneUrls[$langCode] = ($base !== '' ? rtrim($base, '/') : '') . '/xibo/safetyflash-summary/?' . http_build_query($langStandaloneParams, '', '&', PHP_QUERY_RFC3986);
}

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
            font-family: 'Open Sans', system-ui, -apple-system, Segoe UI, Roboto, Arial, sans-serif;
            color: #0f172a;
        }

        body.sf-xibo-standalone {
            width: 100%;
            height: 100%;
            overflow: hidden;
            background: linear-gradient(135deg, #1e3a5f 0%, #0f172a 100%);
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
            background: linear-gradient(135deg, #1e3a5f 0%, #0f172a 100%);
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
            color: rgba(255, 255, 255, 0.88);
            font-size: 1rem;
            max-width: 72ch;
        }
        .sf-xibo-preview-card {
            border-radius: 18px;
            border: 1px solid rgba(255, 255, 255, 0.16);
            background: #ffffff;
            box-shadow: 0 12px 30px rgba(2, 6, 23, 0.24);
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
            border: 1px solid #dbe4ee;
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
        .sf-lang-link--active {
            font-weight: 800;
            color: #0f172a !important;
            text-decoration: underline;
        }
        .sf-standalone-lang-list {
            margin: 0;
            padding: 0;
            list-style: none;
            display: grid;
            gap: 4px;
        }
        .sf-standalone-lang-list li {
            margin: 0;
        }

        .sf-summary {
            position: relative;
            width: 1920px;
            height: 1080px;
            box-sizing: border-box;
            padding: 140px 64px 40px 64px;
            --sf-title-line-height: 1.22;
            background-color: #ffffff;
            background-image: linear-gradient(135deg, #1e3a5f 0%, #0f172a 100%);
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
            padding: 16px 24px 24px 88px;
            box-shadow: none;
        }
        .sf-table-head,
        .sf-row {
            display: grid;
            grid-template-columns: 2.5fr 1.2fr 0.9fr 1fr;
            column-gap: 18px;
            align-items: start;
        }
        .sf-table-head {
            font-size: 20px;
            font-weight: 700;
            color: #f8fafc;
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
            min-height: 124px;
            padding: 18px 20px;
            border-radius: 14px;
            border: 1px solid rgba(17, 24, 39, 0.12);
            border-left: 8px solid transparent;
            background: #ffffff;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.06);
        }
        .sf-row--red {
            border-left-color: #dc2626;
        }
        .sf-row--yellow {
            border-left-color: #a16207;
        }
        .sf-row--green {
            border-left-color: #16a34a;
        }
        .sf-type {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: fit-content;
            padding: 6px 12px;
            border-radius: 999px;
            border: 1px solid transparent;
            font-weight: 700;
            font-size: 18px;
            line-height: 1.2;
            text-transform: uppercase;
            letter-spacing: 0.02em;
        }
        .sf-type--red {
            color: #dc2626;
            background: #fef2f2;
            border-color: #fecaca;
        }
        .sf-type--yellow {
            color: #a16207;
            background: #fefce8;
            border-color: #fde047;
        }
        .sf-type--green {
            color: #16a34a;
            background: #f0fdf4;
            border-color: #86efac;
        }
        .sf-type--default {
            color: #334155;
            background: #f1f5f9;
            border-color: #cbd5e1;
        }
        .sf-cell {
            font-size: 28px;
            line-height: 1.25;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .sf-cell--title {
            white-space: normal;
            overflow: visible;
        }
        .sf-title-wrap {
            display: flex;
            align-items: center;
            flex-wrap: wrap;
            gap: 8px;
            margin-bottom: 8px;
        }
        .sf-title-text {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: normal;
            max-width: 100%;
            font-weight: 700;
            line-height: var(--sf-title-line-height);
        }
        .sf-pill {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 4px 10px;
            border-radius: 999px;
            border: 1px solid transparent;
            font-size: 14px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            line-height: 1.1;
            white-space: nowrap;
        }
        .sf-pill--published {
            background: var(--status-published-bg, #16a34a);
            color: var(--status-published-text, #ffffff);
        }
        .sf-pill--new {
            background: #fefce8;
            color: #a16207;
            border-color: #fde047;
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
            align-items: center;
            justify-content: center;
            gap: 10px;
            flex-wrap: wrap;
            color: #64748b;
        }
        .sf-page-btn {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 14px;
            border-radius: 10px;
            border: 1px solid #d1d5db;
            background: #ffffff;
            color: #111827;
            font-size: 18px;
            font-weight: 600;
            line-height: 1;
            cursor: pointer;
        }
        .sf-page-btn:hover:not(:disabled) {
            background: #f3f4f6;
            border-color: #9ca3af;
        }
        .sf-page-btn:disabled {
            opacity: 0.4;
            cursor: default;
        }
        .sf-page-numbers {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .sf-page-num {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            border-radius: 10px;
            border: 1px solid #d1d5db;
            background: #ffffff;
            color: #374151;
            font-size: 18px;
            font-weight: 600;
            cursor: pointer;
        }
        .sf-page-num:disabled {
            cursor: default;
        }
        .sf-page-num.active {
            background: #fee000;
            border-color: #fee000;
            color: #111827;
            font-weight: 700;
        }
        .sf-page-ellipsis {
            color: #9ca3af;
            font-size: 18px;
            width: 24px;
            text-align: center;
        }
        .sf-page-info {
            margin-left: 8px;
            font-size: 19px;
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
                <button type="button" id="sfPagePrev" class="sf-page-btn">
                    <span aria-hidden="true">‹</span>
                    <span><?= htmlspecialchars((string)($viewI18n['pagination_prev'] ?? 'Previous'), ENT_QUOTES, 'UTF-8') ?></span>
                </button>
                <div class="sf-page-numbers" id="sfPageNumbers"></div>
                <button type="button" id="sfPageNext" class="sf-page-btn">
                    <span><?= htmlspecialchars((string)($viewI18n['pagination_next'] ?? 'Next'), ENT_QUOTES, 'UTF-8') ?></span>
                    <span aria-hidden="true">›</span>
                </button>
                <span id="sfPageIndicator" class="sf-page-info">Sivu 1 / 1</span>
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
                    <p class="sf-xibo-meta-value"><?= $standaloneLangNote ?></p>
                    <ul class="sf-standalone-lang-list">
<?php foreach ($previewStandaloneUrls as $langCode => $langStandaloneUrl): ?>
                        <li><strong><?= strtoupper(htmlspecialchars($langCode, ENT_QUOTES, 'UTF-8')) ?></strong>: <a href="<?= htmlspecialchars($langStandaloneUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer"><?= htmlspecialchars($langStandaloneUrl, ENT_QUOTES, 'UTF-8') ?></a></li>
<?php endforeach; ?>
                    </ul>
                </div>
                <div class="sf-xibo-meta-item">
                    <span class="sf-xibo-meta-label"><?= htmlspecialchars((string)($viewI18n['preview_languages'] ?? 'Language versions'), ENT_QUOTES, 'UTF-8') ?></span>
<?php $previewLanguageLinks = []; ?>
<?php foreach ($previewAppUrls as $langCode => $langAppUrl): ?>
<?php
    $linkHref = htmlspecialchars($langAppUrl, ENT_QUOTES, 'UTF-8');
    $linkText = strtoupper(htmlspecialchars($langCode, ENT_QUOTES, 'UTF-8'));
    $linkClassAttr = $langCode === $uiLang ? ' class="sf-lang-link--active"' : '';
    $previewLanguageLinks[] = '<a href="' . $linkHref . '"' . $linkClassAttr . '>' . $linkText . '</a>';
?>
<?php endforeach; ?>
                    <p class="sf-xibo-meta-value"><?= implode(' | ', $previewLanguageLinks) ?></p>
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
    const maxVisiblePages = 7;
    const totalPages = Math.max(1, Math.ceil(flashes.length / itemsPerPage));
    let currentPage = 0;

    const root = document.getElementById('sfSummaryRoot');
    const list = document.getElementById('sfSummaryList');
    const indicator = document.getElementById('sfPageIndicator');
    const pageNumbers = document.getElementById('sfPageNumbers');
    const prevButton = document.getElementById('sfPagePrev');
    const nextButton = document.getElementById('sfPageNext');
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

    const buildPageWindow = (current, total) => {
        const pages = [];
        if (total <= maxVisiblePages) {
            for (let page = 1; page <= total; page++) {
                pages.push(page);
            }
            return pages;
        }

        pages.push(1);
        const start = Math.max(2, current - 1);
        const end = Math.min(total - 1, current + 1);
        if (start > 2) {
            pages.push('…');
        }
        for (let page = start; page <= end; page++) {
            pages.push(page);
        }
        if (end < total - 1) {
            pages.push('…');
        }
        pages.push(total);
        return pages;
    };

    const renderPagination = () => {
        const current = currentPage + 1;
        indicator.textContent = `${i18n.page || 'Page'} ${current} ${i18n.of || '/'} ${totalPages}`;

        const isNavigationDisabled = totalPages <= 1;
        prevButton.disabled = isNavigationDisabled;
        nextButton.disabled = isNavigationDisabled;

        if (!pageNumbers) {
            return;
        }

        const visiblePages = buildPageWindow(current, totalPages);
        pageNumbers.innerHTML = visiblePages.map((entry) => {
            if (entry === '…') {
                return '<span class="sf-page-ellipsis" aria-hidden="true">…</span>';
            }
            const page = Number(entry);
            const activeClass = page === current ? ' active' : '';
            const ariaCurrent = page === current ? ' aria-current="page"' : '';
            const disabledAttr = page === current ? ' disabled' : '';
            return `<button type="button" class="sf-page-num${activeClass}" data-page="${page}" aria-label="${escapeHtml(i18n.page || 'Page')} ${page}"${ariaCurrent}${disabledAttr}>${page}</button>`;
        }).join('');
    };

    const renderPage = () => {
        if (!flashes.length) {
            list.innerHTML = `<div class="sf-empty">${escapeHtml(i18n.empty || 'No active SafetyFlashes')}</div>`;
            renderPagination();
            return;
        }

        const start = currentPage * itemsPerPage;
        const pageItems = flashes.slice(start, start + itemsPerPage);
        list.innerHTML = pageItems.map((flash) => {
            const type = getTypePresentation(flash.type);
            return `
            <div class="sf-row ${type.rowClass}">
                <div class="sf-cell sf-cell--title">
                    <div class="sf-title-wrap">
                        <span class="sf-pill sf-pill--published">${escapeHtml(i18n.published_tag || 'Published')}</span>
                        ${flash.is_new ? `<span class="sf-pill sf-pill--new">${escapeHtml(i18n.new_badge || 'NEW')}</span>` : ''}
                    </div>
                    <span class="sf-title-text">${escapeHtml(flash.title)}</span>
                </div>
                <div class="sf-cell">${escapeHtml(flash.site_name)}</div>
                <div class="sf-cell sf-type ${type.className}">${escapeHtml(type.label)}</div>
                <div class="sf-cell">${escapeHtml(flash.event_date)}</div>
            </div>
            `;
        }).join('');

        renderPagination();
    };

    renderPage();

    if (prevButton) {
        prevButton.addEventListener('click', () => {
            if (totalPages <= 1) {
                return;
            }
            currentPage = (currentPage - 1 + totalPages) % totalPages;
            renderPage();
        });
    }

    if (nextButton) {
        nextButton.addEventListener('click', () => {
            if (totalPages <= 1) {
                return;
            }
            currentPage = (currentPage + 1) % totalPages;
            renderPage();
        });
    }

    if (pageNumbers) {
        pageNumbers.addEventListener('click', (event) => {
            const pageButton = event.target instanceof HTMLElement ? event.target.closest('[data-page]') : null;
            if (!(pageButton instanceof HTMLElement)) {
                return;
            }
            if (totalPages <= 1) {
                return;
            }
            const targetPage = Number(pageButton.getAttribute('data-page'));
            if (!Number.isInteger(targetPage) || targetPage < 1 || targetPage > totalPages) {
                return;
            }
            currentPage = targetPage - 1;
            renderPage();
        });
    }

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

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

$stmt = $pdo->prepare("
    SELECT
        f.title,
        f.site,
        f.type,
        f.occurred_at
    FROM sf_flashes f
    WHERE f.state = 'published'
      AND (f.display_expires_at IS NULL OR f.display_expires_at > NOW())
      AND f.display_removed_at IS NULL
    ORDER BY COALESCE(f.occurred_at, f.created_at) DESC
    LIMIT 300
");
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

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
        'event_date' => $formattedDate,
    ];
}, $rows);

$backgroundPath = trim((string)sf_get_setting('xibo_summary_background_image', ''));
$baseUrl = rtrim((string)($config['base_url'] ?? ''), '/');
$appBaseUrl = $baseUrl;
$summaryPathSuffix = '/xibo/safetyflash-summary';
if ($appBaseUrl !== '' && substr($appBaseUrl, -strlen($summaryPathSuffix)) === $summaryPathSuffix) {
    $appBaseUrl = substr($appBaseUrl, 0, -strlen($summaryPathSuffix));
}
$base = rtrim($appBaseUrl, '/');
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

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="fi">
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
            background: linear-gradient(180deg, #f8fafc 0%, #e2e8f0 100%);
        }
        .sf-xibo-preview-card {
            max-width: 1440px;
            margin: 0 auto;
            border-radius: 16px;
            border: 1px solid #cbd5e1;
            background: #ffffff;
            box-shadow: 0 16px 40px rgba(15, 23, 42, 0.12);
            padding: 16px;
        }
        .sf-xibo-preview-frame {
            position: relative;
            width: 100%;
            aspect-ratio: 16 / 9;
            border-radius: 12px;
            border: 1px solid #cbd5e1;
            background: #0f172a;
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
            content: "";
            position: absolute;
            inset: 0;
            background: rgba(255, 255, 255, 0.86);
            z-index: 0;
        }
        .sf-summary-inner {
            position: relative;
            z-index: 1;
            height: 100%;
            display: flex;
            flex-direction: column;
        }
        .sf-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 24px;
        }
        .sf-title {
            margin: 0;
            font-size: 52px;
            font-weight: 800;
            letter-spacing: -0.02em;
            color: #0f172a;
        }
        .sf-pill {
            padding: 9px 14px;
            border-radius: 999px;
            border: 1px solid #fed7aa;
            background: #fff7ed;
            color: #c2410c;
            font-weight: 700;
            font-size: 20px;
        }
        .sf-table-head,
        .sf-row {
            display: grid;
            grid-template-columns: 2fr 1.2fr 0.9fr 1fr;
            column-gap: 18px;
            align-items: center;
        }
        .sf-table-head {
            font-size: 22px;
            font-weight: 700;
            color: #334155;
            margin-bottom: 12px;
            padding: 0 12px;
        }
        .sf-list {
            flex: 1;
            display: grid;
            gap: 12px;
            align-content: start;
        }
        .sf-row {
            min-height: 108px;
            padding: 16px 18px;
            border-radius: 18px;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            box-shadow: 0 8px 26px rgba(15, 23, 42, 0.08);
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
            font-size: 30px;
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
            background: rgba(255, 255, 255, 0.8);
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
    <div class="sf-xibo-preview-card">
        <div class="sf-xibo-preview-frame">
<?php endif; ?>
<div class="sf-stage">
    <div class="sf-summary" id="sfSummaryRoot">
        <div class="sf-summary-inner">
            <div class="sf-header">
                <h1 class="sf-title">Aktiiviset SafetyFlashit</h1>
                <div class="sf-pill">Koontinäkymä</div>
            </div>

            <div class="sf-table-head">
                <div>Otsikko</div>
                <div>Työmaa</div>
                <div>Tyyppi</div>
                <div>Tapahtuma-aika</div>
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
    </div>
</div>
<?php require_once __DIR__ . '/../../app/includes/footer.php'; ?>
<?php endif; ?>

<script>
(() => {
    'use strict';

    const flashes = <?= json_encode($flashes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const backgroundUrl = <?= json_encode($backgroundUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const isStandaloneMode = <?= $isStandaloneMode ? 'true' : 'false' ?>;
    const itemsPerPage = 7;
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
            red: { label: 'Punainen', className: 'sf-type--red' },
            yellow: { label: 'Keltainen', className: 'sf-type--yellow' },
            green: { label: 'Vihreä', className: 'sf-type--green' },
        };
        return knownTypes[normalized] || { label: rawType || '-', className: 'sf-type--default' };
    };

    const renderPage = () => {
        if (!flashes.length) {
            list.innerHTML = '<div class="sf-empty">Ei aktiivisia SafetyFlasheja</div>';
            indicator.textContent = 'Sivu 1 / 1';
            return;
        }

        const start = currentPage * itemsPerPage;
        const pageItems = flashes.slice(start, start + itemsPerPage);
        list.innerHTML = pageItems.map((flash) => {
            const type = getTypePresentation(flash.type);
            return `
            <div class="sf-row">
                <div class="sf-cell">${escapeHtml(flash.title)}</div>
                <div class="sf-cell">${escapeHtml(flash.site_name)}</div>
                <div class="sf-cell sf-type ${type.className}">${escapeHtml(type.label)}</div>
                <div class="sf-cell">${escapeHtml(flash.event_date)}</div>
            </div>
            `;
        }).join('');

        indicator.textContent = `Sivu ${currentPage + 1} / ${totalPages}`;
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

<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../assets/lib/Database.php';
require_once __DIR__ . '/../../app/includes/settings.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}

$apiKey = trim((string)($_GET['api_key'] ?? ''));
$configuredApiKeySetting = sf_get_setting('xibo_summary_api_key', null);
$configuredApiKey = $configuredApiKeySetting === null ? '' : trim((string)$configuredApiKeySetting);
if ($configuredApiKeySetting === null) {
    $configuredApiKey = trim((string)(getenv('XIBO_SUMMARY_API_KEY') ?: ''));
}

if ($apiKey === '' || $configuredApiKey === '' || !hash_equals($configuredApiKey, $apiKey)) {
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
$backgroundUrl = '';
if ($backgroundPath !== '' && $baseUrl !== '') {
    $backgroundUrl = $baseUrl . '/' . ltrim($backgroundPath, '/');
}

header('Content-Type: text/html; charset=utf-8');
?>
<!doctype html>
<html lang="fi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=1920, initial-scale=1.0">
    <title>SafetyFlash koonti</title>
    <style>
        html, body {
            margin: 0;
            width: 1920px;
            height: 1080px;
            overflow: hidden;
            font-family: "Segoe UI", Roboto, Arial, sans-serif;
            color: #0f172a;
            background: #ffffff;
        }
        .sf-summary {
            position: relative;
            width: 1920px;
            height: 1080px;
            box-sizing: border-box;
            padding: 56px 64px;
            background-color: #ffffff;
            background-image: var(--sf-bg-image);
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
        .sf-row .sf-type {
            font-weight: 700;
            color: #b91c1c;
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
<body>
<div class="sf-summary" id="sfSummaryRoot" style="--sf-bg-image: none;">
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

<script>
(() => {
    'use strict';

    const flashes = <?= json_encode($flashes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const backgroundUrl = <?= json_encode($backgroundUrl, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const itemsPerPage = 7;
    const totalPages = Math.max(1, Math.ceil(flashes.length / itemsPerPage));
    let currentPage = 0;

    const root = document.getElementById('sfSummaryRoot');
    const list = document.getElementById('sfSummaryList');
    const indicator = document.getElementById('sfPageIndicator');

    if (backgroundUrl) {
        root.style.setProperty('--sf-bg-image', `url("${backgroundUrl}")`);
    }

    const escapeHtml = (value) => String(value || '')
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');

    const renderPage = () => {
        if (!flashes.length) {
            list.innerHTML = '<div class="sf-empty">Ei aktiivisia SafetyFlasheja</div>';
            indicator.textContent = 'Sivu 1 / 1';
            return;
        }

        const start = currentPage * itemsPerPage;
        const pageItems = flashes.slice(start, start + itemsPerPage);
        list.innerHTML = pageItems.map((flash) => `
            <div class="sf-row">
                <div class="sf-cell">${escapeHtml(flash.title)}</div>
                <div class="sf-cell">${escapeHtml(flash.site_name)}</div>
                <div class="sf-cell sf-type">${escapeHtml(flash.type)}</div>
                <div class="sf-cell">${escapeHtml(flash.event_date)}</div>
            </div>
        `).join('');

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

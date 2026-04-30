<?php
// archive.php - public archive view
declare(strict_types=1);

$encodedBase = htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="fi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>SafetyFlash – Arkisto</title>
    <link rel="stylesheet" href="<?= $encodedBase ?>/assets/css/public.css">
</head>
<body class="sf-public sf-public--archive">
<div class="sf-archive">
    <div class="sf-archive__filters" id="sf-filters">
        <select id="sf-filter-site" class="sf-filter-select" aria-label="Työmaa">
            <option value="">Kaikki työmaat</option>
        </select>
        <input type="text" id="sf-filter-q" class="sf-filter-input" placeholder="Hae..." aria-label="Hae">
        <input type="date" id="sf-filter-from" class="sf-filter-date" aria-label="Alkaen">
        <input type="date" id="sf-filter-to" class="sf-filter-date" aria-label="Päättyen">
        <button id="sf-filter-apply" class="sf-filter-btn">Hae</button>
        <button id="sf-filter-clear" class="sf-filter-btn sf-filter-btn--secondary">Tyhjennä</button>
    </div>
    <div id="sf-flash-grid" class="sf-archive__grid" aria-live="polite" aria-label="SafetyFlash-lista"></div>
    <div class="sf-archive__loading" id="sf-loading">
        <div class="sf-spinner"></div>
    </div>
    <div class="sf-archive__empty" id="sf-empty" hidden>
        <p>Ei SafetyFlasheja</p>
    </div>
    <nav class="sf-archive__pagination" id="sf-pagination" aria-label="Sivutus"></nav>
</div>

<div id="sf-modal" class="sf-modal" role="dialog" aria-modal="true" hidden>
    <div class="sf-modal__backdrop" id="sf-modal-backdrop"></div>
    <div class="sf-modal__content">
        <button class="sf-modal__close" id="sf-modal-close" aria-label="Sulje">&times;</button>
        <img class="sf-modal__image" id="sf-modal-image" src="" alt="">
        <div class="sf-modal__body">
            <h2 class="sf-modal__title" id="sf-modal-title"></h2>
            <p class="sf-modal__meta" id="sf-modal-meta"></p>
            <p class="sf-modal__summary" id="sf-modal-summary"></p>
        </div>
    </div>
</div>

<script src="<?= $encodedBase ?>/assets/js/public-archive.js"></script>
<script>
  window.sfArchiveConfig = {
    apiUrl: <?= json_encode($baseUrl . '/app/api/public/flashes.php', JSON_UNESCAPED_SLASHES) ?>,
    sitesUrl: <?= json_encode($baseUrl . '/app/api/public/sites.php', JSON_UNESCAPED_SLASHES) ?>,
    token: <?= json_encode($rawToken) ?>,
    allowedOrigin: <?= json_encode($allowedOrigin) ?>,
    perPage: 12
  };
</script>
<script src="<?= $encodedBase ?>/assets/js/public-resize.js"></script>
</body>
</html>

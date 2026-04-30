<?php
// carousel.php - public carousel view
// Variables available from public.php: $tokenPayload, $baseUrl, $config, $allowedOrigin, $jti, $siteId, $rawToken
declare(strict_types=1);

$interval     = max(5, min(60, (int)($_GET['interval'] ?? 15)));
$encodedToken = htmlspecialchars($rawToken, ENT_QUOTES, 'UTF-8');
$encodedBase  = htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="fi">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>SafetyFlash</title>
    <link rel="stylesheet" href="<?= $encodedBase ?>/assets/css/public.css">
</head>
<body class="sf-public sf-public--carousel">
<div id="sf-carousel" class="sf-carousel" aria-live="polite" aria-label="SafetyFlash karuselli">
    <div class="sf-carousel__track" id="sf-carousel-track"></div>
    <div class="sf-carousel__controls">
        <button class="sf-carousel__btn sf-carousel__btn--prev" id="sf-prev" aria-label="Edellinen">&#8249;</button>
        <div class="sf-carousel__dots" id="sf-dots"></div>
        <button class="sf-carousel__btn sf-carousel__btn--next" id="sf-next" aria-label="Seuraava">&#8250;</button>
    </div>
    <div class="sf-carousel__loading" id="sf-loading">
        <div class="sf-spinner"></div>
    </div>
    <div class="sf-carousel__empty" id="sf-empty" hidden>
        <p>Ei aktiivisia SafetyFlasheja</p>
    </div>
</div>

<div id="sf-modal" class="sf-modal" role="dialog" aria-modal="true" hidden>
    <div class="sf-modal__backdrop" id="sf-modal-backdrop"></div>
    <div class="sf-modal__content">
        <button class="sf-modal__close" id="sf-modal-close" aria-label="Sulje">&times;</button>
        <img class="sf-modal__image" id="sf-modal-image" src="" alt="">
        <div class="sf-modal__body">
            <h2 class="sf-modal__title" id="sf-modal-title"></h2>
            <p class="sf-modal__meta" id="sf-modal-meta"></p>
        </div>
    </div>
</div>

<script src="<?= $encodedBase ?>/assets/js/public-carousel.js"></script>
<script>
  window.sfCarouselConfig = {
    apiUrl: <?= json_encode($baseUrl . '/app/api/public/active.php', JSON_UNESCAPED_SLASHES) ?>,
    token: <?= json_encode($rawToken) ?>,
    interval: <?= (int)$interval ?>,
    allowedOrigin: <?= json_encode($allowedOrigin) ?>,
    refreshInterval: 300000
  };
</script>
<script src="<?= $encodedBase ?>/assets/js/public-resize.js"></script>
</body>
</html>

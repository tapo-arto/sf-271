<?php
declare(strict_types=1);

// Load public bootstrap (no session, no auth)
require_once __DIR__ . '/app/includes/public_bootstrap.php';

// ---- Database ---------------------------------------------------------------
try {
    $pdo = Database::getInstance();
} catch (\Throwable $e) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Service unavailable';
    exit;
}

// ---- Token validation -------------------------------------------------------
$rawToken = trim((string)($_GET['t'] ?? ''));

try {
    $tokenPayload = EmbedToken::verify($rawToken, $pdo);
} catch (\InvalidArgumentException $e) {
    PublicAudit::log($pdo, null, 'unknown', null, 401);
    http_response_code(401);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Unauthorized</title></head>'
       . '<body style="font-family:sans-serif;text-align:center;padding:2rem;">'
       . '<h2>401 Unauthorized</h2><p>Invalid or missing embed token.</p>'
       . '</body></html>';
    exit;
} catch (\RuntimeException $e) {
    $msg  = $e->getMessage();
    $code = str_contains($msg, 'revoked') ? 410 : 401;
    PublicAudit::log($pdo, null, 'unknown', null, $code);
    http_response_code($code);
    header('Content-Type: text/html; charset=utf-8');
    $label  = $code === 410 ? 'Token Revoked' : 'Token Expired / Invalid';
    $detail = htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
    echo "<!DOCTYPE html><html><head><meta charset=\"UTF-8\"><title>{$label}</title></head>"
       . "<body style=\"font-family:sans-serif;text-align:center;padding:2rem;\">"
       . "<h2>{$code} {$label}</h2><p>{$detail}</p>"
       . "</body></html>";
    exit;
}

// ---- Extract claims ---------------------------------------------------------
$allowedOrigin = (string)($tokenPayload['aud'] ?? '');
$viewType      = (string)($tokenPayload['view'] ?? 'carousel');
$siteId        = ($tokenPayload['site'] ?? '*') !== '*' ? (int)$tokenPayload['site'] : null;
$jti           = (string)($tokenPayload['jti'] ?? '');

// ---- Security headers (override security_headers.php defaults) --------------
sf_public_headers($allowedOrigin !== '' ? $allowedOrigin : "'none'");

// ---- Origin / Referer check -------------------------------------------------
$requestOrigin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
$requestReferer = (string)($_SERVER['HTTP_REFERER'] ?? '');
$secFetchSite  = (string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');

$originOk = false;

if (in_array($secFetchSite, ['same-origin', 'none'], true)) {
    $originOk = true;
} elseif ($requestOrigin !== '' && rtrim($requestOrigin, '/') === rtrim($allowedOrigin, '/')) {
    $originOk = true;
} elseif ($requestOrigin === '' && $requestReferer !== '') {
    // Derive origin from Referer header
    $parsed   = parse_url($requestReferer);
    $refOrigin = ($parsed['scheme'] ?? '') . '://' . ($parsed['host'] ?? '');
    if (isset($parsed['port'])) {
        $refOrigin .= ':' . $parsed['port'];
    }
    if (rtrim($refOrigin, '/') === rtrim($allowedOrigin, '/')) {
        $originOk = true;
    }
} elseif ($requestOrigin === '' && $requestReferer === '') {
    // Direct navigation (address bar / curl) – allow for testing
    $originOk = true;
}

if (!$originOk) {
    PublicAudit::log($pdo, $jti, $viewType, $siteId, 403);
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Forbidden</title></head>'
       . '<body style="font-family:sans-serif;text-align:center;padding:2rem;">'
       . '<h2>403 Forbidden</h2><p>This embed is not authorized for this origin.</p>'
       . '</body></html>';
    exit;
}

// ---- Rate limiting ----------------------------------------------------------
$clientIp = sf_public_ip();
if (!PublicRateLimit::check($pdo, $clientIp, $jti, 'public')) {
    PublicAudit::log($pdo, $jti, $viewType, $siteId, 429);
    http_response_code(429);
    header('Retry-After: 60');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Too Many Requests</title></head>'
       . '<body style="font-family:sans-serif;text-align:center;padding:2rem;">'
       . '<h2>429 Too Many Requests</h2><p>Please wait before trying again.</p>'
       . '</body></html>';
    exit;
}

// ---- Audit ------------------------------------------------------------------
PublicAudit::log($pdo, $jti, $viewType, $siteId, 200);

// ---- Route to view ----------------------------------------------------------
$baseUrl = rtrim($config['base_url'] ?? '', '/');

if ($viewType === 'archive') {
    require __DIR__ . '/assets/pages/public/archive.php';
} else {
    require __DIR__ . '/assets/pages/public/carousel.php';
}

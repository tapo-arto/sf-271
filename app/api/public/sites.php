<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/includes/public_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

// ---- Database ---------------------------------------------------------------
try {
    $pdo = Database::getInstance();
} catch (\Throwable $e) {
    http_response_code(503);
    echo json_encode(['error' => 'Service unavailable']);
    exit;
}

// ---- Token validation -------------------------------------------------------
$rawToken = trim((string)($_GET['t'] ?? ''));
try {
    $tokenPayload = EmbedToken::verify($rawToken, $pdo);
} catch (\Throwable $e) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid token']);
    exit;
}

$tokenSiteId   = ($tokenPayload['site'] ?? '*') !== '*' ? (int)$tokenPayload['site'] : null;
$allowedOrigin = (string)($tokenPayload['aud'] ?? '');

// ---- Security headers -------------------------------------------------------
sf_public_headers($allowedOrigin !== '' ? $allowedOrigin : "'none'");

// ---- Query ------------------------------------------------------------------
if ($tokenSiteId !== null) {
    $stmt = $pdo->prepare(
        'SELECT id, name FROM sf_worksites WHERE id = :id AND is_active = 1 LIMIT 1'
    );
    $stmt->execute([':id' => $tokenSiteId]);
} else {
    $stmt = $pdo->query(
        'SELECT id, name FROM sf_worksites
          WHERE is_active = 1 AND show_in_worksite_lists = 1
          ORDER BY name ASC'
    );
}

$sites = $stmt->fetchAll(\PDO::FETCH_ASSOC);

echo json_encode(['sites' => $sites], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

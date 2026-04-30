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

$jti           = (string)($tokenPayload['jti'] ?? '');
$siteId        = ($tokenPayload['site'] ?? '*') !== '*' ? (int)$tokenPayload['site'] : null;
$allowedOrigin = (string)($tokenPayload['aud'] ?? '');

// ---- Rate limiting ----------------------------------------------------------
$clientIp = sf_public_ip();
if (!PublicRateLimit::check($pdo, $clientIp, $jti, 'api')) {
    http_response_code(429);
    header('Retry-After: 60');
    echo json_encode(['error' => 'Rate limit exceeded']);
    exit;
}

// ---- Security headers -------------------------------------------------------
sf_public_headers($allowedOrigin !== '' ? $allowedOrigin : "'none'");

// ---- Query ------------------------------------------------------------------
$baseUrl = rtrim($config['base_url'] ?? '', '/');

$where  = ["f.state = 'published'", 'f.is_archived = 0'];
$params = [];

if ($siteId !== null) {
    $where[]              = 'f.site = (SELECT name FROM sf_worksites WHERE id = :site_id LIMIT 1)';
    $params[':site_id']   = $siteId;
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

$stmt = $pdo->prepare("
    SELECT
        f.public_uid,
        f.title,
        f.occurred_at,
        f.site      AS site_name,
        f.summary,
        f.preview_filename
    FROM sf_flashes f
    {$whereSql}
    ORDER BY f.occurred_at DESC
    LIMIT 50
");
$stmt->execute($params);
$rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);

$items = array_map(static function (array $row) use ($baseUrl): array {
    return [
        'public_uid'      => $row['public_uid'],
        'title'           => $row['title'],
        'occurred_at'     => $row['occurred_at'],
        'site_name'       => $row['site_name'],
        'summary'         => $row['summary'],
        'cover_image_url' => $row['preview_filename']
            ? $baseUrl . '/uploads/previews/' . $row['preview_filename']
            : $baseUrl . '/assets/img/placeholder.svg',
        'content_url'     => $row['preview_filename']
            ? $baseUrl . '/uploads/previews/' . $row['preview_filename']
            : null,
    ];
}, $rows);

// ---- Audit ------------------------------------------------------------------
PublicAudit::log($pdo, $jti, 'carousel', $siteId, 200);

echo json_encode(['items' => $items], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

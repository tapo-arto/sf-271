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
$tokenSiteId   = ($tokenPayload['site'] ?? '*') !== '*' ? (int)$tokenPayload['site'] : null;
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

$baseUrl = rtrim($config['base_url'] ?? '', '/');

// ---- Filter parameters (sanitized) ------------------------------------------
$filterSite = trim((string)($_GET['site'] ?? ''));
$filterQ    = trim((string)($_GET['q'] ?? ''));
$filterFrom = trim((string)($_GET['from'] ?? ''));
$filterTo   = trim((string)($_GET['to'] ?? ''));
$page       = max(1, (int)($_GET['p'] ?? 1));
$perPage    = min(50, max(1, (int)($_GET['per_page'] ?? 12)));
$offset     = ($page - 1) * $perPage;

// ---- Build WHERE ------------------------------------------------------------
$where  = ["f.state = 'published'"];
$params = [];

if ($tokenSiteId !== null) {
    // Token is scoped to one site – ignore any caller-supplied site filter
    $where[]                  = 'f.site = (SELECT name FROM sf_worksites WHERE id = :token_site_id LIMIT 1)';
    $params[':token_site_id'] = $tokenSiteId;
} elseif ($filterSite !== '') {
    $where[]              = 'f.site = :filter_site';
    $params[':filter_site'] = $filterSite;
}

if ($filterQ !== '') {
    $escapedQ         = addcslashes($filterQ, '%_\\');
    $qVal             = '%' . $escapedQ . '%';
    $where[]          = '(f.title LIKE :q1 OR f.summary LIKE :q2)';
    $params[':q1']    = $qVal;
    $params[':q2']    = $qVal;
}

if ($filterFrom !== '') {
    $where[]              = 'f.occurred_at >= :from_date';
    $params[':from_date'] = $filterFrom . ' 00:00:00';
}

if ($filterTo !== '') {
    $where[]            = 'f.occurred_at <= :to_date';
    $params[':to_date'] = $filterTo . ' 23:59:59';
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

// ---- Count total rows -------------------------------------------------------
$countStmt = $pdo->prepare("SELECT COUNT(*) FROM sf_flashes f {$whereSql}");
$countStmt->execute($params);
$total      = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

// ---- Fetch page -------------------------------------------------------------
$dataStmt = $pdo->prepare("
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
    LIMIT " . (int)$perPage . ' OFFSET ' . (int)$offset . '
');
$dataStmt->execute($params);
$rows = $dataStmt->fetchAll(\PDO::FETCH_ASSOC);

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
PublicAudit::log($pdo, $jti, 'archive', $tokenSiteId, 200);

echo json_encode([
    'items'       => $items,
    'total'       => $total,
    'page'        => $page,
    'per_page'    => $perPage,
    'total_pages' => $totalPages,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

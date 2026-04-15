<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';

// Admin only
$user = sf_current_user();
if (!$user || (int)($user['role_id'] ?? 0) !== 1) {
    http_response_code(403);
    echo 'Forbidden';
    exit;
}

$format = strtolower(trim($_GET['format'] ?? 'csv'));
if (!in_array($format, ['csv', 'json'], true)) {
    $format = 'csv';
}

$baseUrl = rtrim($config['base_url'] ?? '', '/');
$date = date('Y-m-d');

// Query worksites (same as tab_worksites.php)
$pdo = Database::getInstance();
$worksites = [];
$stmt = $pdo->query(
    'SELECT w.id, w.name, w.is_active, k.api_key AS display_api_key,
            COUNT(t.id) AS active_flash_count
     FROM sf_worksites w
     LEFT JOIN sf_display_api_keys k ON k.worksite_id = w.id AND k.is_active = 1
     LEFT JOIN sf_flash_display_targets t ON t.display_key_id = k.id AND t.is_active = 1
     GROUP BY w.id, w.name, w.is_active, k.api_key
     ORDER BY w.name ASC'
);
if ($stmt) {
    $worksites = $stmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt2 = $pdo->query('SELECT id, name, is_active, NULL AS display_api_key, 0 AS active_flash_count FROM sf_worksites ORDER BY name ASC');
    if ($stmt2) {
        $worksites = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    }
}

if ($format === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="worksites_' . $date . '.json"');

    $output = [
        'exported_at' => date('c'),
        'worksites' => array_map(function ($ws) use ($baseUrl) {
            $xiboUrl = '';
            if (!empty($ws['display_api_key'])) {
                $xiboUrl = $baseUrl . '/app/api/display_playlist.php?key=' . urlencode($ws['display_api_key']) . '&format=html';
            }
            return [
                'name' => $ws['name'],
                'is_active' => (int)$ws['is_active'] === 1,
                'api_key' => $ws['display_api_key'] ?? '',
                'xibo_url' => $xiboUrl,
                'active_flash_count' => (int)($ws['active_flash_count'] ?? 0),
            ];
        }, $worksites),
    ];

    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// CSV format (default)
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="worksites_' . $date . '.csv"');

// UTF-8 BOM for Excel
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

// Header row
fputcsv($out, ['Työmaa', 'Aktiivinen', 'API-avain', 'Xibo URL', 'Aktiiviset flashit'], ';');

foreach ($worksites as $ws) {
    $xiboUrl = '';
    if (!empty($ws['display_api_key'])) {
        $xiboUrl = $baseUrl . '/app/api/display_playlist.php?key=' . urlencode($ws['display_api_key']) . '&format=html';
    }

    fputcsv($out, [
        $ws['name'],
        (int)$ws['is_active'] === 1 ? 'Kyllä' : 'Ei',
        $ws['display_api_key'] ?? '',
        $xiboUrl,
        (int)($ws['active_flash_count'] ?? 0),
    ], ';');
}

fclose($out);
exit;
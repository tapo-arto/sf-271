<?php
// app/api/injury-heatmap.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../../assets/lib/sf_terms.php';

$uiLang = $_SESSION['ui_lang'] ?? 'fi';

try {
    $pdo = Database::getInstance();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// --- Input validation ---
$period = $_GET['period'] ?? '';
$month  = isset($_GET['month']) && $_GET['month'] !== '' ? (int)$_GET['month'] : null;
$year   = isset($_GET['year'])  && $_GET['year']  !== '' ? (int)$_GET['year']  : null;
$site   = isset($_GET['site'])  ? trim($_GET['site']) : '';

$allowedPeriods = ['thismonth', '3months', '6months', 'thisyear', 'all'];
if ($period && !in_array($period, $allowedPeriods, true)) {
    $period = '';
}
if ($month !== null && ($month < 1 || $month > 12)) {
    $month = null;
}
if ($year !== null && ($year < 1900 || $year > (int)date('Y') + 10)) {
    $year = null;
}

// --- Date filter ---
$dateFilter = '';
$params     = [];

if ($month !== null && $year !== null) {
    $dateFilter          = 'AND f.created_at >= :start_date AND f.created_at < :end_date';
    $params[':start_date'] = sprintf('%04d-%02d-01', $year, $month);
    $params[':end_date']   = date('Y-m-01', strtotime(sprintf('%04d-%02d-01', $year, $month) . ' +1 month'));
} elseif ($year !== null) {
    $dateFilter          = 'AND f.created_at >= :start_date AND f.created_at < :end_date';
    $params[':start_date'] = sprintf('%04d-01-01', $year);
    $params[':end_date']   = sprintf('%04d-01-01', $year + 1);
} elseif ($month !== null) {
    $dateFilter      = 'AND MONTH(f.created_at) = :month';
    $params[':month'] = $month;
} elseif ($period) {
    switch ($period) {
        case 'thismonth':
            $dateFilter = "AND f.created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')";
            break;
        case '3months':
            $dateFilter = 'AND f.created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)';
            break;
        case '6months':
            $dateFilter = 'AND f.created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)';
            break;
        case 'thisyear':
            $dateFilter = "AND f.created_at >= DATE_FORMAT(NOW(), '%Y-01-01')";
            break;
        default:
            $dateFilter = '';
            break;
    }
}

// --- Site filter ---
$siteFilter = '';
if ($site !== '') {
    $siteFilter      = 'AND f.site = :site';
    $params[':site'] = $site;
}

// --- 1. Body-part injury counts ---
$bodyPartCounts = [];
try {
    $sql = "
        SELECT
            bp.svg_id,
            bp.category,
            COUNT(DISTINCT COALESCE(f.translation_group_id, f.id)) AS cnt
        FROM body_parts bp
        LEFT JOIN incident_body_part ibp ON ibp.body_part_id = bp.id
        LEFT JOIN sf_flashes f
            ON  f.id    = ibp.incident_id
            AND f.state = 'published'
            $dateFilter
            $siteFilter
        GROUP BY bp.id, bp.svg_id, bp.category
        ORDER BY bp.sort_order
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $bodyPartCounts[] = [
            'svg_id'   => $row['svg_id'],
            'name'     => sf_bp_term($row['svg_id'], $uiLang),
            'category' => sf_bp_category_term($row['category'], $uiLang),
            'count'    => (int)$row['cnt'],
        ];
    }
} catch (Throwable $e) {
    // silent fail – return empty array
}

// --- 2. Recent flashes that have at least one injury annotation ---
$recentFlashes = [];
try {
    $sql = "
        SELECT DISTINCT
            f.id,
            f.type,
            COALESCE(NULLIF(f.title_short, ''), f.title) AS title,
            f.site,
            f.updated_at
        FROM sf_flashes f
        INNER JOIN incident_body_part ibp ON ibp.incident_id = f.id
        WHERE f.state = 'published'
          $dateFilter
          $siteFilter
        ORDER BY f.updated_at DESC
        LIMIT 200
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($rows)) {
        // Fetch body-part svg_ids for each flash in a single query
        $ids          = array_map(static fn($r) => (int)$r['id'], $rows);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $bpSql        = "
            SELECT ibp.incident_id, bp.svg_id
            FROM   incident_body_part ibp
            INNER JOIN body_parts bp ON bp.id = ibp.body_part_id
            WHERE  ibp.incident_id IN ($placeholders)
        ";
        $bpStmt = $pdo->prepare($bpSql);
        $bpStmt->execute($ids);
        $bpMap = [];
        foreach ($bpStmt->fetchAll(PDO::FETCH_ASSOC) as $bpRow) {
            $bpMap[(int)$bpRow['incident_id']][] = $bpRow['svg_id'];
        }

        foreach ($rows as $row) {
            $fid             = (int)$row['id'];
            $recentFlashes[] = [
                'id'         => $fid,
                'type'       => $row['type']       ?? '',
                'title'      => $row['title']      ?? '',
                'site'       => $row['site']       ?? '',
                'updated_at' => $row['updated_at'] ?? '',
                'body_parts' => $bpMap[$fid]       ?? [],
            ];
        }
    }
} catch (Throwable $e) {
    // silent fail
}

// --- 3. Worksites that have injury data (for the dropdown) ---
$sites = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT f.site
        FROM   sf_flashes f
        INNER JOIN incident_body_part ibp ON ibp.incident_id = f.id
        WHERE  f.state = 'published'
          AND  f.site IS NOT NULL
          AND  f.site <> ''
        ORDER BY f.site ASC
    ");
    $stmt->execute();
    $sites = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'site');
} catch (Throwable $e) {
    // silent fail
}

echo json_encode([
    'bodyPartCounts' => $bodyPartCounts,
    'recentFlashes'  => $recentFlashes,
    'sites'          => $sites,
], JSON_UNESCAPED_UNICODE);
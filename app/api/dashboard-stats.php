<?php
// app/api/dashboard-stats.php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/protect.php';

try {
    $pdo = Database::getInstance();
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Get period parameter
$period = $_GET['period'] ?? '';
$month = isset($_GET['month']) && $_GET['month'] !== '' ? (int)$_GET['month'] : null;
$year = isset($_GET['year']) && $_GET['year'] !== '' ? (int)$_GET['year'] : null;

// Validate inputs
$allowedPeriods = ['thismonth', '3months', '6months', 'thisyear', 'all'];
if ($period && !in_array($period, $allowedPeriods, true)) {
    $period = '';
}
if ($month !== null && ($month < 1 || $month > 12)) {
    $month = null;
}
if ($year !== null && ($year < 1900 || $year > 2100)) {
    $year = null;
}

// Build date filter based on parameters
$dateFilter = '';
$params = [];

// Priority 1: month and year dropdowns
if ($month !== null && $year !== null) {
    // Specific month and year
    $dateFilter = "AND created_at >= :start_date AND created_at < :end_date";
    $params[':start_date'] = sprintf('%04d-%02d-01', $year, $month);
    $params[':end_date'] = date('Y-m-01', strtotime(sprintf('%04d-%02d-01', $year, $month) . ' +1 month'));
} elseif ($year !== null && $month === null) {
    // Specific year, all months
    $dateFilter = "AND created_at >= :start_date AND created_at < :end_date";
    $params[':start_date'] = sprintf('%04d-01-01', $year);
    $params[':end_date'] = sprintf('%04d-01-01', $year + 1);
} elseif ($month !== null && $year === null) {
    // Specific month, all years
    // Note: This uses MONTH() which prevents index usage - may be slow on large datasets
    // This is an edge case and rarely used in practice
    $dateFilter = "AND MONTH(created_at) = :month";
    $params[':month'] = $month;
}
// Priority 2: period parameter (quick selections)
elseif ($period) {
    switch ($period) {
        case 'thismonth':
            $dateFilter = "AND created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')";
            break;
        case '3months':
            $dateFilter = "AND created_at >= DATE_SUB(NOW(), INTERVAL 3 MONTH)";
            break;
        case '6months':
            $dateFilter = "AND created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)";
            break;
        case 'thisyear':
            $dateFilter = "AND created_at >= DATE_FORMAT(NOW(), '%Y-01-01')";
            break;
        case 'all':
        default:
            $dateFilter = '';
            break;
    }
}
// Default: all time
else {
    $dateFilter = '';
}

// Get original type statistics (including converted to green)
$originalStats = ['red' => 0, 'yellow' => 0, 'total' => 0];

try {
    $sql = "
        SELECT 
            COALESCE(original_type, type) as original_type,
            COUNT(DISTINCT COALESCE(translation_group_id, id)) as count
        FROM sf_flashes 
        WHERE state = 'published'
        $dateFilter
        GROUP BY COALESCE(original_type, type)
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $type = $row['original_type'] ?? '';
        $count = (int)($row['count'] ?? 0);
        if (isset($originalStats[$type])) {
            $originalStats[$type] = $count;
        }
        if ($type !== 'green') { // Don't count green in original stats total
            $originalStats['total'] += $count;
        }
    }
} catch (Throwable $e) {
    // Silent fail, return zeros
}

// Get worksite statistics
$worksiteStats = [];

try {
    $sql = "
        SELECT 
            site, 
            COUNT(DISTINCT COALESCE(translation_group_id, id)) as count 
        FROM sf_flashes 
        WHERE state = 'published' 
        AND site IS NOT NULL 
        AND site != ''
        $dateFilter
        GROUP BY site 
        ORDER BY count DESC
        LIMIT 15
    ";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $worksiteStats[] = [
            'site' => $row['site'] ?? '',
            'count' => (int)($row['count'] ?? 0)
        ];
    }
} catch (Throwable $e) {
    // Silent fail, return empty array
}

// Return JSON response
echo json_encode([
    'originalStats' => $originalStats,
    'worksiteStats' => $worksiteStats
], JSON_UNESCAPED_UNICODE);
<?php
// app/api/bulk_update_notifications.php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/log_app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/protect.php';

header('Content-Type: application/json; charset=utf-8');

// Only admins can perform bulk actions
sf_require_role([1]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid method']);
    exit;
}

$mysqli = sf_db();

$userIds = $_POST['user_ids'] ?? [];
$enabled = isset($_POST['email_notifications_enabled']) ? (int)$_POST['email_notifications_enabled'] : 1;

// Normalize to 0 or 1
$enabled = $enabled ? 1 : 0;

// Validate user IDs array
if (!is_array($userIds) || empty($userIds)) {
    echo json_encode(['ok' => false, 'error' => 'No users selected']);
    exit;
}

// Convert to integers and filter invalid values
$userIds = array_map('intval', $userIds);
$userIds = array_filter($userIds, function($id) {
    return $id > 0;
});

if (empty($userIds)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid user IDs']);
    exit;
}

// Build placeholders for IN clause
$placeholders = implode(',', array_fill(0, count($userIds), '?'));
$sql = "UPDATE sf_users SET email_notifications_enabled = ? WHERE id IN ($placeholders)";

$stmt = $mysqli->prepare($sql);
if (!$stmt) {
    sf_app_log("bulk_update_notifications: Failed to prepare statement", LOG_LEVEL_ERROR);
    echo json_encode(['ok' => false, 'error' => 'Database error']);
    exit;
}

// Build bind_param types string: 'i' for enabled + 'i' for each user ID
$types = 'i' . str_repeat('i', count($userIds));
$params = array_merge([$enabled], $userIds);

$stmt->bind_param($types, ...$params);

if (!$stmt->execute()) {
    sf_app_log("bulk_update_notifications: Failed to execute update", LOG_LEVEL_ERROR);
    echo json_encode(['ok' => false, 'error' => 'Database error']);
    exit;
}

$affectedRows = $stmt->affected_rows;
$stmt->close();

sf_app_log("bulk_update_notifications: Updated $affectedRows users, enabled=$enabled");

echo json_encode(['ok' => true, 'affected' => $affectedRows]);

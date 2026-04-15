<?php
// app/api/profile_get.php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/protect.php';

header('Content-Type: application/json; charset=utf-8');

$user = sf_current_user();
if (!$user) {
    echo json_encode(['ok' => false, 'error' => 'Ei kirjautunut']);
    exit;
}

$mysqli = sf_db();

// Hae roolin nimi
$roleStmt = $mysqli->prepare("SELECT name FROM sf_roles WHERE id = ?");
$roleStmt->bind_param('i', $user['role_id']);
$roleStmt->execute();
$roleResult = $roleStmt->get_result();
$roleName = $roleResult->fetch_assoc()['name'] ?? '-';
$roleStmt->close();

// Hae työmaat
$worksitesRes = $mysqli->query("SELECT id, name FROM sf_worksites WHERE is_active = 1 ORDER BY name ASC");
$worksites = [];
while ($w = $worksitesRes->fetch_assoc()) {
    $worksites[] = $w;
}

echo json_encode([
    'ok' => true,
    'user' => [
        'id' => $user['id'],
        'first_name' => $user['first_name'],
        'last_name' => $user['last_name'],
        'email' => $user['email'],
        'role_id' => $user['role_id'],
        'role_name' => $roleName,
        'home_worksite_id' => $user['home_worksite_id']
    ],
    'worksites' => $worksites
]);
<?php
// app/api/users_update.php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/log_app.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/protect.php';

header('Content-Type:  application/json; charset=utf-8');

sf_require_role([1]);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => 'Invalid method']);
    exit;
}

$mysqli = sf_db();

$id    = (int)($_POST['id'] ?? 0);
$first = trim($_POST['first_name'] ?? '');
$last  = trim($_POST['last_name'] ?? '');
$email = trim($_POST['email'] ?? '');
$role  = (int)($_POST['role_id'] ?? 0);
$pass  = $_POST['password'] ??  '';

// Kieli
$uiLang = trim($_POST['ui_lang'] ?? 'fi');
$validLangs = ['fi', 'sv', 'en', 'it', 'el'];
if (!in_array($uiLang, $validLangs, true)) {
    $uiLang = 'fi';
}

// Roolikategoriat
$roleCategoryIds = $_POST['role_category_ids'] ?? [];
if (!is_array($roleCategoryIds)) {
    $roleCategoryIds = [];
}
$roleCategoryIds = array_values(array_unique(array_map('intval', $roleCategoryIds)));
$roleCategoryIds = array_values(array_filter($roleCategoryIds, fn($v) => (int)$v > 0));

// KOTITYÖMAA
$homeWorksiteId = $_POST['home_worksite_id'] ??  '';
if ($homeWorksiteId === '' || $homeWorksiteId === null) {
    $homeWorksiteId = null;
} else {
    $homeWorksiteId = (int)$homeWorksiteId;
    if ($homeWorksiteId <= 0) {
        $homeWorksiteId = null;
    }
}

// EMAIL NOTIFICATIONS
$emailNotificationsEnabled = isset($_POST['email_notifications_enabled']) ? (int)$_POST['email_notifications_enabled'] : 1;
$emailNotificationsEnabled = $emailNotificationsEnabled ? 1 : 0;

if ($id <= 0 || $first === '' || $last === '' || $email === '' || $role <= 0) {
    echo json_encode(['ok' => false, 'error' => 'Puuttuvia tietoja']);
    exit;
}

// Get current role before update to check if it changes
$stmt = $mysqli->prepare('SELECT role_id FROM sf_users WHERE id = ? LIMIT 1');
$stmt->bind_param('i', $id);
$stmt->execute();
$result = $stmt->get_result();
$oldUser = $result ? $result->fetch_assoc() : null;
$oldRoleId = $oldUser ? (int)$oldUser['role_id'] : null;
$stmt->close();

$roleChanged = ($oldRoleId !== null && $oldRoleId !== $role);

// Onko email jonkun toisen käytössä? 
$stmt = $mysqli->prepare('SELECT id FROM sf_users WHERE email = ? AND id != ? AND is_active = 1 LIMIT 1');
$stmt->bind_param('si', $email, $id);
$stmt->execute();
$stmt->store_result();
if ($stmt->num_rows > 0) {
    echo json_encode(['ok' => false, 'error' => 'Tällä sähköpostilla on jo toinen käyttäjä']);
    exit;
}
$stmt->close();

// Päivitetään perustiedot + koti työmaa + email notifications
if ($pass !== '') {
    $hash = password_hash($pass, PASSWORD_DEFAULT);

    if ($hash === false) {
        echo json_encode(['ok' => false, 'error' => 'Salasanan hash muodostus epäonnistui']);
        exit;
    }

    if ($homeWorksiteId === null) {
        $stmt = $mysqli->prepare(
            'UPDATE sf_users
             SET first_name = ?, last_name = ?, email = ?, role_id = ?, ui_lang = ?, home_worksite_id = NULL, password_hash = ?, email_notifications_enabled = ?
             WHERE id = ?'
        );
        $stmt->bind_param('sssissii', $first, $last, $email, $role, $uiLang, $hash, $emailNotificationsEnabled, $id);
    } else {
        $stmt = $mysqli->prepare(
            'UPDATE sf_users
             SET first_name = ?, last_name = ?, email = ?, role_id = ?, ui_lang = ?, home_worksite_id = ?, password_hash = ?, email_notifications_enabled = ?
             WHERE id = ?'
        );
        $stmt->bind_param('sssisisii', $first, $last, $email, $role, $uiLang, $homeWorksiteId, $hash, $emailNotificationsEnabled, $id);
    }
} else {
    if ($homeWorksiteId === null) {
        $stmt = $mysqli->prepare(
            'UPDATE sf_users
             SET first_name = ?, last_name = ?, email = ?, role_id = ?, ui_lang = ?, home_worksite_id = NULL, email_notifications_enabled = ?
             WHERE id = ?'
        );
        $stmt->bind_param('sssisii', $first, $last, $email, $role, $uiLang, $emailNotificationsEnabled, $id);
    } else {
        $stmt = $mysqli->prepare(
            'UPDATE sf_users
             SET first_name = ?, last_name = ?, email = ?, role_id = ?, ui_lang = ?, home_worksite_id = ?, email_notifications_enabled = ?
             WHERE id = ?'
        );
        $stmt->bind_param('sssisiii', $first, $last, $email, $role, $uiLang, $homeWorksiteId, $emailNotificationsEnabled, $id);
    }
}

if (! $stmt->execute()) {
    echo json_encode(['ok' => false, 'error' => 'DB-virhe päivityksessä']);
    exit;
}

$stmt->close();

// Tallenna lisäroolit (vain admin - tämä endpoint on jo suojattu sf_require_role([1]))
$currentUser = sf_current_user();
if (isset($_POST['additional_roles'])) {
    $additionalRoles = $_POST['additional_roles'];
    if (is_array($additionalRoles)) {
        $additionalRoles = array_map('intval', $additionalRoles);
        // Älä tallenna päärolia lisäroolina
        $additionalRoles = array_filter($additionalRoles, fn($r) => $r !== (int)$role);
        
        sf_set_user_additional_roles($id, $additionalRoles, $currentUser['id'] ?? null);
    }
}

// Tallenna roolikategoriat
$delStmt = $mysqli->prepare("DELETE FROM user_role_categories WHERE user_id = ?");
$delStmt->bind_param('i', $id);
$delStmt->execute();
$delStmt->close();

if (!empty($roleCategoryIds)) {
    $stmt = $mysqli->prepare('INSERT IGNORE INTO user_role_categories (user_id, role_category_id) VALUES (?, ?)');
    if ($stmt) {
        foreach ($roleCategoryIds as $catId) {
            $stmt->bind_param('ii', $id, $catId);
            $stmt->execute();
        }
        $stmt->close();
    }
}

// Invalidoi käyttäjän sessio jos rooli vaihtui
if ($roleChanged) {
    if (!isset($_SESSION['invalidated_users'])) {
        $_SESSION['invalidated_users'] = [];
    }
    if (!in_array($id, $_SESSION['invalidated_users'])) {
        $_SESSION['invalidated_users'][] = $id;
    }
}

// Lokita onnistuminen (ilman arkaluontoisia tietoja)
$logPost = $_POST;
unset($logPost['password']);
sf_app_log("users_update: Käyttäjä päivitetty, id=$id, email=$email", LOG_LEVEL_INFO, $logPost);

echo json_encode(['ok' => true]);
exit;
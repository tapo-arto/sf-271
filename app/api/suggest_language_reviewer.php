<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../../assets/lib/sf_terms.php';

$user = sf_current_user();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$roleId = (int)($user['role_id'] ?? 0);
if (!in_array($roleId, [1, 4], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$flashId = (int)($_GET['flash_id'] ?? 0);
if ($flashId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid flash_id'], JSON_UNESCAPED_UNICODE);
    exit;
}

$csrfToken = (string)($_GET['csrf_token'] ?? '');
if (!sf_csrf_validate($csrfToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = Database::getInstance();

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sf_flash_language_reviewers (
            id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
            flash_id    INT UNSIGNED NOT NULL COMMENT 'Target language version id (not group_id)',
            user_id     INT UNSIGNED NOT NULL,
            assigned_by INT UNSIGNED NOT NULL,
            assigned_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            message     TEXT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_flash_user (flash_id, user_id),
            KEY idx_flash (flash_id),
            KEY idx_user  (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $flashStmt = $pdo->prepare("
        SELECT id, translation_group_id
        FROM sf_flashes
        WHERE id = ?
        LIMIT 1
    ");
    $flashStmt->execute([$flashId]);
    $flash = $flashStmt->fetch(PDO::FETCH_ASSOC);
    if (!$flash) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Flash not found'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $groupId = !empty($flash['translation_group_id'])
        ? (int)$flash['translation_group_id']
        : (int)$flash['id'];

    $bundleStmt = $pdo->prepare("
        SELECT id, lang
        FROM sf_flashes
        WHERE id = :gid OR translation_group_id = :gid2
        ORDER BY FIELD(lang, 'fi', 'sv', 'en', 'it', 'el'), id ASC
    ");
    $bundleStmt->execute([':gid' => $groupId, ':gid2' => $groupId]);
    $bundleRows = $bundleStmt->fetchAll(PDO::FETCH_ASSOC);
    if (empty($bundleRows)) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'Bundle not found'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $langMeta = [
        'fi' => ['label' => 'Suomi', 'icon' => 'finnish-flag.png'],
        'sv' => ['label' => 'Svenska', 'icon' => 'swedish-flag.png'],
        'en' => ['label' => 'English', 'icon' => 'english-flag.png'],
        'it' => ['label' => 'Italiano', 'icon' => 'italian-flag.png'],
        'el' => ['label' => 'Ελληνικά', 'icon' => 'greece-flag.png'],
    ];

    $bundle = [];
    $bundleFlashIds = [];
    foreach ($bundleRows as $row) {
        $lang = (string)($row['lang'] ?? 'fi');
        $bundle[] = [
            'flash_id' => (int)$row['id'],
            'lang' => $lang,
            'lang_label' => $langMeta[$lang]['label'] ?? strtoupper($lang),
            'icon' => $langMeta[$lang]['icon'] ?? '',
        ];
        $bundleFlashIds[] = (int)$row['id'];
    }

    $existingReviewers = [];
    if (!empty($bundleFlashIds)) {
        $placeholders = implode(',', array_fill(0, count($bundleFlashIds), '?'));
        $existingStmt = $pdo->prepare("
            SELECT lr.flash_id, lr.user_id, lr.assigned_at, u.first_name, u.last_name
            FROM sf_flash_language_reviewers lr
            INNER JOIN (
                SELECT flash_id, MAX(id) AS max_id
                FROM sf_flash_language_reviewers
                WHERE flash_id IN ($placeholders)
                GROUP BY flash_id
            ) latest ON latest.max_id = lr.id
            LEFT JOIN sf_users u ON u.id = lr.user_id
            ORDER BY lr.flash_id
        ");
        $existingStmt->execute($bundleFlashIds);
        foreach ($existingStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $existingReviewers[(string)(int)$row['flash_id']] = [
                'user_id' => (int)$row['user_id'],
                'first_name' => (string)($row['first_name'] ?? ''),
                'last_name' => (string)($row['last_name'] ?? ''),
                'assigned_at' => (string)($row['assigned_at'] ?? ''),
            ];
        }
    }

    $existingUserIds = [];
    foreach ($existingReviewers as $reviewer) {
        $existingUserIds[] = (int)$reviewer['user_id'];
    }
    $existingUserIds = array_values(array_unique(array_filter($existingUserIds, fn($v) => $v > 0)));

    $allUsersStmt = $pdo->prepare("
        SELECT id, first_name, last_name, ui_lang
        FROM sf_users
        WHERE is_active = 1
          AND id <> :current_user_id
        ORDER BY last_name, first_name, id
    ");
    $allUsersStmt->execute([':current_user_id' => (int)$user['id']]);
    $allActiveUsers = $allUsersStmt->fetchAll(PDO::FETCH_ASSOC);

    $allUsers = [];
    foreach ($allActiveUsers as $activeUser) {
        $uid = (int)$activeUser['id'];
        if (in_array($uid, $existingUserIds, true)) {
            continue;
        }
        $allUsers[] = [
            'id' => $uid,
            'first_name' => (string)($activeUser['first_name'] ?? ''),
            'last_name' => (string)($activeUser['last_name'] ?? ''),
            'ui_lang' => (string)($activeUser['ui_lang'] ?? ''),
        ];
    }

    $suggestions = [];
    $suggestStmt = $pdo->prepare("
        SELECT
            u.id,
            u.first_name,
            u.last_name,
            CASE
                WHEN u.ui_lang = :lang
                     AND EXISTS (
                        SELECT 1
                        FROM safetyflash_logs sl
                        WHERE sl.flash_id = :group_id
                          AND sl.user_id = u.id
                        LIMIT 1
                     )
                    THEN 'ui_lang_match_history'
                WHEN u.ui_lang = :lang2
                     AND EXISTS (
                        SELECT 1
                        FROM sf_flash_language_reviewers lr2
                        INNER JOIN sf_flashes f2 ON f2.id = lr2.flash_id
                        WHERE lr2.user_id = u.id
                          AND f2.lang = :lang3
                        LIMIT 1
                     )
                    THEN 'ui_lang_match_reviewer'
                WHEN u.ui_lang = :lang4
                    THEN 'ui_lang_match'
                ELSE 'other'
            END AS reason
        FROM sf_users u
        WHERE u.is_active = 1
          AND u.id <> :current_user_id
        ORDER BY
            CASE
                WHEN u.ui_lang = :lang5
                     AND EXISTS (
                        SELECT 1
                        FROM safetyflash_logs sl
                        WHERE sl.flash_id = :group_id2
                          AND sl.user_id = u.id
                        LIMIT 1
                     ) THEN 1
                WHEN u.ui_lang = :lang6
                     AND EXISTS (
                        SELECT 1
                        FROM sf_flash_language_reviewers lr2
                        INNER JOIN sf_flashes f2 ON f2.id = lr2.flash_id
                        WHERE lr2.user_id = u.id
                          AND f2.lang = :lang7
                        LIMIT 1
                     ) THEN 2
                WHEN u.ui_lang = :lang8 THEN 3
                ELSE 4
            END ASC,
            u.last_name ASC,
            u.first_name ASC,
            u.id ASC
        LIMIT 1
    ");

    foreach ($bundle as $bundleItem) {
        $lang = (string)$bundleItem['lang'];
        $suggestStmt->execute([
            ':lang' => $lang,
            ':lang2' => $lang,
            ':lang3' => $lang,
            ':lang4' => $lang,
            ':lang5' => $lang,
            ':lang6' => $lang,
            ':lang7' => $lang,
            ':lang8' => $lang,
            ':group_id' => $groupId,
            ':group_id2' => $groupId,
            ':current_user_id' => (int)$user['id'],
        ]);
        $candidate = $suggestStmt->fetch(PDO::FETCH_ASSOC);
        if ($candidate) {
            $suggestions[$lang] = [
                'user_id' => (int)$candidate['id'],
                'first_name' => (string)($candidate['first_name'] ?? ''),
                'last_name' => (string)($candidate['last_name'] ?? ''),
                'reason' => (string)($candidate['reason'] ?? 'other'),
            ];
        }
    }

    echo json_encode([
        'ok' => true,
        'bundle' => $bundle,
        'existing_reviewers' => $existingReviewers,
        'suggestions' => $suggestions,
        'all_users' => $allUsers,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('suggest_language_reviewer.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error'], JSON_UNESCAPED_UNICODE);
}

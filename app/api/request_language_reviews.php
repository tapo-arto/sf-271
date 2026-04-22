<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

define('SF_SKIP_AUTO_CSRF', true);
require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/log.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/../../assets/lib/sf_terms.php';
require_once __DIR__ . '/../../assets/services/email_services.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$currentUser = sf_current_user();
if (!$currentUser) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

$roleId = (int)($currentUser['role_id'] ?? 0);
if (!in_array($roleId, [1, 4], true)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$rawBody = file_get_contents('php://input');
$payload = json_decode($rawBody ?: '', true);
if (!is_array($payload)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid JSON payload'], JSON_UNESCAPED_UNICODE);
    exit;
}

$csrfToken = (string)($payload['csrf_token'] ?? '');
if (!sf_csrf_validate($csrfToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Invalid CSRF token'], JSON_UNESCAPED_UNICODE);
    exit;
}

$message = trim((string)($payload['message'] ?? ''));
if (mb_strlen($message) > 2000) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Message too long'], JSON_UNESCAPED_UNICODE);
    exit;
}

$assignments = $payload['assignments'] ?? null;
if (!is_array($assignments) || empty($assignments)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Assignments are required'], JSON_UNESCAPED_UNICODE);
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

    $normalizedAssignments = [];
    $flashIds = [];
    $userIdsToValidate = [];

    foreach ($assignments as $index => $assignment) {
        if (!is_array($assignment)) {
            throw new RuntimeException('Invalid assignment at index ' . $index);
        }

        $action = strtolower(trim((string)($assignment['action'] ?? '')));
        if (!in_array($action, ['add', 'change', 'remove'], true)) {
            throw new RuntimeException('Invalid action at index ' . $index);
        }

        $flashId = (int)($assignment['flash_id'] ?? 0);
        if ($flashId <= 0) {
            throw new RuntimeException('Invalid flash_id at index ' . $index);
        }

        $userId = isset($assignment['user_id']) ? (int)$assignment['user_id'] : 0;
        if (in_array($action, ['add', 'change'], true) && $userId <= 0) {
            throw new RuntimeException('user_id required for action ' . $action);
        }
        if ($userId === (int)$currentUser['id']) {
            throw new RuntimeException('Cannot assign yourself as reviewer');
        }

        $normalizedAssignments[] = [
            'action' => $action,
            'flash_id' => $flashId,
            'user_id' => $userId,
        ];
        $flashIds[] = $flashId;
        if ($userId > 0) {
            $userIdsToValidate[] = $userId;
        }
    }

    $flashIds = array_values(array_unique($flashIds));
    $userIdsToValidate = array_values(array_unique($userIdsToValidate));

    $placeholders = implode(',', array_fill(0, count($flashIds), '?'));
    $flashStmt = $pdo->prepare("
        SELECT id, state, is_archived, translation_group_id
        FROM sf_flashes
        WHERE id IN ($placeholders)
    ");
    $flashStmt->execute($flashIds);
    $flashRows = $flashStmt->fetchAll(PDO::FETCH_ASSOC);
    if (count($flashRows) !== count($flashIds)) {
        throw new RuntimeException('One or more flashes not found');
    }

    $flashById = [];
    $bundleRootIds = [];
    foreach ($flashRows as $row) {
        $rowId = (int)$row['id'];
        $flashById[$rowId] = $row;
        $state = (string)($row['state'] ?? '');
        if (!in_array($state, ['to_comms', 'published'], true)) {
            throw new RuntimeException('Invalid flash state for language review request');
        }
        if (!empty($row['is_archived'])) {
            throw new RuntimeException('Archived flash cannot be modified');
        }
        $bundleRootIds[] = !empty($row['translation_group_id'])
            ? (int)$row['translation_group_id']
            : $rowId;
    }

    if (count(array_unique($bundleRootIds)) !== 1) {
        throw new RuntimeException('All assignments must target the same bundle');
    }

    if (!empty($userIdsToValidate)) {
        $userPlaceholders = implode(',', array_fill(0, count($userIdsToValidate), '?'));
        $userStmt = $pdo->prepare("
            SELECT id
            FROM sf_users
            WHERE is_active = 1 AND id IN ($userPlaceholders)
        ");
        $userStmt->execute($userIdsToValidate);
        $validUserIds = array_map('intval', $userStmt->fetchAll(PDO::FETCH_COLUMN));
        foreach ($userIdsToValidate as $uid) {
            if (!in_array($uid, $validUserIds, true)) {
                throw new RuntimeException('Reviewer user is not active');
            }
        }
    }

    $uiLang = $_SESSION['ui_lang'] ?? 'fi';
    $added = 0;
    $changed = 0;
    $removed = 0;
    $emailQueue = [];

    $insertReviewerStmt = $pdo->prepare("
        INSERT IGNORE INTO sf_flash_language_reviewers (flash_id, user_id, assigned_by, assigned_at, message)
        VALUES (?, ?, ?, NOW(), ?)
    ");
    $deleteReviewerByFlashStmt = $pdo->prepare("DELETE FROM sf_flash_language_reviewers WHERE flash_id = ?");
    $deleteSpecificReviewerStmt = $pdo->prepare("DELETE FROM sf_flash_language_reviewers WHERE flash_id = ? AND user_id = ?");
    $insertCommentStmt = $pdo->prepare("
        INSERT INTO safetyflash_logs (flash_id, user_id, event_type, description, created_at)
        VALUES (?, ?, 'comment_added', ?, NOW())
    ");

    $pdo->beginTransaction();

    foreach ($normalizedAssignments as $assignment) {
        $action = $assignment['action'];
        $flashId = (int)$assignment['flash_id'];
        $reviewerId = (int)$assignment['user_id'];

        if ($action === 'add') {
            $insertReviewerStmt->execute([$flashId, $reviewerId, (int)$currentUser['id'], $message]);
            if ($insertReviewerStmt->rowCount() > 0) {
                $added++;
                $tagDescription = 'log_comment_label: log_review_requested_tag: @[user:' . $reviewerId . ']';
                if ($message !== '') {
                    $tagDescription .= ': ' . $message;
                }
                $insertCommentStmt->execute([$flashId, (int)$currentUser['id'], $tagDescription]);
                sf_log_event($flashId, 'language_review_requested', 'log_language_review_requested: user=' . $reviewerId);
                $emailQueue[] = ['flash_id' => $flashId, 'user_id' => $reviewerId];
            }
            continue;
        }

        if ($action === 'change') {
            $deleteReviewerByFlashStmt->execute([$flashId]);
            $insertReviewerStmt->execute([$flashId, $reviewerId, (int)$currentUser['id'], $message]);
            if ($insertReviewerStmt->rowCount() > 0) {
                $changed++;
                $tagDescription = 'log_comment_label: log_review_requested_tag: @[user:' . $reviewerId . ']';
                if ($message !== '') {
                    $tagDescription .= ': ' . $message;
                }
                $insertCommentStmt->execute([$flashId, (int)$currentUser['id'], $tagDescription]);
                sf_log_event($flashId, 'language_review_requested', 'log_language_review_requested: user=' . $reviewerId);
                $emailQueue[] = ['flash_id' => $flashId, 'user_id' => $reviewerId];
            }
            continue;
        }

        if ($action === 'remove') {
            if ($reviewerId > 0) {
                $deleteSpecificReviewerStmt->execute([$flashId, $reviewerId]);
                $affected = $deleteSpecificReviewerStmt->rowCount();
            } else {
                $deleteReviewerByFlashStmt->execute([$flashId]);
                $affected = $deleteReviewerByFlashStmt->rowCount();
            }
            if ($affected > 0) {
                $removed++;
            }
        }
    }

    $pdo->commit();

    foreach ($emailQueue as $mailTask) {
        try {
            sf_mail_to_language_reviewer(
                $pdo,
                (int)$mailTask['flash_id'],
                (int)$mailTask['user_id'],
                $message,
                (int)$currentUser['id']
            );
        } catch (Throwable $mailError) {
            error_log('request_language_reviews.php email failed: ' . $mailError->getMessage());
        }
    }

    sf_audit_log(
        'language_reviews_requested',
        'flash',
        (int)$flashIds[0],
        [
            'added' => $added,
            'changed' => $changed,
            'removed' => $removed,
            'assignment_count' => count($normalizedAssignments),
            'ui_lang' => $uiLang,
        ],
        (int)$currentUser['id']
    );

    echo json_encode([
        'ok' => true,
        'added' => $added,
        'changed' => $changed,
        'removed' => $removed,
    ], JSON_UNESCAPED_UNICODE);
} catch (RuntimeException $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('request_language_reviews.php validation: ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('request_language_reviews.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Server error'], JSON_UNESCAPED_UNICODE);
}

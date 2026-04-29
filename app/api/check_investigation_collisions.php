<?php
// app/api/check_investigation_collisions.php
declare(strict_types=1);

/**
 * Check whether investigation (green type) versions already exist for the
 * requested languages within the same flash family.
 *
 * POST params:
 *   source_id   (int)    – ID of the flash whose family is checked
 *   langs       (string) – comma-separated language codes (e.g. "el,en,fi")
 *   csrf_token  (string) – CSRF token
 *
 * Returns JSON:
 * {
 *   "success": true,
 *   "collisions": {
 *     "el": { "status": "free" },
 *     "en": { "status": "existing_draft",     "flash_id": 42 },
 *     "fi": { "status": "existing_published", "flash_id": 17 }
 *   }
 * }
 *
 * Possible status values:
 *   "free"               – no investigation version exists for this language
 *   "existing_draft"     – a draft investigation version exists
 *   "existing_published" – a published investigation version exists
 */

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/log_app.php';

try {
    require_once __DIR__ . '/../../config.php';
    require_once __DIR__ . '/../includes/auth.php';
    require_once __DIR__ . '/../../assets/lib/sf_terms.php';

    if (!function_exists('sf_current_user')) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Auth-funktio puuttuu']);
        exit;
    }

    $currentUser = sf_current_user();
    if (!$currentUser) {
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => 'Kirjautuminen vaaditaan']);
        exit;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Vain POST sallittu']);
        exit;
    }

    // CSRF validation
    if (!function_exists('sf_csrf_validate') || !sf_csrf_validate($_POST['csrf_token'] ?? '')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'CSRF-token virheellinen']);
        exit;
    }

    $sourceId = isset($_POST['source_id']) ? (int)$_POST['source_id'] : 0;
    $langsRaw = trim((string)($_POST['langs'] ?? ''));

    if ($sourceId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Virheellinen source_id']);
        exit;
    }

    $allowedLangs = ['fi', 'sv', 'en', 'it', 'el'];
    $langs = [];
    if ($langsRaw !== '') {
        foreach (explode(',', $langsRaw) as $l) {
            $l = trim($l);
            if (in_array($l, $allowedLangs, true)) {
                $langs[] = $l;
            }
        }
    }

    if (empty($langs)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Ei kieliä']);
        exit;
    }

    $pdo = Database::getInstance();

    // Load the source flash to resolve its translation group
    $srcStmt = $pdo->prepare('SELECT id, translation_group_id FROM sf_flashes WHERE id = ? LIMIT 1');
    $srcStmt->execute([$sourceId]);
    $src = $srcStmt->fetch(PDO::FETCH_ASSOC);

    if (!$src) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Lähde-flashia ei löydy']);
        exit;
    }

    $groupId = !empty($src['translation_group_id'])
        ? (int)$src['translation_group_id']
        : (int)$src['id'];

    // For each requested language, check if a green (investigation) version exists
    $collisions = [];
    $checkStmt = $pdo->prepare('
        SELECT id, state
        FROM sf_flashes
        WHERE type = \'green\'
          AND lang = ?
          AND (id = ? OR translation_group_id = ?)
        ORDER BY id ASC
        LIMIT 1
    ');

    foreach ($langs as $lang) {
        $checkStmt->execute([$lang, $groupId, $groupId]);
        $row = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $collisions[$lang] = ['status' => 'free', 'flash_id' => null];
        } elseif ($row['state'] === 'published') {
            $collisions[$lang] = ['status' => 'existing_published', 'flash_id' => (int)$row['id']];
        } else {
            $collisions[$lang] = ['status' => 'existing_draft', 'flash_id' => (int)$row['id']];
        }
    }

    echo json_encode(['success' => true, 'collisions' => $collisions]);

} catch (PDOException $e) {
    sf_app_log('[check_investigation_collisions] PDO ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Tietokantavirhe']);
} catch (Throwable $e) {
    sf_app_log('[check_investigation_collisions] ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Palvelinvirhe']);
}

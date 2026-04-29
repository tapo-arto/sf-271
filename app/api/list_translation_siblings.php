<?php
// app/api/list_translation_siblings.php
declare(strict_types=1);

/**
 * Returns all published language versions of a flash family.
 *
 * Used by the investigation base-language picker modal.
 *
 * GET params:
 *   flash_id (int) – ID of any flash in the family (original or language child)
 *
 * Returns JSON: [{ id, lang, is_original, published_at, title_short }]
 */

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/log_app.php';

try {
    require_once __DIR__ . '/../../config.php';
    require_once __DIR__ . '/../includes/auth.php';

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

    $flashId = isset($_GET['flash_id']) ? (int)$_GET['flash_id'] : 0;
    if ($flashId <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Virheellinen flash_id']);
        exit;
    }

    $pdo = Database::getInstance();

    // Load the requested flash to find its translation group
    $flashStmt = $pdo->prepare('SELECT id, translation_group_id FROM sf_flashes WHERE id = ? LIMIT 1');
    $flashStmt->execute([$flashId]);
    $flash = $flashStmt->fetch(PDO::FETCH_ASSOC);

    if (!$flash) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Tiedotetta ei löydy']);
        exit;
    }

    // Resolve the translation group id
    $groupId = !empty($flash['translation_group_id'])
        ? (int)$flash['translation_group_id']
        : (int)$flash['id'];

    // Fetch all published members of the family (any type)
    $siblingsStmt = $pdo->prepare('
        SELECT
            id,
            lang,
            title_short,
            state,
            DATE_FORMAT(updated_at, \'%d.%m.%Y\') AS published_at
        FROM sf_flashes
        WHERE state = \'published\'
          AND (id = ? OR translation_group_id = ?)
        ORDER BY id ASC
    ');
    $siblingsStmt->execute([$groupId, $groupId]);
    $rows = $siblingsStmt->fetchAll(PDO::FETCH_ASSOC);

    $result = [];
    foreach ($rows as $row) {
        $result[] = [
            'id'          => (int)$row['id'],
            'lang'        => $row['lang'],
            'is_original' => ((int)$row['id'] === $groupId),
            'published_at'=> $row['published_at'] ?? '',
            'title_short' => $row['title_short'] ?? '',
        ];
    }

    echo json_encode(['success' => true, 'siblings' => $result]);

} catch (PDOException $e) {
    sf_app_log('[list_translation_siblings] PDO ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Tietokantavirhe']);
} catch (Throwable $e) {
    sf_app_log('[list_translation_siblings] ERROR: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Palvelinvirhe']);
}

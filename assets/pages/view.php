<?php
// assets/pages/view.php
declare(strict_types=1);

// DEBUG
ini_set('display_errors', '1');
error_reporting(E_ALL);
ini_set('error_log', __DIR__ . '/../../assets/logs/php_errors.log');

require_once __DIR__ . '/../../app/includes/protect.php';
require_once __DIR__ .'/../../app/includes/statuses.php';
require_once __DIR__ . '/../../app/actions/helpers.php';

$base = rtrim($config['base_url'] ?? '', '/');

// --- DB: PDO ---
try {
    $pdo = Database::getInstance();
} catch (Throwable $e) {
    $errorLang = $_SESSION['ui_lang'] ?? 'fi';
    echo '<p>' . htmlspecialchars(sf_term('db_error', $errorLang), ENT_QUOTES, 'UTF-8') . '</p>';
    exit;
}

// --- ID ---
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($id <= 0) {
    $errorLang = $_SESSION['ui_lang'] ?? 'fi';
    echo '<p>' . htmlspecialchars(sf_term('invalid_id', $errorLang), ENT_QUOTES, 'UTF-8') . '</p>';
    exit;
}

// --- Safetyflash ---
$stmt = $pdo->prepare("
    SELECT *,
        DATE_FORMAT(created_at, '%d.%m.%Y %H:%i')   AS createdFmt,
        DATE_FORMAT(updated_at, '%d.%m.%Y %H:%i')   AS updatedFmt,
        DATE_FORMAT(occurred_at, '%d.%m.%Y %H:%i')  AS occurredFmt
    FROM sf_flashes
    WHERE id = ?
    LIMIT 1
");
$stmt->execute([$id]);
$flash = $stmt->fetch();

if (!$flash) {
    $errorLang = $_SESSION['ui_lang'] ?? 'fi';
    echo '<p>' . htmlspecialchars(sf_term('flash_not_found', $errorLang), ENT_QUOTES, 'UTF-8') . '</p>';
    exit;
}

// Record user's last read timestamp
$currentUser = sf_current_user();
if ($currentUser && $flash) {
    // Use translation_group_id if available (marks all language versions as read)
    $flashId = !empty($flash['translation_group_id'])
        ? (int)$flash['translation_group_id']
        : (int)$flash['id'];
    $userId = (int)$currentUser['id'];
    
    // Upsert the last_read_at timestamp
    try {
        $readStmt = $pdo->prepare("
            INSERT INTO sf_flash_reads (flash_id, user_id, last_read_at)
            VALUES (:flash_id, :user_id, NOW())
            ON DUPLICATE KEY UPDATE last_read_at = NOW()
        ");
        $readStmt->execute([
            ':flash_id' => $flashId,
            ':user_id' => $userId
        ]);
    } catch (PDOException $e) {
        // Silently fail if table doesn't exist yet - migration might not be applied
        error_log('Failed to update flash read timestamp: ' . $e->getMessage());
    }
}

$uiLang          = $_SESSION['ui_lang'] ?? 'fi';
$currentUiLang   = $uiLang ?? 'fi';

// Load existing body parts for quick-edit
$existing_body_parts = [];
try {
    $bpStmt = $pdo->prepare("
        SELECT bp.svg_id
        FROM incident_body_part ibp
        JOIN body_parts bp ON bp.id = ibp.body_part_id
        WHERE ibp.incident_id = :id
        ORDER BY bp.sort_order
    ");
    $bpStmt->execute([':id' => $id]);
    $existing_body_parts = array_column($bpStmt->fetchAll(PDO::FETCH_ASSOC), 'svg_id');
} catch (Throwable $e) {
    error_log('view.php: Failed to load body parts: ' . $e->getMessage());
}

// Load additional info entries for this flash
$additionalInfoEntries = [];

/**
 * Sanitize HTML content from the additional info WYSIWYG editor.
 * Strips all disallowed tags and removes all attributes from allowed tags.
 * Allowed tags match the SAFE_TAGS list in the client-side JS.
 */
function sf_sanitize_ai_html(string $html): string {
    $allowed = '<p><br><strong><em><u><ol><ul><li><span>';
    $html = strip_tags($html, $allowed);
    // Remove all attributes from allowed tags; preserve self-closing slash (e.g. <br />)
    $html = preg_replace('/<(\w+)(?:\s[^>]*)?(\/?)>/', '<$1$2>', $html);
    return $html;
}

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS sf_flash_additional_info (
            id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            flash_id   INT UNSIGNED NOT NULL,
            user_id    INT UNSIGNED NOT NULL,
            content    TEXT         NOT NULL,
            created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_flash_id (flash_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $aiStmt = $pdo->prepare("
        SELECT ai.id, ai.user_id, ai.content, ai.created_at,
               u.first_name, u.last_name
        FROM sf_flash_additional_info ai
        LEFT JOIN sf_users u ON u.id = ai.user_id
        WHERE ai.flash_id = ?
        ORDER BY ai.created_at ASC
    ");
    $aiStmt->execute([$id]);
    $additionalInfoEntries = $aiStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('view.php: Failed to load additional info: ' . $e->getMessage());
}

// Check if user can manage reviewers (admin, safety team, or original creator)
$canManageReviewers = false;
if ($currentUser) {
    $userRoleId = (int)($currentUser['role_id'] ?? 0);
    $userId = (int)($currentUser['id'] ?? 0);
    $flashCreatorId = (int)($flash['created_by'] ?? 0);
    
    // Admin (1), Safety Team (3), or original creator
    $canManageReviewers = ($userRoleId === 1 || $userRoleId === 3 || $userId === $flashCreatorId);
}

// Lokia varten ryhmän juuri
$logFlashId = !empty($flash['translation_group_id'])
    ? (int)$flash['translation_group_id']
    : (int)$flash['id'];

// Current user's per-flash comment notification preference
$commentNotificationsChecked = true;
$currentUserIdForComments = (int)($currentUser['id'] ?? 0);

if ($currentUserIdForComments > 0) {
    try {
        $stmtCommentPref = $pdo->prepare("
            SELECT is_enabled
            FROM sf_comment_subscriptions
            WHERE flash_id = ? AND user_id = ?
            LIMIT 1
        ");
        $stmtCommentPref->execute([$logFlashId, $currentUserIdForComments]);
        $commentPrefRow = $stmtCommentPref->fetch(PDO::FETCH_ASSOC);

        if ($commentPrefRow !== false) {
            $commentNotificationsChecked = ((int)$commentPrefRow['is_enabled'] === 1);
        }
    } catch (Throwable $e) {
        $commentNotificationsChecked = true;
    }
}

// Varmista, että batch_id-sarake on olemassa safetyflash_logs-taulussa
try {
    $pdo->exec("ALTER TABLE safetyflash_logs ADD COLUMN IF NOT EXISTS batch_id VARCHAR(36) DEFAULT NULL");
    $pdo->exec("ALTER TABLE safetyflash_logs ADD INDEX IF NOT EXISTS idx_batch_id (batch_id)");
} catch (Throwable $e) {
    // Kirjataan varoitus lokiin, mutta ei kaadeta sivua – sarake lisätään myöhemmin
    error_log('view.php: batch_id migration warning: ' . $e->getMessage());
}

// Hae lokit (ryhmän juurella)
$logs = [];
$logStmt = $pdo->prepare("
    SELECT 
        l.id,
        l.event_type,
        l.description,
        l.created_at,
        l.user_id,
        l.batch_id,
        l.parent_comment_id as parent_id,
        u.first_name,
        u.last_name
    FROM safetyflash_logs l
    LEFT JOIN sf_users u ON u.id = l.user_id
    WHERE l.flash_id = ?
    ORDER BY l.created_at DESC, l.id DESC
");
$logStmt->execute([$logFlashId]);
$logs = $logStmt->fetchAll();

// Fallback: jos lokitaulu on tyhjä, näytä vähintään luontiaika
if (empty($logs)) {
    $creatorName = trim(($flash['created_by_first_name'] ?? '') .' ' .($flash['created_by_last_name'] ?? ''));
    if ($creatorName === '') $creatorName = null;

    $logs = [[
        'id' => 0,
        'event_type' => 'created',
        'description' => sf_term('log_created', $currentUiLang) ?? 'Created',
        'created_at' => $flash['created_at'] ?? ($flash['createdFmtRaw'] ?? null),
        'first_name' => $creatorName ? ($flash['created_by_first_name'] ?? '') : null,
        'last_name'  => $creatorName ? ($flash['created_by_last_name'] ?? '') : null,
    ]];
}

// Onko tämä kieliversio vai alkuperäinen flash?
$isTranslation = !empty($flash['translation_group_id'])
    && (int) $flash['translation_group_id'] !== (int) $flash['id'];

$editUrl  = $base .  '/index.php?page=form&id=' .  $id;

// --- Työmaavastaavan tarkistus: näytä kenellä tarkistuksessa ---
$pendingSupervisorUsers = [];
$selectedSupervisorIds = [];

if (($flash['state'] ?? '') === 'pending_supervisor') {
    $rawSel = $flash['selected_approvers'] ?? null;

    if (!empty($rawSel)) {
        $decoded = json_decode((string)$rawSel, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            // Tue sekä [1,2] että {"approver_ids":[1,2]} muodot
            if (isset($decoded['approver_ids']) && is_array($decoded['approver_ids'])) {
                $decoded = $decoded['approver_ids'];
            }

            $selectedSupervisorIds = array_values(array_unique(array_map('intval', $decoded)));
            $selectedSupervisorIds = array_values(array_filter($selectedSupervisorIds, fn($v) => (int)$v > 0));
        }
    }

    if (!empty($selectedSupervisorIds)) {
        $placeholders = implode(',', array_fill(0, count($selectedSupervisorIds), '?'));
        $stmtSup = $pdo->prepare("
            SELECT id, first_name, last_name, email
            FROM sf_users
            WHERE id IN ($placeholders)
            ORDER BY last_name, first_name
        ");
        $stmtSup->execute($selectedSupervisorIds);
        $pendingSupervisorUsers = $stmtSup->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

// --- Tuetut kielet ja lippujen ikonit ---
$supportedLangs = [
    'fi' => ['label' => 'FI', 'icon' => 'finnish-flag.png'],
    'sv' => ['label' => 'SV', 'icon' => 'swedish-flag.png'],
    'en' => ['label' => 'EN', 'icon' => 'english-flag.png'],
    'it' => ['label' => 'IT', 'icon' => 'italian-flag.png'],
    'el' => ['label' => 'EL', 'icon' => 'greece-flag.png'], // 'el' on Kreikan kielikoodi
];

// Lippu-apufunktio kieliversioiden visuaaliseen tunnistamiseen
if (!function_exists('sf_lang_flag')) {
    function sf_lang_flag(string $lang): string {
        return match($lang) {
            'fi' => '🇫🇮',
            'sv' => '🇸🇪',
            'en' => '🇬🇧',
            'it' => '🇮🇹',
            'el' => '🇬🇷',
            default => '🏳️',
        };
    }
}

// --- Kieliversiot & preview ---
require_once __DIR__ .'/../services/render_services.php';

$currentId   = (int) ($flash['id'] ?? 0);
$currentLang = $flash['lang'] ?? 'fi';

$translationGroupId = !empty($flash['translation_group_id'])
    ? (int) $flash['translation_group_id']
    : $currentId;

$translations = [];
if ($translationGroupId > 0 && function_exists('sf_get_flash_translations')) {
    $translations = sf_get_flash_translations($pdo, $translationGroupId);
    if (!isset($translations[$currentLang]) && $currentId > 0) {
        $translations[$currentLang] = $currentId;
    }
}

// Check preview status
$previewStatus = $flash['preview_status'] ?? 'completed';
$isPreviewPending = ($previewStatus === 'pending' || $previewStatus === 'processing');

// Jos preview_filename puuttuu, yritä generoida se
if (empty($flash['preview_filename']) && $currentId > 0 && function_exists('sf_generate_flash_preview') && !$isPreviewPending) {
    try {
        sf_generate_flash_preview($pdo, $currentId);
        // Hae uudelleen
        $stmtPrev = $pdo->prepare("SELECT preview_filename, preview_status FROM sf_flashes WHERE id = ?");
        $stmtPrev->execute([$currentId]);
        $prevRow = $stmtPrev->fetch();
        if ($prevRow && !empty($prevRow['preview_filename'])) {
            $flash['preview_filename'] = $prevRow['preview_filename'];
        }
        if ($prevRow && !empty($prevRow['preview_status'])) {
            $previewStatus = $prevRow['preview_status'];
            $isPreviewPending = ($previewStatus === 'pending' || $previewStatus === 'processing');
        }
    } catch (Throwable $e) {
        error_log("Could not auto-generate preview for flash {$currentId}: " .$e->getMessage());
    }
}

// Cache-buster kuville (estää vanhan kuvan näkymisen viewissä)
$sfCacheBust = function (string $url, ?string $absPath): string {
    if (!empty($absPath) && is_file($absPath)) {
        $v = (string) filemtime($absPath);
        return $url . (strpos($url, '?') === false ? '?' : '&') . 'v=' . rawurlencode($v);
    }
    return $url;
};

// --- Preview-kuva 1 ---
$previewUrl = "{$base}/assets/img/camera-placeholder.png";
$previewAbsPath = null;

if (!empty($flash['preview_filename'])) {
    $filename = $flash['preview_filename'];
    $previewPathNew = __DIR__ .'/../../uploads/previews/' .$filename;
    $previewPathOld = __DIR__ .'/../../img/' .$filename; // legacy

    if (is_file($previewPathNew)) {
        $previewUrl = "{$base}/uploads/previews/" .$filename;
        $previewAbsPath = $previewPathNew;
    } elseif (is_file($previewPathOld)) {
        $previewUrl = "{$base}/img/" .$filename;
        $previewAbsPath = $previewPathOld;
    }
}

$previewUrl = $sfCacheBust($previewUrl, $previewAbsPath);

// --- Preview-kuva 2 (vain tutkintatiedotteille) ---
$previewUrl2 = null;
$hasSecondCard = false;
$previewAbsPath2 = null;

// Check if second card exists by checking if the file exists (simpler and more reliable)
if ($flash['type'] === 'green' && !empty($flash['preview_filename_2'])) {
    $filename2 = $flash['preview_filename_2'];
    $previewPath2New = __DIR__ .'/../../uploads/previews/' .$filename2;
    $previewPath2Old = __DIR__ .'/../../img/' .$filename2;
    
    if (is_file($previewPath2New)) {
        $previewUrl2 = "{$base}/uploads/previews/" .$filename2;
        $previewAbsPath2 = $previewPath2New;
        $hasSecondCard = true;
    } elseif (is_file($previewPath2Old)) {
        $previewUrl2 = "{$base}/img/" .$filename2;
        $previewAbsPath2 = $previewPath2Old;
        $hasSecondCard = true;
    }
}

if (!empty($previewUrl2)) {
    $previewUrl2 = $sfCacheBust($previewUrl2, $previewAbsPath2);
}
// UUSI: Editorissa generoitu rasteri (uploads/edited) – luetaan annotations_datasta
$sfAnn = json_decode($flash['annotations_data'] ?? '', true);
$sfEditedImages = (is_array($sfAnn) && isset($sfAnn['edited_images']) && is_array($sfAnn['edited_images']))
    ? $sfAnn['edited_images']
    : [];

$sfGetEditedUrl = function (int $slot) use ($sfEditedImages, $base, $sfCacheBust): ?string {
    $key = (string) $slot;
    if (!empty($sfEditedImages[$key])) {
        $file = $sfEditedImages[$key];
        $url  = $base .'/uploads/edited/' .$file;
        $abs  = __DIR__ .'/../../uploads/edited/' .$file;
        return $sfCacheBust($url, $abs);
    }
    return null;
};

// Kuvapolkujen muodostaminen JS:lle
$getImageUrlForJs = function ($filename) use ($base) {
    if (empty($filename)) {
        return '';
    }
    
    // Tarkista ensin uploads/images
    $path = "uploads/images/{$filename}";
    $fullPath = __DIR__ ."/../../{$path}";
    if (file_exists($fullPath)) {
        return "{$base}/{$path}";
    }
    
    // Tarkista uploads/library (kuvakirjasto)
    $libPath = "uploads/library/{$filename}";
    $libFullPath = __DIR__ ."/../../{$libPath}";
    if (file_exists($libFullPath)) {
        return "{$base}/{$libPath}";
    }
    
    // Vanha polku (legacy)
    $oldPath = "img/{$filename}";
    $oldFullPath = __DIR__ . "/../../{$oldPath}";
    if (file_exists($oldFullPath)) {
        return "{$base}/{$oldPath}";
    }
    
    // Palauta tyhjä jos ei löydy
    return '';
};

// Hae originaalin grid_bitmap jos tämä on kieliversio
$originalGridBitmap = $flash['grid_bitmap'] ?? '';
$originalGridBitmapUrl = '';

if (empty($originalGridBitmap) && ! empty($flash['translation_group_id'])) {
    // Hae originaalin grid_bitmap
    $origStmt = $pdo->prepare("SELECT grid_bitmap FROM sf_flashes WHERE id = ?  LIMIT 1");
    $origStmt->execute([(int)$flash['translation_group_id']]);
    $origRow = $origStmt->fetch();
    if ($origRow && !empty($origRow['grid_bitmap'])) {
        $originalGridBitmap = $origRow['grid_bitmap'];
    }
}

// Muodosta grid_bitmap URL
if (! empty($originalGridBitmap)) {
    if (strpos($originalGridBitmap, 'data:image/') === 0) {
        $originalGridBitmapUrl = $originalGridBitmap;
    } else {
        $gridPath = __DIR__ . '/../../uploads/grids/' . $originalGridBitmap;
        if (file_exists($gridPath)) {
            $originalGridBitmapUrl = $base .  '/uploads/grids/' . $originalGridBitmap;
        }
    }
}

// Prepare flash data for JavaScript - ALWAYS use parent ID for creating translations
$parentFlashId = !empty($flash['translation_group_id']) 
    ? (int)$flash['translation_group_id'] 
    : (int)$flash['id'];

$flashDataForJs = [
    'id' => $parentFlashId,  // Use parent ID for translation creation
    'current_id' => (int)$flash['id'],  // Current flash being viewed
    'translation_group_id' => $flash['translation_group_id'] ?? null,
    'type' => $flash['type'],
    'title' => $flash['title'],
    'title_short' => $flash['title_short'] ?? $flash['summary'] ?? '',
    'description' => $flash['description'] ?? '',
    'root_causes' => $flash['root_causes'] ?? '',
    'actions' => $flash['actions'] ??  '',
    'site' => $flash['site'] ??  '',
    'site_detail' => $flash['site_detail'] ?? '',
    'occurred_at' => $flash['occurred_at'] ?? '',
    'lang' => $flash['lang'] ??  'fi',
    'image_main' => $flash['image_main'] ?? '',
    'image_2' => $flash['image_2'] ?? '',
    'image_3' => $flash['image_3'] ?? '',
    'image_main_url' => ($sfGetEditedUrl(1) ?: $getImageUrlForJs($flash['image_main'] ?? null)),
    'image_2_url' => ($sfGetEditedUrl(2) ?: $getImageUrlForJs($flash['image_2'] ?? null)),
    'image_3_url' => ($sfGetEditedUrl(3) ?: $getImageUrlForJs($flash['image_3'] ?? null)),
    'image1_transform' => $flash['image1_transform'] ?? '',
    'image2_transform' => $flash['image2_transform'] ?? '',
    'image3_transform' => $flash['image3_transform'] ?? '',
    'grid_style' => $flash['grid_style'] ?? 'grid-3-main-top',
    'grid_bitmap' => $originalGridBitmap,
    'grid_bitmap_url' => $originalGridBitmapUrl,
];

// --- Tyyppien labelit termistön kautta ---
$typeKeyMap = [
    'red'    => 'first_release',
    'yellow' => 'dangerous_situation',
    'green'  => 'investigation_report',
];
$typeKey   = $typeKeyMap[$flash['type']] ?? null;
$typeLabel = $typeKey ? sf_term($typeKey, $currentUiLang) : 'Safetyflash';

// --- Apu: generaattori lokirivin avataria varten (nimi -> initials) ---
function sf_avatar_initials(string $name): string {
    $parts = preg_split('/\s+/', trim($name));
    $initials = '';
    foreach ($parts as $p) {
        if ($p !== '') $initials .= mb_strtoupper(mb_substr($p, 0, 1));
        if (mb_strlen($initials) >= 2) break;
    }
    return $initials ?: 'SF';
}
// ===== TOIMINTOJEN MÄÄRITYS KÄYTTÄJÄN ROOLIN JA TILAN MUKAAN =====
$currentUser = sf_current_user();
$roleId = (int)($currentUser['role_id'] ?? 0);
$currentUserId = (int)($currentUser['id'] ?? 0);
$createdBy = (int)($flash['created_by'] ?? 0);
$stateVal = $flash['state'] ?? 'draft';

$isOwner = ($currentUserId > 0 && $createdBy === $currentUserId);
$isAdmin = ($roleId === 1);
$isSafety = ($roleId === 3);
$isComms = ($roleId === 4);

$actions = [];

// Check if archived - if so, disable most actions
$isArchived = !empty($flash['is_archived']);

// Kommentointi kaikille kirjautuneille (ei arkistoiduille)
if (!$isArchived) {
    $actions[] = 'comment';
}

// If archived, no further actions allowed
if ($isArchived) {
    // Archived flashes cannot be edited or modified
    // Only viewing is allowed
} else {
    // Määritä toiminnot tilan ja roolin mukaan
switch ($stateVal) {
    case 'draft':
        if ($isOwner || $isAdmin) {
            $actions[] = 'edit';
            $actions[] = 'delete';
            $actions[] = 'send_to_review';
        }
        break;

case 'pending_supervisor':
        require_once __DIR__ . '/../../app/services/ApprovalRouting.php';
        $isSupervisor = ApprovalRouting::isUserSupervisor($pdo, $currentUserId);
        $isSelectedApprover = ApprovalRouting::isUserSelectedApprover($pdo, (int)$id, $currentUserId);

        if (($isSupervisor && $isSelectedApprover) || $isAdmin) {
            $actions[] = 'edit';
            $actions[] = 'send_to_safety';
            $actions[] = 'request';
        }

        if ($isSafety) {
            $actions[] = 'edit';
        }

        if ($isAdmin || $isSafety) {
            $actions[] = 'approve_to_comms';
            $actions[] = 'publish_direct';
        }

        break;

    case 'pending_review':
        if ($isSafety || $isAdmin) {
            $actions[] = 'edit';
            $actions[] = 'request';
            $actions[] = 'comms';
            $actions[] = 'publish_direct';
        }
        break;

case 'request_info': 
    if ($isOwner || $isAdmin) {
        $actions[] = 'edit';
        // send_to_review poistettu - se näkyy jo lomakkeen esikatselussa
    }
    break;

    case 'reviewed':
        if ($isSafety || $isAdmin) {
            $actions[] = 'edit';
            $actions[] = 'comms';
        }
        break;

    case 'to_comms':
        if ($isComms || $isAdmin) {
            $actions[] = 'edit';
            $actions[] = 'publish';
            $actions[] = 'request';     // Palauta turvatiimille
        }
        // Turvatiimi voi myös muokata viestinnällä-tilassa
        if ($isSafety) {
            $actions[] = 'edit';
        }
        break;

    case 'published': 
        if ($isAdmin || $isSafety || $isComms) {
            $actions[] = 'edit';
        }
        // Add archive action for admin and safety team
        if (($isAdmin || $isSafety) && !$isArchived) {
            $actions[] = 'archive';
        }
        // Infonäyttöjen hallinta julkaistuille flasheille
        if ($isAdmin || $isSafety || $isComms) {
            $actions[] = 'display_targets';
        }
        break;
}

// Poista duplikaatit
$actions = array_unique($actions);
// Admin voi aina poistaa
if ($isAdmin && ! in_array('delete', $actions)) {
    $actions[] = 'delete';
}
}

$hasActions = ! empty($actions);

// Determine if current user can edit this flash (used in Images tab JS)
$canEdit = in_array('edit', $actions);

// Determine if current user can add extra images (broader than canEdit - allows owner/admin/safety in all states)
$canAddExtraImages = $isOwner || $isAdmin || $isSafety;

// Determine if current user can access report settings (Settings modal, body map for all types)
$canAccessSettings = !$isArchived && ($isAdmin || $isSafety || $isOwner);

// Merge existing original flash into investigation report
// original_type can already be set manually from settings, so the merge button
// must stay visible until an actual original flash has been linked.
$hasLinkedOriginalFlash = false;

try {
    $stmtHasLinkedOriginalFlash = $pdo->prepare("
        SELECT COUNT(*) 
        FROM sf_flash_snapshots
        WHERE flash_id = ?
          AND version_type IN ('ensitiedote', 'vaaratilanne')
    ");
    $stmtHasLinkedOriginalFlash->execute([(int)$flash['id']]);
    $hasLinkedOriginalFlash = ((int)$stmtHasLinkedOriginalFlash->fetchColumn() > 0);
} catch (Throwable $e) {
    error_log('view.php merge visibility check failed: ' . $e->getMessage());
    $hasLinkedOriginalFlash = false;
}

$canMergeOriginalFlash =
    !$isArchived
    && !$isTranslation
    && (($flash['type'] ?? '') === 'green')
    && !$hasLinkedOriginalFlash
    && ($isAdmin || $isSafety || $isComms || $isOwner || $canEdit);

$iconBase = $base .'/assets/img/icons/';
?>
<?php $stateCss = preg_replace('/[^a-z0-9_\-]/i', '', (string)($flash['state'] ?? '')); ?>
<div class="sf-page-container">
<div class="view-container view-state-<?= htmlspecialchars($stateCss, ENT_QUOTES, 'UTF-8') ?>">
    <div class="view-back" style="display: flex; justify-content: space-between; align-items: center;">
        <a
          href="<?= htmlspecialchars($base) ?>/index.php?page=list"
          class="btn-back"
          aria-label="<?= htmlspecialchars(sf_term('back_to_list', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
        >
          ← <?= htmlspecialchars(sf_term('back_to_list', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </a>
        <?php if (($flash['type'] ?? '') === 'green'): ?>
        <button
           id="btnGenerateReport"
           data-report-url="<?= htmlspecialchars($base) ?>/app/api/generate_report.php?id=<?= (int)$id ?>"
           class="btn-report-topright"
           aria-label="<?= htmlspecialchars(sf_term('btn_report', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
            <img src="<?= $iconBase ?>report.svg" alt="" class="btn-report-icon">
            <span><?= htmlspecialchars(sf_term('btn_report', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
        </button>
        <?php endif; ?>
    </div>

    <!-- ===== FOOTER ACTION BAR (siirretty ylös, jotta näkyy heti) ===== -->
    <?php if ($hasActions): ?>
    <div class="view-footer-actions" role="toolbar" aria-label="Toiminnot">
        <div class="view-footer-buttons-4col">

            <?php if (in_array('comment', $actions)): ?>
                <button class="footer-btn fb-comment" id="footerComment" type="button" aria-label="<?= htmlspecialchars(sf_term('footer_comment', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                    <img src="<?= $iconBase ?>comment_icon.svg" alt="" class="footer-icon">
                    <span class="btn-label"><?= htmlspecialchars(sf_term('footer_comment', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                </button>
            <?php endif; ?>

            <?php if (in_array('edit', $actions)): ?>
                <button class="footer-btn fb-edit" id="footerEdit" type="button" aria-label="<?= htmlspecialchars(sf_term('footer_edit', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                    <img src="<?= $iconBase ?>edit_icon.svg" alt="" class="footer-icon">
                    <span class="btn-label"><?= htmlspecialchars(sf_term('footer_edit', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                </button>
            <?php endif; ?>

            <?php if (in_array('delete', $actions)): ?>
                <button class="footer-btn fb-delete" id="footerDelete" type="button" aria-label="<?= htmlspecialchars(sf_term('footer_delete', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                    <img src="<?= $iconBase ?>delete_icon.svg" alt="" class="footer-icon">
                    <span class="btn-label"><?= htmlspecialchars(sf_term('footer_delete', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                </button>
            <?php endif; ?>

            <?php if (in_array('request', $actions)): ?>
                <button class="footer-btn fb-request" id="footerRequest" type="button" aria-label="<?= htmlspecialchars(sf_term('footer_return', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                    <img src="<?= $iconBase ?>reverse_icon.svg" alt="" class="footer-icon">
                    <span class="btn-label"><?= htmlspecialchars(sf_term('footer_return', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                </button>
            <?php endif; ?>

            <?php if (in_array('approve_to_comms', $actions)): ?>
                <?php
                $lblApproveToComms = [
                    'fi' => 'Hyväksy → viestintään',
                    'sv' => 'Godkänn → kommunikation',
                    'en' => 'Approve → Comms',
                    'it' => 'Approva → Comunicazione',
                    'el' => 'Έγκριση → Επικοινωνία',
                ][$currentUiLang] ?? 'Approve → Comms';
                ?>
                <button
                    class="footer-btn fb-comms"
                    id="footerApproveToComms"
                    type="button"
                    data-modal-open="#modalToComms"
                    aria-label="<?= htmlspecialchars($lblApproveToComms, ENT_QUOTES, 'UTF-8') ?>"
                >
                    <img src="<?= $iconBase ?>communications_icon.svg" alt="" class="footer-icon">
                    <span class="btn-label"><?= htmlspecialchars($lblApproveToComms, ENT_QUOTES, 'UTF-8') ?></span>
                </button>
            <?php endif; ?>

            <?php if (in_array('comms', $actions)): ?>
                <button class="footer-btn fb-comms" id="footerComms" type="button" aria-label="<?= htmlspecialchars(sf_term('footer_to_comms', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                    <img src="<?= $iconBase ?>communications_icon.svg" alt="" class="footer-icon">
                    <span class="btn-label"><?= htmlspecialchars(sf_term('footer_to_comms', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                </button>
            <?php endif; ?>

            <?php if (in_array('send_to_safety', $actions)): ?>
                <button class="footer-btn fb-send-safety" id="footerSendSafety" type="button" aria-label="<?= htmlspecialchars(sf_term('footer_send_to_safety', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                    <img src="<?= $iconBase ?>forward_icon.svg" alt="" class="footer-icon">
                    <span class="btn-label"><?= htmlspecialchars(sf_term('footer_send_to_safety', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                </button>
            <?php endif; ?>

            <?php if (in_array('publish', $actions)): ?>
                <button class="footer-btn fb-publish" id="footerPublish" type="button" aria-label="<?= htmlspecialchars(sf_term('footer_publish', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                    <img src="<?= $iconBase ?>publish_icon.svg" alt="" class="footer-icon">
                    <span class="btn-label"><?= htmlspecialchars(sf_term('footer_publish', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                </button>
            <?php endif; ?>

                        <?php if (in_array('publish_direct', $actions)): ?>
                <button
                    class="footer-btn fb-publish"
                    id="footerPublishDirect"
                    type="button"
                    data-modal-open="#modalPublishDirect"
                    aria-label="<?= htmlspecialchars(sf_term('footer_publish_direct', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                >
                    <img src="<?= $iconBase ?>publish_icon.svg" alt="" class="footer-icon">
                    <span class="btn-label"><?= htmlspecialchars(sf_term('footer_publish_direct', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                </button>
            <?php endif; ?>

            <?php if (in_array('archive', $actions)): ?>
                <button class="footer-btn fb-archive" id="footerArchive" type="button" aria-label="<?= htmlspecialchars(sf_term('btn_archive', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                    <img src="<?= $iconBase ?>archive_icon.svg" alt="" class="footer-icon">
                    <span class="btn-label"><?= htmlspecialchars(sf_term('btn_archive', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                </button>
            <?php endif; ?>

            <?php if (in_array('display_targets', $actions)): ?>
                <button class="footer-btn fb-display-targets" id="footerDisplayTargets" type="button" aria-label="<?= htmlspecialchars(sf_term('footer_display_targets', $currentUiLang) ?? 'Infonäytöt', ENT_QUOTES, 'UTF-8') ?>">
                    <img src="<?= $iconBase ?>display.svg" alt="" class="footer-icon">
                    <span class="btn-label"><?= htmlspecialchars(sf_term('footer_display_targets', $currentUiLang) ?? 'Infonäytöt', ENT_QUOTES, 'UTF-8') ?></span>
                </button>
            <?php endif; ?>

            <?php if ($canMergeOriginalFlash): ?>
                <button
                    class="footer-btn fb-merge"
                    id="footerMergeFlash"
                    type="button"
                    aria-label="<?= htmlspecialchars(sf_term('footer_merge_flash', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                >
                    <img src="<?= $iconBase ?>link.svg" alt="" class="footer-icon">
                    <span class="btn-label"><?= htmlspecialchars(sf_term('footer_merge_flash', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                </button>
            <?php endif; ?>

            <?php if (in_array('send_to_review', $actions)): ?>
                <a href="<?= htmlspecialchars($base) ?>/index.php?page=form&id=<?= (int) $id ?>&step=6" class="footer-btn fb-comms">
                    <img src="<?= $iconBase ?>supervisor_icon.svg" alt="" class="footer-icon">
                    <span class="btn-label"><?= htmlspecialchars(sf_term('footer_send_to_review', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                </a>
            <?php endif; ?>

            <?php if ($canAccessSettings): ?>
                <button class="footer-btn fb-settings" id="footerSettings" type="button"
                        data-modal-open="#sfReportSettingsModal"
                        aria-label="<?= htmlspecialchars(sf_term('footer_settings', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                    <svg class="footer-icon" viewBox="0 0 24 24" aria-hidden="true" focusable="false" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round">
                        <path d="M12 8a4 4 0 1 1 0 8 4 4 0 0 1 0-8z"/>
                        <path d="M4 12h2M18 12h2M12 4v2M12 18v2M7 7l1.5 1.5M15.5 15.5L17 17M7 17l1.5-1.5M15.5 8.5L17 7"/>
                    </svg>
                    <span class="btn-label"><?= htmlspecialchars(sf_term('footer_settings', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                </button>
            <?php endif; ?>

        </div>
    </div>
    <?php endif; // End of hasActions check ?>

    <div
      class="lang-switcher"
      role="tablist"
      aria-label="<?= htmlspecialchars(sf_term('view_languages_aria', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
    >
        <?php foreach ($supportedLangs as $langCode => $langData):
            $hasTranslation = isset($translations[$langCode]);
            $isActive = ($langCode === $currentLang);
            
            // LISÄTTY: Arkistoidussa näytetään vain olemassa olevat käännökset
            if ($isArchived && ! $hasTranslation) {
                continue; // Ohita puuttuvat käännökset arkistoidussa
            }
            
            // Build tooltip text based on state
            $tooltipText = '';
            if ($isActive) {
                // Active version tooltip
                $tooltipText = sf_term('lang_tooltip_active_' . $langCode, $currentUiLang) ?? '';
            } elseif ($hasTranslation) {
                // Created version tooltip (go to version)
                $tooltipText = sf_term('lang_tooltip_goto_' . $langCode, $currentUiLang) ?? '';
            } else {
                // Missing version tooltip (add version)
                $tooltipText = sf_term('lang_tooltip_add_' . $langCode, $currentUiLang) ?? '';
            }
            
            // Get add button text
            $addButtonText = sf_term('lang_add_button_text', $currentUiLang) ?? '+Lisää';
        ?>
            <div class="lang-chip <?= $isActive ? 'active' : '' ?> <?= $hasTranslation ? 'has-version' : 'no-version' ?>" role="button" tabindex="0" title="<?= htmlspecialchars($tooltipText, ENT_QUOTES, 'UTF-8') ?>">
                <?php if ($hasTranslation): ?>
                    <a href="index.php?page=view&id=<?= (int)$translations[$langCode] ?>" class="lang-link">
                        <img class="lang-flag-img" src="<?= htmlspecialchars($base) ?>/assets/img/<?= htmlspecialchars($langData['icon']) ?>" alt="<?= htmlspecialchars($langData['label']) ?>">
                        <span class="lang-label"><?= htmlspecialchars($langData['label']) ?></span>
                    </a>
                <?php elseif (! $isArchived): ?>
                    <!-- Näytä + -nappi vain jos EI arkistoitu -->
                    <button type="button" class="lang-add-button" data-lang="<?= htmlspecialchars($langCode) ?>" data-lang-label="<?= htmlspecialchars($langData['label']) ?>" data-base-id="<?= (int)$currentId ?>" onclick="sfConfirmTranslation(this)">
                        <img class="lang-flag-img" src="<?= htmlspecialchars($base) ?>/assets/img/<?= htmlspecialchars($langData['icon']) ?>" alt="<?= htmlspecialchars($langData['label']) ?>">
                        <span class="lang-label"><?= htmlspecialchars($addButtonText) ?></span>
                    </button>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    
    <!-- UUSI RAKENNE ALKAA TÄSTÄ -->
    <div class="view-layout">
        <!-- Vasen palsta -->
        <div class="view-left">
            <div class="view-box preview-box" 
                 data-flash-id="<?= (int)$flash['id'] ?>"
                 data-preview-status="<?= htmlspecialchars($previewStatus) ?>">
                <!-- Loading spinner for preview image -->
                <div class="preview-loading-spinner" id="previewSpinner">
                    <div class="spinner"></div>
                    <span class="spinner-text"><?= htmlspecialchars(sf_term('loading', $currentUiLang) ?: 'Ladataan...', ENT_QUOTES, 'UTF-8') ?></span>
                </div>
                
                <?php if ($flash['type'] === 'green' && $hasSecondCard): ?>
                    <!-- TUTKINTATIEDOTE: Välilehdet kahdelle kortille -->
                    <div class="sf-view-preview-tabs" id="sfViewPreviewTabs">
                        <button type="button"
                                class="sf-view-tab-btn active"
                                data-target="preview1">
                            <?= htmlspecialchars(sf_term('card_1_summary', $currentUiLang) ?? '1. Yhteenveto', ENT_QUOTES, 'UTF-8') ?>
                        </button>
                        <button type="button"
                                class="sf-view-tab-btn"
                                data-target="preview2">
                            <?= htmlspecialchars(sf_term('card_2_investigation', $currentUiLang) ?? '2. Juurisyyt & toimenpiteet', ENT_QUOTES, 'UTF-8') ?>
                        </button>
                    </div>

                    <div class="sf-view-preview-cards">
                        <div class="sf-view-preview-card active" id="viewPreview1">
                            <img src="<?= htmlspecialchars($previewUrl) ?>" alt="Preview kortti 1"
                                 class="preview-image preview-image-clickable" id="viewPreviewImage1"
                                 loading="eager"
                                 data-preview-fullscreen-trigger="true"
                                 data-preview-title="<?= htmlspecialchars(sf_term('card_1_summary', $currentUiLang) ?? '1. Yhteenveto', ENT_QUOTES, 'UTF-8') ?>"
                                 tabindex="0"
                                 role="button">
                        </div>
                        <div class="sf-view-preview-card" id="viewPreview2" style="display:none;">
                            <?php if ($previewUrl2): ?>
                                <img src="<?= htmlspecialchars($previewUrl2) ?>" alt="Preview kortti 2"
                                     class="preview-image preview-image-clickable" id="viewPreviewImage2"
                                     loading="lazy"
                                     decoding="async"
                                     data-preview-fullscreen-trigger="true"
                                     data-preview-title="<?= htmlspecialchars(sf_term('card_2_investigation', $currentUiLang) ?? '2. Juurisyyt & toimenpiteet', ENT_QUOTES, 'UTF-8') ?>"
                                     tabindex="0"
                                     role="button">
                            <?php else: ?>
                                <div class="sf-preview-placeholder">
                                    <p>
                                        <?= htmlspecialchars(
                                            sf_term('preview_2_not_generated', $currentUiLang)
                                            ?? 'Kortin 2 preview-kuvaa ei ole vielä generoitu.',
                                            ENT_QUOTES,
                                            'UTF-8'
                                        ) ?>
                                    </p>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Kaksi latausnappia vierekkäin (EI dropdown) -->
                    <!-- Note: Already inside hasSecondCard block, but double-check both files exist -->
                    <?php if (!empty($flash['preview_filename']) && $previewUrl2): ?>
                    <div class="sf-download-buttons">
                        <a href="<?= htmlspecialchars($previewUrl) ?>" 
                           download="<?= htmlspecialchars(sf_generate_download_filename($flash, 1)) ?>"
                           class="sf-btn-download">
                            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                                <polyline points="7 10 12 15 17 10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                                <line x1="12" y1="15" x2="12" y2="3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <span><?= htmlspecialchars(sf_term('card_1_label', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                        </a>
                        <a href="<?= htmlspecialchars($previewUrl2) ?>" 
                           download="<?= htmlspecialchars(sf_generate_download_filename($flash, 2)) ?>"
                           class="sf-btn-download">
                            <svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true">
                                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                                <polyline points="7 10 12 15 17 10" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                                <line x1="12" y1="15" x2="12" y2="3" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                            </svg>
                            <span><?= htmlspecialchars(sf_term('card_2_label', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                        </a>
                    </div>
                    <?php endif; ?>

                <?php else: ?>
                    <!-- NORMAALI: Yksi preview-kuva (red/yellow tai green ilman toista korttia) -->
                    <?php if ($isPreviewPending): ?>
                        <!-- Skeleton placeholder when preview is being generated -->
                        <div class="skeleton-preview-placeholder">
                            <div class="skeleton-preview-box">
                                <div class="skeleton-preview-image skeleton"></div>
                            </div>
                            <div class="sf-preview-pending-message sf-generating-overlay">
                                <div class="sf-generating-content">
                                    <div class="sf-generating-spinner"></div>
                                    <div class="sf-generating-text"><?= htmlspecialchars(sf_term('preview_being_generated', $currentUiLang) ?: 'Esikatselukuvaa luodaan...', ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="sf-preview-progress-wrap">
                                        <div class="sf-preview-progress-bar" style="width: 10%"></div>
                                    </div>
                                    <div class="sf-preview-progress-text">10%</div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <img src="<?= htmlspecialchars($previewUrl) ?>" alt="Preview"
                             class="preview-image preview-image-clickable" id="viewPreviewImage"
                             loading="eager"
                             data-preview-fullscreen-trigger="true"
                             data-preview-title="<?= htmlspecialchars(sf_term('preview_and_save', $currentUiLang) ?? 'Esikatselu', ENT_QUOTES, 'UTF-8') ?>"
                             tabindex="0"
                             role="button">
                    <?php endif; ?>

                    <?php if (!empty($flash['preview_filename']) && !$isPreviewPending): ?>
                        <div class="preview-download-wrapper">
                            <a href="<?= htmlspecialchars($previewUrl) ?>"
                               download="<?= htmlspecialchars(sf_generate_download_filename($flash)) ?>"
                               class="btn-download-preview"
                               title="<?= htmlspecialchars(sf_term('download_preview', $currentUiLang) ?? 'Lataa kuva', ENT_QUOTES, 'UTF-8') ?>">
                                <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true">
                                    <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"
                                          stroke="currentColor" stroke-width="2"
                                          stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                                    <polyline points="7 10 12 15 17 10"
                                              stroke="currentColor" stroke-width="2"
                                              stroke-linecap="round" stroke-linejoin="round" fill="none"/>
                                    <line x1="12" y1="15" x2="12" y2="3"
                                          stroke="currentColor" stroke-width="2"
                                          stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                                <span>
                                    <?= htmlspecialchars(sf_term('download_preview', $currentUiLang) ?? 'Lataa JPG', ENT_QUOTES, 'UTF-8') ?>
                                </span>
                            </a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div> <!-- .preview-box -->

            <!-- KOMMENTIT JA TAPAHTUMALOKI TAB-NÄKYMÄ -->
            <div class="sf-view-activity-section view-box">
                <div class="sf-activity-tabs">
                    <button class="sf-activity-tab active" data-tab="comments">
                        <img src="<?= $base ?>/assets/img/icons/comment.svg" alt="" class="sf-tab-icon">
                        <span><?= htmlspecialchars(sf_term('activity_tab_comments', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="sf-tab-badge" id="commentCount">0</span>
                    </button>
                    <button class="sf-activity-tab" data-tab="events">
                        <img src="<?= $base ?>/assets/img/icons/timeline.svg" alt="" class="sf-tab-icon">
                        <span><?= htmlspecialchars(sf_term('activity_tab_events', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                    </button>
                    <button class="sf-activity-tab" data-tab="additionalInfo">
                        <img src="<?= $base ?>/assets/img/icons/list.svg" alt="" class="sf-tab-icon">
                        <span><?= htmlspecialchars(sf_term('additional_info_tab_label', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                    </button>
                    <button class="sf-activity-tab" data-tab="versions">
                        <img src="<?= $base ?>/assets/img/icons/version-document.svg" alt="" class="sf-tab-icon">
                        <span><?= htmlspecialchars(sf_term('activity_tab_versions', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
                    </button>
                    <button class="sf-activity-tab" data-tab="images" id="imagesTabBtn">
                        <img src="<?= $base ?>/assets/img/icons/image.svg" alt="" class="sf-tab-icon">
                        <span><?= htmlspecialchars(sf_term('activity_tab_images', $currentUiLang) ?: 'Kuvat', ENT_QUOTES, 'UTF-8') ?></span>
                    </button>
                </div>

                <!-- KOMMENTIT TAB -->
                <div class="sf-tab-content active" id="tabComments">
                    <div class="sf-comments-container">

                        <div class="sf-comment-toggle-wrap">
                            <span class="sf-comment-toggle-text">
                                <?= htmlspecialchars(sf_term('comment_email_subscribe', $_SESSION['ui_lang'] ?? 'fi'), ENT_QUOTES, 'UTF-8') ?>
                            </span>

                            <label class="sf-switch" for="commentNotifyInline">
                                <input
                                    type="checkbox"
                                    id="commentNotifyInline"
                                    <?= !empty($commentNotificationsChecked) ? 'checked' : '' ?>
                                >
                                <span class="sf-switch-slider"></span>
                            </label>
                        </div>

                        <?php
                        // Suodata vain kommentit ja submission kommentit
                        $comments = array_filter($logs, function($log) {
                            $eventType = $log['event_type'] ?? '';
                            return in_array($eventType, ['comment_added', 'submission_comment']);
                        });
                        
                        if (empty($comments)):
                        ?>
                            <div class="sf-empty-state">
                                <img src="<?= $base ?>/assets/img/icons/no-comments.svg" alt="" class="sf-empty-icon">
                                <p><?= htmlspecialchars(sf_term('comments_empty', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                        <?php else:
                            // Group comments by parent
                            $topLevelComments = [];
                            $repliesByParent = [];
                            
                            foreach ($comments as $comment) {
                                $parentId = $comment['parent_id'] ?? null;
                                if ($parentId) {
                                    if (!isset($repliesByParent[$parentId])) {
                                        $repliesByParent[$parentId] = [];
                                    }
                                    $repliesByParent[$parentId][] = $comment;
                                } else {
                                    $topLevelComments[] = $comment;
                                }
                            }
                            
                            // Function to render a single comment
                            function renderComment($comment, $repliesByParent, $currentUserId, $isAdmin, $currentUiLang, $base, $isReply = false) {
                                $first = trim((string)($comment['first_name'] ?? ''));
                                $last = trim((string)($comment['last_name'] ?? ''));
                                $fullName = trim($first . ' ' . $last);
                                $avatarInitials = sf_avatar_initials($fullName);
                                
                                $eventType = $comment['event_type'] ?? '';
                                $isSubmissionComment = ($eventType === 'submission_comment');
                                
                                // Parse kommentti description-kentästä
                                $descRaw = $comment['description'] ?? '';
                                $commentText = '';
                                if (preg_match('/log_comment_label:\s*(.+)/is', $descRaw, $match)) {
                                    $commentText = trim($match[1]);
                                    // Translate a nested log_ key prefix if present
                                    // e.g. "log_sent_to_comms: <message>" → "Sent to communications: <message>"
                                    if (preg_match('/^(log_\w+):\s*(.*)$/su', $commentText, $nestedMatch)) {
                                        $nestedKey   = $nestedMatch[1];
                                        $nestedValue = trim($nestedMatch[2]);
                                        $nestedT     = sf_term($nestedKey, $currentUiLang);
                                        if ($nestedT !== $nestedKey) {
                                            $commentText = $nestedValue !== ''
                                                ? $nestedT . ': ' . $nestedValue
                                                : $nestedT;
                                        }
                                    }
                                } else {
                                    $commentText = $descRaw;
                                }
                                
                                // Relatiivinen aika
                                $timeAgo = sf_time_ago($comment['created_at'], $currentUiLang);
                                
                                $isOwnComment = ($comment['user_id'] ?? 0) == ($currentUserId ?? 0);
                                $replyClass = $isReply ? 'sf-comment-reply' : '';
                                $parentIdAttr = !empty($comment['parent_id']) ? ' data-parent-id="' . (int)$comment['parent_id'] . '"' : '';
                            ?>
                                <div class="sf-comment-item <?= $isOwnComment ? 'sf-comment-own' : '' ?> <?= $replyClass ?>" data-comment-id="<?= (int)$comment['id'] ?>"<?= $parentIdAttr ?>>
                                    <div class="sf-comment-avatar" data-name="<?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($avatarInitials, ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                    <div class="sf-comment-content">
                                        <div class="sf-comment-header">
                                            <span class="sf-comment-author"><?= htmlspecialchars($fullName ?: 'Unknown', ENT_QUOTES, 'UTF-8') ?></span>
                                            <?php if ($isSubmissionComment): ?>
<span class="sf-comment-badge sf-badge-submission" style="background: #3b82f6; color: white; padding: 2px 8px; border-radius: 4px; font-size: 0.75rem; margin-left: 8px; display: inline-flex; align-items: center; gap: 4px;">
    <img src="assets/img/icons/information.svg" alt="" style="width:12px; height:12px; filter: brightness(0) invert(1);">
    <?= htmlspecialchars(sf_term('submission_comment_badge', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
</span>
                                            <?php endif; ?>
                                            <span class="sf-comment-time" title="<?= htmlspecialchars($comment['created_at'], ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars($timeAgo, ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </div>
                                        <div class="sf-comment-body">
                                            <?= nl2br(htmlspecialchars($commentText, ENT_QUOTES, 'UTF-8')) ?>
                                        </div>
                                        <?php if (!$isSubmissionComment): ?>
                                        <div class="sf-comment-actions">
                                            <button type="button" class="sf-comment-action-btn btn-reply-comment" data-comment-id="<?= (int)$comment['id'] ?>">
                                                <img src="<?= $base ?>/assets/img/icons/reply.svg" alt="" class="sf-action-icon">
                                                <?= htmlspecialchars(sf_term('comment_reply', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                                            </button>
                                            <?php if ($isOwnComment || $isAdmin): ?>
                                                <button type="button" class="sf-comment-action-btn btn-edit-comment" data-comment-id="<?= (int)$comment['id'] ?>">
                                                    <img src="<?= $base ?>/assets/img/icons/edit.svg" alt="" class="sf-action-icon">
                                                    <?= htmlspecialchars(sf_term('comment_edit', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                                                </button>
                                                <button type="button" class="sf-comment-action-btn btn-delete-comment sf-text-danger" data-comment-id="<?= (int)$comment['id'] ?>">
                                                    <img src="<?= $base ?>/assets/img/icons/delete.svg" alt="" class="sf-action-icon">
                                                    <?= htmlspecialchars(sf_term('comment_delete', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php
                                // Render replies recursively
                                if (isset($repliesByParent[$comment['id']])) {
                                    foreach ($repliesByParent[$comment['id']] as $reply) {
                                        renderComment($reply, $repliesByParent, $currentUserId, $isAdmin, $currentUiLang, $base, true);
                                    }
                                }
                            }
                        ?>
                            <div class="sf-comments-list">
                                <?php
                                // Render all top-level comments with their replies
                                foreach ($topLevelComments as $topComment) {
                                    renderComment($topComment, $repliesByParent, $currentUserId, $isAdmin, $currentUiLang, $base, false);
                                }
                                ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- TAPAHTUMAT TAB -->
                <div class="sf-tab-content" id="tabEvents">
                    <div class="sf-events-timeline">
                        <?php
                        // Helper function to translate flash type names
                        function sf_translate_flash_type($typeKey, $lang) {
                            $typeMap = [
                                'red' => 'first_release',
                                'yellow' => 'dangerous_situation',
                                'green' => 'investigation_report',
                            ];
                            
                            $termKey = $typeMap[$typeKey] ?? null;
                            return $termKey ? sf_term($termKey, $lang) : $typeKey;
                        }
                        
                        // Suodata tapahtumat (ei kommentteja)
                        $events = array_filter($logs, function($log) {
                            return ($log['event_type'] ?? '') !== 'comment_added';
                        });
                        $events = array_values($events);

                        // Ryhmittele tapahtumat batch_id:n mukaan
                        // Vanhat lokit ilman batch_id:tä ryhmitellään aikaleiman perusteella (±2s, sama käyttäjä)
                        $batchGroups  = []; // batch_id → [events]
                        $noIdGroups   = []; // array of [events] arrays (aika-ryhmitellyt)
                        // Toleranssi vanhoille lokeille ilman batch_id:tä (sekunteina)
                        $legacyGroupingWindowSeconds = 2;

                        foreach ($events as $event) {
                            $bid = $event['batch_id'] ?? null;
                            if (!empty($bid)) {
                                $batchGroups[$bid][] = $event;
                            } else {
                                $matched = false;
                                foreach ($noIdGroups as &$group) {
                                    $firstTs  = strtotime($group[0]['created_at'] ?? '');
                                    $thisTs   = strtotime($event['created_at'] ?? '');
                                    $sameUser = ($group[0]['user_id'] ?? '') === ($event['user_id'] ?? '');
                                    if ($sameUser && $firstTs !== false && $thisTs !== false && abs($firstTs - $thisTs) <= $legacyGroupingWindowSeconds) {
                                        $group[] = $event;
                                        $matched = true;
                                        break;
                                    }
                                }
                                unset($group);
                                if (!$matched) {
                                    $noIdGroups[] = [$event];
                                }
                            }
                        }

                        // Yhdistä kaikki ryhmät yhdeksi listaksi
                        $displayGroups = array_values($batchGroups);
                        foreach ($noIdGroups as $group) {
                            $displayGroups[] = $group;
                        }

                        // Lajittele uusimmat ensin (ensimmäisen rivin created_at mukaan)
                        usort($displayGroups, function($a, $b) {
                            $ta = strtotime($a[0]['created_at'] ?? '') ?: 0;
                            $tb = strtotime($b[0]['created_at'] ?? '') ?: 0;
                            return $tb - $ta;
                        });

                        if (empty($displayGroups)):
                        ?>
                            <div class="sf-empty-state">
                                <img src="<?= $base ?>/assets/img/icons/no-events.svg" alt="" class="sf-empty-icon">
                                <p><?= htmlspecialchars(sf_term('events_empty', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                        <?php else: ?>
                            <?php foreach ($displayGroups as $group):
                                $event = $group[0]; // Ensisijainen tapahtuma ryhmässä
                                $first = trim((string)($event['first_name'] ?? ''));
                                $last = trim((string)($event['last_name'] ?? ''));
                                $fullName = trim($first . ' ' . $last);
                                
                                $eventType = $event['event_type'] ?? 'UNKNOWN_EVENT';
                                // Try with log_ prefix first (terms are keyed as log_<event_type>),
                                // then try the raw event_type as fallback
                                $eventLabel = sf_term('log_' . $eventType, $currentUiLang);
                                if ($eventLabel === 'log_' . $eventType) {
                                    $eventLabel = sf_term($eventType, $currentUiLang);
                                }
                                
                                // Määritä ikoni event_type:n perusteella
                                $eventIcons = [
                                    'created' => 'create.svg',
                                    'updated' => 'edit.svg',
                                    'state_changed' => 'status-change.svg',
                                    'sent_to_review' => 'send.svg',
                                    'sent_to_comms' => 'send.svg',
                                    'published' => 'publish.svg',
                                    'approved' => 'approve.svg',
                                    'rejected' => 'reject.svg',
                                    'archived' => 'archive.svg',
                                    'deleted' => 'delete.svg',
                                ];
                                $iconFile = $eventIcons[$eventType] ?? 'info.svg';
                                
                                $timeAgo = sf_time_ago($event['created_at'], $currentUiLang);

                                // Helper closure: kääntää yhden tapahtuman kuvauksen HTML:ksi
                                $parseEventDesc = function(string $descRaw) use ($currentUiLang): string {
                                    $descToShow = '';
                                    $lines = explode("\n", $descRaw);
                                    foreach ($lines as $line) {
                                        $line = trim($line);
                                        if ($line === '') {
                                            continue;
                                        }
                                        $translatedLine = $line;
                                        // 1. OCCURRED_AT
                                        if (preg_match('/^occurred_at:\s*(.+?)\s*→\s*(.+)$/u', $line, $matches)) {
                                            $beforeLabel = sf_term('occurred_at', $currentUiLang);
                                            $before = trim($matches[1]);
                                            $after  = str_replace('T', ' ', trim($matches[2]));
                                            $translatedLine = '<strong>' . $beforeLabel . ':</strong> ' . $before . ' → ' . $after;
                                        }
                                        // 2. STATUS PIPE
                                        elseif (preg_match('/^(log_\w+)\|status:(\w+)$/u', $line, $matches)) {
                                            $translatedLine = '<strong>' . sf_term($matches[1], $currentUiLang) . ':</strong> ' . sf_status_label($matches[2], $currentUiLang);
                                        }
                                        // 3. DISTRIBUTION SENT (new format: counts:fi:5,se:3)
                                        elseif (preg_match('/^log_distribution_sent\|counts:(.+)$/u', $line, $matches)) {
                                            $recipientsLabel = sf_term('log_recipients_count', $currentUiLang);
                                            $parts = [];
                                            foreach (explode(',', $matches[1]) as $pair) {
                                                $pairParts = explode(':', trim($pair), 2);
                                                if (count($pairParts) !== 2) {
                                                    continue;
                                                }
                                                $cc  = trim($pairParts[0]);
                                                $cnt = trim($pairParts[1]);
                                                if ($cc !== '') {
                                                    $countryName = sf_term("country_name_{$cc}", $currentUiLang);
                                                    if ($countryName === "country_name_{$cc}") {
                                                        $countryName = strtoupper($cc);
                                                    }
                                                    $parts[] = $countryName . ($cnt !== '' ? ': ' . $cnt . ' ' . $recipientsLabel : '');
                                                }
                                            }
                                            $translatedLine = '<strong>' . sf_term('log_distribution_sent', $currentUiLang) . ':</strong> ' . implode('; ', $parts);
                                        }
                                        // 3b. DISTRIBUTION SENT (legacy format: countries:…|details:…)
                                        elseif (preg_match('/^log_distribution_sent\|countries:([^|]+)\|details:(.+)$/u', $line, $matches)) {
                                            $translatedLine = '<strong>' . sf_term('log_distribution_sent', $currentUiLang) . ':</strong> ' . trim($matches[2]);
                                        }
                                        // 4. MULTI-PARAM PIPE
                                        elseif (preg_match('/^(log_\w+)\|(.+)$/u', $line, $matches)) {
                                            $logTranslated = sf_term($matches[1], $currentUiLang);
                                            $params = [];
                                            foreach (explode('|', $matches[2]) as $part) {
                                                if (strpos($part, ':') !== false) {
                                                    [$k, $v] = array_map('trim', explode(':', $part, 2));
                                                    $params[$k] = $v;
                                                }
                                            }
                                            $translatedLine = isset($params['details'])
                                                ? '<strong>' . $logTranslated . ':</strong> ' . $params['details']
                                                : '<strong>' . $logTranslated . '</strong>';
                                        }
                                        // 5. LABEL
                                        elseif (preg_match('/^(log_\w+_label):\s*(.+)$/u', $line, $matches)) {
                                            $translatedLine = '<strong>' . sf_term($matches[1], $currentUiLang) . ':</strong> ' . trim($matches[2]);
                                        }
                                        // 6. FIELD CHANGE with quotes
                                        elseif (preg_match('/^([a-z_]+):\s*"([^"]+)"\s*→\s*"([^"]+)"$/u', $line, $matches)) {
                                            $translatedLine = '<strong>' . sf_term($matches[1], $currentUiLang) . ':</strong> ' . $matches[2] . ' → ' . $matches[3];
                                        }
                                        // 6b. TYPE CHANGE
                                        elseif (preg_match('/^type:\s*(\w+)\s*→\s*(\w+)$/u', $line, $matches)) {
                                            $translatedLine = '<strong>' . sf_term('type', $currentUiLang) . ':</strong> '
                                                . sf_translate_flash_type(trim($matches[1]), $currentUiLang)
                                                . ' → '
                                                . sf_translate_flash_type(trim($matches[2]), $currentUiLang);
                                        }
                                        // 7. FIELD CHANGE without quotes
                                        elseif (preg_match('/^([a-z_]+):\s*([^→]+)\s*→\s*(.+)$/u', $line, $matches)) {
                                            $oldValue = trim($matches[2]);
                                            $newValue = trim($matches[3]);
                                            $oldT = sf_status_label($oldValue, $currentUiLang);
                                            if ($oldT && $oldT !== $oldValue && preg_match('/^[a-z_]+$/', $oldValue)) $oldValue = $oldT;
                                            $newT = sf_status_label($newValue, $currentUiLang);
                                            if ($newT && $newT !== $newValue && preg_match('/^[a-z_]+$/', $newValue)) $newValue = $newT;
                                            $translatedLine = '<strong>' . sf_term($matches[1], $currentUiLang) . ':</strong> ' . $oldValue . ' → ' . $newValue;
                                        }
                                        // 8. SIMPLE KEY:VALUE
                                        elseif (preg_match('/^(log_\w+|[a-z_]+):\s*(.+)$/u', $line, $matches)) {
                                            $key   = $matches[1];
                                            $value = trim($matches[2]);
                                            $keyT  = sf_term($key, $currentUiLang);
                                            if ($keyT === $key && strpos($key, 'log_') !== 0) {
                                                $translatedLine = $line;
                                            } else {
                                                $translatedLine = '<strong>' . $keyT . ':</strong> ' . $value;
                                            }
                                        }
                                        // 9. Käännä koko rivi
                                        else {
                                            $translated = sf_term($line, $currentUiLang);
                                            if ($translated !== $line) {
                                                $translatedLine = $translated;
                                            }
                                        }
                                        if ($descToShow !== '') {
                                            $descToShow .= "\n";
                                        }
                                        $descToShow .= $translatedLine;
                                    }
                                    $descProcessed = function_exists('sf_log_status_replace')
                                        ? sf_log_status_replace($descToShow, $currentUiLang)
                                        : $descToShow;
                                    return strip_tags($descProcessed, '<span><strong>');
                                };

                                // Kerää kaikkien ryhmän tapahtumien kuvaukset
                                $groupDescriptions = [];
                                foreach ($group as $groupEvent) {
                                    $parsed = $parseEventDesc($groupEvent['description'] ?? '');
                                    if ($parsed !== '') {
                                        $groupDescriptions[] = $parsed;
                                    }
                                }
                                $isBatch = count($group) > 1;
                            ?>
                                <div class="sf-event-item<?= $isBatch ? ' sf-event-item--batch' : '' ?>" data-event-type="<?= htmlspecialchars($eventType, ENT_QUOTES, 'UTF-8') ?>">
                                    <div class="sf-event-icon">
                                        <img src="<?= $base ?>/assets/img/icons/<?= htmlspecialchars($iconFile, ENT_QUOTES, 'UTF-8') ?>" alt="">
                                    </div>
                                    <div class="sf-event-content">
                                        <div class="sf-event-header">
                                            <span class="sf-event-label"><?= htmlspecialchars($eventLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                            <span class="sf-event-time"><?= htmlspecialchars($timeAgo, ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                        <?php if (!empty($fullName)): ?>
                                            <div class="sf-event-user">
                                                <?= htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                        <?php endif; ?>
                                        <?php if (!empty($groupDescriptions)): ?>
                                            <div class="sf-event-description">
                                                <?php if ($isBatch): ?>
                                                    <ul class="sf-event-batch-list">
                                                        <?php foreach ($groupDescriptions as $descItem): ?>
                                                            <li><?= nl2br($descItem) ?></li>
                                                        <?php endforeach; ?>
                                                    </ul>
                                                <?php else: ?>
                                                    <?php
                                                    $descLines = array_values(array_filter(
                                                        explode("\n", $groupDescriptions[0]),
                                                        fn($l) => trim($l) !== ''
                                                    ));
                                                    ?>
                                                    <?php if (count($descLines) > 1): ?>
                                                        <ul class="sf-event-batch-list">
                                                            <?php foreach ($descLines as $descLine): ?>
                                                                <li><?= $descLine ?></li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    <?php else: ?>
                                                        <?= $groupDescriptions[0] ?>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- LISÄTIEDOT TAB -->
                <div class="sf-tab-content" id="tabAdditionalInfo">
                    <div class="sf-additional-info-container">

                        <p class="sf-additional-info-description" style="margin-bottom: 1rem; color: #4b5563; font-size: 0.92rem;">
                            <?= htmlspecialchars(sf_term('additional_info_description', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                        </p>

                        <?php
                        // Show body map button only for red flashes (or investigations from a red original)
                        $showBodyMapInTab = (
                            ($flash['type'] === 'red') ||
                            ($flash['original_type'] ?? '') === 'red'
                        );
                        ?>
                        <?php if ($showBodyMapInTab && $canAccessSettings): ?>
                        <div class="sf-additional-info-bodymap" style="margin-bottom: 1.25rem;">
                            <button type="button" id="sfTabBodyMapBtn"
                                    class="sf-btn sf-btn-secondary"
                                    style="display: inline-flex; align-items: center; gap: 0.5rem;">
                                <img src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/img/icons/injury_icon.svg"
                                     width="18" height="18" alt="" aria-hidden="true">
                                <?= htmlspecialchars(sf_term('body_map_open_btn', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                            </button>
                        </div>
                        <?php endif; ?>

                        <?php if ($canAccessSettings): ?>
                        <div class="sf-additional-info-form" style="margin-bottom: 1.25rem;">
                            <button type="button" id="sfOpenAddAdditionalInfoBtn" class="sf-btn sf-btn-primary">
                                <?= htmlspecialchars(sf_term('additional_info_add_btn', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                            </button>
                        </div>
                        <?php endif; ?>

                        <div class="sf-additional-info-list" id="sfAdditionalInfoList">
                            <?php foreach ($additionalInfoEntries as $aiEntry): ?>
                                <?php
                                $aiFirst   = trim((string)($aiEntry['first_name'] ?? ''));
                                $aiLast    = trim((string)($aiEntry['last_name'] ?? ''));
                                $aiName    = trim($aiFirst . ' ' . $aiLast) ?: sf_term('additional_info_unknown_author', $currentUiLang);
                                $aiIsOwn   = $canAccessSettings && ((int)($aiEntry['user_id'] ?? 0) === $currentUserId || $isAdmin);
                                ?>
                                <div class="sf-comment-item" data-ai-id="<?= (int)$aiEntry['id'] ?>">
                                    <div class="sf-comment-content">
                                        <div class="sf-comment-header">
                                            <div>
                                                <span class="sf-comment-author"><?= htmlspecialchars($aiName, ENT_QUOTES, 'UTF-8') ?></span>
                                                <span class="sf-comment-time">&middot; <?= htmlspecialchars($aiEntry['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>
                                            <?php if ($aiIsOwn): ?>
                                            <div class="sf-comment-actions">
                                                <button type="button"
                                                        class="sf-comment-action-btn btn-edit-additional-info"
                                                        data-ai-id="<?= (int)$aiEntry['id'] ?>"
                                                        data-content="<?= htmlspecialchars($aiEntry['content'], ENT_QUOTES, 'UTF-8') ?>">
                                                    <img src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/img/icons/edit.svg" alt="" class="sf-action-icon">
                                                    <?= htmlspecialchars(sf_term('comment_edit', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                                                </button>
                                                <button type="button"
                                                        class="sf-comment-action-btn btn-delete-additional-info sf-text-danger"
                                                        data-ai-id="<?= (int)$aiEntry['id'] ?>">
                                                    <img src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/img/icons/delete.svg" alt="" class="sf-action-icon">
                                                    <?= htmlspecialchars(sf_term('comment_delete', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                                                </button>
                                            </div>
                                            <?php endif; ?>
                                        </div>
                                        <div class="sf-comment-body">
                                            <?= sf_sanitize_ai_html($aiEntry['content']) ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>

                    </div>
                </div>
                <!-- VERSIOT TAB -->
                <div class="sf-tab-content" id="tabVersions">
                    <div class="sf-versions-container">
                        <?php
                        // Fetch published versions
                        $stmtVersions = $pdo->prepare("
                            SELECT s.*, u.first_name, u.last_name 
                            FROM sf_flash_snapshots s
                            LEFT JOIN sf_users u ON s.published_by = u.id
                            WHERE s.flash_id = ?
                            ORDER BY s.published_at DESC
                        ");
                        $stmtVersions->execute([$logFlashId]);
                        $snapshots = $stmtVersions->fetchAll();
                        $totalVersions = count($snapshots);
                        
                        ?>
                        
                        
                        <?php if (empty($snapshots)): ?>
                            <div class="sf-empty-state">
                                <img src="<?= $base ?>/assets/img/icons/version.svg" alt="" class="sf-empty-icon">
                                <p><?= htmlspecialchars(sf_term('version_no_versions', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></p>
                            </div>
                        <?php else: ?>
                            <div class="sf-version-list">
                                <?php 
                                // Define version type mappings once before loop
                                $versionTypeIcons = [
                                    'ensitiedote' => 'icon-red.png',
                                    'vaaratilanne' => 'icon-yellow.png',
                                    'tutkintatiedote' => 'icon-green.png',
                                    'paivitys' => 'icon-yellow.png',
                                ];
                                $versionTypeColors = [
                                    'ensitiedote' => 'red',
                                    'vaaratilanne' => 'yellow',
                                    'tutkintatiedote' => 'green',
                                    'paivitys' => 'yellow',
                                ];
                                
                                $currentVersionTypeMap = [
                                    'red' => 'ensitiedote',
                                    'yellow' => 'vaaratilanne',
                                    'green' => 'tutkintatiedote',
                                ];

                                $currentFlashVersionType = $currentVersionTypeMap[$flash['type'] ?? 'green'] ?? 'tutkintatiedote';
                                $currentFlashLang = strtoupper((string)($flash['lang'] ?? 'FI'));

                                usort($snapshots, static function (array $a, array $b) use ($currentFlashVersionType, $currentFlashLang): int {
                                    $aType = (string)($a['version_type'] ?? '');
                                    $bType = (string)($b['version_type'] ?? '');
                                    $aLang = strtoupper((string)($a['lang'] ?? 'FI'));
                                    $bLang = strtoupper((string)($b['lang'] ?? 'FI'));

                                    $aIsCurrent = ($aType === $currentFlashVersionType && $aLang === $currentFlashLang);
                                    $bIsCurrent = ($bType === $currentFlashVersionType && $bLang === $currentFlashLang);

                                    if ($aIsCurrent && !$bIsCurrent) {
                                        return -1;
                                    }
                                    if (!$aIsCurrent && $bIsCurrent) {
                                        return 1;
                                    }

                                    $aPublished = strtotime((string)($a['published_at'] ?? '')) ?: 0;
                                    $bPublished = strtotime((string)($b['published_at'] ?? '')) ?: 0;

                                    if ($aPublished === $bPublished) {
                                        return ((int)($b['id'] ?? 0)) <=> ((int)($a['id'] ?? 0));
                                    }

                                    return $bPublished <=> $aPublished;
                                });

                                $currentVersionMarked = false;
                                $totalVersions = count($snapshots);

                                foreach ($snapshots as $index => $snapshot): 
                                    $versionTypeLabel = sf_term('version_' . $snapshot['version_type'], $currentUiLang) ?? $snapshot['version_type'];
                                    $publisherName = trim(($snapshot['first_name'] ?? '') . ' ' . ($snapshot['last_name'] ?? ''));
                                    if ($publisherName === '') {
                                        $publisherName = sf_term('log_system_user', $currentUiLang) ?? 'System';
                                    }
                                    $publishedDate = date('d.m.Y', strtotime($snapshot['published_at']));
                                    $publishedTime = date('H:i', strtotime($snapshot['published_at']));
                                    $versionNum = $totalVersions - $index;
                                    
                                    // Map snapshot's version_type to icon and color
                                    $snapshotVersionType = $snapshot['version_type'] ?? 'vaaratilanne';
                                    $versionIcon = $versionTypeIcons[$snapshotVersionType] ?? 'icon-yellow.png';
                                    $versionColorClass = $versionTypeColors[$snapshotVersionType] ?? 'yellow';

                                    $snapshotLang = strtoupper($snapshot['lang'] ?? 'FI');

                                    $isLatest = false;
                                    if (
                                        !$currentVersionMarked
                                        && $snapshotVersionType === $currentFlashVersionType
                                        && $snapshotLang === $currentFlashLang
                                    ) {
                                        $isLatest = true;
                                        $currentVersionMarked = true;
                                    }
                                    
                                    // Language display
                                    $langFlags = [
                                        'FI' => '🇫🇮',
                                        'SV' => '🇸🇪',
                                        'EN' => '🇬🇧',
                                        'IT' => '🇮🇹',
                                        'EL' => '🇬🇷',
                                    ];
                                    $langFlag = $langFlags[$snapshotLang] ?? '';
                                ?>
                                    <div class="sf-version-card sf-version-type-<?= htmlspecialchars($versionColorClass, ENT_QUOTES, 'UTF-8') ?><?= $isLatest ? ' sf-version-latest' : '' ?>">
                                        <?php if ($isLatest): ?>
                                            <span class="sf-version-badge"><?= htmlspecialchars(sf_term('version_current', $currentUiLang) ?? 'Current', ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php endif; ?>
                                        
                                        <div class="sf-version-header">
                                            <div class="sf-version-icon-wrapper">
                                                <!-- USE TYPE-SPECIFIC ICON -->
                                                <img src="<?= $base ?>/assets/img/<?= htmlspecialchars($versionIcon, ENT_QUOTES, 'UTF-8') ?>" alt="" class="sf-version-icon">
                                            </div>
                                            <div class="sf-version-title-block">
                                                <h4 class="sf-version-type-label">
                                                    <?= htmlspecialchars($versionTypeLabel, ENT_QUOTES, 'UTF-8') ?>
                                                    <span class="sf-version-lang-badge"><?= $langFlag ?> <?= $snapshotLang ?></span>
                                                </h4>
                                                <span class="sf-version-number">
                                                    <?= htmlspecialchars(sf_term('version_number', $currentUiLang) ?? 'Version', ENT_QUOTES, 'UTF-8') ?> <?= $versionNum ?>
                                                </span>
                                            </div>
                                        </div>
                                        
                                        <div class="sf-version-meta-row">
                                            <div class="sf-version-meta-item">
                                                <img src="<?= $base ?>/assets/img/icons/timeline.svg" alt="" class="sf-version-meta-icon">
                                                <span class="sf-version-date"><?= htmlspecialchars($publishedDate, ENT_QUOTES, 'UTF-8') ?></span>
                                                <span class="sf-version-time"><?= htmlspecialchars($publishedTime, ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>
                                            <div class="sf-version-meta-item">
                                                <img src="<?= $base ?>/assets/img/icons/user.svg" alt="" class="sf-version-meta-icon">
                                                <span class="sf-version-publisher"><?= htmlspecialchars($publisherName, ENT_QUOTES, 'UTF-8') ?></span>
                                            </div>
                                            <button class="sf-version-view-btn" 
                                                    onclick="openVersionModal('<?= htmlspecialchars($base . $snapshot['image_path'], ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($versionTypeLabel, ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars($snapshot['published_at'], ENT_QUOTES, 'UTF-8') ?>')">
                                                <img src="<?= $base ?>/assets/img/icons/eye_icon.svg" alt="" class="sf-btn-icon">
                                                <?= htmlspecialchars(sf_term('version_view', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- IMAGES TAB -->
                <div class="sf-tab-content" id="tabImages">
                    <div class="images-tab-content">
                        <div class="images-loading" id="imagesLoading">
                            <div class="images-spinner"></div>
                            <p><?= htmlspecialchars(sf_term('images_loading', $currentUiLang) ?: 'Ladataan kuvia...', ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                        <div id="imagesUploadContainer" style="display: none; margin-bottom: 1rem;">
                            <button type="button" id="imagesUploadBtn" class="sf-btn sf-btn-primary">
                                <?= htmlspecialchars(sf_term('add_images_btn', $currentUiLang) ?: 'Lisää kuvia', ENT_QUOTES, 'UTF-8') ?>
                            </button>
                        </div>
                        <div class="images-grid" id="imagesGrid" style="display: none;">
                            <!-- Images will be loaded here by JavaScript -->
                        </div>
                        <div class="no-images-message" id="noImagesMessage" style="display: none;">
                            <p><?= htmlspecialchars(sf_term('no_images_message', $currentUiLang) ?: 'Ei kuvia.', ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                    </div>
                </div>

            </div>
        </div>

<!-- Oikea palsta -->
        <div class="view-right">

            <?php
            // Hae kaikki kieliversiot oikean palstan tilakorttia varten
            $stmtAllLangVers = $pdo->prepare("
                SELECT id, lang, state, title, published_at FROM sf_flashes
                WHERE id = :gid OR translation_group_id = :gid2
                ORDER BY FIELD(lang, 'fi', 'sv', 'en', 'it', 'el')
            ");
            $stmtAllLangVers->execute([':gid' => $translationGroupId, ':gid2' => $translationGroupId]);
            $allLangVersions = $stmtAllLangVers->fetchAll(PDO::FETCH_ASSOC);
            ?>

            <?php /* Kieliversiot-osio poistettu sidebarista — tuplatieto, näkyy jo yläosan välilehtinä */ ?>

            <?php
            // Tarkista onko tämä julkaisematon kieliversio jonka ryhmässä on jo julkaistuja
            $isUnpublishedTranslation = false;
            if ($flash['state'] === 'draft' && !empty($flash['translation_group_id'])) {
                $stmtGroupPublished = $pdo->prepare("
                    SELECT COUNT(*) FROM sf_flashes
                    WHERE (id = ? OR translation_group_id = ?)
                      AND state = 'published' AND id != ?
                ");
                $gidCheck = (int)$flash['translation_group_id'];
                $stmtGroupPublished->execute([$gidCheck, $gidCheck, $flash['id']]);
                $isUnpublishedTranslation = (int)$stmtGroupPublished->fetchColumn() > 0;
            }
            ?>

            <?php if ($isUnpublishedTranslation && ($isAdmin || $isSafety || $isComms)): ?>
            <div class="sf-card sf-publish-single-card">
                <h4>
                    <?= sf_lang_flag($flash['lang']) ?>
                    <?= htmlspecialchars(sf_term('publish_language_version', $currentUiLang) ?? 'Julkaise kieliversio', ENT_QUOTES, 'UTF-8') ?>
                </h4>
                <p class="sf-help-text">
                    <?= htmlspecialchars(sf_term('publish_single_description', $currentUiLang) ?? 'Tämä kieliversio on luonnos. Julkaise se erikseen omille infonäytöilleen.', ENT_QUOTES, 'UTF-8') ?>
                </p>
                <button type="button"
                        class="sf-btn sf-btn-primary"
                        id="btnPublishSingleLang"
                        data-flash-id="<?= (int)$flash['id'] ?>"
                        data-flash-lang="<?= htmlspecialchars($flash['lang'], ENT_QUOTES, 'UTF-8') ?>"
                        onclick="openPublishSingleModal()">
                    <?= htmlspecialchars(sf_term('btn_publish_language_version', $currentUiLang) ?? 'Julkaise tämä kieliversio →', ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
            <?php endif; ?>

            <?php if (file_exists(__DIR__ . '/../partials/view_playlist_status.php')): ?>
                <?php require __DIR__ . '/../partials/view_playlist_status.php'; ?>
            <?php endif; ?>

            <div class="view-meta-include">
                <?php require __DIR__ . '/../partials/view_meta_box.php'; ?>
            </div>

            <?php /* view_display_targets.php removed: info is already accessible via the footer "Infonäytöt" button modal */ ?>
            <?php /* if (file_exists(__DIR__ . '/../partials/view_display_targets.php')): ?>
                <?php require __DIR__ . '/../partials/view_display_targets.php'; ?>
            <?php endif; */ ?>
        </div>
    </div> <!-- .view-layout -->

</div> <!-- .view-container -->
</div> <!-- .sf-page-container -->

<!-- Hidden select for body map quick-edit (pre-populated with current body parts) -->
<select id="sfInjuredPartsHidden" multiple class="sf-form-hidden" style="display:none;" aria-hidden="true">
    <?php foreach ($existing_body_parts as $svgId): ?>
        <option value="<?= htmlspecialchars($svgId, ENT_QUOTES, 'UTF-8') ?>" selected><?= htmlspecialchars($svgId, ENT_QUOTES, 'UTF-8') ?></option>
    <?php endforeach; ?>
</select>

<?php
// Body map modal — available for all report types
$uiLang = $currentUiLang;
include __DIR__ . '/../partials/body_map_modal.php';
?>

<!-- ===== MODALIT ===== -->
<div class="sf-modal hidden" id="modalEdit" role="dialog" aria-modal="true" aria-labelledby="modalEditTitle">
    <div class="sf-modal-content">
        <h2 id="modalEditTitle">
            <?= htmlspecialchars(sf_term('modal_edit_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </h2>
        <p>
            <?= htmlspecialchars(sf_term('modal_edit_text', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </p>
        <div class="sf-modal-actions">
            <button
              type="button"
              class="sf-btn sf-btn-secondary"
              data-modal-close="modalEdit"
            >
              <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button
              type="button"
              class="sf-btn sf-btn-primary"
              id="modalEditOk"
            >
              <?= htmlspecialchars(sf_term('btn_ok_edit', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>

<div class="sf-modal hidden" id="modalComment" role="dialog" aria-modal="true" aria-labelledby="modalCommentTitle">
    <div class="sf-modal-content">
        <h2 id="modalCommentTitle">
            <?= htmlspecialchars(sf_term('modal_comment_title', $currentUiLang) ?? 'Lisää kommentti', ENT_QUOTES, 'UTF-8') ?>
        </h2>
        <form method="post" action="<?= htmlspecialchars($base) ?>/app/actions/comment.php?id=<?= (int)$id ?>" id="commentForm">
            <?= sf_csrf_field() ?>
            <input type="hidden" id="editCommentId" name="comment_id" value="">
            <label for="commentMessage">
                <?= htmlspecialchars(sf_term('modal_comment_label', $currentUiLang) ?? 'Kommentti', ENT_QUOTES, 'UTF-8') ?>
            </label>
            <div style="position:relative;">
                <textarea
                  id="commentMessage"
                  name="message"
                  rows="4"
                  maxlength="2000"
                  placeholder="<?= htmlspecialchars(sf_term('modal_comment_placeholder', $currentUiLang) ?? '', ENT_QUOTES, 'UTF-8') ?>"
                  autocomplete="off"
                ></textarea>
                <div id="mentionDropdown" class="sf-mention-dropdown" style="display:none;" role="listbox" aria-label="User suggestions"></div>
            </div>
            <div id="mentionedUsersContainer"></div>

<div id="commentNotifyWrap" style="margin-top:14px;">
    <input type="hidden" name="comment_notifications_enabled" value="0">
    <label for="commentNotificationsEnabled" style="display:flex;align-items:center;gap:10px;font-weight:600;cursor:pointer;">
        <input
            type="checkbox"
            id="commentNotificationsEnabled"
            name="comment_notifications_enabled"
            value="1"
            <?= !empty($commentNotificationsChecked) ? 'checked' : '' ?>
        >
        <?= htmlspecialchars(sf_term('comment_email_subscribe', $_SESSION['ui_lang'] ?? 'fi'), ENT_QUOTES, 'UTF-8') ?>
    </label>
</div>

            <div class="sf-modal-actions">
                <button
                  type="button"
                  class="sf-btn sf-btn-secondary"
                  data-modal-close="modalComment"
                >
                  <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
                <button type="submit" class="sf-btn sf-btn-primary">
                  <?= htmlspecialchars(sf_term('btn_comment_send', $currentUiLang) ?? 'Tallenna kommentti', ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </form>
    </div>
</div>

<?php if ($canAccessSettings): ?>

<div class="sf-modal hidden" id="modalMergeFlash" role="dialog" aria-modal="true" aria-labelledby="modalMergeFlashTitle">
    <div class="sf-modal-content sf-modal-merge-flash">
        <h2 id="modalMergeFlashTitle">
            <?= htmlspecialchars(sf_term('modal_merge_flash_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </h2>

        <p style="margin-bottom: 1rem; color: #4b5563; line-height: 1.5;">
            <?= htmlspecialchars(sf_term('modal_merge_flash_text', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </p>

        <div style="margin-bottom: 1rem;">
            <input
                type="text"
                id="sfMergeSearchInput"
                class="sf-input"
                placeholder="<?= htmlspecialchars(sf_term('modal_merge_flash_search_placeholder', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                style="width: 100%;"
            >
        </div>

        <div id="sfMergeFlashStatus" style="margin-bottom: 0.75rem; color: #6b7280; font-size: 0.95rem;"></div>

        <div
            id="sfMergeCandidateList"
            style="display: flex; flex-direction: column; gap: 0.75rem; max-height: 380px; overflow-y: auto; margin-bottom: 1rem;"
        ></div>

        <div
            id="sfMergeConfirmBox"
            style="display: none; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 12px; padding: 1rem; margin-bottom: 1rem;"
        >
            <p style="margin: 0; color: #374151; line-height: 1.5;">
                <?= htmlspecialchars(sf_term('modal_merge_flash_confirm_text', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </p>
        </div>

        <div class="sf-modal-actions">
            <button type="button" class="sf-btn sf-btn-secondary" data-modal-close="modalMergeFlash">
                <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" class="sf-btn sf-btn-primary" id="sfMergeConfirmBtn" disabled>
                <?= htmlspecialchars(sf_term('btn_merge_flash', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>

<div class="sf-modal hidden" id="sfAdditionalInfoModal" role="dialog" aria-modal="true" aria-labelledby="sfAdditionalInfoModalTitle">
    <div class="sf-modal-content">
        <h2 id="sfAdditionalInfoModalTitle">
            <?= htmlspecialchars(sf_term('additional_info_modal_add_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </h2>
        <form id="sfAdditionalInfoForm">
            <input type="hidden" id="sfAdditionalInfoEditId" value="">
            <label for="sfAdditionalInfoEditor">
                <?= htmlspecialchars(sf_term('additional_info_placeholder', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <div id="sfAdditionalInfoEditor" style="min-height: 140px; background: #fff;" role="textbox" aria-multiline="true" aria-label="<?= htmlspecialchars(sf_term('additional_info_placeholder', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"></div>
            <span id="sfAdditionalInfoStatus" style="display:block; font-size: 0.875rem; min-height: 1.2em;" aria-live="polite"></span>
            <div class="sf-modal-actions">
                <button type="button" class="sf-btn sf-btn-secondary" data-modal-close="sfAdditionalInfoModal">
                    <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
                <button type="submit" class="sf-btn sf-btn-primary" id="sfAdditionalInfoSubmitBtn">
                    <?= htmlspecialchars(sf_term('btn_save', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

<div class="sf-modal hidden" id="modalRequestInfo" role="dialog" aria-modal="true" aria-labelledby="modalRequestInfoTitle">
    <div class="sf-modal-content">
        <h2 id="modalRequestInfoTitle">
            <?= htmlspecialchars(sf_term('modal_request_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </h2>
        <form method="post" action="<?= htmlspecialchars($base) ?>/app/actions/request_info.php?id=<?= (int)$id ?>">
            <?= sf_csrf_field() ?>
            <label for="reqMessage">
                <?= htmlspecialchars(sf_term('modal_request_label', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <textarea
              id="reqMessage"
              name="message"
              rows="4"
              placeholder="<?= htmlspecialchars(sf_term('modal_request_placeholder', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
            ></textarea>
            <div class="sf-modal-actions">
                <button
                  type="button"
                  class="sf-btn sf-btn-secondary"
                  data-modal-close="modalRequestInfo"
                >
                  <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
                <button type="submit" class="sf-btn sf-btn-primary">
                  <?= htmlspecialchars(sf_term('btn_send_request', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Send to Communications (Multi-step) -->
<div class="sf-modal hidden" id="modalToComms" role="dialog" aria-modal="true" aria-labelledby="modalToCommsTitle">
    <div class="sf-modal-content sf-modal-comms">
        
        <form id="commsForm">
        <?= sf_csrf_field() ?>

        <!-- STEP 1: Language Versions -->
        <div class="sf-comms-step" id="commsStep1">
            <h2 id="modalToCommsTitle">
                <?= htmlspecialchars(sf_term('modal_to_comms_title', $currentUiLang) ?? 'Lähetä viestintään', ENT_QUOTES, 'UTF-8') ?>
            </h2>
            
            <div class="sf-step-indicator">
                <span class="sf-step active">1</span>
                <span class="sf-step-line"></span>
                <span class="sf-step">2</span>
                <span class="sf-step-line"></span>
                <span class="sf-step">3</span>
                <span class="sf-step-line"></span>
                <span class="sf-step">4</span>
            </div>

                <div class="sf-field">
                    <label class="sf-label">
                        <?= htmlspecialchars(sf_term('comms_step1_languages', $currentUiLang) ?? 'Valitse kieliversiot', ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <p class="sf-help-text">
                        <?= htmlspecialchars(sf_term('comms_step1_languages_help', $currentUiLang) ?? 'Valitse mitkä kieliversiot lähetetään viestintään', ENT_QUOTES, 'UTF-8') ?>
                    </p>
                    
                    <div class="sf-language-chips">
                        <?php foreach ($supportedLangs as $langCode => $langData): 
                            $isDefault = ($langCode === 'fi');
                        ?>
                            <label class="sf-chip-toggle <?= $isDefault ? 'selected' : '' ?>">
                                <input type="checkbox" 
                                       name="languages[]" 
                                       value="<?= htmlspecialchars($langCode) ?>"
                                       <?= $isDefault ? 'checked' : '' ?>>
                                <img src="<?= htmlspecialchars($base) ?>/assets/img/<?= htmlspecialchars($langData['icon']) ?>" 
                                     alt="<?= htmlspecialchars($langData['label']) ?>"
                                     class="lang-flag-img"
                                     style="width: 26px; height: 26px; border-radius: 50%; object-fit: cover;">
                                <span><?= htmlspecialchars($langData['label']) ?></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </div>

            <div class="sf-modal-actions">
                <button type="button" class="sf-btn sf-btn-secondary" data-modal-close="modalToComms">
                    <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang) ?? 'Peruuta', ENT_QUOTES, 'UTF-8') ?>
                </button>
                <button type="button" class="sf-btn sf-btn-primary" id="btnCommsStep1Next">
                    <?= htmlspecialchars(sf_term('btn_next', $currentUiLang) ?? 'Seuraava', ENT_QUOTES, 'UTF-8') ?> →
                </button>
            </div>
        </div>

        <!-- STEP 2: Xibo Screens -->
        <div class="sf-comms-step hidden" id="commsStep2">
            <div class="sf-comms-header-row">
                <h2><?= htmlspecialchars(sf_term('modal_to_comms_title', $currentUiLang) ?? 'Lähetä viestintään', ENT_QUOTES, 'UTF-8') ?></h2>
                <div class="sf-step-indicator sf-step-indicator-inline">
                    <span class="sf-step done">✓</span>
                    <span class="sf-step-line done"></span>
                    <span class="sf-step active">2</span>
                    <span class="sf-step-line"></span>
                    <span class="sf-step">3</span>
                    <span class="sf-step-line"></span>
                    <span class="sf-step">4</span>
                </div>
            </div>

            <div class="sf-field">
                <label class="sf-label"><?= htmlspecialchars(sf_term('comms_step2_screens', $currentUiLang) ?? 'Xibo-näytöt', ENT_QUOTES, 'UTF-8') ?></label>
                <p class="sf-help-text"><?= htmlspecialchars(sf_term('comms_step2_screens_help', $currentUiLang) ?? 'Valitse työmaanäytöt jossa SafetyFlash esitetään', ENT_QUOTES, 'UTF-8') ?></p>

                <!-- Display target selector -->
                <input type="hidden" name="screens_option" value="selected">
                <div id="commsScreensSelection">
                    <?php
                    $commsOriginalFlash = $flash;
                    $stmtCommsVersions = $pdo->prepare("
                        SELECT id, lang, title FROM sf_flashes
                        WHERE id = :gid OR translation_group_id = :gid2
                        ORDER BY FIELD(lang, 'fi', 'sv', 'en', 'it', 'el')
                    ");
                    $stmtCommsVersions->execute([':gid' => $translationGroupId, ':gid2' => $translationGroupId]);
                    $commsLangVersions = $stmtCommsVersions->fetchAll(PDO::FETCH_ASSOC);
                    foreach ($commsLangVersions as $commsVer):
                        $flash = $commsVer;
                        $context = 'safety_team';
                        unset($preselectedIds);
                        ?>
                        <div style="margin-top: 24px; margin-bottom: 12px; font-weight: 600; display: flex; align-items: center; gap: 8px; padding-bottom: 8px; border-bottom: 1px solid #e5e7eb;">
                            <?php
                            $langData = $supportedLangs[$commsVer['lang']] ?? null;
                            if ($langData): ?>
                                <img src="<?= htmlspecialchars($base) ?>/assets/img/<?= htmlspecialchars($langData['icon']) ?>" alt="<?= htmlspecialchars($langData['label']) ?>" style="width: 24px; height: 24px; border-radius: 50%; object-fit: cover;">
                                <span><?= htmlspecialchars($langData['label']) ?> (<?= htmlspecialchars($commsVer['title']) ?>)</span>
                            <?php else: ?>
                                <span><?= htmlspecialchars(strtoupper($commsVer['lang'])) ?> (<?= htmlspecialchars($commsVer['title']) ?>)</span>
                            <?php endif; ?>
                        </div>
                        <?php
                        require __DIR__ . '/../partials/display_target_selector.php';
                    endforeach;
                    $flash = $commsOriginalFlash;
                    ?>
                </div>
            </div>

            <div class="sf-modal-actions">
                <button type="button" class="sf-btn sf-btn-secondary" id="btnCommsStep2Back">← <?= htmlspecialchars(sf_term('btn_back', $currentUiLang) ?? 'Takaisin', ENT_QUOTES, 'UTF-8') ?></button>
                <button type="button" class="sf-btn sf-btn-primary" id="btnCommsStep2Next"><?= htmlspecialchars(sf_term('btn_next', $currentUiLang) ?? 'Seuraava', ENT_QUOTES, 'UTF-8') ?> →</button>
            </div>
        </div>

        <!-- STEP 3: Distribution (Simplified) -->
        <div class="sf-comms-step hidden" id="commsStep3">
            <h2><?= htmlspecialchars(sf_term('modal_to_comms_title', $currentUiLang) ?? 'Lähetä viestintään', ENT_QUOTES, 'UTF-8') ?></h2>
            
            <div class="sf-step-indicator">
                <span class="sf-step done">✓</span>
                <span class="sf-step-line done"></span>
                <span class="sf-step done">✓</span>
                <span class="sf-step-line done"></span>
                <span class="sf-step active">3</span>
                <span class="sf-step-line"></span>
                <span class="sf-step">4</span>
            </div>

            <div class="sf-field">
                <label class="sf-label">
                    <?= htmlspecialchars(sf_term('comms_step3_wider_distribution', $currentUiLang) ?? 'Laajempi jakelu', ENT_QUOTES, 'UTF-8') ?>
                </label>
                <p class="sf-help-text">
                    <?= htmlspecialchars(sf_term('comms_step3_wider_distribution_help', $currentUiLang) ?? 'Lähetä SafetyFlash myös laajemmalle jakelulistalle', ENT_QUOTES, 'UTF-8') ?>
                </p>
                
                <div class="sf-toggle-card">
                    <div class="sf-toggle-card-content">
                        <div class="sf-toggle-icon">
                            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <path d="M21.21 15.89A10 10 0 1 1 8 2.83"/>
                                <path d="M22 12A10 10 0 0 0 12 2v10z"/>
                            </svg>
                        </div>
                        <div class="sf-toggle-text">
                            <strong><?= htmlspecialchars(sf_term('comms_wider_distribution_label', $currentUiLang) ?? 'Lähetä laajempaan jakeluun', ENT_QUOTES, 'UTF-8') ?></strong>
                            <small id="widerDistributionLabel"><?= htmlspecialchars(sf_term('comms_wider_distribution_no', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></small>
                        </div>
                    </div>
                    <label class="sf-modern-toggle">
                        <input type="checkbox" name="wider_distribution" id="widerDistribution" value="1">
                        <span class="sf-modern-toggle-track">
                            <span class="sf-modern-toggle-thumb"></span>
                        </span>
                    </label>
                </div>
            </div>

            <div class="sf-modal-actions">
                <button type="button" class="sf-btn sf-btn-secondary" id="btnCommsStep3Back">
                    ← <?= htmlspecialchars(sf_term('btn_back', $currentUiLang) ?? 'Takaisin', ENT_QUOTES, 'UTF-8') ?>
                </button>
                <button type="button" class="sf-btn sf-btn-primary" id="btnCommsStep3Next">
                    <?= htmlspecialchars(sf_term('btn_next', $currentUiLang) ?? 'Seuraava', ENT_QUOTES, 'UTF-8') ?> →
                </button>
            </div>
        </div>

        <!-- STEP 4: Summary & Message -->
        <div class="sf-comms-step hidden" id="commsStep4">
            <h2><?= htmlspecialchars(sf_term('modal_to_comms_title', $currentUiLang) ?? 'Lähetä viestintään', ENT_QUOTES, 'UTF-8') ?></h2>
            
            <div class="sf-step-indicator">
                <span class="sf-step done">✓</span>
                <span class="sf-step-line done"></span>
                <span class="sf-step done">✓</span>
                <span class="sf-step-line done"></span>
                <span class="sf-step done">✓</span>
                <span class="sf-step-line done"></span>
                <span class="sf-step active">4</span>
            </div>

            <div class="sf-comms-summary">
                <h3><?= htmlspecialchars(sf_term('comms_summary_title', $currentUiLang) ?? 'Yhteenveto', ENT_QUOTES, 'UTF-8') ?></h3>
                
                <div class="sf-summary-item">
                    <img src="<?= htmlspecialchars($base) ?>/assets/img/icons/globe.svg" alt="" class="sf-summary-icon">
                    <strong><?= htmlspecialchars(sf_term('comms_summary_languages', $currentUiLang) ?? 'Kieliversiot', ENT_QUOTES, 'UTF-8') ?></strong>
                    <span id="commsSummaryLanguages">-</span>
                </div>
                
                <div class="sf-summary-item">
                    <img src="<?= htmlspecialchars($base) ?>/assets/img/icons/screen.svg" alt="" class="sf-summary-icon">
                    <strong><?= htmlspecialchars(sf_term('comms_summary_screens', $currentUiLang) ?? 'Xibo-näytöt', ENT_QUOTES, 'UTF-8') ?></strong>
                    <span id="commsSummaryScreens">-</span>
                </div>
                
                <div class="sf-summary-item">
                    <img src="<?= htmlspecialchars($base) ?>/assets/img/icons/megaphone.svg" alt="" class="sf-summary-icon">
                    <strong><?= htmlspecialchars(sf_term('comms_summary_distribution', $currentUiLang) ?? 'Jakelu', ENT_QUOTES, 'UTF-8') ?></strong>
                    <span id="commsSummaryDistribution">-</span>
                </div>
            </div>

            <div class="sf-field" style="margin-top: 1.5rem;">
                <label for="commsMessage" class="sf-label">
                    <img src="<?= htmlspecialchars($base) ?>/assets/img/icons/comment.svg" alt="" style="width: 16px; height: 16px; opacity: 0.7; margin-right: 4px; vertical-align: middle;">
                    <?= htmlspecialchars(sf_term('modal_to_comms_label', $currentUiLang) ?? 'Viesti viestintään (valinnainen)', ENT_QUOTES, 'UTF-8') ?>
                </label>
                <textarea
                  id="commsMessage"
                  name="message"
                  rows="4"
                  class="sf-textarea"
                  placeholder="<?= htmlspecialchars(sf_term('modal_to_comms_placeholder', $currentUiLang) ?? 'Lisätiedot viestintätiimille...', ENT_QUOTES, 'UTF-8') ?>"
                ></textarea>
            </div>

            <div class="sf-modal-actions">
                <button type="button" class="sf-btn sf-btn-secondary" id="btnCommsStep4Back">
                    ← <?= htmlspecialchars(sf_term('btn_back', $currentUiLang) ?? 'Takaisin', ENT_QUOTES, 'UTF-8') ?>
                </button>
                <button type="submit" class="sf-btn sf-btn-primary" id="btnCommsSend">
                    <?= htmlspecialchars(sf_term('btn_send_comms', $currentUiLang) ?? 'Lähetä viestintään', ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </div>

        </form>
    </div>
</div>

<div class="sf-modal hidden" id="modalPublishDirect" role="dialog" aria-modal="true" aria-labelledby="modalPublishDirectTitle">
    <div class="sf-modal-content">
        <h2 id="modalPublishDirectTitle">
            <?= htmlspecialchars(sf_term('modal_publish_direct_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </h2>

        <form method="post" action="<?= htmlspecialchars($base) ?>/app/actions/publish_direct.php?id=<?= (int)$id ?>">
            <?= sf_csrf_field() ?>

            <p>
                <?= htmlspecialchars(sf_term('modal_publish_direct_text', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </p>

            <label for="publishDirectMessage">
                <?= htmlspecialchars(sf_term('modal_publish_direct_label', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </label>

            <textarea
                id="publishDirectMessage"
                name="message"
                rows="5"
                required
                placeholder="<?= htmlspecialchars(sf_term('modal_publish_direct_placeholder', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
            ></textarea>

            <p class="sf-help-text">
                <?= htmlspecialchars(sf_term('modal_publish_direct_help', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </p>

            <div class="sf-modal-actions">
                <button
                    type="button"
                    class="sf-btn sf-btn-secondary"
                    data-modal-close="modalPublishDirect"
                >
                    <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>

                <button type="submit" class="sf-btn sf-btn-primary">
                    <?= htmlspecialchars(sf_term('btn_publish_direct', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Send to Safety Team (from Supervisor) -->
<div class="sf-modal hidden" id="modalSendSafety" role="dialog" aria-modal="true" aria-labelledby="modalSendSafetyTitle">
    <div class="sf-modal-content">
        <h2 id="modalSendSafetyTitle">
            <?= htmlspecialchars(sf_term('modal_send_safety_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </h2>
        <form method="post" action="<?= htmlspecialchars($base) ?>/app/actions/supervisor_to_safety.php">
            <?= sf_csrf_field() ?>
            <input type="hidden" name="flash_id" value="<?= (int)$id ?>">
            <label for="safetyMessage">
                <?= htmlspecialchars(sf_term('modal_send_safety_message_label', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </label>
            <textarea
              id="safetyMessage"
              name="message"
              rows="4"
              placeholder="<?= htmlspecialchars(sf_term('modal_send_safety_placeholder', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
            ></textarea>
            <div class="sf-modal-actions">
                <button
                  type="button"
                  class="sf-btn sf-btn-secondary"
                  data-modal-close="modalSendSafety"
                >
                  <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
                <button type="submit" class="sf-btn sf-btn-primary">
                  <?= htmlspecialchars(sf_term('footer_send_to_safety', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </form>
    </div>
</div>

<div class="sf-modal hidden" id="modalPublish" role="dialog" aria-modal="true" aria-labelledby="modalPublishTitle">
    <div class="sf-modal-content sf-modal-publish-stepper">

        <!-- Stepper-indikaattori -->
        <div class="sf-step-indicator sf-publish-step-indicator">
            <span class="sf-step active" id="publishStepDot1" title="<?= htmlspecialchars(sf_term('publish_step1_title', $currentUiLang) ?? 'Perustiedot', ENT_QUOTES, 'UTF-8') ?>">1</span>
            <span class="sf-step-line" id="publishStepLine1"></span>
            <span class="sf-step" id="publishStepDot2" title="<?= htmlspecialchars(sf_term('publish_step2_title', $currentUiLang) ?? 'Infonäytöt', ENT_QUOTES, 'UTF-8') ?>">2</span>
            <span class="sf-step-line" id="publishStepLine2"></span>
            <span class="sf-step" id="publishStepDot3" title="<?= htmlspecialchars(sf_term('publish_step3_title', $currentUiLang) ?? 'Aika-asetukset', ENT_QUOTES, 'UTF-8') ?>">3</span>
            <span class="sf-step-line" id="publishStepLine3"></span>
            <span class="sf-step" id="publishStepDot4" title="<?= htmlspecialchars(sf_term('publish_step4_title', $currentUiLang) ?? 'Vahvista', ENT_QUOTES, 'UTF-8') ?>">4</span>
        </div>

        <!-- Julkaisuvaihtoehdot — yhteinen form kaikille askelille -->
        <form id="publishForm" method="POST" action="<?= htmlspecialchars($base) ?>/app/actions/publish.php?id=<?= (int)$id ?>">
            <?= sf_csrf_field() ?>

            <!-- ===== VAIHE 1: Perustiedot ===== -->
            <div class="sf-publish-step" id="publishStep1">
                <h2 id="modalPublishTitle">
                    <?= htmlspecialchars(sf_term('modal_publish_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    <small class="sf-publish-step-label"><?= htmlspecialchars(sf_term('publish_step1_title', $currentUiLang) ?? 'Perustiedot', ENT_QUOTES, 'UTF-8') ?></small>
                </h2>
                <p><?= htmlspecialchars(sf_term('modal_publish_text', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></p>

                <div class="sf-publish-options">
                    <!-- Lähetä jakeluryhmälle -->
                    <label class="sf-checkbox-option">
                        <input type="checkbox" name="send_to_distribution" id="publishSendDistribution" value="1">
                        <span class="sf-checkbox-label">
                            <strong><?= htmlspecialchars(sf_term('publish_send_to_distribution', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></strong>
                            <small><?= htmlspecialchars(sf_term('publish_send_to_distribution_hint', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></small>
                        </span>
                    </label>

                    <!-- Maakohtainen jakelu -->
                    <div class="sf-country-selection" id="countrySelectionDiv" style="display:none;">
                        <label class="sf-label"><?= htmlspecialchars(sf_term('publish_select_countries', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></label>
                        <div class="sf-country-flags">
                            <?php
                            $distributionCountries = [
                                'fi' => ['label_key' => 'country_finland', 'icon' => 'finnish-flag.png', 'default' => true],
                                'sv' => ['label_key' => 'country_sweden', 'icon' => 'swedish-flag.png', 'default' => false],
                                'en' => ['label_key' => 'country_uk', 'icon' => 'english-flag.png', 'default' => false],
                                'it' => ['label_key' => 'country_italy', 'icon' => 'italian-flag.png', 'default' => false],
                                'el' => ['label_key' => 'country_greece', 'icon' => 'greece-flag.png', 'default' => false],
                            ];
                            foreach ($distributionCountries as $countryCode => $countryData):
                            ?>
                                <label class="sf-flag-chip">
                                    <input type="checkbox"
                                           name="distribution_countries[]"
                                           value="<?= htmlspecialchars($countryCode) ?>"
                                           <?= $countryData['default'] ? 'checked' : '' ?>>
                                    <img src="<?= htmlspecialchars($base) ?>/assets/img/<?= htmlspecialchars($countryData['icon']) ?>"
                                         alt="<?= htmlspecialchars(sf_term($countryData['label_key'], $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- Henkilövahinkoja - VAIN punaisille -->
                    <?php if (($flash['type'] ?? '') === 'red'): ?>
                    <label class="sf-checkbox-option sf-checkbox-warning">
                        <input type="checkbox" name="has_personal_injury" id="publishPersonalInjury" value="1">
                        <span class="sf-checkbox-label">
                            <strong>⚠️ <?= htmlspecialchars(sf_term('publish_personal_injury', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></strong>
                            <small><?= htmlspecialchars(sf_term('publish_personal_injury_hint', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></small>
                        </span>
                    </label>
                    <?php endif; ?>

                    <!-- Otsikon esikatselu -->
                    <div class="sf-email-subject-preview" id="emailSubjectPreview" style="display:none;">
                        <strong><?= htmlspecialchars(sf_term('publish_subject_preview', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>:</strong>
                        <code id="emailSubjectText"></code>
                    </div>
                </div>

                <div class="sf-modal-actions">
                    <button type="button" class="sf-btn sf-btn-secondary" data-modal-close="modalPublish">
                        <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                    <button type="button" class="sf-btn sf-btn-primary" id="btnPublishStep1Next">
                        <?= htmlspecialchars(sf_term('btn_next', $currentUiLang) ?? 'Seuraava', ENT_QUOTES, 'UTF-8') ?> →
                    </button>
                </div>
            </div>

            <!-- ===== VAIHE 2: Työmaan infonäytöt ===== -->
            <div class="sf-publish-step hidden" id="publishStep2">
                <h2><?= htmlspecialchars(sf_term('publish_step2_title', $currentUiLang) ?? 'Työmaan infonäytöt', ENT_QUOTES, 'UTF-8') ?></h2>

                <!-- Infonäyttövalinnat per kieliversio -->
                <?php
                $originalFlash = $flash;
                $stmtLangVersions = $pdo->prepare("
                    SELECT id, lang, title FROM sf_flashes
                    WHERE id = :gid OR translation_group_id = :gid2
                    ORDER BY FIELD(lang, 'fi', 'sv', 'en', 'it', 'el')
                ");
                $stmtLangVersions->execute([':gid' => $translationGroupId, ':gid2' => $translationGroupId]);
                $langVersions = $stmtLangVersions->fetchAll(PDO::FETCH_ASSOC);
                ?>
                <?php if (!empty($langVersions)): ?>
                    <div class="sf-display-targets-section">
                        <?php foreach ($langVersions as $ver): ?>
                            <div class="sf-lang-display-section">
                                <?php
                                    $flash = $ver;
                                    $context = 'publish';
                                    require __DIR__ . '/../partials/display_target_selector.php';
                                ?>
                            </div>
                        <?php endforeach; ?>
                        <?php $flash = $originalFlash; ?>
                    </div>
                <?php endif; ?>

                <div class="sf-modal-actions">
                    <button type="button" class="sf-btn sf-btn-secondary" id="btnPublishStep2Back">
                        ← <?= htmlspecialchars(sf_term('btn_back', $currentUiLang) ?? 'Takaisin', ENT_QUOTES, 'UTF-8') ?>
                    </button>
                    <button type="button" class="sf-btn sf-btn-primary" id="btnPublishStep2Next">
                        <?= htmlspecialchars(sf_term('btn_next', $currentUiLang) ?? 'Seuraava', ENT_QUOTES, 'UTF-8') ?> →
                    </button>
                </div>
            </div>

            <!-- ===== VAIHE 3: Aika-asetukset ===== -->
            <div class="sf-publish-step hidden" id="publishStep3">
                <h2><?= htmlspecialchars(sf_term('publish_step3_title', $currentUiLang) ?? 'Aika-asetukset', ENT_QUOTES, 'UTF-8') ?></h2>

                <!-- Näkyvyysaika infonäytöillä -->
                <?php require __DIR__ . '/../partials/publish_display_ttl.php'; ?>

                <!-- Näyttökesto per kuva -->
                <?php require __DIR__ . '/../partials/publish_display_duration.php'; ?>

                <div class="sf-modal-actions">
                    <button type="button" class="sf-btn sf-btn-secondary" id="btnPublishStep3Back">
                        ← <?= htmlspecialchars(sf_term('btn_back', $currentUiLang) ?? 'Takaisin', ENT_QUOTES, 'UTF-8') ?>
                    </button>
                    <button type="button" class="sf-btn sf-btn-primary" id="btnPublishStep3Next">
                        <?= htmlspecialchars(sf_term('btn_next', $currentUiLang) ?? 'Seuraava', ENT_QUOTES, 'UTF-8') ?> →
                    </button>
                </div>
            </div>

            <!-- ===== VAIHE 4: Vahvistus ===== -->
            <div class="sf-publish-step hidden" id="publishStep4">
                <h2><?= htmlspecialchars(sf_term('publish_step4_title', $currentUiLang) ?? 'Vahvista julkaisu', ENT_QUOTES, 'UTF-8') ?></h2>

                <div class="sf-publish-summary" id="publishSummary">
                    <dl class="sf-summary-list">
                        <dt><?= htmlspecialchars(sf_term('publish_summary_distribution', $currentUiLang) ?? 'Jakeluryhmä', ENT_QUOTES, 'UTF-8') ?></dt>
                        <dd id="summaryDistribution">—</dd>
                        <dt><?= htmlspecialchars(sf_term('publish_summary_displays', $currentUiLang) ?? 'Infonäytöt', ENT_QUOTES, 'UTF-8') ?></dt>
                        <dd id="summaryDisplays">—</dd>
                        <dt><?= htmlspecialchars(sf_term('publish_summary_ttl', $currentUiLang) ?? 'Näkyvyysaika', ENT_QUOTES, 'UTF-8') ?></dt>
                        <dd id="summaryTtl">—</dd>
                        <dt><?= htmlspecialchars(sf_term('publish_summary_duration', $currentUiLang) ?? 'Näyttökesto', ENT_QUOTES, 'UTF-8') ?></dt>
                        <dd id="summaryDuration">—</dd>
                    </dl>
                </div>

                <div class="sf-modal-actions">
                    <button type="button" class="sf-btn sf-btn-secondary" id="btnPublishStep4Back">
                        ← <?= htmlspecialchars(sf_term('btn_back', $currentUiLang) ?? 'Takaisin', ENT_QUOTES, 'UTF-8') ?>
                    </button>
                    <button type="submit" class="sf-btn sf-btn-primary">
                        <?= htmlspecialchars(sf_term('btn_publish', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </div>
            </div>

        </form>
    </div>
</div>

<!-- Publish Single Language Version Modal -->
<div id="publishSingleModal" class="sf-modal hidden" role="dialog" aria-modal="true" aria-labelledby="publishSingleModalTitle">
    <div class="sf-modal-content">
        <div class="sf-modal-header">
            <h3 id="publishSingleModalTitle">
                <?= sf_lang_flag($flash['lang']) ?>
                <?= htmlspecialchars(sf_term('publish_language_version', $currentUiLang) ?? 'Julkaise kieliversio', ENT_QUOTES, 'UTF-8') ?>
                — <?= htmlspecialchars(strtoupper($flash['lang']), ENT_QUOTES, 'UTF-8') ?>
            </h3>
            <button type="button" class="sf-modal-close-btn" onclick="closePublishSingleModal()">&times;</button>
        </div>

        <form method="post" action="<?= htmlspecialchars($base) ?>/app/actions/publish.php?id=<?= (int)$flash['id'] ?>">
            <?= sf_csrf_field() ?>
            <input type="hidden" name="publish_mode" value="single">

            <div class="sf-modal-body">
                <!-- TTL valitsin -->
                <?php require __DIR__ . '/../partials/publish_display_ttl.php'; ?>

                <!-- Kesto valitsin -->
                <?php require __DIR__ . '/../partials/publish_display_duration.php'; ?>

                <!-- Näyttövalitsin — kaikki aktiiviset näytöt -->
                <div class="sf-lang-display-section">
                    <?php
                        $context = 'publish';
                        require __DIR__ . '/../partials/display_target_selector.php';
                    ?>
                </div>

                <!-- Henkilövahinko -->
                <?php if (($flash['type'] ?? '') === 'red'): ?>
                <div class="sf-checkbox-option sf-checkbox-warning" style="margin-top:1rem;">
                    <label>
                        <input type="checkbox" name="has_personal_injury" value="1"
                            <?= !empty($flash['has_personal_injury']) ? 'checked' : '' ?>>
                        ⚠️ <?= htmlspecialchars(sf_term('publish_personal_injury', $currentUiLang) ?? 'Henkilövahinko', ENT_QUOTES, 'UTF-8') ?>
                    </label>
                </div>
                <?php endif; ?>
            </div>

            <div class="sf-modal-footer">
                <button type="button" class="sf-btn sf-btn-secondary" onclick="closePublishSingleModal()">
                    <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang) ?? 'Peruuta', ENT_QUOTES, 'UTF-8') ?>
                </button>
                <button type="submit" class="sf-btn sf-btn-primary">
                    <?= sf_lang_flag($flash['lang']) ?>
                    <?= htmlspecialchars(sf_term('btn_publish_language_version', $currentUiLang) ?? 'Julkaise kieliversio', ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- JavaScript otsikon esikatseluun -->
<script>
(function() {
    const distributionCheckbox = document.getElementById('publishSendDistribution');
    const injuryCheckbox = document.getElementById('publishPersonalInjury');
    const previewDiv = document.getElementById('emailSubjectPreview');
    const subjectText = document.getElementById('emailSubjectText');
    const countryDiv = document.getElementById('countrySelectionDiv');
    
    if (!distributionCheckbox || !previewDiv) return;
    
    const flashType = <?= json_encode($flash['type'] ?? 'yellow', JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const flashTitle = <?= json_encode($flash['title'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    const flashSite = <?= json_encode($flash['worksite'] ?? $flash['site'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    
    const typeLabels = {
        red: '🔴 <?= addslashes(sf_term('email_type_red', $currentUiLang)) ?>',
        yellow: '🟡 <?= addslashes(sf_term('email_type_yellow', $currentUiLang)) ?>',
        green: '🟢 <?= addslashes(sf_term('email_type_green', $currentUiLang)) ?>'
    };
    
    const injuryPrefix = '⚠️ <?= addslashes(sf_term('email_personal_injury_warning', $currentUiLang)) ?>';
    
    function updatePreview() {
        const showPreview = distributionCheckbox.checked;
        previewDiv.style.display = showPreview ? 'block' : 'none';
        
        // Show/hide country selection
        if (countryDiv) {
            countryDiv.style.display = showPreview ? 'block' : 'none';
        }
        
        if (!showPreview) return;
        
        let parts = [];
        
        // Henkilövahinko-varoitus (vain jos valittu ja punainen)
        if (injuryCheckbox && injuryCheckbox.checked && flashType === 'red') {
            parts.push(injuryPrefix);
        }
        
        // Tyyppi
        parts.push(typeLabels[flashType] || typeLabels.yellow);
        
        // Otsikko
        if (flashTitle) {
            parts.push(flashTitle);
        }
        
        // Työmaa
        if (flashSite) {
            parts.push('(' + flashSite + ')');
        }
        
        subjectText.textContent = parts.join(' - ');
    }
    
    distributionCheckbox.addEventListener('change', updatePreview);
    if (injuryCheckbox) {
        injuryCheckbox.addEventListener('change', updatePreview);
    }
    
    updatePreview();
})();
</script>

<script>
// ===== Publish Modal Stepper =====
(function () {
    'use strict';

    var currentStep = 1;
    var totalSteps = 4;

    function showPublishStep(step) {
        for (var i = 1; i <= totalSteps; i++) {
            var el = document.getElementById('publishStep' + i);
            if (el) {
                if (i === step) {
                    el.classList.remove('hidden');
                } else {
                    el.classList.add('hidden');
                }
            }
            // Update dot states
            var dot = document.getElementById('publishStepDot' + i);
            if (dot) {
                dot.classList.toggle('active', i === step);
                dot.classList.toggle('done', i < step);
            }
            // Update line states
            if (i < totalSteps) {
                var line = document.getElementById('publishStepLine' + i);
                if (line) {
                    line.classList.toggle('done', i < step);
                }
            }
        }
        currentStep = step;
        if (step === 4) {
            updatePublishSummary();
        }
    }

    function updatePublishSummary() {
        // Distribution
        var distCb = document.getElementById('publishSendDistribution');
        var summaryDist = document.getElementById('summaryDistribution');
        if (summaryDist && distCb) {
            summaryDist.textContent = distCb.checked
                ? (window.SF_TERMS && window.SF_TERMS.publish_yes ? window.SF_TERMS.publish_yes : '✅ Kyllä')
                : '—';
        }

        // TTL — read the label text directly from the selected chip
        var ttlInput = document.querySelector('#publishForm input[name="display_ttl_days"]:checked');
        var summaryTtl = document.getElementById('summaryTtl');
        if (summaryTtl) {
            var ttlLabel = ttlInput ? ttlInput.closest('label') : null;
            summaryTtl.textContent = ttlLabel ? ttlLabel.textContent.trim() : (ttlInput ? ttlInput.value : '—');
        }

        // Duration — read the label text directly from the selected chip
        var durInput = document.querySelector('#publishForm input[name="display_duration_seconds"]:checked');
        var summaryDur = document.getElementById('summaryDuration');
        if (summaryDur) {
            var durLabel = durInput ? durInput.closest('label') : null;
            summaryDur.textContent = durLabel ? durLabel.textContent.trim() : (durInput ? durInput.value + 's' : '—');
        }

        // Display targets — use data-label attribute or parent .sf-dt-result-item text
        var selectedDisplays = [];
        document.querySelectorAll('#publishForm .sf-display-chip-input:checked, #publishForm .dt-display-chip-cb:checked').forEach(function (cb) {
            var label = cb.getAttribute('data-label');
            if (!label) {
                var parent = cb.closest('.sf-dt-result-item') || cb.closest('.sf-display-chip');
                if (parent) {
                    label = parent.textContent.trim();
                }
            }
            if (label) {
                selectedDisplays.push(label);
            }
        });
        var summaryDisplays = document.getElementById('summaryDisplays');
        if (summaryDisplays) {
            summaryDisplays.textContent = selectedDisplays.length > 0 ? selectedDisplays.join(', ') : '—';
        }
    }

    function init() {
        var btn1Next = document.getElementById('btnPublishStep1Next');
        var btn2Back = document.getElementById('btnPublishStep2Back');
        var btn2Next = document.getElementById('btnPublishStep2Next');
        var btn3Back = document.getElementById('btnPublishStep3Back');
        var btn3Next = document.getElementById('btnPublishStep3Next');
        var btn4Back = document.getElementById('btnPublishStep4Back');

        if (btn1Next) btn1Next.addEventListener('click', function () { showPublishStep(2); });
        if (btn2Back) btn2Back.addEventListener('click', function () { showPublishStep(1); });
        if (btn2Next) btn2Next.addEventListener('click', function () { showPublishStep(3); });
        if (btn3Back) btn3Back.addEventListener('click', function () { showPublishStep(2); });
        if (btn3Next) btn3Next.addEventListener('click', function () { showPublishStep(4); });
        if (btn4Back) btn4Back.addEventListener('click', function () { showPublishStep(3); });

        // Reset to step 1 when modal opens
        var footerPublish = document.getElementById('footerPublish');
        if (footerPublish) {
            footerPublish.addEventListener('click', function () {
                showPublishStep(1);
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>

<div class="sf-modal hidden" id="modalDelete" role="dialog" aria-modal="true" aria-labelledby="modalDeleteTitle">
    <div class="sf-modal-content">
        <h2 id="modalDeleteTitle">
            <?= htmlspecialchars(sf_term('modal_delete_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </h2>
        <div id="deleteModalContent">
            <!-- Content will be populated by JavaScript -->
            <p>
                <?= htmlspecialchars(sf_term('modal_delete_text', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </p>
        </div>
        <form method="post" action="<?= htmlspecialchars($base) ?>/app/actions/delete.php?id=<?= (int)$id ?>">
            <?= sf_csrf_field() ?>
            <div class="sf-modal-actions">
                <button
                  type="button"
                  class="sf-btn sf-btn-secondary"
                  data-modal-close="modalDelete"
                >
                  <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
                <button type="submit" class="sf-btn sf-btn-danger">
                  <?= htmlspecialchars(sf_term('btn_delete', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Reviewer Modals -->
<div class="sf-modal hidden" id="modalAddReviewer" role="dialog" aria-modal="true" aria-labelledby="modalAddReviewerTitle">
    <div class="sf-modal-content">
        <div class="sf-modal-header">
            <h2 id="modalAddReviewerTitle">
                <?= htmlspecialchars(sf_term('reviewer_add_modal_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </h2>
            <button type="button" class="sf-modal-close-btn" data-modal-close="modalAddReviewer">×</button>
        </div>
        
        <div class="sf-modal-body">
            <div class="sf-field">
                <label for="reviewerSearch" class="sf-label">
                    <?= htmlspecialchars(sf_term('reviewer_select_user', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <div class="sf-search-select">
                    <input type="text" 
                           id="reviewerSearch" 
                           class="sf-search-input"
                           placeholder="<?= htmlspecialchars(sf_term('reviewer_search_placeholder', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                           autocomplete="off">
                    <div class="sf-search-dropdown hidden" id="reviewerSearchDropdown">
                        <!-- Dynamically populated by JavaScript -->
                    </div>
                </div>
                <input type="hidden" id="selectedReviewerId" value="">
                <div id="selectedReviewerDisplay" class="selected-reviewer-display hidden"></div>
            </div>
        </div>
        
        <div class="sf-modal-actions">
            <button type="button" class="sf-btn sf-btn-secondary" data-modal-close="modalAddReviewer">
                <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" id="btnAddReviewer" class="sf-btn sf-btn-primary">
                <?= htmlspecialchars(sf_term('add_reviewer', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>

<div class="sf-modal hidden" id="modalReplaceReviewer" role="dialog" aria-modal="true" aria-labelledby="modalReplaceReviewerTitle">
    <div class="sf-modal-content">
        <div class="sf-modal-header">
            <h2 id="modalReplaceReviewerTitle">
                <?= htmlspecialchars(sf_term('reviewer_replace_modal_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </h2>
            <button type="button" class="sf-modal-close-btn" data-modal-close="modalReplaceReviewer">×</button>
        </div>
        
        <div class="sf-modal-body">
            <div class="sf-field" id="currentReviewersSection">
                <label class="sf-label">
                    <?= htmlspecialchars(sf_term('reviewer_current_reviewers', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <div id="currentReviewersDisplay" class="current-reviewers-display">
                    <!-- Populated by JavaScript -->
                </div>
            </div>
            
            <div class="sf-field">
                <label for="reviewerSearchReplace" class="sf-label">
                    <?= htmlspecialchars(sf_term('reviewer_select_user', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <div class="sf-search-select">
                    <input type="text" 
                           id="reviewerSearchReplace" 
                           class="sf-search-input"
                           placeholder="<?= htmlspecialchars(sf_term('reviewer_search_placeholder', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                           autocomplete="off">
                    <div class="sf-search-dropdown hidden" id="reviewerSearchReplaceDropdown">
                        <!-- Dynamically populated by JavaScript -->
                    </div>
                </div>
                <input type="hidden" id="selectedReviewerIdReplace" value="">
                <div id="selectedReviewerDisplayReplace" class="selected-reviewer-display hidden"></div>
            </div>
        </div>
        
        <div class="sf-modal-actions">
            <button type="button" class="sf-btn sf-btn-secondary" data-modal-close="modalReplaceReviewer">
                <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" id="btnReplaceReviewer" class="sf-btn sf-btn-primary">
                <?= htmlspecialchars(sf_term('replace_reviewer', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>

<script>
// Store flash data for delete modal
window.sfFlashData = {
    id: <?= (int)$id ?>,
    translationGroupId: <?= !empty($flash['translation_group_id']) ? (int)$flash['translation_group_id'] : 'null' ?>,
    lang: '<?= htmlspecialchars($flash['lang'] ?? 'fi', ENT_QUOTES, 'UTF-8') ?>',
    isTranslation: <?= !empty($flash['translation_group_id']) ? 'true' : 'false' ?>,
    uiLang: '<?= htmlspecialchars($currentUiLang, ENT_QUOTES, 'UTF-8') ?>',
    title: '<?= htmlspecialchars($flash['title'] ?? '', ENT_QUOTES, 'UTF-8') ?>',
    type: '<?= htmlspecialchars($flash['type'] ?? '', ENT_QUOTES, 'UTF-8') ?>',
    site: '<?= htmlspecialchars($flash['site'] ?? '', ENT_QUOTES, 'UTF-8') ?>'
};

// Translation terms for JavaScript
window.sfDeleteTerms = {
    delete_original_confirm_title: <?= json_encode(sf_term('delete_original_confirm_title', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    delete_original_confirm_message: <?= json_encode(sf_term('delete_original_confirm_message', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    delete_original_versions_count: <?= json_encode(sf_term('delete_original_versions_count', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    delete_translation_confirm_title: <?= json_encode(sf_term('delete_translation_confirm_title', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    delete_translation_confirm_message: <?= json_encode(sf_term('delete_translation_confirm_message', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    delete_translation_which: <?= json_encode(sf_term('delete_translation_which', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    lang_name_fi: <?= json_encode(sf_term('lang_name_fi', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    lang_name_sv: <?= json_encode(sf_term('lang_name_sv', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    lang_name_en: <?= json_encode(sf_term('lang_name_en', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    lang_name_it: <?= json_encode(sf_term('lang_name_it', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    lang_name_el: <?= json_encode(sf_term('lang_name_el', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>
};

// Update delete modal content when opened
document.addEventListener('DOMContentLoaded', function() {
    const deleteButtons = document.querySelectorAll('[data-modal-open="modalDelete"]');
    
    deleteButtons.forEach(btn => {
        btn.addEventListener('click', function() {
            updateDeleteModalContent();
        });
    });
});

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function getTypeInfo(type) {
    const typeMap = {
        'red': { dot: '🔴', label: 'Ensitiedote' },
        'yellow': { dot: '🟡', label: 'Vaaratilanne' },
        'green': { dot: '🟢', label: 'Tutkintatiedote' }
    };
    return typeMap[type] || { dot: '⚪', label: type };
}

function updateDeleteModalContent() {
    const modalTitle = document.getElementById('modalDeleteTitle');
    const modalContent = document.getElementById('deleteModalContent');
    const flashData = window.sfFlashData;
    const terms = window.sfDeleteTerms;
    
    if (!flashData.isTranslation) {
        // Deleting original - check for translations
        const groupId = flashData.id;
        
        // Fetch translations via AJAX
        const url = new URL('<?= htmlspecialchars($base) ?>/app/api/get_flash_translations.php', window.location.origin);
        url.searchParams.set('group_id', groupId);
        
        fetch(url)
            .then(response => response.json())
            .then(data => {
                if (data.ok && data.translations && Object.keys(data.translations).length > 0) {
                    // Has translations - show warning
                    const count = Object.keys(data.translations).length;
                    const langNames = [];
                    
                    for (const lang in data.translations) {
                        const langKey = 'lang_name_' + lang;
                        if (terms[langKey]) {
                            langNames.push(terms[langKey]);
                        }
                    }
                    
                    modalTitle.textContent = terms.delete_original_confirm_title;
                    
                    const typeInfo = getTypeInfo(flashData.type);
                    const flashDetails = typeInfo.dot + ' ' + typeInfo.label + ' – \'' + escapeHtml(flashData.title) + '\' (' + escapeHtml(flashData.site) + ')';
                    
                    let html = '<p style="margin-bottom: 1rem;"><strong>Poistetaan:</strong> ' + flashDetails + '</p>';
                    html += '<p style="margin-bottom: 0.5rem;">' + terms.delete_original_confirm_message + '</p>';
                    html += '<p style="margin-bottom: 0.5rem;"><strong>' + terms.delete_original_versions_count.replace('%d', count) + '</strong></p>';
                    html += '<ul style="margin-left: 1.5rem;">';
                    langNames.forEach(name => {
                        html += '<li>' + escapeHtml(name) + '</li>';
                    });
                    html += '</ul>';
                    
                    modalContent.innerHTML = html;
                } else {
                    // No translations - use default message
                    modalTitle.textContent = terms.delete_original_confirm_title;
                    
                    const typeInfo = getTypeInfo(flashData.type);
                    const flashDetails = typeInfo.dot + ' ' + typeInfo.label + ' – \'' + escapeHtml(flashData.title) + '\' (' + escapeHtml(flashData.site) + ')';
                    
                    let html = '<p style="margin-bottom: 1rem;"><strong>Poistetaan:</strong> ' + flashDetails + '</p>';
                    html += '<p>' + terms.delete_original_confirm_message + '</p>';
                    
                    modalContent.innerHTML = html;
                }
            })
            .catch(error => {
                console.error('Error fetching translations:', error);
                // Fallback to default message
                modalTitle.textContent = terms.delete_original_confirm_title;
                
                const typeInfo = getTypeInfo(flashData.type);
                const flashDetails = typeInfo.dot + ' ' + typeInfo.label + ' – \'' + escapeHtml(flashData.title) + '\' (' + escapeHtml(flashData.site) + ')';
                
                let html = '<p style="margin-bottom: 1rem;"><strong>Poistetaan:</strong> ' + flashDetails + '</p>';
                html += '<p>' + terms.delete_original_confirm_message + '</p>';
                
                modalContent.innerHTML = html;
            });
    } else {
        // Deleting translation
        const langKey = 'lang_name_' + flashData.lang;
        const langName = terms[langKey] || flashData.lang;
        
        // Get language flag emoji
        const langFlags = {
            'fi': '🇫🇮',
            'sv': '🇸🇪', 
            'en': '🇬🇧',
            'it': '🇮🇹',
            'el': '🇬🇷'
        };
        const flag = langFlags[flashData.lang] || '🏳️';
        
        modalTitle.textContent = terms.delete_translation_confirm_title;
        
        let html = '<p style="margin-bottom: 1rem;"><strong>Poistetaan:</strong> ' + flag + ' ' + escapeHtml(langName) + ' kieliversio tiedotteesta \'' + escapeHtml(flashData.title) + '\'</p>';
        html += '<p>' + terms.delete_translation_confirm_message + '</p>';
        
        modalContent.innerHTML = html;
    }
}
</script>

<!-- ARKISTOI-MODAALI -->
<div class="sf-modal hidden" id="modalArchive" role="dialog" aria-modal="true" aria-labelledby="modalArchiveTitle">
    <div class="sf-modal-content">
        <h2 id="modalArchiveTitle">
            <?= htmlspecialchars(sf_term('archive_confirm_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </h2>
        <p>
            <?= htmlspecialchars(sf_term('archive_confirm_message', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </p>
        <div class="sf-modal-actions">
            <button
              type="button"
              class="sf-btn sf-btn-secondary"
              data-modal-close="modalArchive"
            >
              <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" class="sf-btn sf-btn-primary" id="modalArchiveConfirm">
              <?= htmlspecialchars(sf_term('btn_archive', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>

<!-- POISTA KOMMENTTI -MODAALI -->
<div class="sf-modal hidden" id="modalDeleteComment" role="dialog" aria-modal="true" aria-labelledby="modalDeleteCommentTitle">
    <div class="sf-modal-content">
        <h2 id="modalDeleteCommentTitle">
            <?= htmlspecialchars(sf_term('comment_delete_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </h2>
        <p>
            <?= htmlspecialchars(sf_term('comment_delete_confirm', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </p>
        <p class="sf-help-text" style="color: #dc2626; margin-top: 0.5rem;">
            <?= htmlspecialchars(sf_term('comment_delete_warning', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </p>
        <div class="sf-modal-actions">
            <button type="button" class="sf-btn sf-btn-secondary" data-modal-close="modalDeleteComment">
                <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" class="sf-btn sf-btn-danger" id="modalDeleteCommentConfirm">
                <?= htmlspecialchars(sf_term('btn_delete', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>

<!-- POISTA LISÄTIETO -MODAALI -->
<div class="sf-modal hidden" id="modalDeleteInfo" role="dialog" aria-modal="true" aria-labelledby="modalDeleteInfoTitle">
    <div class="sf-modal-content">
        <h2 id="modalDeleteInfoTitle">
            <?= htmlspecialchars(sf_term('comment_delete_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </h2>
        <p>
            <?= htmlspecialchars(sf_term('comment_delete_confirm', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </p>
        <div class="sf-modal-actions">
            <button type="button" class="sf-btn sf-btn-secondary" data-modal-close="modalDeleteInfo">
                <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" class="sf-btn sf-btn-danger" id="modalDeleteInfoConfirm">
                <?= htmlspecialchars(sf_term('btn_delete', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>

<!-- DELETE LOG ENTRY MODAL (admin only) -->
<div class="sf-modal hidden" id="modalDeleteLog" role="dialog" aria-modal="true">
    <div class="sf-modal-content">
        <h2><?= htmlspecialchars(sf_term('log_delete_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></h2>
        <p><?= htmlspecialchars(sf_term('log_delete_confirm', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></p>
        <div class="sf-modal-actions">
            <button type="button" class="sf-btn sf-btn-secondary" data-modal-close="modalDeleteLog">
                <?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" class="sf-btn sf-btn-danger" id="confirmDeleteLog">
                <?= htmlspecialchars(sf_term('btn_delete', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>

<!-- KIELIVERSIO-MODAALI (vaiheittainen) -->
<div class="sf-modal hidden" id="modalTranslation" role="dialog" aria-modal="true" aria-labelledby="modalTranslationTitle">
    <div class="sf-modal-content sf-modal-translation">
        
        <!-- VAIHE 1: Lomake -->
        <div class="sf-translation-step" id="translationStep1">
            <h2 id="modalTranslationTitle">
                <?php echo htmlspecialchars(sf_term('modal_translation_title', $currentUiLang) ?? 'Luo kieliversio', ENT_QUOTES, 'UTF-8'); ?>
            </h2>
            
            <div class="sf-step-indicator">
                <span class="sf-step active">1</span>
                <span class="sf-step-line"></span>
                <span class="sf-step">2</span>
            </div>

            <form id="translationForm">
                <input type="hidden" name="source_id" value="<?php echo (int)$flash['id']; ?>">
                <input type="hidden" name="target_lang" id="translationTargetLang" value="">
                
                <div class="sf-field">
                    <label class="sf-label">
                        <?php echo htmlspecialchars(sf_term('translation_target_lang', $currentUiLang) ?? 'Kohdekieli', ENT_QUOTES, 'UTF-8'); ?>
                    </label>
                    <div class="sf-translation-lang-display" id="translationLangDisplay"></div>
                </div>

                <div class="sf-field">
                    <label for="translationTitleShort" class="sf-label">
                        <?php echo htmlspecialchars(sf_term('short_title_label', $currentUiLang) ?? 'Lyhyt kuvaus', ENT_QUOTES, 'UTF-8'); ?> *
                    </label>
                    <textarea 
                        name="title_short" 
                        id="translationTitleShort" 
                        class="sf-textarea" 
                        rows="2" 
                        maxlength="125"
                        required
                    ></textarea>
                    <div class="sf-char-count"><span id="titleCharCount">0</span>/125</div>
                </div>

                <div class="sf-field">
                    <label for="translationDescription" class="sf-label">
                        <?php echo htmlspecialchars(sf_term('description_label', $currentUiLang) ?? 'Kuvaus', ENT_QUOTES, 'UTF-8'); ?> *
                    </label>
                    <textarea 
                        name="description" 
                        id="translationDescription" 
                        class="sf-textarea" 
                        rows="5"
                        maxlength="900"
                        required
                    ></textarea>
                    <div class="sf-char-count"><span id="descCharCount">0</span>/900</div>
                </div>

                <?php if ($flash['type'] === 'green'): ?>
                    <div class="sf-field">
                        <label for="translationRootCauses" class="sf-label">
                            <?php echo htmlspecialchars(sf_term('root_cause_label', $currentUiLang) ?? 'Juurisyyt', ENT_QUOTES, 'UTF-8'); ?>
                        </label>
                        <textarea name="root_causes" id="translationRootCauses" class="sf-textarea" rows="3"></textarea>
                    </div>

                    <div class="sf-field">
                        <label for="translationActions" class="sf-label">
                            <?php echo htmlspecialchars(sf_term('actions_label', $currentUiLang) ?? 'Toimenpiteet', ENT_QUOTES, 'UTF-8'); ?>
                        </label>
                        <textarea name="actions" id="translationActions" class="sf-textarea" rows="3"></textarea>
                    </div>
                <?php endif; ?>
            </form>

            <div class="sf-modal-actions">
                <button type="button" class="sf-btn sf-btn-secondary" data-modal-close="modalTranslation">
                    <?php echo htmlspecialchars(sf_term('btn_cancel', $currentUiLang) ?? 'Peruuta', ENT_QUOTES, 'UTF-8'); ?>
                </button>
                <button type="button" class="sf-btn sf-btn-primary" id="btnToStep2">
                    <?php echo htmlspecialchars(sf_term('btn_next', $currentUiLang) ?? 'Seuraava', ENT_QUOTES, 'UTF-8'); ?> →
                </button>
            </div>
        </div>

        <!-- VAIHE 2: Esikatselu -->
        <div class="sf-translation-step hidden" id="translationStep2">
            <h2>
                <?php echo htmlspecialchars(sf_term('preview_and_save', $currentUiLang) ?? 'Esikatselu ja tallennus', ENT_QUOTES, 'UTF-8'); ?>
            </h2>
            
            <div class="sf-step-indicator">
                <span class="sf-step done">✓</span>
                <span class="sf-step-line done"></span>
                <span class="sf-step active">2</span>
            </div>

            <div class="sf-translation-preview-wrapper">
                <div id="sfTranslationPreviewContainer">
                    <?php require __DIR__ .'/../partials/preview_modal.php'; ?>
                </div>
            </div>

            <div id="translationStatus" class="sf-translation-status"></div>

            <div class="sf-modal-actions">
                <button type="button" class="sf-btn sf-btn-secondary" id="btnBackToStep1">
                    ← <?php echo htmlspecialchars(sf_term('btn_back', $currentUiLang) ?? 'Takaisin', ENT_QUOTES, 'UTF-8'); ?>
                </button>
                <button type="button" class="sf-btn sf-btn-primary" id="btnSaveTranslation">
                    <?php echo htmlspecialchars(sf_term('btn_save_translation', $currentUiLang) ?? 'Tallenna kieliversio', ENT_QUOTES, 'UTF-8'); ?>
                </button>
            </div>
        </div>

    </div>
</div>

<!-- KIELIVERSIO VAHVISTUSMODAALI (kevyt) -->
<div class="sf-modal hidden" id="modalTranslationConfirm" role="dialog" aria-modal="true" aria-labelledby="modalTranslationConfirmTitle">
    <div class="sf-modal-backdrop" onclick="sfCloseTranslationConfirm()"></div>
    <div class="sf-modal-content sf-modal-confirm">
        <div class="sf-modal-header">
            <h3 id="modalTranslationConfirmTitle">
                <span style="margin-right: 8px;">🌐</span>
                <?php echo htmlspecialchars(sf_term('modal_translation_confirm_title', $currentUiLang) ?? 'Luo kieliversio', ENT_QUOTES, 'UTF-8'); ?>
            </h3>
            <button class="sf-modal-close" onclick="sfCloseTranslationConfirm()">✕</button>
        </div>
        <div class="sf-modal-body">
            <p class="sf-confirm-question">
                <?php echo htmlspecialchars(sf_term('modal_translation_confirm_message', $currentUiLang) ?? 'Haluatko luoda kieliversion tälle SafetyFlashille?', ENT_QUOTES, 'UTF-8'); ?>
            </p>
            
            <div class="sf-confirm-card" id="translationConfirmCard">
                <!-- Target language row with flag -->
                <div class="sf-confirm-lang-row" id="translationConfirmLangRow">
                    <!-- Populated by JS: flag image + language name + code -->
                </div>
                
                <!-- Source flash info -->
                <div class="sf-confirm-source">
                    <span class="sf-confirm-source-label">
                        <?php echo htmlspecialchars(sf_term('confirm_source_flash_label', $currentUiLang), ENT_QUOTES, 'UTF-8'); ?>:
                    </span>
                    <span class="sf-confirm-source-title" id="translationConfirmSourceTitle">
                        <!-- Populated by JS from SF_FLASH_DATA -->
                    </span>
                </div>
                
                <!-- Meta row: site + type badge -->
                <div class="sf-confirm-meta">
                    <span class="sf-confirm-site" id="translationConfirmSite">
                        <!-- Populated by JS: 📍 Site name -->
                    </span>
                    <span class="sf-confirm-type-badge" id="translationConfirmType">
                        <!-- Populated by JS: colored dot + type name -->
                    </span>
                </div>
            </div>
        </div>
        <div class="sf-modal-footer">
            <button type="button" class="sf-btn sf-btn-secondary" onclick="sfCloseTranslationConfirm()">
                <?php echo htmlspecialchars(sf_term('btn_cancel', $currentUiLang) ?? 'Peruuta', ENT_QUOTES, 'UTF-8'); ?>
            </button>
            <button type="button" class="sf-btn sf-btn-primary" id="btnConfirmTranslation">
                <?php echo htmlspecialchars(sf_term('btn_create_translation', $currentUiLang) ?? 'Kyllä, luo kieliversio', ENT_QUOTES, 'UTF-8'); ?>
            </button>
        </div>
    </div>
</div>

<!-- VERSION MODAL -->
<div class="sf-modal hidden" id="versionModal" role="dialog" aria-modal="true" aria-labelledby="versionModalTitle">
    <div class="sf-modal-backdrop" onclick="closeVersionModal()"></div>
    <div class="sf-modal-content sf-version-modal">
        <div class="sf-modal-header">
            <h3 id="versionModalTitle"><?= htmlspecialchars(sf_term('version_ensitiedote', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></h3>
            <span id="versionModalDate" class="sf-version-modal-date"></span>
            <button class="sf-modal-close" onclick="closeVersionModal()">✕</button>
        </div>
        <div class="sf-modal-body">
            <img id="versionModalImage" src="" alt="SafetyFlash version" class="sf-version-image">
        </div>
        <div class="sf-modal-footer">
            <a id="versionDownloadBtn" href="" download class="sf-btn sf-btn-primary">
                📥 <?= htmlspecialchars(sf_term('version_download', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </a>
        </div>
    </div>
</div>

<?php if (in_array('display_targets', $actions ?? [])): ?>
<?php require __DIR__ . '/../partials/modal_display_targets.php'; ?>
<?php endif; ?>

<?php if ($canAccessSettings): ?>
<?php require __DIR__ . '/../partials/report_settings_modal.php'; ?>
<?php endif; ?>


<?php /* Footer action bar siirretty ylös (näkyy heti sivun latautuessa). */ ?>

<!-- html2canvas tarvitaan kuvan generointiin -->
<script src="<?= sf_asset_url('assets/js/vendor/html2canvas.min.js', $base) ?>"></script>

<!-- Quill WYSIWYG editor (vendored locally) -->
<script src="<?= sf_asset_url('assets/js/vendor/quill.min.js', $base) ?>"></script>
<script src="<?= sf_asset_url('assets/js/vendor/purify.min.js', $base) ?>"></script>

<!-- Safetyflash CSS & JS -->
<link rel="stylesheet" href="<?= sf_asset_url('assets/css/display-ttl.css', $base) ?>">
<link rel="stylesheet" href="<?= sf_asset_url('assets/css/preview.css', $base) ?>">
<link rel="stylesheet" href="<?= sf_asset_url('assets/css/copy-to-clipboard.css', $base) ?>">
<link rel="stylesheet" href="<?= sf_asset_url('assets/css/image_captions.css', $base) ?>">
<!-- view.js and copy-to-clipboard.js are loaded in index.php with versioning, removed duplicates here -->
<script src="<?= sf_asset_url('assets/js/translation.js', $base) ?>"></script>
<script src="<?= sf_asset_url('assets/js/display-playlist.js', $base) ?>"></script>
<script src="<?= sf_asset_url('assets/js/comms-modal.js', $base) ?>"></script>
<?php if (in_array('display_targets', $actions ?? [])): ?>
<script src="<?= sf_asset_url('assets/js/display-targets-modal.js', $base) ?>"></script>
<?php endif; ?>

<script>
window.SF_LIST_I18N = window.SF_LIST_I18N || {};
window.SF_LIST_I18N.editingIndicator = <?= json_encode(sf_term('editing_indicator', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>;
window.SF_BASE_URL = window.SF_BASE_URL || <?= json_encode($base) ?>;
</script>
<script src="<?= sf_asset_url('assets/js/editing-indicator.js', $base) ?>"></script>

<!-- Sivukohtaiset datat -->
<script>
window.SF_LOG_SHOW_MORE   = <?php echo json_encode(sf_term('log_show_more', $currentUiLang)); ?>;
window.SF_LOG_SHOW_LESS   = <?php echo json_encode(sf_term('log_show_less', $currentUiLang)); ?>;
window.SF_BASE_URL        = <?php echo json_encode($base); ?>;
window.SF_CSRF_TOKEN      = <?php echo json_encode(sf_csrf_token()); ?>;
window.SF_FLASH_ID        = <?php echo json_encode($id); ?>;
window.SF_CAN_EDIT        = <?php echo json_encode($canEdit); ?>;
window.SF_EDIT_URL        = <?php echo json_encode($editUrl); ?>;
window.SF_TERMS = {
    comment_delete_confirm: <?php echo json_encode(sf_term('comment_delete_confirm', $currentUiLang)); ?>,
    comment_deleted: <?php echo json_encode(sf_term('comment_deleted', $currentUiLang)); ?>,
    comment_updated: <?php echo json_encode(sf_term('comment_updated', $currentUiLang)); ?>,
    comment_delete_error: <?php echo json_encode(sf_term('comment_delete_error', $currentUiLang)); ?>,
    comment_update_error: <?php echo json_encode(sf_term('comment_update_error', $currentUiLang)); ?>,
    comment_add_error: <?php echo json_encode(sf_term('comment_add_error', $currentUiLang)); ?>,
    comment_error_empty: <?php echo json_encode(sf_term('comment_error_empty', $currentUiLang)); ?>,
    comment_added: <?php echo json_encode(sf_term('comment_added', $currentUiLang)); ?>,
    comment_reply: <?php echo json_encode(sf_term('comment_reply', $currentUiLang)); ?>,
    comment_edit: <?php echo json_encode(sf_term('comment_edit', $currentUiLang)); ?>,
    comment_delete: <?php echo json_encode(sf_term('comment_delete', $currentUiLang)); ?>,
    modal_comment_edit_title: <?php echo json_encode(sf_term('modal_comment_edit_title', $currentUiLang)); ?>,
    modal_comment_reply_title: <?php echo json_encode(sf_term('modal_comment_reply_title', $currentUiLang)); ?>,
    modal_comment_title: <?php echo json_encode(sf_term('modal_comment_title', $currentUiLang)); ?>,
    comments_empty: <?php echo json_encode(sf_term('comments_empty', $currentUiLang)); ?>,
    time_just_now: <?php echo json_encode(sf_term('time_just_now', $currentUiLang)); ?>,
    // Communications modal terms
    comms_summary_none: <?php echo json_encode(sf_term('comms_summary_none', $currentUiLang) ?? 'Ei valintoja'); ?>,
    comms_screens_all: <?php echo json_encode(sf_term('comms_screens_all', $currentUiLang) ?? 'Kaikki näytöt'); ?>,
    comms_all_countries: <?php echo json_encode(sf_term('comms_all_countries', $currentUiLang) ?? 'Kaikki maat'); ?>,
    comms_summary_worksites: <?php echo json_encode(sf_term('comms_summary_worksites', $currentUiLang) ?? 'työmaata'); ?>,
    comms_screens_selected: <?php echo json_encode(sf_term('comms_screens_selected', $currentUiLang) ?? 'Valitse työmaat'); ?>,
    comms_summary_no_distribution: <?php echo json_encode(sf_term('comms_summary_no_distribution', $currentUiLang) ?? 'Ei jakelulistoja'); ?>,
    comms_error_no_languages: <?php echo json_encode(sf_term('comms_error_no_languages', $currentUiLang) ?? 'Valitse vähintään yksi kieliversio'); ?>,
    comms_wider_distribution_yes: <?php echo json_encode(sf_term('comms_wider_distribution_yes', $currentUiLang) ?? 'Kyllä, lähetä laajempaan jakeluun'); ?>,
    comms_wider_distribution_no: <?php echo json_encode(sf_term('comms_wider_distribution_no', $currentUiLang) ?? 'Ei, vain valitut näytöt'); ?>,
    comms_summary_yes: <?php echo json_encode(sf_term('comms_summary_yes', $currentUiLang) ?? 'Kyllä'); ?>,
    comms_summary_no: <?php echo json_encode(sf_term('comms_summary_no', $currentUiLang) ?? 'Ei'); ?>,
    country_finland: <?php echo json_encode(sf_term('country_finland', $currentUiLang) ?? 'Suomi'); ?>,
    country_italy: <?php echo json_encode(sf_term('country_italy', $currentUiLang) ?? 'Italia'); ?>,
    country_greece: <?php echo json_encode(sf_term('country_greece', $currentUiLang) ?? 'Kreikka'); ?>,
    status_sending: <?php echo json_encode(sf_term('status_sending', $currentUiLang) ?? 'Lähetetään...'); ?>,
    error_sending: <?php echo json_encode(sf_term('error_sending', $currentUiLang) ?? 'Virhe lähetyksessä'); ?>,
    error_network: <?php echo json_encode(sf_term('error_network', $currentUiLang) ?? 'Verkkovirhe'); ?>,
    btn_send_comms: <?php echo json_encode(sf_term('btn_send_comms', $currentUiLang) ?? 'Lähetä viestintään'); ?>,
    log_delete_error: <?php echo json_encode(sf_term('log_delete_error', $currentUiLang)); ?>,
    log_deleted: <?php echo json_encode(sf_term('log_deleted', $currentUiLang)); ?>,
    // Extra images gallery terms
    extra_img_delete_confirm: <?php echo json_encode(sf_term('extra_img_delete_confirm', $currentUiLang)); ?>,
    delete_success: <?php echo json_encode(sf_term('delete_success', $currentUiLang)); ?>,
    delete_error: <?php echo json_encode(sf_term('delete_error', $currentUiLang)); ?>,
    unknown_error: <?php echo json_encode(sf_term('unknown_error', $currentUiLang)); ?>,
    select_image_files: <?php echo json_encode(sf_term('select_image_files', $currentUiLang)); ?>,
    images_loading_error: <?php echo json_encode(sf_term('images_loading_error', $currentUiLang)); ?>,
    images_uploading: <?php echo json_encode(sf_term('images_uploading', $currentUiLang)); ?>,
    upload_success: <?php echo json_encode(sf_term('upload_success', $currentUiLang)); ?>,
    upload_error: <?php echo json_encode(sf_term('upload_error', $currentUiLang)); ?>,
    upload_modal_title: <?php echo json_encode(sf_term('upload_modal_title', $currentUiLang)); ?>,
    upload_drag_text: <?php echo json_encode(sf_term('upload_drag_text', $currentUiLang)); ?>,
    btn_cancel: <?php echo json_encode(sf_term('btn_cancel', $currentUiLang)); ?>,
    btn_delete: <?php echo json_encode(sf_term('btn_delete', $currentUiLang)); ?>,
    // Translation confirmation modal terms
    type_red: <?php echo json_encode(sf_term('type_red', $currentUiLang)); ?>,
    type_yellow: <?php echo json_encode(sf_term('type_yellow', $currentUiLang)); ?>,
    type_green: <?php echo json_encode(sf_term('type_green', $currentUiLang)); ?>,
    confirm_creating_translation: <?php echo json_encode(sf_term('confirm_creating_translation', $currentUiLang)); ?>,
    // Publish summary terms
    publish_yes: <?php echo json_encode(sf_term('publish_yes', $currentUiLang) ?? '✅ Kyllä'); ?>
};
window.SF_FLASH_DATA      = <?php echo json_encode($flashDataForJs); ?>;
window.SF_SUPPORTED_LANGS = <?php echo json_encode($supportedLangs); ?>;
window.SF_CSRF_TOKEN      = <?php echo json_encode(sf_csrf_token()); ?>;
window.SF_ARCHIVE_BTN_TEXT = <?php echo json_encode(sf_term('btn_archive', $currentUiLang)); ?>;
window.SF_ARCHIVING_TEXT  = <?php echo json_encode(sf_term('archiving_in_progress', $currentUiLang) ?: 'Archiving...'); ?>;

// Käännökset translation.js:lle - kaikki tuetut kielet
window.SF_TRANSLATIONS = {
    metaLabels: {
        fi: { site: <?php echo json_encode(sf_term('preview_meta_site', 'fi')); ?>, date: <?php echo json_encode(sf_term('preview_meta_date', 'fi')); ?> },
        sv: { site: <?php echo json_encode(sf_term('preview_meta_site', 'sv')); ?>, date: <?php echo json_encode(sf_term('preview_meta_date', 'sv')); ?> },
        en: { site: <?php echo json_encode(sf_term('preview_meta_site', 'en')); ?>, date: <?php echo json_encode(sf_term('preview_meta_date', 'en')); ?> },
        it: { site: <?php echo json_encode(sf_term('preview_meta_site', 'it')); ?>, date: <?php echo json_encode(sf_term('preview_meta_date', 'it')); ?> },
        el: { site: <?php echo json_encode(sf_term('preview_meta_site', 'el')); ?>, date: <?php echo json_encode(sf_term('preview_meta_date', 'el')); ?> }
    },
    messages: {
        validationFillRequired: <?php echo json_encode(sf_term('validation_fill_required', $currentUiLang)); ?>,
        generatingImage: <?php echo json_encode(sf_term('generating_image', $currentUiLang)); ?>,
        saving: <?php echo json_encode(sf_term('status_saving', $currentUiLang)); ?>,
        translationSaved: <?php echo json_encode(sf_term('translation_saved', $currentUiLang)); ?>,
        errorPrefix: <?php echo json_encode(sf_term('error_prefix', $currentUiLang)); ?>,
        saveTranslationButton: <?php echo json_encode(sf_term('save_translation_button', $currentUiLang)); ?>
    }
};

// Hide loading spinner and fade in preview image
document.addEventListener('DOMContentLoaded', function() {
    const previewSpinner = document.getElementById('previewSpinner');
    const previewImages = document.querySelectorAll('.preview-box .preview-image');
    
    if (previewSpinner && previewImages.length > 0) {
        // Function to hide spinner and show image with fade-in
        const showImageFn = function(img) {
            previewSpinner.classList.add('loaded');
            img.classList.add('loaded');
        };
        
        previewImages.forEach(function(img) {
            // If image is already loaded (from cache)
            if (img.complete && img.naturalHeight !== 0) {
                showImageFn(img);
            } else {
                // Wait for image to load
                img.addEventListener('load', function() {
                    showImageFn(img);
                });
                // Handle error - still hide spinner
                img.addEventListener('error', function() {
                    previewSpinner.classList.add('loaded');
                });
            }
        });
        
        // Fallback: show everything after 3 seconds regardless
        setTimeout(function() {
            previewSpinner.classList.add('loaded');
            previewImages.forEach(function(img) {
                img.classList.add('loaded');
            });
        }, 3000);
    }

    // ===== COPY TO CLIPBOARD BUTTONS =====
    if (window.SafetyFlashCopy) {
        // Load translations for copy buttons
        window.SF_I18N = window.SF_I18N || {};
        window.SF_I18N.copy_image = <?php echo json_encode(sf_term('copy_image', $currentUiLang)); ?>;
        window.SF_I18N.report_generating = <?php echo json_encode(sf_term('report_generating', $currentUiLang)); ?>;
window.SF_I18N.report_success = <?php echo json_encode(sf_term('report_success', $currentUiLang)); ?>;
window.SF_I18N.report_error = <?php echo json_encode(sf_term('report_error', $currentUiLang)); ?>;
window.SF_I18N.report_button_loading = <?php echo json_encode(sf_term('report_button_loading', $currentUiLang)); ?>;
window.SF_I18N.report_button_done = <?php echo json_encode(sf_term('report_button_done', $currentUiLang)); ?>;
window.SF_I18N.report_button_error = <?php echo json_encode(sf_term('report_button_error', $currentUiLang)); ?>;
        window.SF_I18N.copying_image = <?php echo json_encode(sf_term('copying_image', $currentUiLang)); ?>;
        window.SF_I18N.image_copied = <?php echo json_encode(sf_term('image_copied', $currentUiLang)); ?>;
        window.SF_I18N.copy_failed = <?php echo json_encode(sf_term('copy_failed', $currentUiLang)); ?>;
        window.SF_I18N.preview_error = <?php echo json_encode(sf_term('preview_generation_error', $currentUiLang)); ?>;
        window.SF_I18N.refresh_page = <?php echo json_encode(sf_term('preview_refresh_page', $currentUiLang)); ?>;

        // Add copy button for card 1 (all flash types)
        const viewPreview1 = document.getElementById('viewPreview1');
        const viewPreviewImage = document.getElementById('viewPreviewImage');
        const previewBox = document.querySelector('.preview-box');
        
        if (viewPreview1) {
            // Tutkintatiedote with tabs - add button to card container
            window.SafetyFlashCopy.addCopyButton(viewPreview1, {
                label: window.SF_I18N.copy_image,
                copyingLabel: window.SF_I18N.copying_image,
                successMessage: window.SF_I18N.image_copied,
                errorMessage: window.SF_I18N.copy_failed,
                position: 'top-right'
            });
        } else if (viewPreviewImage && previewBox) {
            // Normal flash (red/yellow) - add button to preview-box
            window.SafetyFlashCopy.addCopyButton(previewBox, {
                label: window.SF_I18N.copy_image,
                copyingLabel: window.SF_I18N.copying_image,
                successMessage: window.SF_I18N.image_copied,
                errorMessage: window.SF_I18N.copy_failed,
                position: 'top-right'
            });
        }

        // Add copy button for card 2 (tutkintatiedote only, if exists)
        const viewPreview2 = document.getElementById('viewPreview2');
        if (viewPreview2 && viewPreview2.querySelector('img')) {
            window.SafetyFlashCopy.addCopyButton(viewPreview2, {
                label: window.SF_I18N.copy_image,
                copyingLabel: window.SF_I18N.copying_image,
                successMessage: window.SF_I18N.image_copied,
                errorMessage: window.SF_I18N.copy_failed,
                position: 'top-right'
            });
        }
    }
});
</script>
<!-- Preview Polling Module -->
<script src="<?= sf_asset_url('assets/js/preview-polling.js', $base) ?>"></script>
<script>
// Initialize polling for view page
document.addEventListener('DOMContentLoaded', function() {
    const previewBox = document.querySelector('.preview-box[data-preview-status]');
    if (!previewBox) return;
    
    const flashId = previewBox.dataset.flashId;
    const previewStatus = previewBox.dataset.previewStatus;
    const id = parseInt(flashId);
    
    if (!flashId || isNaN(id) || id <= 0) return;
    
    if (previewStatus === 'pending' || previewStatus === 'processing') {
        const progressBar = document.querySelector('.sf-preview-progress-bar');
        const progressText = document.querySelector('.sf-preview-progress-text');
        const pendingMessage = document.querySelector('.sf-preview-pending-message');
        const previewImage = document.querySelector('.preview-image');
        
        window.SFPreviewPolling.start(id, {
            onProgress: (id, progress, status) => {
                if (progressBar) {
                    progressBar.style.width = progress + '%';
                }
                if (progressText) {
                    progressText.textContent = progress + '%';
                }
            },
            onComplete: (id, previewUrl, previewUrl2) => {
                // Replace skeleton with actual image with fade-in
                const skeletonPlaceholder = document.querySelector('.skeleton-preview-placeholder');
                if (skeletonPlaceholder && previewUrl) {
                    const img = document.createElement('img');
                    img.src = previewUrl;
                    img.alt = 'Preview';
                    img.className = 'preview-image';
                    img.loading = 'eager';
                    img.style.opacity = '0';
                    
                    img.onload = () => {
                        skeletonPlaceholder.style.opacity = '0';
                        setTimeout(() => {
                            skeletonPlaceholder.parentNode.replaceChild(img, skeletonPlaceholder);
                            img.style.transition = 'opacity 0.5s ease';
                            img.style.opacity = '1';
                        }, 300);
                    };
                }
                
                // Update status attribute
                previewBox.dataset.previewStatus = 'completed';
            },
            onFailed: (id) => {
                if (progressText) {
                    progressText.textContent = window.SF_I18N?.preview_error || 'Error';
                }
                if (pendingMessage) {
                    pendingMessage.classList.add('sf-generating-failed');
                }
            },
            onTimeout: (id) => {
                if (progressText) {
                    progressText.textContent = window.SF_I18N?.refresh_page || 'Refresh page';
                }
            }
        });
    }
});

// ========== VERSION MODAL FUNCTIONS ==========
function openVersionModal(imagePath, versionType, publishedAt) {
    const modal = document.getElementById('versionModal');
    const title = document.getElementById('versionModalTitle');
    const date = document.getElementById('versionModalDate');
    const image = document.getElementById('versionModalImage');
    const downloadBtn = document.getElementById('versionDownloadBtn');
    
    title.textContent = versionType;
    
    // Format date
    const dateObj = new Date(publishedAt);
    const langCode = '<?= $currentUiLang ?>';
    date.textContent = '<?= htmlspecialchars(sf_term('version_published', $currentUiLang), ENT_QUOTES, 'UTF-8') ?> ' + 
                       dateObj.toLocaleString(langCode);
    
    image.src = imagePath;
    downloadBtn.href = imagePath;
    
    modal.classList.remove('hidden');
    modal.style.display = 'flex';
    document.body.style.overflow = 'hidden';
}

function closeVersionModal() {
    const modal = document.getElementById('versionModal');
    modal.classList.add('hidden');
    modal.style.display = 'none';
    document.body.style.overflow = '';
}

// ESC key closes version modal
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        const versionModal = document.getElementById('versionModal');
        if (versionModal && !versionModal.classList.contains('hidden')) {
            closeVersionModal();
        }
    }
});

// ===== REVIEWER FUNCTIONALITY =====
(function() {
    'use strict';
    
    const flashId = <?= (int)$id ?>;
    const baseUrl = '<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>';
    const uiLang = '<?= htmlspecialchars($currentUiLang, ENT_QUOTES, 'UTF-8') ?>';
    
    // Translation terms
    const terms = {
        reviewerAdded: <?= json_encode(sf_term('reviewer_added', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
        reviewerReplaced: <?= json_encode(sf_term('reviewer_replaced', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
        reviewerRemoved: <?= json_encode(sf_term('reviewer_removed', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
        reviewerError: <?= json_encode(sf_term('reviewer_error', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
        reviewerRemoveConfirm: <?= json_encode(sf_term('reviewer_remove_confirm', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
        errorPrefix: <?= json_encode(sf_term('error_prefix', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
        errorNetwork: <?= json_encode(sf_term('error_network', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>
    };
    
    // XSS prevention helper
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Show notification
    function showNotification(message, type = 'success') {
        // Use existing toast notification system
        if (typeof window.sfToast === 'function') {
            window.sfToast(type, message);
        } else if (window.sfShowNotification) {
            window.sfShowNotification(message, type);
        } else {
            // Luo yksinkertainen toast-ilmoitus fallbackina
            const toast = document.createElement('div');
            toast.className = 'sf-toast sf-toast-' + type;
            toast.textContent = message;
            toast.style.cssText = 'position:fixed;top:80px;right:20px;z-index:100001;padding:12px 20px;border-radius:10px;color:#fff;font-size:14px;font-weight:600;box-shadow:0 4px 12px rgba(0,0,0,0.15);opacity:0;transform:translateX(40px);transition:all 0.3s ease;' +
                (type === 'error' ? 'background:#ef4444;' : type === 'warning' ? 'background:#f59e0b;' : 'background:#10b981;');
            document.body.appendChild(toast);
            requestAnimationFrame(() => { toast.style.opacity = '1'; toast.style.transform = 'translateX(0)'; });
            setTimeout(() => {
                toast.style.opacity = '0';
                toast.style.transform = 'translateX(40px)';
                setTimeout(() => toast.remove(), 300);
            }, 3000);
        }
    }
    
    // Open modal helper
    function openModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.remove('hidden');
            document.body.classList.add('sf-modal-open');
        }
    }
    
    // Close modal helper
    function closeModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.add('hidden');
            const openModals = document.querySelectorAll('.sf-modal:not(.hidden)');
            if (openModals.length === 0) {
                document.body.classList.remove('sf-modal-open');
            }
        }
    }
    
    // Fetch and refresh reviewer list
    function refreshReviewerList() {
        fetch(baseUrl + '/app/api/get_flash_reviewers.php?flash_id=' + flashId)
            .then(response => response.json())
            .then(data => {
                if (data.ok) {
                    updateReviewerDisplay(data.reviewers);
                }
            })
            .catch(err => {
                console.error('Error refreshing reviewer list:', err);
            });
    }
    
    // Update reviewer display
    function updateReviewerDisplay(reviewers) {
        const reviewerList = document.getElementById('reviewerList');
        const reviewerEmpty = document.getElementById('reviewerEmpty');
        
        if (reviewers && reviewers.length > 0) {
            if (reviewerEmpty) reviewerEmpty.classList.add('hidden');
            if (reviewerList) {
                reviewerList.innerHTML = '';
                reviewers.forEach(reviewer => {
                    const card = createReviewerCard(reviewer);
                    reviewerList.appendChild(card);
                });
                reviewerList.classList.remove('hidden');
            }
        } else {
            if (reviewerList) reviewerList.classList.add('hidden');
            if (reviewerEmpty) reviewerEmpty.classList.remove('hidden');
        }
    }
    
    // Create reviewer card element
    function createReviewerCard(reviewer) {
        const card = document.createElement('div');
        card.className = 'reviewer-card';
        card.dataset.userId = reviewer.user_id;
        
        const name = escapeHtml((reviewer.first_name || '') + ' ' + (reviewer.last_name || '')).trim();
        const email = escapeHtml(reviewer.email || '');
        const assignedAt = escapeHtml(reviewer.assigned_at_formatted || '');
        
        card.innerHTML = `
            <div class="reviewer-info">
                <div class="reviewer-name">${name || 'ID ' + reviewer.user_id}</div>
                ${email ? `<div class="reviewer-email">${email}</div>` : ''}
                ${assignedAt ? `<div class="reviewer-assigned"><?= htmlspecialchars(sf_term('reviewer_assigned_at', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>: ${assignedAt}</div>` : ''}
            </div>
            <?php if ($canManageReviewers): ?>
            <button type="button" class="reviewer-remove-btn" data-user-id="${reviewer.user_id}" data-flash-id="${flashId}" title="<?= htmlspecialchars(sf_term('remove_reviewer', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                <svg viewBox="0 0 24 24" focusable="false">
                    <line x1="18" y1="6" x2="6" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    <line x1="6" y1="6" x2="18" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                </svg>
            </button>
            <?php endif; ?>
        `;
        
        return card;
    }
    
    // Search users for reviewers
    let searchTimeout = null;
    function setupUserSearch(inputId, dropdownId, selectedIdField, displayField) {
        const searchInput = document.getElementById(inputId);
        const dropdown = document.getElementById(dropdownId);
        const selectedId = document.getElementById(selectedIdField);
        const display = document.getElementById(displayField);
        
        if (!searchInput || !dropdown) return;
        
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimeout);
            const query = this.value.trim();
            
            if (query.length < 2) {
                dropdown.classList.add('hidden');
                dropdown.innerHTML = '';
                return;
            }
            
            searchTimeout = setTimeout(() => {
                fetch(baseUrl + '/app/api/search_reviewers.php?query=' + encodeURIComponent(query))
                    .then(response => response.json())
                    .then(data => {
                        if (data.ok && data.users) {
                            displaySearchResults(data.users, dropdown, selectedId, display, searchInput);
                        }
                    })
                    .catch(err => {
                        console.error('Search error:', err);
                    });
            }, 300);
        });
        
        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (!searchInput.contains(e.target) && !dropdown.contains(e.target)) {
                dropdown.classList.add('hidden');
            }
        });
    }
    
    // Display search results
    function displaySearchResults(users, dropdown, selectedIdField, displayField, searchInput) {
        if (users.length === 0) {
            dropdown.innerHTML = '<div class="sf-dropdown-item sf-dropdown-empty"><?= htmlspecialchars(sf_term('reviewer_no_users_found', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></div>';
            dropdown.classList.remove('hidden');
            return;
        }
        
        dropdown.innerHTML = users.map(user => {
            const name = escapeHtml(user.name || (user.first_name + ' ' + user.last_name));
            const email = escapeHtml(user.email || '');
            const roleName = escapeHtml(user.role_name || '');
            
            return `<div class="sf-dropdown-item" data-user-id="${user.id}" data-name="${name}" data-email="${email}">
                <div class="user-info">
                    <div class="user-name">${name}</div>
                    <div class="user-details">${email}${roleName ? ' - ' + roleName : ''}</div>
                </div>
            </div>`;
        }).join('');
        
        dropdown.classList.remove('hidden');
        
        // Handle selection
        dropdown.querySelectorAll('.sf-dropdown-item').forEach(item => {
            if (!item.classList.contains('sf-dropdown-empty')) {
                item.addEventListener('click', function() {
                    const userId = this.dataset.userId;
                    const userName = this.dataset.name;
                    const userEmail = this.dataset.email;
                    
                    selectedIdField.value = userId;
                    searchInput.value = '';
                    dropdown.classList.add('hidden');
                    
                    // Show selected user
                    displayField.innerHTML = `
                        <div class="selected-user-chip">
                            <span>${escapeHtml(userName)}</span>
                            <button type="button" class="remove-selection">×</button>
                        </div>
                    `;
                    displayField.classList.remove('hidden');
                    
                    // Handle removal
                    displayField.querySelector('.remove-selection').addEventListener('click', function() {
                        selectedIdField.value = '';
                        displayField.classList.add('hidden');
                        displayField.innerHTML = '';
                    });
                });
            }
        });
    }
    
    // Add reviewer button handlers
    document.querySelectorAll('.reviewer-action-btn[data-action="add"]').forEach(btn => {
        btn.addEventListener('click', function() {
            const selectedId = document.getElementById('selectedReviewerId');
            const display = document.getElementById('selectedReviewerDisplay');
            if (selectedId) selectedId.value = '';
            if (display) {
                display.classList.add('hidden');
                display.innerHTML = '';
            }
            openModal('modalAddReviewer');
        });
    });
    
    // Replace reviewer button handlers
    document.querySelectorAll('.reviewer-action-btn[data-action="replace"]').forEach(btn => {
        btn.addEventListener('click', function() {
            // Fetch current reviewers and display them
            fetch(baseUrl + '/app/api/get_flash_reviewers.php?flash_id=' + flashId)
                .then(response => response.json())
                .then(data => {
                    if (data.ok) {
                        const display = document.getElementById('currentReviewersDisplay');
                        if (display && data.reviewers && data.reviewers.length > 0) {
                            display.innerHTML = data.reviewers.map(r => {
                                const name = escapeHtml((r.first_name || '') + ' ' + (r.last_name || '')).trim();
                                return `<div class="current-reviewer-chip">${name || 'ID ' + r.user_id}</div>`;
                            }).join('');
                        } else if (display) {
                            display.innerHTML = '<div class="no-reviewers"><?= htmlspecialchars(sf_term('reviewer_no_reviewers', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></div>';
                        }
                    }
                });
            
            const selectedId = document.getElementById('selectedReviewerIdReplace');
            const display = document.getElementById('selectedReviewerDisplayReplace');
            if (selectedId) selectedId.value = '';
            if (display) {
                display.classList.add('hidden');
                display.innerHTML = '';
            }
            openModal('modalReplaceReviewer');
        });
    });
    
    // Remove reviewer button handlers (event delegation)
    document.addEventListener('click', function(e) {
        const removeBtn = e.target.closest('.reviewer-remove-btn');
        if (removeBtn) {
            const userId = removeBtn.dataset.userId;
            const flashId = removeBtn.dataset.flashId;
            
            if (confirm(terms.reviewerRemoveConfirm)) {
                removeReviewer(flashId, userId);
            }
        }
    });
    
    // Add reviewer action
    const btnAddReviewer = document.getElementById('btnAddReviewer');
    if (btnAddReviewer) {
        btnAddReviewer.addEventListener('click', function() {
            const userId = document.getElementById('selectedReviewerId').value;
            
            if (!userId) {
                showNotification('<?= htmlspecialchars(sf_term('reviewer_select_user', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>', 'warning');
                return;
            }
            
            const formData = new FormData();
            formData.append('flash_id', flashId);
            formData.append('user_id', userId);
            formData.append('csrf_token', window.SF_CSRF_TOKEN);
            
            fetch(baseUrl + '/app/api/add_reviewer.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.ok) {
                    showNotification(terms.reviewerAdded, 'success');
                    closeModal('modalAddReviewer');
                    refreshReviewerList();
                } else {
                    showNotification(terms.errorPrefix + ': ' + (data.error || terms.reviewerError), 'error');
                }
            })
            .catch(err => {
                console.error('Add reviewer error:', err);
                showNotification(terms.errorNetwork, 'error');
            });
        });
    }
    
    // Replace reviewer action
    const btnReplaceReviewer = document.getElementById('btnReplaceReviewer');
    if (btnReplaceReviewer) {
        btnReplaceReviewer.addEventListener('click', function() {
            const userId = document.getElementById('selectedReviewerIdReplace').value;
            
            if (!userId) {
                showNotification('<?= htmlspecialchars(sf_term('reviewer_select_user', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>', 'warning');
                return;
            }
            
            const formData = new FormData();
            formData.append('flash_id', flashId);
            formData.append('user_id', userId);
            formData.append('csrf_token', window.SF_CSRF_TOKEN);
            
            fetch(baseUrl + '/app/api/replace_reviewer.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.ok) {
                    showNotification(terms.reviewerReplaced, 'success');
                    closeModal('modalReplaceReviewer');
                    refreshReviewerList();
                } else {
                    showNotification(terms.errorPrefix + ': ' + (data.error || terms.reviewerError), 'error');
                }
            })
            .catch(err => {
                console.error('Replace reviewer error:', err);
                showNotification(terms.errorNetwork, 'error');
            });
        });
    }
    
    // Remove reviewer function
    function removeReviewer(flashId, userId) {
        const formData = new FormData();
        formData.append('flash_id', flashId);
        formData.append('user_id', userId);
        formData.append('csrf_token', window.SF_CSRF_TOKEN);
        
        fetch(baseUrl + '/app/api/remove_reviewer.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.ok) {
                showNotification(terms.reviewerRemoved, 'success');
                refreshReviewerList();
            } else {
                showNotification(terms.errorPrefix + ': ' + (data.error || terms.reviewerError), 'error');
            }
        })
        .catch(err => {
            console.error('Remove reviewer error:', err);
            showNotification(terms.errorNetwork, 'error');
        });
    }
    
    // Setup search functionality for both modals
    setupUserSearch('reviewerSearch', 'reviewerSearchDropdown', 'selectedReviewerId', 'selectedReviewerDisplay');
    setupUserSearch('reviewerSearchReplace', 'reviewerSearchReplaceDropdown', 'selectedReviewerIdReplace', 'selectedReviewerDisplayReplace');
    
})();
</script>
<div class="sf-view-preview-fullscreen-modal hidden" id="sfViewPreviewFullscreenModal" aria-hidden="true">
    <div class="sf-view-preview-fullscreen-backdrop" id="sfViewPreviewFullscreenBackdrop"></div>

    <div class="sf-view-preview-fullscreen-dialog" role="dialog" aria-modal="true" aria-labelledby="sfViewPreviewFullscreenTitle">
        <div class="sf-view-preview-fullscreen-header">
            <h3 id="sfViewPreviewFullscreenTitle"><?= htmlspecialchars(sf_term('preview_and_save', $currentUiLang) ?? 'Esikatselu', ENT_QUOTES, 'UTF-8') ?></h3>

            <div class="sf-view-preview-fullscreen-toolbar">
                <button type="button" class="sf-view-preview-fullscreen-toolbtn" id="sfViewPreviewZoomOut" aria-label="Loitonna">−</button>
                <button type="button" class="sf-view-preview-fullscreen-toolbtn" id="sfViewPreviewZoomReset" aria-label="Sovita ruutuun">Sovita ruutuun</button>
                <button type="button" class="sf-view-preview-fullscreen-toolbtn" id="sfViewPreviewZoomIn" aria-label="Lähennä">+</button>
                <button type="button" class="sf-view-preview-fullscreen-close" id="sfViewPreviewFullscreenClose" aria-label="<?= htmlspecialchars(sf_term('btn_close', $currentUiLang) ?: 'Sulje', ENT_QUOTES, 'UTF-8') ?>">×</button>
            </div>
        </div>

        <div class="sf-view-preview-fullscreen-body" id="sfViewPreviewFullscreenBody">
            <img
                id="sfViewPreviewFullscreenImage"
                src=""
                alt=""
                class="sf-view-preview-fullscreen-image"
            >
        </div>
    </div>
</div>

<!-- Image Lightbox Modal -->
<div class="image-lightbox" id="imageLightbox">
    <button class="image-lightbox-close" id="lightboxClose" aria-label="<?= htmlspecialchars(sf_term('btn_close', $currentUiLang) ?: 'Sulje', ENT_QUOTES, 'UTF-8') ?>">&times;</button>
    <div class="image-lightbox-content">
        <img id="lightboxImage" src="" alt="">
    </div>
</div>

<!-- Upload Modal -->
<div class="sf-modal hidden" id="uploadModal" role="dialog" aria-modal="true" aria-labelledby="uploadModalTitle">
    <div class="sf-modal-backdrop"></div>
    <div class="sf-modal-content sf-upload-modal-content">
        <div class="sf-modal-header">
            <h3 id="uploadModalTitle"><?= htmlspecialchars(sf_term('upload_modal_title', $currentUiLang) ?: 'Lisää kuvia', ENT_QUOTES, 'UTF-8') ?></h3>
            <button class="sf-modal-close" id="uploadModalClose" aria-label="<?= htmlspecialchars(sf_term('btn_close', $currentUiLang) ?: 'Sulje', ENT_QUOTES, 'UTF-8') ?>">&times;</button>
        </div>
        <div class="sf-modal-body">
            <div class="sf-upload-drop-zone" id="uploadDropZone">
                <div class="sf-upload-drop-icon">📁</div>
                <div class="sf-upload-drop-text"><?= htmlspecialchars(sf_term('upload_drag_text', $currentUiLang) ?: 'Vedä ja pudota kuvia tähän', ENT_QUOTES, 'UTF-8') ?></div>
                <div class="sf-upload-drop-hint"><?= htmlspecialchars(sf_term('or', $currentUiLang) ?: 'tai', ENT_QUOTES, 'UTF-8') ?></div>
                <button type="button" class="sf-btn sf-btn-primary sf-upload-browse-btn" id="uploadBrowseBtn">
                    <?= htmlspecialchars(sf_term('upload_browse_btn', $currentUiLang) ?: 'Lataa koneelta', ENT_QUOTES, 'UTF-8') ?>
                </button>
                <input type="file" id="uploadFileInput" accept="image/*" multiple style="display: none;">
            </div>
            <div class="sf-upload-progress" id="uploadProgress">
                <div class="sf-upload-progress-bar">
                    <div class="sf-upload-progress-fill" id="uploadProgressFill">0%</div>
                </div>
                <div class="sf-upload-progress-text" id="uploadProgressText"></div>
            </div>
        </div>
    </div>
</div>

<!-- Images Tab JavaScript -->
<script src="<?= sf_asset_url('assets/js/modules/extra_images_view.js', $base) ?>"></script>
<script>
(function() {
    'use strict';
    
    const flashId = <?= (int)$id ?>;
    const baseUrl = '<?= $base ?>';
    const canEdit = <?= json_encode($canEdit ?? false) ?>;
    const canAddExtraImages = <?= json_encode($canAddExtraImages ?? false) ?>;
    
    <?php
    // Build main images array for JavaScript
    $mainImages = [];
    $imageFields = [
        'image_main' => ['caption' => 'image1_caption', 'imageType' => 'main1'],
        'image_2' => ['caption' => 'image2_caption', 'imageType' => 'main2'],
        'image_3' => ['caption' => 'image3_caption', 'imageType' => 'main3']
    ];
    foreach ($imageFields as $field => $meta) {
        if (!empty($flash[$field])) {
            $filename = $flash[$field];
            $mainImages[] = [
                'url' => $getImageUrlForJs($filename),
                // Main images use full-size for both URL and thumb_url since they're already optimized
                // and don't have separate thumbnails in the uploads/images directory
                'thumb_url' => $getImageUrlForJs($filename),
                'isMain' => true,
                'filename' => $filename,
                'caption' => $flash[$meta['caption']] ?? '',
                'imageType' => $meta['imageType'],
                'flash_id' => (int)$id
            ];
        }
    }
    ?>
    const mainImages = <?= json_encode($mainImages) ?>;
    
    let imagesLoaded = false;
    
    // Set SF_BASE_URL for the module
    window.SF_BASE_URL = baseUrl;
    
    // Tab switching logic
    const tabs = document.querySelectorAll('.sf-activity-tab');
    tabs.forEach(tab => {
        tab.addEventListener('click', function() {
            const targetTab = this.dataset.tab;
            
            // Handle images tab activation (lazy loading)
            if (targetTab === 'images' && !imagesLoaded) {
                imagesLoaded = true;
                window.initExtraImages(flashId, canEdit, mainImages, canAddExtraImages);
            }
            
            // Update active states
            tabs.forEach(t => t.classList.remove('active'));
            this.classList.add('active');
            
            // Show/hide tab content
            const allTabContent = document.querySelectorAll('.sf-tab-content');
            allTabContent.forEach(content => {
                content.classList.remove('active');
            });
            
            // Build target content ID: 'tab' + capitalized tab name (e.g., 'tabImages' for 'images')
            const targetContentId = 'tab' + targetTab.charAt(0).toUpperCase() + targetTab.slice(1);
            const targetContent = document.getElementById(targetContentId);
            if (targetContent) {
                targetContent.classList.add('active');
            }
        });
    });

    // Activate tab from URL parameter on page load
    (function () {
        var allowedTabs = ['comments', 'events', 'additionalInfo', 'versions', 'images'];
        var urlParams = new URLSearchParams(window.location.search);
        var initialTab = urlParams.get('tab');
        if (initialTab && allowedTabs.indexOf(initialTab) !== -1) {
            var targetTabBtn = document.querySelector('.sf-activity-tab[data-tab="' + initialTab + '"]');
            if (targetTabBtn) { targetTabBtn.click(); }
        }
    })();
    
    /**
     * Close lightbox
     */
    function closeLightbox() {
        const lightbox = document.getElementById('imageLightbox');
        if (lightbox) {
            lightbox.classList.remove('active');
        }
    }
    
    // Lightbox close handlers
    const lightboxClose = document.getElementById('lightboxClose');
    const lightbox = document.getElementById('imageLightbox');
    
    if (lightboxClose) {
        lightboxClose.addEventListener('click', closeLightbox);
    }
    
    if (lightbox) {
        // Close on background click
        lightbox.addEventListener('click', function(e) {
            if (e.target === lightbox) {
                closeLightbox();
            }
        });
        
        // Close on Escape key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape' && lightbox.classList.contains('active')) {
                closeLightbox();
            }
        });
    }
})();

// ===== PUBLISH SINGLE LANGUAGE VERSION MODAL =====
function openPublishSingleModal() {
    const modal = document.getElementById('publishSingleModal');
    if (modal) {
        modal.classList.remove('hidden');
        document.body.classList.add('sf-modal-open');
    }
}

function closePublishSingleModal() {
    const modal = document.getElementById('publishSingleModal');
    if (modal) {
        modal.classList.add('hidden');
        const openModals = document.querySelectorAll('.sf-modal:not(.hidden)');
        if (openModals.length === 0) {
            document.body.classList.remove('sf-modal-open');
        }
    }
}
</script>

<?php if ($canMergeOriginalFlash): ?>
<script>
(function () {
    'use strict';

    var mergeBtn = document.getElementById('footerMergeFlash');
    var mergeModal = document.getElementById('modalMergeFlash');
    var searchInput = document.getElementById('sfMergeSearchInput');
    var statusBox = document.getElementById('sfMergeFlashStatus');
    var listBox = document.getElementById('sfMergeCandidateList');
    var confirmBox = document.getElementById('sfMergeConfirmBox');
    var confirmBtn = document.getElementById('sfMergeConfirmBtn');

    if (!mergeBtn || !mergeModal || !searchInput || !statusBox || !listBox || !confirmBtn) {
        return;
    }

    var baseUrl = <?= json_encode($base, JSON_UNESCAPED_UNICODE) ?>;
    var investigationId = <?= (int)$flash['id'] ?>;
    var csrfToken = <?= json_encode(sf_csrf_token(), JSON_UNESCAPED_UNICODE) ?>;

    var texts = {
        loading: <?= json_encode(sf_term('modal_merge_flash_loading', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
        empty: <?= json_encode(sf_term('modal_merge_flash_empty', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
        success: <?= json_encode(sf_term('modal_merge_flash_success', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
        mergeButton: <?= json_encode(sf_term('btn_merge_flash', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
        occurredPrefix: <?= json_encode(sf_term('modal_merge_flash_occurred_label', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
        creatorPrefix: <?= json_encode(sf_term('modal_merge_flash_creator_label', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>
    };

    var selectedFlashId = 0;
    var selectedCard = null;
    var searchTimer = null;

    function openMergeModal() {
        mergeModal.classList.remove('hidden');
        document.body.classList.add('sf-modal-open');
        searchInput.value = '';
        selectedFlashId = 0;
        selectedCard = null;
        confirmBtn.disabled = true;
        confirmBox.style.display = 'none';
        loadCandidates('');
        window.setTimeout(function () {
            searchInput.focus();
        }, 50);
    }

    function closeMergeModal() {
        mergeModal.classList.add('hidden');
        var anyOpen = document.querySelector('.sf-modal:not(.hidden)');
        if (!anyOpen) {
            document.body.classList.remove('sf-modal-open');
        }
    }

    function escapeHtml(value) {
        var div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    function typeColor(type) {
        if (type === 'red') {
            return '#dc2626';
        }
        if (type === 'yellow') {
            return '#d97706';
        }
        return '#2563eb';
    }

    function selectCandidate(card, flashId) {
        selectedFlashId = flashId;
        selectedCard = card;

        Array.prototype.forEach.call(listBox.querySelectorAll('.sf-merge-card'), function (item) {
            item.style.borderColor = '#e5e7eb';
            item.style.boxShadow = 'none';
            item.style.background = '#ffffff';
            item.setAttribute('aria-pressed', 'false');
        });

        card.style.borderColor = '#2563eb';
        card.style.boxShadow = '0 0 0 3px rgba(37, 99, 235, 0.18)';
        card.style.background = '#eff6ff';
        card.setAttribute('aria-pressed', 'true');

        confirmBtn.disabled = false;
        confirmBox.style.display = 'block';
    }

    function renderCandidates(items) {
        listBox.innerHTML = '';
        selectedFlashId = 0;
        selectedCard = null;
        confirmBtn.disabled = true;
        confirmBox.style.display = 'none';

        if (!items || !items.length) {
            statusBox.textContent = texts.empty;
            return;
        }

        statusBox.textContent = '';

        items.forEach(function (item) {
            var title = item.title && item.title.trim() !== '' ? item.title : (item.title_short || item.summary || ('#' + item.id));
            var summary = item.summary || '';
            var worksite = item.site || '';
            var siteDetail = item.site_detail || '';
            var creator = item.creator_name || '';
            var occurred = item.occurred_fmt || '';
            var color = typeColor(item.type);

            var card = document.createElement('button');
            card.type = 'button';
            card.className = 'sf-merge-card';
            card.setAttribute('aria-pressed', 'false');
            card.style.textAlign = 'left';
            card.style.width = '100%';
            card.style.border = '1px solid #e5e7eb';
            card.style.background = '#ffffff';
            card.style.borderRadius = '14px';
            card.style.padding = '1rem';
            card.style.cursor = 'pointer';
            card.style.transition = 'all 0.16s ease';

            card.innerHTML = ''
                + '<div style="display:flex;justify-content:space-between;gap:1rem;align-items:flex-start;">'
                + '  <div style="min-width:0;">'
                + '    <div style="display:flex;align-items:center;gap:0.5rem;margin-bottom:0.5rem;flex-wrap:wrap;">'
                + '      <span style="display:inline-flex;align-items:center;gap:0.35rem;font-weight:700;color:' + color + ';">'
                + '        <span style="width:10px;height:10px;border-radius:999px;background:' + color + ';display:inline-block;"></span>'
                +          escapeHtml(item.type_label || item.type)
                + '      </span>'
                + '      <span style="display:inline-flex;padding:0.2rem 0.55rem;border-radius:999px;background:#f3f4f6;color:#374151;font-size:0.8rem;">'
                +          escapeHtml(item.state_label || item.state)
                + '      </span>'
                + '    </div>'
                + '    <div style="font-weight:700;color:#111827;margin-bottom:0.35rem;">' + escapeHtml(title) + '</div>'
                + (summary !== '' ? '<div style="color:#4b5563;font-size:0.95rem;line-height:1.45;margin-bottom:0.55rem;">' + escapeHtml(summary) + '</div>' : '')
                + '    <div style="display:flex;flex-direction:column;gap:0.2rem;color:#6b7280;font-size:0.88rem;">'
                + (worksite !== '' ? '<div><strong style="color:#374151;">' + escapeHtml(worksite) + '</strong>' + (siteDetail !== '' ? ' – ' + escapeHtml(siteDetail) : '') + '</div>' : '')
                + (occurred !== '' ? '<div><strong style="color:#374151;">' + escapeHtml(texts.occurredPrefix) + ':</strong> ' + escapeHtml(occurred) + '</div>' : '')
                + (creator !== '' ? '<div><strong style="color:#374151;">' + escapeHtml(texts.creatorPrefix) + ':</strong> ' + escapeHtml(creator) + '</div>' : '')
                + '    </div>'
                + '  </div>'
                + '  <div style="font-size:0.85rem;color:#6b7280;white-space:nowrap;">#' + escapeHtml(item.id) + '</div>'
                + '</div>';

            card.addEventListener('click', function () {
                selectCandidate(card, item.id);
            });

            listBox.appendChild(card);
        });
    }

    function loadCandidates(query) {
        statusBox.textContent = texts.loading;
        listBox.innerHTML = '';
        confirmBtn.disabled = true;
        confirmBox.style.display = 'none';

        var url = baseUrl + '/app/api/get_merge_candidates.php?flash_id=' + encodeURIComponent(investigationId);
        if (query && query.trim() !== '') {
            url += '&q=' + encodeURIComponent(query.trim());
        }

        fetch(url, {
            method: 'GET',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        })
        .then(function (data) {
            if (!data.ok) {
                throw new Error(data.error || 'Load failed');
            }
            renderCandidates(data.items || []);
        })
        .catch(function (error) {
            statusBox.textContent = error && error.message ? error.message : 'Error';
        });
    }

    mergeBtn.addEventListener('click', function () {
        openMergeModal();
    });

    searchInput.addEventListener('input', function () {
        var value = this.value;
        window.clearTimeout(searchTimer);
        searchTimer = window.setTimeout(function () {
            loadCandidates(value);
        }, 250);
    });

    confirmBtn.addEventListener('click', function () {
        if (!selectedFlashId) {
            return;
        }

        confirmBtn.disabled = true;
        confirmBtn.textContent = texts.loading;

        var formData = new FormData();
        formData.append('csrf_token', csrfToken);
        formData.append('investigation_id', String(investigationId));
        formData.append('original_flash_id', String(selectedFlashId));

        fetch(baseUrl + '/app/api/merge_investigation_flash.php', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(function (response) {
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        })
        .then(function (data) {
            if (!data.ok) {
                throw new Error(data.error || 'Merge failed');
            }

            if (typeof window.sfToast === 'function') {
                window.sfToast('success', data.message || texts.success);
            }

            if (data.redirect) {
                window.location.href = data.redirect;
                return;
            }

            window.location.reload();
        })
        .catch(function (error) {
            confirmBtn.disabled = false;
            confirmBtn.textContent = texts.mergeButton;

            if (typeof window.sfToast === 'function') {
                window.sfToast('error', error && error.message ? error.message : 'Merge failed');
            } else {
                window.alert(error && error.message ? error.message : 'Merge failed');
            }
        });
    });

    document.addEventListener('click', function (event) {
        if (event.target === mergeModal) {
            closeMergeModal();
        }
    });
})();
</script>
<?php endif; ?>

<!-- Image Captions Module -->
<script src="<?= sf_asset_url('assets/js/modules/image_captions.js', $base) ?>"></script>

<script src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/js/body-map.js"></script>
<script>
(function () {
    'use strict';

    var flashId   = <?= (int)$flash['id'] ?>;
    var csrfToken = window.SF_CSRF_TOKEN || '';
    var apiUrl    = '<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/app/api/update_body_parts.php';

    var saveMsgs = {
        success: <?= json_encode(sf_term('body_map_save_success', $currentUiLang) ?: 'Loukkaantumiset tallennettu', JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
        error:   <?= json_encode(sf_term('body_map_save_error', $currentUiLang)   ?: 'Tallennus epäonnistui', JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
    };

    function init() {
        var saveBtn = document.getElementById('sfBodyMapSaveBtn');
        if (!saveBtn) { return; }

        saveBtn.addEventListener('click', function () {
            var hiddenSelect = document.getElementById('sfInjuredPartsHidden');
            if (!hiddenSelect) { return; }

            var parts = Array.from(hiddenSelect.options)
                .filter(function (o) { return o.selected; })
                .map(function (o) { return o.value; });

            var formData = new FormData();
            formData.append('flash_id', flashId);
            formData.append('csrf_token', csrfToken);
            parts.forEach(function (p) { formData.append('injured_parts[]', p); });

            fetch(apiUrl, { method: 'POST', body: formData })
                .then(function (r) {
                    if (!r.ok) { throw new Error('HTTP ' + r.status); }
                    return r.json();
                })
                .then(function (data) {
                    if (data.ok) {
                        // Sync hidden select and tags with the server-confirmed saved parts
                        if (Array.isArray(data.saved_parts) && window.BodyMap) {
                            window.BodyMap.init();
                        }
                        if (typeof showNotification === 'function') {
                            showNotification(saveMsgs.success, 'success');
                        }
                    } else {
                        if (typeof showNotification === 'function') {
                            showNotification(saveMsgs.error + (data.error ? ': ' + data.error : ''), 'error');
                        }
                    }
                })
                .catch(function () {
                    if (typeof showNotification === 'function') {
                        showNotification(saveMsgs.error, 'error');
                    }
                });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
<?php if ($canAccessSettings): ?>
<script>
(function () {
    'use strict';

    var flashId    = <?= (int)$flash['id'] ?>;
    var csrfToken  = window.SF_CSRF_TOKEN || '';
    var settingsApiUrl = '<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/app/api/save_report_settings.php';

    var settingsMsgs = {
        saved: <?= json_encode(sf_term('settings_original_type_saved', $currentUiLang), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
        error: <?= json_encode(sf_term('settings_original_type_error', $currentUiLang), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
    };

    function initSettingsModal() {
        // Auto-save original type on change
        var originalTypeSelect = document.getElementById('sfOriginalTypeSelect');
        if (originalTypeSelect) {
            originalTypeSelect.addEventListener('change', function () {
                saveOriginalType(this.value);
            });
        }
    }

    function saveOriginalType(value) {
        var statusEl = document.getElementById('sfOriginalTypeSaveStatus');
        if (statusEl) { statusEl.textContent = ''; }

        var formData = new FormData();
        formData.append('flash_id', flashId);
        formData.append('original_type', value);
        formData.append('csrf_token', csrfToken);

        fetch(settingsApiUrl, { method: 'POST', body: formData })
            .then(function (r) {
                if (!r.ok) { throw new Error('HTTP ' + r.status); }
                return r.json();
            })
            .then(function (data) {
                if (data.ok) {
                    if (statusEl) {
                        statusEl.textContent = settingsMsgs.saved;
                        statusEl.className = 'sf-settings-save-status sf-settings-save-ok';
                        setTimeout(function () { statusEl.textContent = ''; statusEl.className = 'sf-settings-save-status'; }, 2500);
                    }
                } else {
                    if (statusEl) {
                        statusEl.textContent = settingsMsgs.error;
                        statusEl.className = 'sf-settings-save-status sf-settings-save-error';
                    }
                    if (typeof showNotification === 'function') {
                        showNotification(settingsMsgs.error, 'error');
                    }
                }
            })
            .catch(function () {
                if (statusEl) {
                    statusEl.textContent = settingsMsgs.error;
                    statusEl.className = 'sf-settings-save-status sf-settings-save-error';
                }
                if (typeof showNotification === 'function') {
                    showNotification(settingsMsgs.error, 'error');
                }
            });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSettingsModal);
    } else {
        initSettingsModal();
    }
})();
</script>
<?php endif; ?>

<!-- Body map button in Lisätiedot tab -->
<?php if ($canAccessSettings && $showBodyMapInTab): ?>
<script>
(function () {
    'use strict';
    function initTabBodyMap() {
        var btn = document.getElementById('sfTabBodyMapBtn');
        if (!btn) { return; }
        btn.addEventListener('click', function () {
            var bodyMapModal = document.getElementById('sfBodyMapModal');
            if (bodyMapModal) {
                bodyMapModal.classList.remove('hidden');
                document.body.classList.add('sf-modal-open');
                var focusable = bodyMapModal.querySelector('button, [href], input, select, textarea');
                if (focusable) { focusable.focus({ preventScroll: true }); }
            }
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initTabBodyMap);
    } else {
        initTabBodyMap();
    }
})();
</script>
<?php endif; ?>

<!-- Additional Info AJAX -->
<?php if ($canAccessSettings): ?>
<script>
(function () {
    'use strict';

    var flashId          = <?= (int)$flash['id'] ?>;
    var csrfToken        = window.SF_CSRF_TOKEN || '';
    var additionalApiUrl = '<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/app/api/save_additional_info.php';

    var deleteApiUrl = '<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/app/api/delete_additional_info.php';

    var aiMsgs = {
        saved:            <?= json_encode(sf_term('additional_info_saved', $currentUiLang), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
        error:            <?= json_encode(sf_term('additional_info_save_error', $currentUiLang), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
        unknownAuthor:    <?= json_encode(sf_term('additional_info_unknown_author', $currentUiLang), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
        titleAdd:         <?= json_encode(sf_term('additional_info_modal_add_title', $currentUiLang), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
        titleEdit:        <?= json_encode(sf_term('additional_info_modal_edit_title', $currentUiLang), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
        editBtnLabel:     <?= json_encode(sf_term('comment_edit', $currentUiLang), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
        deleteBtnLabel:   <?= json_encode(sf_term('comment_delete', $currentUiLang), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
        deleteConfirm:    <?= json_encode(sf_term('comment_delete_confirm', $currentUiLang), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
        deleteSuccess:    <?= json_encode(sf_term('comment_deleted', $currentUiLang), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
        deleteError:      <?= json_encode(sf_term('additional_info_save_error', $currentUiLang), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
        baseUrl:          '<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>',
    };

    var quillEditor = null;

    var SAFE_TAGS = ['P', 'BR', 'STRONG', 'EM', 'U', 'OL', 'UL', 'LI', 'SPAN'];
    function sanitizeHtml(html) {
        if (typeof DOMPurify !== 'undefined') {
            return DOMPurify.sanitize(html, { ALLOWED_TAGS: SAFE_TAGS, ALLOWED_ATTR: [] });
        }
        // DOMPurify not loaded — block submission to prevent unsanitized content
        return null;
    }

    function escapeHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    function getQuill() {
        if (quillEditor) { return quillEditor; }
        if (typeof Quill === 'undefined') { return null; }
        var editorEl = document.getElementById('sfAdditionalInfoEditor');
        if (!editorEl) { return null; }
        quillEditor = new Quill('#sfAdditionalInfoEditor', {
            theme: 'snow',
            modules: {
                toolbar: [
                    ['bold', 'italic', 'underline'],
                    [{ 'list': 'ordered' }, { 'list': 'bullet' }],
                    ['clean']
                ]
            }
        });
        return quillEditor;
    }

    function openModal(editId, prefillHtml) {
        var modal    = document.getElementById('sfAdditionalInfoModal');
        var titleEl  = document.getElementById('sfAdditionalInfoModalTitle');
        var editIdEl = document.getElementById('sfAdditionalInfoEditId');
        var status   = document.getElementById('sfAdditionalInfoStatus');
        if (!modal) { return; }

        editIdEl.value = editId || '';
        if (titleEl) { titleEl.textContent = editId ? aiMsgs.titleEdit : aiMsgs.titleAdd; }
        if (status)  { status.textContent = ''; }

        modal.classList.remove('hidden');
        document.body.classList.add('sf-modal-open');

        var q = getQuill();
        if (q) {
            if (prefillHtml) {
                q.clipboard.dangerouslyPasteHTML(sanitizeHtml(prefillHtml));
            } else {
                q.setContents([]);
            }
            setTimeout(function () { q.focus(); }, 50);
        }
    }

    function closeModal() {
        var modal = document.getElementById('sfAdditionalInfoModal');
        if (!modal) { return; }
        modal.classList.add('hidden');
        document.body.classList.remove('sf-modal-open');
    }

    function renderNewEntry(entry) {
        var name  = ((entry.first_name || '') + ' ' + (entry.last_name || '')).trim() || aiMsgs.unknownAuthor;
        var div   = document.createElement('div');
        div.className    = 'sf-comment-item';
        div.dataset.aiId = entry.id;
        var contentHtml  = sanitizeHtml(entry.content || '');
        div.innerHTML =
            '<div class="sf-comment-content">' +
                '<div class="sf-comment-header">' +
                    '<div>' +
                        '<span class="sf-comment-author">' + escapeHtml(name) + '</span>' +
                        ' <span class="sf-comment-time">&middot; ' + escapeHtml(entry.created_at || '') + '</span>' +
                    '</div>' +
                    '<div class="sf-comment-actions">' +
                        '<button type="button" class="sf-comment-action-btn btn-edit-additional-info"' +
                            ' data-ai-id="' + escapeHtml(String(entry.id)) + '"' +
                            ' data-content="' + escapeHtml(entry.content || '') + '">' +
                            '<img src="' + escapeHtml(aiMsgs.baseUrl) + '/assets/img/icons/edit.svg" alt="" class="sf-action-icon">' +
                            ' ' + escapeHtml(aiMsgs.editBtnLabel) +
                        '</button>' +
                        '<button type="button" class="sf-comment-action-btn btn-delete-additional-info sf-text-danger"' +
                            ' data-ai-id="' + escapeHtml(String(entry.id)) + '">' +
                            '<img src="' + escapeHtml(aiMsgs.baseUrl) + '/assets/img/icons/delete.svg" alt="" class="sf-action-icon">' +
                            ' ' + escapeHtml(aiMsgs.deleteBtnLabel) +
                        '</button>' +
                    '</div>' +
                '</div>' +
                '<div class="sf-comment-body">' + contentHtml + '</div>' +
            '</div>';
        return div;
    }

    function updateEntryInList(id, content) {
        var item = document.querySelector('.sf-comment-item[data-ai-id="' + id + '"]');
        if (!item) { return; }
        var contentEl  = item.querySelector('.sf-comment-body');
        var editBtn    = item.querySelector('.btn-edit-additional-info');
        if (contentEl) { contentEl.innerHTML = sanitizeHtml(content); }
        if (editBtn)   { editBtn.dataset.content = content; }
    }

    function submitForm() {
        var editIdEl    = document.getElementById('sfAdditionalInfoEditId');
        var status      = document.getElementById('sfAdditionalInfoStatus');
        var submitBtn   = document.getElementById('sfAdditionalInfoSubmitBtn');
        var editId      = editIdEl ? editIdEl.value.trim() : '';

        var q = getQuill();
        // Use getText() to reliably check if the editor is empty (strips all HTML)
        var plainText = q ? q.getText().trim() : '';
        if (!plainText) { return; }

        // Get and sanitize the HTML content before sending
        var content = q ? sanitizeHtml(q.root.innerHTML) : '';
        if (content === null) {
            // DOMPurify library failed to load — block submission
            if (status) {
                status.textContent = aiMsgs.error;
                status.style.color = '#dc2626';
            }
            return;
        }
        // Safety net: if sanitizer stripped everything (shouldn't happen when plainText is set)
        if (!content) { return; }

        if (submitBtn) { submitBtn.disabled = true; }
        if (status)    { status.textContent = ''; }

        var formData = new FormData();
        formData.append('flash_id',   flashId);
        formData.append('content',    content);
        formData.append('csrf_token', csrfToken);
        if (editId) { formData.append('id', editId); }

        fetch(additionalApiUrl, { method: 'POST', body: formData })
            .then(function (r) {
                if (!r.ok) { throw new Error('HTTP ' + r.status); }
                return r.json();
            })
            .then(function (data) {
                if (data.ok && data.entry) {
                    closeModal();
                    document.body.classList.remove('sf-modal-open');
                    if (typeof showNotification === 'function') {
                        showNotification(aiMsgs.saved, 'success');
                    }
                    setTimeout(function () {
                        var url = new URL(window.location.href);
                        url.searchParams.set('tab', 'additionalInfo');
                        window.location.href = url.toString();
                    }, 500);
                } else {
                    if (status) {
                        status.textContent = aiMsgs.error;
                        status.style.color = '#dc2626';
                    }
                }
            })
            .catch(function () {
                if (status) {
                    status.textContent = aiMsgs.error;
                    status.style.color = '#dc2626';
                }
            })
            .finally(function () {
                if (submitBtn) { submitBtn.disabled = false; }
            });
    }

    function init() {
        // "Add text" button opens modal
        var openBtn = document.getElementById('sfOpenAddAdditionalInfoBtn');
        if (openBtn) {
            openBtn.addEventListener('click', function () {
                openModal('', '');
            });
        }

        // Form submit inside modal
        var form = document.getElementById('sfAdditionalInfoForm');
        if (form) {
            form.addEventListener('submit', function (e) {
                e.preventDefault();
                submitForm();
            });
        }

        // Edit buttons on existing entries (delegated)
        var list = document.getElementById('sfAdditionalInfoList');
        if (list) {
            list.addEventListener('click', function (e) {
                var btn = e.target.closest('.btn-edit-additional-info');
                if (!btn) { return; }
                openModal(btn.dataset.aiId, btn.dataset.content);
            });
        }

        // Delete buttons on existing entries – open app modal for confirmation
        var pendingDeleteAiId = null;

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.btn-delete-additional-info');
            if (!btn) { return; }
            var aiId = btn.dataset.aiId;
            if (!aiId) { return; }
            pendingDeleteAiId = aiId;
            var deleteModal = document.getElementById('modalDeleteInfo');
            if (deleteModal) {
                deleteModal.classList.remove('hidden');
                document.body.classList.add('sf-modal-open');
                var focusable = deleteModal.querySelector('button');
                if (focusable) { focusable.focus({ preventScroll: true }); }
            }
        });

        var deleteInfoConfirmBtn = document.getElementById('modalDeleteInfoConfirm');
        if (deleteInfoConfirmBtn) {
            deleteInfoConfirmBtn.addEventListener('click', function () {
                var aiId = pendingDeleteAiId;
                if (!aiId) { return; }
                pendingDeleteAiId = null;

                var deleteModal = document.getElementById('modalDeleteInfo');
                if (deleteModal) {
                    deleteModal.classList.add('hidden');
                    if (!document.querySelector('.sf-modal:not(.hidden)')) {
                        document.body.classList.remove('sf-modal-open');
                    }
                }

                var deleteBtn = document.querySelector('.btn-delete-additional-info[data-ai-id="' + aiId + '"]');
                if (deleteBtn) { deleteBtn.disabled = true; }

                var fd = new FormData();
                fd.append('id', aiId);
                fd.append('csrf_token', csrfToken);
                fetch(deleteApiUrl, { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.ok) {
                            if (typeof showNotification === 'function') {
                                showNotification(aiMsgs.deleteSuccess, 'success');
                            }
                            var item = deleteBtn ? deleteBtn.closest('.sf-comment-item') : null;
                            if (item) {
                                item.style.transition = 'opacity 0.3s';
                                item.style.opacity = '0';
                            }
                            setTimeout(function () {
                                var url = new URL(window.location.href);
                                url.searchParams.set('tab', 'additionalInfo');
                                window.location.href = url.toString();
                            }, 400);
                        } else {
                            if (deleteBtn) { deleteBtn.disabled = false; }
                            if (typeof showNotification === 'function') {
                                showNotification(aiMsgs.deleteError, 'error');
                            }
                        }
                    })
                    .catch(function () {
                        if (deleteBtn) { deleteBtn.disabled = false; }
                        if (typeof showNotification === 'function') {
                            showNotification(aiMsgs.deleteError, 'error');
                        }
                    });
            });
        }

        // Close modal on backdrop click
        var modal = document.getElementById('sfAdditionalInfoModal');
        if (modal) {
            modal.addEventListener('click', function (e) {
                if (e.target === modal) { closeModal(); }
            });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
</script>
<?php endif; ?>

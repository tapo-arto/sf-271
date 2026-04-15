<?php
// app/actions/publish.php
declare(strict_types=1);

// Set error handler to convert warnings/notices to exceptions
set_error_handler(function($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

try {

require_once __DIR__ . '/../includes/protect.php';
require_once __DIR__ . '/../includes/log_app.php';
require_once __DIR__ . '/../includes/statuses.php';
require_once __DIR__ . '/../includes/audit_log.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/../../assets/services/email_services.php'; 
require_once __DIR__ . '/../includes/file_cleanup.php';

$id  = sf_validate_id();
$pdo = sf_get_pdo();

// Haetaan flash
$stmt = $pdo->prepare("
    SELECT id, translation_group_id, title, state, lang 
    FROM sf_flashes 
    WHERE id = :id 
    LIMIT 1
");
$stmt->execute([':id' => $id]);
$flash = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$flash) {
    sf_redirect($config['base_url'] . "/index.php?page=list");
    exit;
}

// Tallenna vanha tila
$oldState = (string)($flash['state'] ?? '');

// Get translation group ID
$groupId = $flash['translation_group_id'] ?: $flash['id'];

$logFlashId = !empty($flash['translation_group_id'])
    ? (int)$flash['translation_group_id']
    : (int)$flash['id'];

// ========== MÄÄRITÄ userId HETI ALUSSA ==========
$userId = $_SESSION['user_id'] ?? null;
$currentUiLang = $_SESSION['ui_lang'] ?? 'fi';
// ================================================

// Lue POST-parametrit (julkaisumodaalista)
$sendToDistribution = isset($_POST['send_to_distribution']) && $_POST['send_to_distribution'] === '1';
$hasPersonalInjury = isset($_POST['has_personal_injury']) && $_POST['has_personal_injury'] === '1';

// Lue valitut maat (POST)
$selectedCountries = $_POST['distribution_countries'] ?? ['fi']; // Default: Suomi

// ========== JULKAISULOGIIKKA ==========
$publishMode = $_POST['publish_mode'] ?? 'all';

if ($publishMode === 'single') {
    // ===== TAPAUS B: Yksittäisen kieliversion julkaisu =====
    $updateStmt = $pdo->prepare("
        UPDATE sf_flashes 
        SET state = 'published', 
            status = 'JULKAISTU',
            published_at = COALESCE(published_at, NOW()),
            has_personal_injury = :injury,
            sent_to_distribution = :distribution,
            updated_at = NOW()
        WHERE id = :id
    ");
    $updateStmt->execute([
        ':id' => $id,
        ':injury' => $hasPersonalInjury ? 1 : 0,
        ':distribution' => $sendToDistribution ? 1 : 0,
    ]);

    // Clear display snapshot so the new published preview takes over on Xibo displays.
    $pdo->prepare("UPDATE sf_flashes SET display_snapshot_active = 0, display_snapshot_preview = NULL WHERE id = ?")
        ->execute([$id]);

    sf_app_log("publish.php: Single language version published: flash_id={$id}");

    // Display targets — vain tälle kieliversiolle
    $singleTargets = $_POST['display_targets'][$id] ?? [];
    $pdo->prepare("DELETE FROM sf_flash_display_targets WHERE flash_id = ?")->execute([$id]);

    if (!empty($singleTargets)) {
        $stmtInsert = $pdo->prepare("
            INSERT INTO sf_flash_display_targets
            (flash_id, display_key_id, is_active, selected_by, selected_at, activated_at)
            VALUES (?, ?, 1, ?, NOW(), NOW())
        ");
        foreach ($singleTargets as $displayId) {
            $displayId = (int)$displayId;
            if ($displayId > 0) {
                $stmtInsert->execute([$id, $displayId, $userId]);
            }
        }
    }

    // TTL tälle versiolle
    $ttlDays = (int)($_POST['display_ttl_days'] ?? 30);
    if ($ttlDays > 0) {
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$ttlDays} days"));
        $pdo->prepare("UPDATE sf_flashes SET display_expires_at = ?, display_removed_at = NULL, display_removed_by = NULL WHERE id = ?")
            ->execute([$expiresAt, $id]);
    } else {
        $pdo->prepare("UPDATE sf_flashes SET display_expires_at = NULL, display_removed_at = NULL, display_removed_by = NULL WHERE id = ?")
            ->execute([$id]);
    }

    // Kesto tälle versiolle
    $durationSeconds = max(5, min(120, (int)($_POST['display_duration_seconds'] ?? 30)));
    $pdo->prepare("UPDATE sf_flashes SET display_duration_seconds = ? WHERE id = ?")
        ->execute([$durationSeconds, $id]);

} else {
    // ===== TAPAUS A: Kaikkien valmiiden kieliversioiden julkaisu =====
    $stmtAllVersions = $pdo->prepare("
        SELECT id, lang, state FROM sf_flashes 
        WHERE id = :gid OR translation_group_id = :gid2
    ");
    $stmtAllVersions->execute([':gid' => $groupId, ':gid2' => $groupId]);
    $allVersions = $stmtAllVersions->fetchAll(PDO::FETCH_ASSOC);

    $publishedVersionIds = [];
    $allDisplayTargets = $_POST['display_targets'] ?? [];

    foreach ($allVersions as $ver) {
        $verId = (int)$ver['id'];
        $verState = $ver['state'];
        $hasTargets = !empty($allDisplayTargets[$verId]);

        if ($verState !== 'draft' || $hasTargets) {
            $publishedVersionIds[] = $verId;
        } else {
            sf_app_log("publish.php: Skipping draft version flash_id={$verId} ({$ver['lang']}) — no display targets selected");
        }
    }

    if (!empty($publishedVersionIds)) {
        $placeholders = implode(',', array_fill(0, count($publishedVersionIds), '?'));
        $updateStmt = $pdo->prepare("
            UPDATE sf_flashes 
            SET state = 'published', 
                status = 'JULKAISTU',
                published_at = COALESCE(published_at, NOW()),
                has_personal_injury = ?,
                sent_to_distribution = ?,
                updated_at = NOW()
            WHERE id IN ({$placeholders})
        ");
        $params = array_merge(
            [$hasPersonalInjury ? 1 : 0, $sendToDistribution ? 1 : 0],
            $publishedVersionIds
        );
        $updateStmt->execute($params);

        // Clear display snapshot so the new published preview takes over on Xibo displays.
        $clearPlaceholders = implode(',', array_fill(0, count($publishedVersionIds), '?'));
        $pdo->prepare("UPDATE sf_flashes SET display_snapshot_active = 0, display_snapshot_preview = NULL WHERE id IN ({$clearPlaceholders})")
            ->execute($publishedVersionIds);

        sf_app_log("publish.php: Published " . count($publishedVersionIds) . " versions: " . implode(',', $publishedVersionIds));
    }

    // Display targets per kieliversio
    foreach ($allVersions as $ver) {
        $verId = (int)$ver['id'];
        if (!in_array($verId, $publishedVersionIds)) {
            continue;
        }

        $targetsForThis = $allDisplayTargets[$verId] ?? [];
        $pdo->prepare("DELETE FROM sf_flash_display_targets WHERE flash_id = ?")->execute([$verId]);

        if (!empty($targetsForThis)) {
            $stmtInsert = $pdo->prepare("
                INSERT INTO sf_flash_display_targets
                (flash_id, display_key_id, is_active, selected_by, selected_at, activated_at)
                VALUES (?, ?, 1, ?, NOW(), NOW())
            ");
            foreach ($targetsForThis as $displayId) {
                $displayId = (int)$displayId;
                if ($displayId > 0) {
                    $stmtInsert->execute([$verId, $displayId, $userId]);
                }
            }
        }
    }

    // TTL ja kesto kaikille julkaistuille versioille
    $ttlDays = (int)($_POST['display_ttl_days'] ?? 30);
    $durationSeconds = max(5, min(120, (int)($_POST['display_duration_seconds'] ?? 30)));

    foreach ($publishedVersionIds as $verId) {
        if ($ttlDays > 0) {
            $expiresAt = date('Y-m-d H:i:s', strtotime("+{$ttlDays} days"));
            $pdo->prepare("UPDATE sf_flashes SET display_expires_at = ?, display_removed_at = NULL, display_removed_by = NULL WHERE id = ?")
                ->execute([$expiresAt, $verId]);
        } else {
            $pdo->prepare("UPDATE sf_flashes SET display_expires_at = NULL, display_removed_at = NULL, display_removed_by = NULL WHERE id = ?")
                ->execute([$verId]);
        }
        $pdo->prepare("UPDATE sf_flashes SET display_duration_seconds = ? WHERE id = ?")
            ->execute([$durationSeconds, $verId]);
    }
}
// ========================================

// ========== SAVE SNAPSHOT ==========
// Only save snapshot if this is a new publish (not already published)
if ($oldState !== 'published') {
    
    // Check flash type to determine version_type
    $stmtFlash = $pdo->prepare("SELECT type FROM sf_flashes WHERE id = ? LIMIT 1");
    $stmtFlash->execute([$groupId]);
    $flashType = $stmtFlash->fetchColumn();
    
    // Determine version_type based on flash type
    $versionType = match($flashType) {
        'red' => 'ensitiedote',
        'yellow' => 'vaaratilanne',
        'green' => 'tutkintatiedote',
        default => 'vaaratilanne',
    };
    
    // Use existing preview image (already generated when flash was created/edited)
    $stmtPreview = $pdo->prepare("SELECT preview_filename FROM sf_flashes WHERE id = ? LIMIT 1");
    $stmtPreview->execute([$groupId]);
    $previewFilename = $stmtPreview->fetchColumn();
    
    // Sanitize filename to prevent path traversal attacks
    if ($previewFilename) {
        $previewFilename = basename($previewFilename);
    }
    
    $baseDir = dirname(__DIR__, 2);
    $previewPath = $previewFilename ? $baseDir . '/uploads/previews/' . $previewFilename : null;
    
    if ($previewPath && file_exists($previewPath)) {
        // Hae olemassa oleva snapshot tälle tyypille ja kielelle
        $stmtExisting = $pdo->prepare("
            SELECT id, image_path FROM sf_flash_snapshots 
            WHERE flash_id = ? AND version_type = ? AND lang = ?
            LIMIT 1
        ");
        $stmtExisting->execute([$groupId, $versionType, sf_get_flash_lang($flash)]);
        $existing = $stmtExisting->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            // Korvaa olemassa oleva snapshot
            $snapshotFullPath = $baseDir . $existing['image_path'];
            if (copy($previewPath, $snapshotFullPath)) {
                $stmtUpdate = $pdo->prepare("
                    UPDATE sf_flash_snapshots 
                    SET published_at = NOW(), published_by = ?
                    WHERE id = ?
                ");
                $stmtUpdate->execute([$userId, $existing['id']]);
                sf_app_log("publish.php: Updated existing snapshot for flash {$groupId}, type: {$versionType}");
            } else {
                sf_app_log("publish.php: Failed to update snapshot for flash {$groupId}", LOG_LEVEL_ERROR);
            }
        } else {
            // Luo uusi snapshot
            $snapshotDir = $baseDir . '/storage/snapshots/' . $groupId;
            if (!is_dir($snapshotDir)) {
                mkdir($snapshotDir, 0755, true);
            }
            
            $timestamp = date('Y-m-d_His');
            $snapshotFilename = $versionType . '_' . $timestamp . '.jpg';
            $snapshotPath = $snapshotDir . '/' . $snapshotFilename;
            
            if (copy($previewPath, $snapshotPath)) {
                $relativeImagePath = '/storage/snapshots/' . $groupId . '/' . $snapshotFilename;
                
                // Hae version_number
                $stmtCount = $pdo->prepare("SELECT COUNT(*) FROM sf_flash_snapshots WHERE flash_id = ?");
                $stmtCount->execute([$groupId]);
                $versionNumber = (int)$stmtCount->fetchColumn() + 1;
                
                $stmtSnapshot = $pdo->prepare("
                    INSERT INTO sf_flash_snapshots 
                    (flash_id, version_type, lang, version_number, image_path, published_at, published_by)
                    VALUES (:flash_id, :version_type, :lang, :version_number, :image_path, NOW(), :published_by)
                ");
                $stmtSnapshot->execute([
                    ':flash_id' => $groupId,
                    ':version_type' => $versionType,
                    ':lang' => sf_get_flash_lang($flash),
                    ':version_number' => $versionNumber,
                    ':image_path' => $relativeImagePath,
                    ':published_by' => $userId,
                ]);
                sf_app_log("publish.php: Created new snapshot for flash {$groupId}, type: {$versionType}");
            } else {
                sf_app_log("publish.php: Failed to copy snapshot for flash {$groupId}", LOG_LEVEL_ERROR);
            }
        }
    } else {
        sf_app_log("publish.php: Preview generation failed for flash {$groupId}", LOG_LEVEL_ERROR);
    }
}
// ===================================

// Lokimerkintä safetyflash_logs-tauluun
$statusLabel = sf_status_label('published', $currentUiLang);

// Tallennetaan avaimella
$desc = "log_status_set: published";

$log = $pdo->prepare("
    INSERT INTO safetyflash_logs (flash_id, user_id, event_type, description, created_at)
    VALUES (:flash_id, :user_id, :event_type, :description, NOW())
");
$log->execute([
    ':flash_id'   => $logFlashId,
    ':user_id'    => $userId,
    ':event_type' => 'published',
    ':description'=> $desc,
]);

// Kirjataan myös erillinen state_changed tapahtuma
require_once __DIR__ . '/../../assets/lib/sf_terms.php';
if ($oldState !== 'published') {
    $oldStateLabel = sf_status_label($oldState, $currentUiLang);
    $newStateLabel = sf_status_label('published', $currentUiLang);
    $stateChangeDesc = sf_term('log_state_changed', $currentUiLang) . ": {$oldStateLabel} → {$newStateLabel}";
    
    $logStateChange = $pdo->prepare("
        INSERT INTO safetyflash_logs (flash_id, user_id, event_type, description, created_at)
        VALUES (:flash_id, :user_id, :event_type, :description, NOW())
    ");
    $logStateChange->execute([
        ':flash_id'   => $logFlashId,
        ':user_id'    => $userId,
        ':event_type' => 'state_changed',
        ':description'=> $stateChangeDesc,
    ]);
}

// ========== AUDIT LOG ==========
$user = sf_current_user();

sf_audit_log(
    'flash_publish',                 // action (vastaa sf_audit_action_label-listaa)
    'flash',                         // target type
    (int)$id,                        // target id
    [
        'title'      => $flash['title'] ?? null,
        'new_status' => 'published',
    ],
    $user ? (int)$user['id'] : null  // user id
);
// ================================

// Lähetetään julkaisu-sähköposti
if (function_exists('sf_mail_published')) {
    try {
        sf_mail_published($pdo, $id);
    } catch (Throwable $e) {
        sf_app_log('publish: sf_mail_published ERROR: ' . $e->getMessage(), LOG_LEVEL_ERROR);
    }
}

// Lähetetään julkaisu-ilmoitus TEKIJÄLLE
if (function_exists('sf_mail_published_to_creator')) {
    try {
        sf_mail_published_to_creator($pdo, $id);
    } catch (Throwable $e) {
        sf_app_log('publish: sf_mail_published_to_creator ERROR: ' . $e->getMessage(), LOG_LEVEL_ERROR);
    }
}

// Lähetä maakohtaisille jakeluryhmille
$distributionResults = [];
if ($sendToDistribution && function_exists('sf_mail_to_distribution_by_country')) {
    foreach ($selectedCountries as $countryCode) {
        try {
            $recipientCount = sf_mail_to_distribution_by_country($pdo, $id, $countryCode, $hasPersonalInjury);
            $distributionResults[$countryCode] = $recipientCount;
            sf_app_log("publish.php: Sent to {$countryCode}, recipients: {$recipientCount}");
        } catch (Throwable $e) {
            sf_app_log("publish.php: Distribution error for {$countryCode}: " . $e->getMessage(), LOG_LEVEL_ERROR);
            $distributionResults[$countryCode] = 0;
        }
    }
}

// Lokimerkintä jakeluista
if (!empty($distributionResults)) {
    $distParts = [];
    foreach ($distributionResults as $country => $count) {
        $countryName = sf_term("country_name_{$country}", $currentUiLang) ?? strtoupper($country);
        $recipientsLabel = sf_term('log_recipients_count', $currentUiLang) ?? 'recipients';
        $distParts[] = "{$countryName}: {$count} {$recipientsLabel}";
    }
    
    $distDesc = "log_distribution_sent|countries:" . implode(',', array_keys($distributionResults)) . 
                "|details:" . implode('; ', $distParts);
    
    $logDist = $pdo->prepare("
        INSERT INTO safetyflash_logs (flash_id, user_id, event_type, description, created_at)
        VALUES (:flash_id, :user_id, :event_type, :description, NOW())
    ");
    $logDist->execute([
        ':flash_id'   => $logFlashId,
        ':user_id'    => $userId,
        ':event_type' => 'distribution_sent',
        ':description'=> $distDesc,
    ]);
}

// Palauta JSON jos AJAX-pyyntö, muuten redirect
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if ($isAjax) {
    header('Content-Type: application/json');
    echo json_encode([
        'ok' => true,
        'message' => sf_term('notice_published', $currentUiLang),
        'redirect' => $config['base_url'] . "/index.php?page=view&id={$id}"
    ]);
    exit;
}

sf_redirect($config['base_url'] . "/index.php?page=view&id={$id}&notice=published");

} catch (Throwable $e) {
    // Log error for debugging
    if (function_exists('sf_app_log')) {
        sf_app_log(basename(__FILE__) . ' FATAL ERROR: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), LOG_LEVEL_ERROR);
    } else {
        error_log(basename(__FILE__) . ' FATAL ERROR: ' . $e->getMessage());
    }
    
    // Check if this was an AJAX request
    $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && 
              strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
    
    if ($isAjax) {
        // Return JSON error with ACTUAL error message
        header('Content-Type: application/json');
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => $e->getMessage(),
            'debug' => $e->getFile() . ':' . $e->getLine(),
            'trace' => array_slice(explode("\n", $e->getTraceAsString()), 0, 3)
        ]);
        exit;
    }
    
    // Fallback redirect for non-AJAX
    $base = '';
    if (isset($config['base_url'])) {
        $base = rtrim($config['base_url'], '/');
    }
    $id = $_GET['id'] ?? $_POST['flash_id'] ?? 0;
    if ($base !== '') {
        header("Location: {$base}/index.php?page=view&id={$id}&notice=error");
    } else {
        header("Location: /index.php?page=view&id={$id}&notice=error");
    }
    exit;
}

restore_error_handler();
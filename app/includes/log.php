<?php
// app/includes/log.php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

/**
 * Kirjaa tapahtuman safetyflash-lokiin.
 *
 * @param int    $flashId     Safetyflashin ID (sf_flashes.id)
 * @param string $eventType   lyhyt koodi: created, updated, status_changed, comment_added, sent_to_review, published, etc.
 * @param string $description ihmisen luettava selite lokiin
 */
function sf_log_event(int $flashId, string $eventType, string $description = ''): void
{
    $mysqli = sf_db();
    $user   = sf_current_user();
    $userId = $user['id'] ?? null;

    $sql = "INSERT INTO safetyflash_logs (flash_id, user_id, event_type, description, created_at)
            VALUES (?, ?, ?, ?, NOW())";

    $stmt = $mysqli->prepare($sql);
    if (!$stmt) {
        // ei kaadeta koko sovellusta lokivirheeseen
        error_log('sf_log_event prepare failed: ' . $mysqli->error);
        return;
    }

    // user_id voi olla NULL -> bindataan iiss ja annetaan 0 jos tyhjä
    $uid = $userId ? (int)$userId : 0;
    $stmt->bind_param('iiss', $flashId, $uid, $eventType, $description);

    if (!$stmt->execute()) {
        error_log('sf_log_event execute failed: ' . $stmt->error);
    }

    $stmt->close();
}
/**
 * Log field-level changes as a single event.
 *
 * @deprecated Use direct INSERT into safetyflash_logs combined with sf_audit_log() instead.
 *
 * @param int   $flashId Flash ID
 * @param array $old     Old field values
 * @param array $new     New field values
 */
function sf_log_changes(int $flashId, array $old, array $new): void
{
    trigger_error('sf_log_changes() is deprecated, use direct INSERT into safetyflash_logs combined with sf_audit_log() instead', E_USER_DEPRECATED);
    if (!function_exists('sf_term')) {
        require_once __DIR__ . '/../../assets/lib/sf_terms.php';
    }

    $currentUiLang = $_SESSION['ui_lang'] ?? 'fi';

    $fieldTermMap = [
        'title'       => 'log_title_changed',
        'title_short' => 'log_title_short_changed',
        'summary'     => 'log_summary_changed',
        'description' => 'log_description_changed',
        'site'        => 'log_site_changed',
        'site_detail' => 'log_site_detail_changed',
        'occurred_at' => 'log_occurred_at_changed',
        'root_causes' => 'log_root_causes_changed',
        'actions'     => 'log_actions_changed',
    ];

    $changes = [];

    foreach ($new as $key => $value) {
        if (!array_key_exists($key, $old)) {
            continue;
        }
        if ($old[$key] === $value) {
            continue;
        }

        // Ohitetaan tekniset kentät
        if (in_array($key, ['updated_at', 'created_at', 'preview_filename'], true)) {
            continue;
        }

        $oldVal = (string)($old[$key] ?? '');
        $newVal = (string)($value ?? '');

        $localizedName = isset($fieldTermMap[$key])
            ? sf_term($fieldTermMap[$key], $currentUiLang)
            : ucfirst($key);

        $changes[] = $localizedName . ": '{$oldVal}' → '{$newVal}'";
    }

    if (!$changes) {
        return;
    }

    $msg = implode("\n", $changes);
    sf_log_event($flashId, 'status_changed', $msg);
}
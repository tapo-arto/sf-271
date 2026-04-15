<?php
/**
 * FlashLogService
 * 
 * Centralized logging service for SafetyFlash.
 * Handles logging of edits, type changes, state changes, and field changes to safetyflash_logs table.
 * 
 * @deprecated Use direct INSERT into safetyflash_logs combined with sf_audit_log() instead.
 *
 * @package SafetyFlash
 * @subpackage Services
 */

declare(strict_types=1);

if (!function_exists('sf_term')) {
    require_once __DIR__ . '/../../assets/lib/sf_terms.php';
}

class FlashLogService
{
    /**
     * Log a general edit event
     * 
     * @param int $flashId Flash ID
     * @param array $changes Array of changes made
     * @param int $userId User ID who made the edit
     * @param string|null $batchId Optional batch ID for grouping
     * @return void
     */
    public function logEdit(int $flashId, array $changes, int $userId, ?string $batchId = null): void
    {
        $pdo = Database::getInstance();
        
        // Get translation group ID for logging
        $stmt = $pdo->prepare("SELECT translation_group_id FROM sf_flashes WHERE id = ?");
        $stmt->execute([$flashId]);
        $groupId = $stmt->fetchColumn();
        $logFlashId = $groupId ?: $flashId;

        // Map raw field names to term keys (stored as keys, translated at display time)
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

        // Fields whose content is too long to show old→new; only store the term key
        $longFields = ['description', 'root_causes', 'actions', 'summary'];
        
        // Build description using term keys so the UI can translate them at render time
        $changeDescriptions = [];
        foreach ($changes as $field => $change) {
            if (isset($change['old']) && isset($change['new'])) {
                $termKey = $fieldTermMap[$field] ?? $field;

                if (in_array($field, $longFields, true)) {
                    // Just store the term key – view.php pattern 9 will translate it
                    $changeDescriptions[] = $termKey;
                } else {
                    // Store as "term_key: old → new" – view.php pattern 7 translates the key
                    $changeDescriptions[] = "{$termKey}: {$change['old']} → {$change['new']}";
                }
            }
        }
        
        // Each change is stored on a separate line so parseEventDesc in view.php
        // can process and translate each entry individually (splits on "\n")
        $description = !empty($changeDescriptions) 
            ? implode("\n", $changeDescriptions)
            : 'log_flash_edited';
        
        $stmt = $pdo->prepare("
            INSERT INTO safetyflash_logs (flash_id, user_id, event_type, description, batch_id, created_at)
            VALUES (?, ?, 'edited', ?, ?, NOW())
        ");
        $stmt->execute([$logFlashId, $userId, $description, $batchId]);
    }
    
    /**
     * Log a type change event with Finnish labels
     * 
     * @param int $flashId Flash ID
     * @param string $oldType Old type (red/yellow/green)
     * @param string $newType New type (red/yellow/green)
     * @param int $userId User ID who made the change
     * @param string|null $batchId Optional batch ID for grouping
     * @return void
     */
    public function logTypeChange(int $flashId, string $oldType, string $newType, int $userId, ?string $batchId = null): void
    {
        $pdo = Database::getInstance();
        
        // Get translation group ID for logging
        $stmt = $pdo->prepare("SELECT translation_group_id FROM sf_flashes WHERE id = ?");
        $stmt->execute([$flashId]);
        $groupId = $stmt->fetchColumn();
        $logFlashId = $groupId ?: $flashId;
        
        // Store raw type slugs so view.php pattern 6b (sf_translate_flash_type) can translate them
        $description = "type: {$oldType} → {$newType}";
        
        $stmt = $pdo->prepare("
            INSERT INTO safetyflash_logs (flash_id, user_id, event_type, description, batch_id, created_at)
            VALUES (?, ?, 'type_changed', ?, ?, NOW())
        ");
        $stmt->execute([$logFlashId, $userId, $description, $batchId]);
    }
    
    /**
     * Log a state change event
     * 
     * @param int $flashId Flash ID
     * @param string $oldState Old state
     * @param string $newState New state
     * @param int $userId User ID who made the change
     * @param string|null $batchId Optional batch ID for grouping
     * @return void
     */
    public function logStateChange(int $flashId, string $oldState, string $newState, int $userId, ?string $batchId = null): void
    {
        $pdo = Database::getInstance();
        
        // Get translation group ID for logging
        $stmt = $pdo->prepare("SELECT translation_group_id FROM sf_flashes WHERE id = ?");
        $stmt->execute([$flashId]);
        $groupId = $stmt->fetchColumn();
        $logFlashId = $groupId ?: $flashId;
        
        // Store raw state slugs so view.php pattern 7 (sf_status_label) can translate them
        $description = "log_state_changed: {$oldState} → {$newState}";
        
        $stmt = $pdo->prepare("
            INSERT INTO safetyflash_logs (flash_id, user_id, event_type, description, batch_id, created_at)
            VALUES (?, ?, 'state_changed', ?, ?, NOW())
        ");
        $stmt->execute([$logFlashId, $userId, $description, $batchId]);
    }
    
    /**
     * Log a specific field change
     * 
     * @param int $flashId Flash ID
     * @param string $fieldName Name of the field that changed
     * @param string $oldValue Old value
     * @param string $newValue New value
     * @param int $userId User ID who made the change
     * @param string|null $batchId Optional batch ID for grouping
     * @return void
     */
    public function logFieldChange(int $flashId, string $fieldName, string $oldValue, string $newValue, int $userId, ?string $batchId = null): void
    {
        $pdo = Database::getInstance();
        
        // Get translation group ID for logging
        $stmt = $pdo->prepare("SELECT translation_group_id FROM sf_flashes WHERE id = ?");
        $stmt->execute([$flashId]);
        $groupId = $stmt->fetchColumn();
        $logFlashId = $groupId ?: $flashId;
        
        // Truncate long values for readability
        $oldValueShort = mb_substr($oldValue, 0, 50);
        $newValueShort = mb_substr($newValue, 0, 50);
        
        if (mb_strlen($oldValue) > 50) {
            $oldValueShort .= '...';
        }
        if (mb_strlen($newValue) > 50) {
            $newValueShort .= '...';
        }
        
        $description = "{$fieldName}: \"{$oldValueShort}\" → \"{$newValueShort}\"";
        
        $stmt = $pdo->prepare("
            INSERT INTO safetyflash_logs (flash_id, user_id, event_type, description, batch_id, created_at)
            VALUES (?, ?, 'field_changed', ?, ?, NOW())
        ");
        $stmt->execute([$logFlashId, $userId, $description, $batchId]);
    }
}

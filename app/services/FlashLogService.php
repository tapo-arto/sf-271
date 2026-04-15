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
        
        $currentUiLang = $_SESSION['ui_lang'] ?? 'fi';

        // Map raw field names to localized term keys
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

        // Fields whose content is too long to show old→new; only show the localized label
        $longFields = ['description', 'root_causes', 'actions', 'summary'];
        
        // Build description of changes
        $changeDescriptions = [];
        foreach ($changes as $field => $change) {
            if (isset($change['old']) && isset($change['new'])) {
                $localizedName = isset($fieldTermMap[$field])
                    ? sf_term($fieldTermMap[$field], $currentUiLang)
                    : $field;

                if (in_array($field, $longFields, true)) {
                    $changeDescriptions[] = $localizedName;
                } else {
                    $changeDescriptions[] = "{$localizedName}: {$change['old']} → {$change['new']}";
                }
            }
        }
        
        $description = !empty($changeDescriptions) 
            ? implode(', ', $changeDescriptions)
            : sf_term('log_flash_edited', $currentUiLang);
        
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
        
        $currentUiLang = $_SESSION['ui_lang'] ?? 'fi';
        
        // Localized type labels
        $typeTermMap = [
            'red'    => 'first_release',
            'yellow' => 'dangerous_situation',
            'green'  => 'investigation_report',
        ];
        
        $oldLabel = isset($typeTermMap[$oldType])
            ? sf_term($typeTermMap[$oldType], $currentUiLang)
            : $oldType;
        $newLabel = isset($typeTermMap[$newType])
            ? sf_term($typeTermMap[$newType], $currentUiLang)
            : $newType;
        
        $description = sf_term('log_type_changed', $currentUiLang) . ": {$oldLabel} → {$newLabel}";
        
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
        
        // Try to get localized state labels if available
        $currentUiLang = $_SESSION['ui_lang'] ?? 'fi';
        
        // Load statuses file if sf_status_label function is not defined
        if (!function_exists('sf_status_label')) {
            $statusesFile = __DIR__ . '/../includes/statuses.php';
            if (file_exists($statusesFile)) {
                require_once $statusesFile;
            }
        }
        
        $oldStateLabel = function_exists('sf_status_label') 
            ? sf_status_label($oldState, $currentUiLang)
            : $oldState;
        $newStateLabel = function_exists('sf_status_label')
            ? sf_status_label($newState, $currentUiLang)
            : $newState;
        
        $description = sf_term('log_state_changed', $currentUiLang) . ": {$oldStateLabel} → {$newStateLabel}";
        
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

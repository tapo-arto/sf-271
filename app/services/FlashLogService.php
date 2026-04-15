<?php
/**
 * FlashLogService
 * 
 * Centralized logging service for SafetyFlash.
 * Handles logging of edits, type changes, state changes, and field changes to safetyflash_logs table.
 * 
 * @package SafetyFlash
 * @subpackage Services
 */

declare(strict_types=1);

class FlashLogService
{
    /**
     * Log a general edit event
     * 
     * @param int $flashId Flash ID
     * @param array $changes Array of changes made
     * @param int $userId User ID who made the edit
     * @return void
     */
    public function logEdit(int $flashId, array $changes, int $userId): void
    {
        $pdo = Database::getInstance();
        
        // Get translation group ID for logging
        $stmt = $pdo->prepare("SELECT translation_group_id FROM sf_flashes WHERE id = ?");
        $stmt->execute([$flashId]);
        $groupId = $stmt->fetchColumn();
        $logFlashId = $groupId ?: $flashId;
        
        // Build description of changes
        $changeDescriptions = [];
        foreach ($changes as $field => $change) {
            if (isset($change['old']) && isset($change['new'])) {
                $changeDescriptions[] = "{$field}: {$change['old']} → {$change['new']}";
            }
        }
        
        $description = !empty($changeDescriptions) 
            ? implode(', ', $changeDescriptions)
            : 'Flash edited';
        
        $stmt = $pdo->prepare("
            INSERT INTO safetyflash_logs (flash_id, user_id, event_type, description, created_at)
            VALUES (?, ?, 'edited', ?, NOW())
        ");
        $stmt->execute([$logFlashId, $userId, $description]);
    }
    
    /**
     * Log a type change event with Finnish labels
     * 
     * @param int $flashId Flash ID
     * @param string $oldType Old type (red/yellow/green)
     * @param string $newType New type (red/yellow/green)
     * @param int $userId User ID who made the change
     * @return void
     */
    public function logTypeChange(int $flashId, string $oldType, string $newType, int $userId): void
    {
        $pdo = Database::getInstance();
        
        // Get translation group ID for logging
        $stmt = $pdo->prepare("SELECT translation_group_id FROM sf_flashes WHERE id = ?");
        $stmt->execute([$flashId]);
        $groupId = $stmt->fetchColumn();
        $logFlashId = $groupId ?: $flashId;
        
        // Finnish type labels
        $typeLabels = [
            'red' => 'Ensitiedote',
            'yellow' => 'Vaaratilanne',
            'green' => 'Tutkintatiedote'
        ];
        
        $oldLabel = $typeLabels[$oldType] ?? $oldType;
        $newLabel = $typeLabels[$newType] ?? $newType;
        
        $description = "Type changed: {$oldLabel} → {$newLabel}";
        
        $stmt = $pdo->prepare("
            INSERT INTO safetyflash_logs (flash_id, user_id, event_type, description, created_at)
            VALUES (?, ?, 'type_changed', ?, NOW())
        ");
        $stmt->execute([$logFlashId, $userId, $description]);
    }
    
    /**
     * Log a state change event
     * 
     * @param int $flashId Flash ID
     * @param string $oldState Old state
     * @param string $newState New state
     * @param int $userId User ID who made the change
     * @return void
     */
    public function logStateChange(int $flashId, string $oldState, string $newState, int $userId): void
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
        
        $description = "State changed: {$oldStateLabel} → {$newStateLabel}";
        
        $stmt = $pdo->prepare("
            INSERT INTO safetyflash_logs (flash_id, user_id, event_type, description, created_at)
            VALUES (?, ?, 'state_changed', ?, NOW())
        ");
        $stmt->execute([$logFlashId, $userId, $description]);
    }
    
    /**
     * Log a specific field change
     * 
     * @param int $flashId Flash ID
     * @param string $fieldName Name of the field that changed
     * @param string $oldValue Old value
     * @param string $newValue New value
     * @param int $userId User ID who made the change
     * @return void
     */
    public function logFieldChange(int $flashId, string $fieldName, string $oldValue, string $newValue, int $userId): void
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
            INSERT INTO safetyflash_logs (flash_id, user_id, event_type, description, created_at)
            VALUES (?, ?, 'field_changed', ?, NOW())
        ");
        $stmt->execute([$logFlashId, $userId, $description]);
    }
}

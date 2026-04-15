<?php
/**
 * FlashPermissionService
 * 
 * Centralized permission checking service for SafetyFlash.
 * Determines who can edit flashes, change types, and change states based on roles and flash state.
 * 
 * @package SafetyFlash
 * @subpackage Services
 */

declare(strict_types=1);

require_once __DIR__ . '/ApprovalRouting.php';

class FlashPermissionService
{
    /**
     * Check if user can edit a flash based on role and flash state
     * 
     * @param array $user User data with role_id and id
     * @param array $flash Flash data with state, created_by, and id
     * @return bool True if user can edit
     */
    public function canEdit(array $user, array $flash): bool
    {
        $roleId = (int)($user['role_id'] ?? 0);
        $state = $flash['state'] ?? '';
        $isAdmin = ($roleId === 1);
        $isSafety = ($roleId === 3);
        $isComms = ($roleId === 4);
        $isCreator = ((int)($flash['created_by'] ?? 0)) === ((int)($user['id'] ?? 0));
        
        // Admin can always edit
        if ($isAdmin) {
            return true;
        }
        
        // Safety team permissions
        if ($isSafety && in_array($state, ['pending_supervisor', 'pending_review', 'reviewed', 'to_comms', 'published'], true)) {
            return true;
        }
        
        // Communications permissions
        if ($isComms && in_array($state, ['to_comms', 'published'], true)) {
            return true;
        }
        
        // Creator permissions
        if ($isCreator && in_array($state, ['draft', 'request_info'], true)) {
            return true;
        }
        
        // Supervisor permissions (check if user is selected approver)
        if ($state === 'pending_supervisor') {
            $pdo = Database::getInstance();
            if (ApprovalRouting::isUserSelectedApprover($pdo, (int)($flash['id'] ?? 0), (int)($user['id'] ?? 0))) {
                return true;
            }
        }
        
        return false;
    }
    
    /**
     * Check if user can change flash type (red/yellow/green)
     * 
     * Type change is allowed whenever user has edit permission
     * 
     * @param array $user User data with role_id and id
     * @param array $flash Flash data with state, created_by, and id
     * @return bool True if user can change type
     */
    public function canChangeType(array $user, array $flash): bool
    {
        // Type change allowed whenever user can edit
        return $this->canEdit($user, $flash);
    }
    
    /**
     * Check if user can change flash state
     * 
     * For inline editing, state changes are NOT allowed.
     * Only workflow actions can change state.
     * 
     * @param array $user User data with role_id and id
     * @param array $flash Flash data with state, created_by, and id
     * @return bool Always false for inline edit
     */
    public function canChangeState(array $user, array $flash): bool
    {
        // Inline editing must NOT change state
        // Only workflow actions can change state
        return false;
    }
    
    /**
     * Get states that a role can edit
     * 
     * @param int $roleId Role ID
     * @return array List of editable states
     */
    public function getEditableStatesForRole(int $roleId): array
    {
        switch ($roleId) {
            case 1: // Admin
                return ['draft', 'request_info', 'pending_supervisor', 'pending_review', 'reviewed', 'to_comms', 'published'];
            
            case 3: // Safety Team
                return ['pending_supervisor', 'pending_review', 'reviewed', 'to_comms', 'published'];
            
            case 4: // Communications
                return ['to_comms', 'published'];
            
            default:
                return ['draft', 'request_info']; // Creator only
        }
    }
}

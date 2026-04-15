<?php
/**
 * FlashSaveService
 * 
 * Centralized save service for SafetyFlash.
 * Orchestrates saving flash data with permission checks, change detection, logging, and worker job creation.
 * 
 * @package SafetyFlash
 * @subpackage Services
 */

declare(strict_types=1);

require_once __DIR__ . '/FlashPermissionService.php';
require_once __DIR__ . '/FlashLogService.php';
require_once __DIR__ . '/FlashImageService.php';
require_once __DIR__ . '/../includes/log_app.php';
require_once __DIR__ . '/../includes/image_helpers.php';

class PermissionException extends Exception {}

class FlashSaveService
{
    private FlashPermissionService $permissionService;
    private FlashLogService $logService;
    
    public function __construct()
    {
        $this->permissionService = new FlashPermissionService();
        $this->logService = new FlashLogService();
    }
    
    /**
     * Main save method - orchestrates the entire save process
     * 
     * @param int $flashId Flash ID to save
     * @param array $data Form data to save
     * @param array $user Current user data
     * @return array Result array with 'ok' and 'flash_id'
     * @throws Exception If flash not found or other errors occur
     * @throws PermissionException If user lacks permission
     */
    public function save(int $flashId, array $data, array $user): array
    {
        $pdo = Database::getInstance();
        
        // 1. Fetch existing flash
        $stmt = $pdo->prepare("SELECT * FROM sf_flashes WHERE id = ?");
        $stmt->execute([$flashId]);
        $flash = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$flash) {
            throw new Exception('Flash not found');
        }
        
        // 2. Check edit permission
        if (!$this->permissionService->canEdit($user, $flash)) {
            throw new PermissionException('No edit permission');
        }
        
        // 3. Check type change permission
        if (isset($data['type']) && $data['type'] !== $flash['type']) {
            if (!$this->permissionService->canChangeType($user, $flash)) {
                throw new PermissionException('Cannot change flash type');
            }
        }
        
        // 4. Detect changes
        $changes = $this->detectChanges($flash, $data);
        
        // 5. Update database (state NEVER changes in inline edit)
        $this->updateFlash($flashId, $data, $flash);
        
        // 6. Log changes
        if (isset($changes['type'])) {
            $this->logService->logTypeChange(
                $flashId,
                $flash['type'],
                $data['type'],
                (int)($user['id'] ?? 0)
            );
            // Remove type from general changes to avoid duplicate logging
            unset($changes['type']);
        }
        
        // Log all other changes
        if (!empty($changes)) {
            $this->logService->logEdit($flashId, $changes, (int)($user['id'] ?? 0));
        }
        
        // 7. Create worker job for image generation
        // Debug logging for preview data
        $preview1Length = isset($data['preview_image_data']) ? strlen($data['preview_image_data']) : 0;
        $preview2Length = isset($data['preview_image_data_2']) ? strlen($data['preview_image_data_2']) : 0;
        sf_app_log("[FlashSaveService] Creating worker job for flash {$flashId}: preview_image_data length: {$preview1Length}, preview_image_data_2 length: {$preview2Length}");
        
        $this->createJobFile($flashId, array_merge($data, ['user_id' => $user['id'] ?? null]));
        
        // 8. Trigger worker to process images
        $this->triggerWorker($flashId);
        
        return ['ok' => true, 'flash_id' => $flashId];
    }
    
    /**
     * Validate user permissions
     * 
     * @param int $flashId Flash ID
     * @param array $data Form data
     * @param array $user Current user
     * @throws PermissionException If user lacks permission
     * @return void
     */
    public function validatePermissions(int $flashId, array $data, array $user): void
    {
        $pdo = Database::getInstance();
        
        $stmt = $pdo->prepare("SELECT * FROM sf_flashes WHERE id = ?");
        $stmt->execute([$flashId]);
        $flash = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$flash) {
            throw new Exception('Flash not found');
        }
        
        if (!$this->permissionService->canEdit($user, $flash)) {
            throw new PermissionException('No edit permission');
        }
        
        // Check type change permission if type is being changed
        if (isset($data['type']) && $data['type'] !== $flash['type']) {
            if (!$this->permissionService->canChangeType($user, $flash)) {
                throw new PermissionException('Cannot change flash type');
            }
        }
    }
    
    /**
     * Detect what changed between original and new data
     * 
     * @param array $original Original flash data
     * @param array $data New data
     * @return array Array of changes with old and new values
     */
    public function detectChanges(array $original, array $data): array
    {
        $changes = [];
        
        // Fields to track for changes
        $trackFields = [
            'type', 'title', 'title_short', 'summary', 'description',
            'site', 'site_detail', 'occurred_at', 'root_causes', 'actions'
        ];
        
        foreach ($trackFields as $field) {
            if (isset($data[$field]) && isset($original[$field])) {
                $oldValue = (string)$original[$field];
                $newValue = (string)$data[$field];
                
                if ($oldValue !== $newValue) {
                    $changes[$field] = [
                        'old' => $oldValue,
                        'new' => $newValue
                    ];
                }
            }
        }
        
        return $changes;
    }
    
    /**
     * Update flash in database
     * 
     * @param int $flashId Flash ID
     * @param array $data Form data
     * @param array $currentFlash Current flash data from database (optional, fetched if not provided)
     * @return void
     * @throws Exception If database update fails
     */
    public function updateFlash(int $flashId, array $data, ?array $currentFlash = null): void
    {
        $pdo = Database::getInstance();
        
        // Parse occurred_at date if present
        $occurredAt = null;
        if (!empty($data['occurred_at'])) {
            $ts = strtotime($data['occurred_at']);
            if ($ts !== false) {
                $occurredAt = date('Y-m-d H:i:s', $ts);
            }
        }
        
        // Check if type is changing and original_type should be preserved
        if ($currentFlash === null) {
            $stmt = $pdo->prepare("SELECT type, original_type FROM sf_flashes WHERE id = ?");
            $stmt->execute([$flashId]);
            $currentFlash = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        $originalType = $currentFlash['original_type'] ?? null;
        $newType = trim((string)($data['type'] ?? 'yellow'));
        $oldType = $currentFlash['type'] ?? '';
        
        // If type is changing and original_type is not yet set, store the old type
        if ($newType !== $oldType && $originalType === null) {
            $originalType = $oldType;
        }
        
        $sql = "UPDATE sf_flashes SET
            title = :title,
            title_short = :title_short,
            summary = :summary,
            description = :description,
            type = :type,
            original_type = :original_type,
            site = :site,
            site_detail = :site_detail,
            occurred_at = :occurred_at,
            lang = :lang,
            root_causes = :root_causes,
            actions = :actions,
            annotations_data = :annotations_data,
            image_main = :image_main,
            image_2 = :image_2,
            image_3 = :image_3,
            image1_caption = :image1_caption,
            image2_caption = :image2_caption,
            image3_caption = :image3_caption,
            image1_transform = :image1_transform,
            image2_transform = :image2_transform,
            image3_transform = :image3_transform,
            grid_layout = :grid_layout,
            grid_bitmap = :grid_bitmap,
            font_size_override = :font_size_override,
            layout_mode = :layout_mode,
            processing_status = 'pending',
            is_processing = 1,
            preview_status = 'pending',
            updated_at = NOW()
            WHERE id = :id";
        
        // Process grid_bitmap before database update
        $gridBitmapValue = trim((string)($data['grid_bitmap'] ?? ''));
        $gridBitmapFilename = '';

        if ($gridBitmapValue === '') {
            $gridBitmapFilename = '';
        } elseif (strncmp($gridBitmapValue, 'temp_grid_', 10) === 0) {
            $tempFilename = basename($gridBitmapValue);
            $tempPath = __DIR__ . '/../../uploads/temp/' . $tempFilename;
            $gridsDir = __DIR__ . '/../../uploads/grids/';

            if (!is_dir($gridsDir)) {
                @mkdir($gridsDir, 0755, true);
            }

            if (is_file($tempPath)) {
                $tmpExt = strtolower((string)pathinfo($tempFilename, PATHINFO_EXTENSION));
                $allowedExts = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

                if (!in_array($tmpExt, $allowedExts, true)) {
                    $tmpExt = 'png';
                }

                if ($tmpExt === 'jpeg') {
                    $tmpExt = 'jpg';
                }

                $permanentFilename = 'grid_' . $flashId . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $tmpExt;
                $permanentPath = $gridsDir . $permanentFilename;

                if (@rename($tempPath, $permanentPath)) {
                    @chmod($permanentPath, 0644);
                    $gridBitmapFilename = $permanentFilename;
                } elseif (@copy($tempPath, $permanentPath)) {
                    @chmod($permanentPath, 0644);
                    @unlink($tempPath);
                    $gridBitmapFilename = $permanentFilename;
                } else {
                    error_log("FlashSaveService: Failed to move temp grid bitmap for flash {$flashId}: {$tempPath}");
                    $gridBitmapFilename = '';
                }
            } else {
                error_log("FlashSaveService: Temp grid bitmap file not found for flash {$flashId}: {$tempPath}");
                $gridBitmapFilename = '';
            }
        } else {
            $gridBitmapFilename = sf_save_grid_bitmap_to_file($gridBitmapValue, $flashId);
        }

        // Resolve image values: prefer library image selection over existing, if set
        $imageMain = trim((string)($data['library_image_1'] ?? ''));
        if ($imageMain === '') {
            $imageMain = trim((string)($data['existing_image_1'] ?? ''));
        }
        $image2 = trim((string)($data['library_image_2'] ?? ''));
        if ($image2 === '') {
            $image2 = trim((string)($data['existing_image_2'] ?? ''));
        }
        $image3 = trim((string)($data['library_image_3'] ?? ''));
        if ($image3 === '') {
            $image3 = trim((string)($data['existing_image_3'] ?? ''));
        }

        $stmt = $pdo->prepare($sql);
        $success = $stmt->execute([
            ':title' => trim((string)($data['title'] ?? '')),
            ':title_short' => trim((string)($data['title_short'] ?? '')),
            ':summary' => trim((string)($data['summary'] ?? '')),
            ':description' => trim((string)($data['description'] ?? '')),
            ':type' => $newType,
            ':original_type' => $originalType,
            ':site' => trim((string)($data['site'] ?? '')),
            ':site_detail' => trim((string)($data['site_detail'] ?? '')),
            ':occurred_at' => $occurredAt,
            ':lang' => trim((string)($data['lang'] ?? 'fi')),
            ':root_causes' => trim((string)($data['root_causes'] ?? '')),
            ':actions' => trim((string)($data['actions'] ?? '')),
            ':annotations_data' => trim((string)($data['annotations_data'] ?? '[]')),
            ':image_main' => $imageMain,
            ':image_2'    => $image2,
            ':image_3'    => $image3,
            ':image1_caption' => trim((string)($data['image1_caption'] ?? '')),
            ':image2_caption' => trim((string)($data['image2_caption'] ?? '')),
            ':image3_caption' => trim((string)($data['image3_caption'] ?? '')),
            ':image1_transform' => trim((string)($data['image1_transform'] ?? '')),
            ':image2_transform' => trim((string)($data['image2_transform'] ?? '')),
            ':image3_transform' => trim((string)($data['image3_transform'] ?? '')),
            ':grid_layout' => trim((string)($data['grid_layout'] ?? 'grid-1')),
            ':grid_bitmap' => $gridBitmapFilename,
            ':font_size_override' => !empty($data['font_size_override']) ? trim((string)$data['font_size_override']) : null,
            ':layout_mode' => !empty($data['layout_mode']) ? trim((string)$data['layout_mode']) : 'auto',
            ':id' => $flashId,
        ]);
        
        if (!$success) {
            throw new Exception('Failed to update flash in database');
        }
    }
    
    /**
     * Create job record in sf_jobs table for worker to process flash image
     *
     * @param int $flashId Flash ID
     * @param array $data Form data (POST data)
     * @return void
     */
    public function createJobFile(int $flashId, array $data): void
    {
        // Create job data for worker
        $jobData = [
            'post' => $data,
            'files' => [] // Edit mode doesn't upload new files, just updates previews
        ];

        try {
            FlashImageService::upsertJob($flashId, $jobData);
        } catch (Throwable $e) {
            error_log("FlashSaveService: Failed to create job record for flash {$flashId}: " . $e->getMessage());
        }
    }
    
    /**
     * Trigger worker execution for image processing
     * Tries background execution first, falls back to inline
     * 
     * @param int $flashId Flash ID to process
     * @return void
     */
    private function triggerWorker(int $flashId): void
    {
        $workerPath = __DIR__ . '/../api/process_flash_worker.php';
        
        if (!file_exists($workerPath)) {
            error_log("FlashSaveService: Worker script not found");
            return;
        }
        
        // Try background execution first (non-blocking)
        if (function_exists('shell_exec')) {
            $phpBinary = PHP_BINARY ?: 'php';
            $cmd = escapeshellarg($phpBinary) . " " . escapeshellarg($workerPath) . " " . escapeshellarg((string)$flashId) . " > /dev/null 2>&1 &";
            
            $result = @shell_exec($cmd);
            if ($result !== null) {
                sf_app_log("[FlashSaveService] Worker triggered in background for flash {$flashId}");
                return;
            }
        }
        
        // Fallback: inline execution (blocking but reliable)
        sf_app_log("[FlashSaveService] Falling back to inline worker execution for flash {$flashId}");
        
        if (!defined('SF_ALLOW_WEB_WORKER')) {
            define('SF_ALLOW_WEB_WORKER', true);
        }
        
        // Save and restore GET to avoid side effects
        $originalGet = $_GET;
        $_GET['flash_id'] = $flashId;
        
        try {
            require $workerPath;
        } catch (Throwable $e) {
            error_log("FlashSaveService: Inline worker failed: " . $e->getMessage());
        }
        
        $_GET = $originalGet;
    }
}
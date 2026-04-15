<?php
/**
 * Feedback Page
 * 
 * Displays user feedback submissions.
 * - Regular users see their own feedback
 * - Admins see all feedback with management capabilities
 */

declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../app/includes/auth.php';
require_once __DIR__ . '/../../assets/lib/Database.php';
require_once __DIR__ . '/../../assets/lib/sf_terms.php';

// Require authentication
sf_require_login();

// Initialize Database
Database::setConfig($config['db'] ?? []);

$user = sf_current_user();
$isAdmin = $user && (int)$user['role_id'] === 1;
$userId = (int)$user['id'];
$uiLang = $_SESSION['ui_lang'] ?? 'fi';
$base = rtrim($config['base_url'] ?? '', '/');

// Get filters
$filterStatus = $_GET['status'] ?? '';
$filterCategory = $_GET['category'] ?? '';

// Build query
$db = Database::getInstance();
$whereClauses = [];
$params = [];

// Filter out merged feedbacks (show only non-merged or those merged into others)
$whereClauses[] = "f.merged_into_id IS NULL";

// Non-admins only see their own feedback
if (!$isAdmin) {
    $whereClauses[] = "f.reported_by = :user_id";
    $params[':user_id'] = $userId;
}

// Status filter
if ($filterStatus && in_array($filterStatus, ['new', 'in_progress', 'resolved', 'rejected'], true)) {
    $whereClauses[] = "f.status = :status";
    $params[':status'] = $filterStatus;
}

// Category filter
if ($filterCategory && in_array($filterCategory, ['critical', 'visual', 'improvement', 'bug', 'other'], true)) {
    $whereClauses[] = "f.category = :category";
    $params[':category'] = $filterCategory;
}

$whereClause = empty($whereClauses) ? '' : 'WHERE ' . implode(' AND ', $whereClauses);

$sql = "SELECT f.*, 
               u1.first_name as reporter_first_name, u1.last_name as reporter_last_name,
               u2.first_name as resolver_first_name, u2.last_name as resolver_last_name,
               (SELECT COUNT(*) FROM sf_feedback_comments WHERE feedback_id = f.id) as comment_count,
               (SELECT COUNT(*) FROM sf_feedback WHERE merged_into_id = f.id) as merged_count
        FROM sf_feedback f
        LEFT JOIN sf_users u1 ON f.reported_by = u1.id
        LEFT JOIN sf_users u2 ON f.resolved_by = u2.id
        $whereClause
        ORDER BY f.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$feedbacks = $stmt->fetchAll();

// Fetch comments for all feedbacks
$feedbackIds = array_column($feedbacks, 'id');
$commentsMap = [];
if (!empty($feedbackIds)) {
    $placeholders = implode(',', array_fill(0, count($feedbackIds), '?'));
    $commentsQuery = "
        SELECT c.*, u.first_name, u.last_name 
        FROM sf_feedback_comments c
        LEFT JOIN sf_users u ON c.user_id = u.id
        WHERE c.feedback_id IN ($placeholders)
        ORDER BY c.created_at ASC
    ";
    $stmt = $db->prepare($commentsQuery);
    $stmt->execute($feedbackIds);
    $comments = $stmt->fetchAll();
    
    foreach ($comments as $comment) {
        $fid = (int)$comment['feedback_id'];
        if (!isset($commentsMap[$fid])) {
            $commentsMap[$fid] = [];
        }
        $commentsMap[$fid][] = $comment;
    }
}

// Category configs with icons and colors
$categoryConfig = [
    'critical' => ['icon' => 'alert-circle.svg', 'color' => '#dc2626', 'label_key' => 'feedback_category_critical'],
    'visual' => ['icon' => 'eye_icon.svg', 'color' => '#9333ea', 'label_key' => 'feedback_category_visual'],
    'improvement' => ['icon' => 'idea.svg', 'color' => '#2563eb', 'label_key' => 'feedback_category_improvement'],
    'bug' => ['icon' => 'error.svg', 'color' => '#ea580c', 'label_key' => 'feedback_category_bug'],
    'other' => ['icon' => 'file-text.svg', 'color' => '#6b7280', 'label_key' => 'feedback_category_other'],
];

// Status configs with colors
$statusConfig = [
    'new' => ['color' => '#059669', 'label_key' => 'feedback_status_new'],
    'in_progress' => ['color' => '#eab308', 'label_key' => 'feedback_status_in_progress'],
    'resolved' => ['color' => '#0aa907', 'label_key' => 'feedback_status_resolved'],
    'rejected' => ['color' => '#dc2626', 'label_key' => 'feedback_status_rejected'],
];
?>

<div class="sf-page-container">
    <div class="sf-page-header">
        <h1 class="sf-page-title"><?= htmlspecialchars(sf_term('feedback_title', $uiLang), ENT_QUOTES, 'UTF-8') ?></h1>
        <div class="sf-page-actions">
            <button type="button" class="sf-btn sf-btn-primary" id="btnNewFeedback">
                <img src="<?= $base ?>/assets/img/icons/feedback.svg" alt="" class="sf-btn-icon">
                <?= htmlspecialchars(sf_term('feedback_new', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>

    <?php if ($isAdmin): ?>
    <div class="sf-filters">
        <div class="sf-filter-group">
            <label for="filterStatus" class="sf-filter-label"><?= htmlspecialchars(sf_term('feedback_filter_status', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
            <select id="filterStatus" class="sf-filter-select">
                <option value=""><?= htmlspecialchars(sf_term('feedback_filter_all', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                <option value="new" <?= $filterStatus === 'new' ? 'selected' : '' ?>><?= htmlspecialchars(sf_term('feedback_status_new', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                <option value="in_progress" <?= $filterStatus === 'in_progress' ? 'selected' : '' ?>><?= htmlspecialchars(sf_term('feedback_status_in_progress', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                <option value="resolved" <?= $filterStatus === 'resolved' ? 'selected' : '' ?>><?= htmlspecialchars(sf_term('feedback_status_resolved', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                <option value="rejected" <?= $filterStatus === 'rejected' ? 'selected' : '' ?>><?= htmlspecialchars(sf_term('feedback_status_rejected', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
            </select>
        </div>
        
        <div class="sf-filter-group">
            <label for="filterCategory" class="sf-filter-label"><?= htmlspecialchars(sf_term('feedback_filter_category', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
            <select id="filterCategory" class="sf-filter-select">
                <option value=""><?= htmlspecialchars(sf_term('feedback_filter_all', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                <?php foreach ($categoryConfig as $catKey => $catData): ?>
                    <option value="<?= htmlspecialchars($catKey) ?>" <?= $filterCategory === $catKey ? 'selected' : '' ?>>
                        <?= htmlspecialchars(sf_term($catData['label_key'], $uiLang), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <?php endif; ?>

    <div class="sf-feedback-list">
        <?php if (empty($feedbacks)): ?>
            <div class="sf-feedback-empty">
                <p><?= htmlspecialchars(sf_term('feedback_no_results', $uiLang), ENT_QUOTES, 'UTF-8') ?></p>
            </div>
        <?php else: ?>
            <?php foreach ($feedbacks as $feedback): ?>
                <?php
                $category = $feedback['category'] ?? 'other';
                $status = $feedback['status'] ?? 'new';
                $catData = $categoryConfig[$category] ?? $categoryConfig['other'];
                $statusData = $statusConfig[$status] ?? $statusConfig['new'];
                $reporterName = trim(($feedback['reporter_first_name'] ?? '') . ' ' . ($feedback['reporter_last_name'] ?? ''));
                if (empty($reporterName)) $reporterName = 'Unknown';
                
                // Determine who can delete this feedback
                $canManage = $isAdmin;
                $canDelete = $isAdmin || ((int)$feedback['reported_by'] === $userId);
                
                // Get comments for this feedback
                $feedbackComments = $commentsMap[(int)$feedback['id']] ?? [];
                $commentCount = (int)($feedback['comment_count'] ?? 0);
                $mergedCount = (int)($feedback['merged_count'] ?? 0);
                ?>
                <div class="sf-content-card" id="feedback-<?= (int)$feedback['id'] ?>">
                    <div class="sf-feedback-card-header">
                        <div class="sf-feedback-card-badges">
                            <span class="sf-feedback-badge sf-feedback-badge-category" 
                                  style="background-color: <?= htmlspecialchars($catData['color']) ?>;">
                                <img src="<?= $base ?>/assets/img/icons/<?= htmlspecialchars($catData['icon']) ?>" alt="" class="sf-icon" aria-hidden="true">
                                <?= htmlspecialchars(sf_term($catData['label_key'], $uiLang), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <span class="sf-feedback-badge sf-feedback-badge-status<?= $status === 'new' ? ' sf-status-new-pulse' : '' ?>" 
                                  style="background-color: <?= htmlspecialchars($statusData['color']) ?>;">
                                <?php if ($status === 'in_progress'): ?>
                                    <span class="sf-spinner-icon" aria-hidden="true"></span>
                                <?php elseif ($status === 'resolved'): ?>
                                    <img src="<?= $base ?>/assets/img/icons/check.svg" alt="" class="sf-icon-orig" aria-hidden="true">
                                <?php endif; ?>
                                <?= htmlspecialchars(sf_term($statusData['label_key'], $uiLang), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </div>
                        <div class="sf-feedback-card-actions">
                            <?php if ($canManage): ?>
                                <button type="button" 
                                        class="sf-btn sf-btn-small sf-btn-secondary btn-manage-feedback" 
                                        data-feedback-id="<?= (int)$feedback['id'] ?>">
                                    <img src="<?= $base ?>/assets/img/icons/settings.svg" alt="" class="sf-icon-feedback" aria-hidden="true">
                                    <?= htmlspecialchars(sf_term('feedback_manage', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                                </button>
                                <button type="button" 
                                        class="sf-btn sf-btn-small sf-btn-secondary btn-merge-feedback" 
                                        data-feedback-id="<?= (int)$feedback['id'] ?>"
                                        data-feedback-title="<?= htmlspecialchars($feedback['title'], ENT_QUOTES, 'UTF-8') ?>">
                                    <img src="<?= $base ?>/assets/img/icons/link.svg" alt="" class="sf-icon-feedback" aria-hidden="true">
                                    <?= htmlspecialchars(sf_term('feedback_merge_action', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                                </button>
                            <?php endif; ?>
                            <?php if ($canDelete): ?>
                                <button type="button" 
                                        class="sf-btn sf-btn-small sf-btn-danger btn-delete-feedback" 
                                        data-feedback-id="<?= (int)$feedback['id'] ?>"
                                        data-feedback-title="<?= htmlspecialchars($feedback['title'], ENT_QUOTES, 'UTF-8') ?>">
                                    <img src="<?= $base ?>/assets/img/icons/delete.svg" alt="" class="sf-icon" aria-hidden="true">
                                    <?= htmlspecialchars(sf_term('feedback_delete', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <h3 class="sf-feedback-card-title"><?= htmlspecialchars($feedback['title'], ENT_QUOTES, 'UTF-8') ?></h3>
                    
                    <p class="sf-feedback-card-description"><?= nl2br(htmlspecialchars($feedback['description'], ENT_QUOTES, 'UTF-8')) ?></p>
                    
                    <div class="sf-feedback-card-meta">
                        <span><?= htmlspecialchars(sf_term('feedback_reported_by', $uiLang), ENT_QUOTES, 'UTF-8') ?>: 
                              <strong><?= htmlspecialchars($reporterName, ENT_QUOTES, 'UTF-8') ?></strong></span>
                        <span><?= htmlspecialchars(sf_term('feedback_created_at', $uiLang), ENT_QUOTES, 'UTF-8') ?>: 
                              <?= htmlspecialchars(date('Y-m-d H:i', strtotime($feedback['created_at'])), ENT_QUOTES, 'UTF-8') ?></span>
                        <?php if ($commentCount > 0): ?>
                        <span class="sf-feedback-comments-count" title="<?= htmlspecialchars(sf_term('feedback_comments', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
                            <img src="<?= $base ?>/assets/img/icons/comment.svg" alt="" class="sf-icon-feedback" aria-hidden="true">
                            <?= (int)$commentCount ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($mergedCount > 0): ?>
                    <div class="sf-feedback-merged">
                        <img src="<?= $base ?>/assets/img/icons/paperclip.svg" alt="" class="sf-icon" aria-hidden="true">
                        +<?= (int)$mergedCount ?> <?= htmlspecialchars(sf_term('feedback_merged_feedbacks', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <?php endif; ?>
                    
                    <?php if ($feedback['resolved_at']): ?>
                        <?php $resolverName = trim(($feedback['resolver_first_name'] ?? '') . ' ' . ($feedback['resolver_last_name'] ?? '')); ?>
                        <div class="sf-feedback-card-resolved">
                            <span><?= htmlspecialchars(sf_term('feedback_resolved_by', $uiLang), ENT_QUOTES, 'UTF-8') ?>: 
                                  <strong><?= htmlspecialchars($resolverName ?: 'Unknown', ENT_QUOTES, 'UTF-8') ?></strong></span>
                            <span><?= htmlspecialchars(sf_term('feedback_resolved_at', $uiLang), ENT_QUOTES, 'UTF-8') ?>: 
                                  <?= htmlspecialchars(date('Y-m-d H:i', strtotime($feedback['resolved_at'])), ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Comments section -->
                    <div class="sf-feedback-comments-section">
                        <button class="sf-feedback-comments-toggle" data-feedback-id="<?= (int)$feedback['id'] ?>">
                            <img src="<?= $base ?>/assets/img/icons/comment.svg" alt="" class="sf-icon-feedback" aria-hidden="true">
                            <?= htmlspecialchars(sf_term('feedback_comments', $uiLang), ENT_QUOTES, 'UTF-8') ?> (<?= (int)$commentCount ?>) 
                            <span class="sf-toggle-icon"><img src="<?= $base ?>/assets/img/icons/chevron-down.svg" alt="" class="sf-icon-sm" aria-hidden="true"></span>
                        </button>
                        
                        <div class="sf-feedback-comments-list" id="comments-<?= (int)$feedback['id'] ?>" style="display: none;">
                            <?php if (!empty($feedbackComments)): ?>
                                <?php foreach ($feedbackComments as $comment): ?>
                                    <?php 
                                    $commenterName = trim(($comment['first_name'] ?? '') . ' ' . ($comment['last_name'] ?? ''));
                                    if (empty($commenterName)) $commenterName = 'Unknown';
                                    $canDeleteComment = $isAdmin || ((int)$comment['user_id'] === $userId);
                                    ?>
                                    <div class="sf-feedback-comment" data-comment-id="<?= (int)$comment['id'] ?>">
                                        <div class="sf-feedback-comment-header">
                                            <span class="sf-feedback-comment-author"><?= htmlspecialchars($commenterName, ENT_QUOTES, 'UTF-8') ?></span>
                                            <span><?= htmlspecialchars(date('Y-m-d H:i', strtotime($comment['created_at'])), ENT_QUOTES, 'UTF-8') ?></span>
                                        </div>
                                        <div class="sf-feedback-comment-text"><?= nl2br(htmlspecialchars($comment['comment'], ENT_QUOTES, 'UTF-8')) ?></div>
                                        <?php if ($canDeleteComment): ?>
                                        <button class="sf-feedback-comment-delete" data-comment-id="<?= (int)$comment['id'] ?>" title="<?= htmlspecialchars(sf_term('feedback_comment_delete_confirm', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
                                            <img src="<?= $base ?>/assets/img/icons/delete.svg" alt="" class="sf-icon-feedback" aria-hidden="true">
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            
                            <!-- Add comment form -->
                            <div class="sf-feedback-comment-form">
                                <textarea placeholder="<?= htmlspecialchars(sf_term('feedback_comment_placeholder', $uiLang), ENT_QUOTES, 'UTF-8') ?>" maxlength="2000" data-feedback-id="<?= (int)$feedback['id'] ?>"></textarea>
                                <button class="sf-btn sf-btn-primary sf-btn-small sf-btn-send-comment" data-feedback-id="<?= (int)$feedback['id'] ?>">
                                    <?= htmlspecialchars(sf_term('feedback_comment_send', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- New Feedback Modal -->
<div id="modalNewFeedback" class="sf-modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="sf-modal-content">
        <div class="sf-modal-header">
            <h3><?= htmlspecialchars(sf_term('feedback_new', $uiLang), ENT_QUOTES, 'UTF-8') ?></h3>
            <button type="button" class="sf-modal-close-btn" data-modal-close aria-label="Close">×</button>
        </div>
        
        <form id="formNewFeedback" class="sf-modal-body">
            <?= sf_csrf_field() ?>
            
            <div class="sf-form-group">
                <label for="feedbackTitle"><?= htmlspecialchars(sf_term('feedback_title_label', $uiLang), ENT_QUOTES, 'UTF-8') ?> *</label>
                <input type="text" 
                       id="feedbackTitle" 
                       name="title" 
                       maxlength="255" 
                       required 
                       class="sf-form-input">
            </div>
            
            <div class="sf-form-group">
                <label for="feedbackCategory"><?= htmlspecialchars(sf_term('feedback_category_label', $uiLang), ENT_QUOTES, 'UTF-8') ?> *</label>
                <select id="feedbackCategory" name="category" required class="sf-form-input">
                    <?php foreach ($categoryConfig as $catKey => $catData): ?>
                        <option value="<?= htmlspecialchars($catKey) ?>">
                            <?= $catData['emoji'] ?> <?= htmlspecialchars(sf_term($catData['label_key'], $uiLang), ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="sf-form-group">
                <label for="feedbackDescription"><?= htmlspecialchars(sf_term('feedback_description_label', $uiLang), ENT_QUOTES, 'UTF-8') ?> *</label>
                <textarea id="feedbackDescription" 
                          name="description" 
                          rows="6" 
                          required 
                          class="sf-form-input"></textarea>
            </div>
        </form>
        
        <div class="sf-modal-actions">
            <button type="button" class="sf-btn sf-btn-secondary" data-modal-close>
                <?= htmlspecialchars(sf_term('feedback_cancel', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" class="sf-btn sf-btn-primary" id="btnSubmitFeedback">
                <?= htmlspecialchars(sf_term('feedback_submit', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>

<?php if ($isAdmin): ?>
<!-- Admin Manage Feedback Modal -->
<div id="modalManageFeedback" class="sf-modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="sf-modal-content">
        <div class="sf-modal-header">
            <h3><?= htmlspecialchars(sf_term('feedback_manage', $uiLang), ENT_QUOTES, 'UTF-8') ?></h3>
            <button type="button" class="sf-modal-close-btn" data-modal-close aria-label="Close">×</button>
        </div>
        
        <form id="formManageFeedback" class="sf-modal-body">
            <?= sf_csrf_field() ?>
            <input type="hidden" id="manageFeedbackId" name="feedback_id">
            
            <div class="sf-form-group">
                <label><?= htmlspecialchars(sf_term('feedback_title_label', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
                <p id="manageFeedbackTitle" class="sf-feedback-display-text"></p>
            </div>
            
            <div class="sf-form-group">
                <label><?= htmlspecialchars(sf_term('feedback_description_label', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
                <p id="manageFeedbackDescription" class="sf-feedback-display-text"></p>
            </div>
            
            <div class="sf-form-group">
                <label for="manageStatus"><?= htmlspecialchars(sf_term('feedback_update_status', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
                <select id="manageStatus" name="status" class="sf-form-input">
                    <option value="new"><?= htmlspecialchars(sf_term('feedback_status_new', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                    <option value="in_progress"><?= htmlspecialchars(sf_term('feedback_status_in_progress', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                    <option value="resolved"><?= htmlspecialchars(sf_term('feedback_status_resolved', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                    <option value="rejected"><?= htmlspecialchars(sf_term('feedback_status_rejected', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                </select>
            </div>
            
            <div class="sf-form-group">
                <label for="manageAdminNotes"><?= htmlspecialchars(sf_term('feedback_admin_notes_label', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
                <textarea id="manageAdminNotes" 
                          name="admin_notes" 
                          rows="4" 
                          class="sf-form-input"></textarea>
            </div>
        </form>
        
        <div class="sf-modal-actions">
            <button type="button" class="sf-btn sf-btn-secondary" data-modal-close>
                <?= htmlspecialchars(sf_term('feedback_cancel', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" class="sf-btn sf-btn-secondary" id="btnCreateUpdateFromFeedback"
                    title="<?= htmlspecialchars(sf_term('feedback_create_update', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
                <img src="<?= $base ?>/assets/img/icons/changelog_icon.svg" alt="" class="sf-btn-icon" aria-hidden="true">
                <?= htmlspecialchars(sf_term('feedback_create_update', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" class="sf-btn sf-btn-primary" id="btnSaveFeedback">
                <?= htmlspecialchars(sf_term('feedback_save', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>

<!-- Create Update from Feedback Modal -->
<div id="modalCreateUpdate" class="sf-modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="sf-modal-content" style="max-width:660px;">
        <div class="sf-modal-header">
            <h3><?= htmlspecialchars(sf_term('feedback_create_update', $uiLang), ENT_QUOTES, 'UTF-8') ?></h3>
            <button type="button" class="sf-modal-close-btn" data-modal-close aria-label="Close">×</button>
        </div>

        <form id="formCreateUpdate" class="sf-modal-body">
            <?= sf_csrf_field() ?>
            <input type="hidden" id="createUpdateFeedbackId" name="feedback_id" value="0">

            <!-- Language tabs -->
            <?php
            $termsConfig   = sf_get_terms_config();
            $supportedLangs = $termsConfig['languages'] ?? ['fi', 'sv', 'en', 'it', 'el'];
            $langLabels = [
                'fi' => 'Suomi (FI)', 'sv' => 'Svenska (SV)', 'en' => 'English (EN)',
                'it' => 'Italiano (IT)', 'el' => 'Ελληνικά (EL)',
            ];
            ?>
            <div style="display:flex;gap:4px;margin-bottom:16px;flex-wrap:wrap;">
                <?php foreach ($supportedLangs as $idx => $lang): ?>
                    <button type="button"
                            class="sf-btn sf-btn-small <?= $idx === 0 ? 'sf-btn-primary' : 'sf-btn-secondary' ?> sf-cu-lang-tab"
                            data-lang="<?= htmlspecialchars($lang) ?>">
                        <?= htmlspecialchars($langLabels[$lang] ?? strtoupper($lang)) ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <?php foreach ($supportedLangs as $idx => $lang): ?>
                <div class="sf-cu-lang-panel" data-lang="<?= htmlspecialchars($lang) ?>"
                     <?= $idx !== 0 ? 'style="display:none;"' : '' ?>>
                    <div class="sf-form-group">
                        <label for="cuTitle_<?= $lang ?>">
                            <?= htmlspecialchars(sf_term('updates_field_title', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                            (<?= strtoupper($lang) ?>)
                        </label>
                        <input type="text" id="cuTitle_<?= $lang ?>" class="sf-form-input sf-cu-title" data-lang="<?= $lang ?>">
                    </div>
                    <div class="sf-form-group">
                        <label for="cuContent_<?= $lang ?>">
                            <?= htmlspecialchars(sf_term('updates_field_content', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                            (<?= strtoupper($lang) ?>)
                        </label>
                        <textarea id="cuContent_<?= $lang ?>" rows="4" class="sf-form-input sf-cu-content" data-lang="<?= $lang ?>"></textarea>
                    </div>
                </div>
            <?php endforeach; ?>

            <div class="sf-form-group" style="margin-top:12px;">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer;">
                    <input type="checkbox" id="cuIsPublished" name="is_published" value="1"
                           style="width:18px;height:18px;cursor:pointer;">
                    <span><?= htmlspecialchars(sf_term('updates_field_is_published', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
                </label>
            </div>
        </form>

        <div class="sf-modal-actions">
            <button type="button" class="sf-btn sf-btn-secondary" data-modal-close>
                <?= htmlspecialchars(sf_term('feedback_cancel', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" class="sf-btn sf-btn-primary" id="btnSaveCreateUpdate">
                <?= htmlspecialchars(sf_term('feedback_save', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>

<!-- Admin Merge Feedback Modal -->
<div id="modalMergeFeedback" class="sf-modal hidden" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="sf-modal-content">
        <div class="sf-modal-header">
            <h3><?= htmlspecialchars(sf_term('feedback_merge_action', $uiLang), ENT_QUOTES, 'UTF-8') ?></h3>
            <button type="button" class="sf-modal-close-btn" data-modal-close aria-label="Close">×</button>
        </div>
        
        <form id="formMergeFeedback" class="sf-modal-body">
            <?= sf_csrf_field() ?>
            <input type="hidden" id="mergeSourceId" name="source_id">
            
            <div class="sf-form-group">
                <label><?= htmlspecialchars(sf_term('feedback_title_label', $uiLang), ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars(sf_term('feedback_merge_action', $uiLang), ENT_QUOTES, 'UTF-8') ?>)</label>
                <p id="mergeSourceTitle" class="sf-feedback-display-text"></p>
            </div>
            
            <div class="sf-form-group">
                <label for="mergeTargetId"><?= htmlspecialchars(sf_term('feedback_merged_into', $uiLang), ENT_QUOTES, 'UTF-8') ?> *</label>
                <select id="mergeTargetId" name="target_id" required class="sf-form-input">
                    <option value="">-- <?= htmlspecialchars(sf_term('feedback_select_target', $uiLang), ENT_QUOTES, 'UTF-8') ?> --</option>
                </select>
                <small style="color: #64748b; display: block; margin-top: 0.25rem;">
                    <?= htmlspecialchars(sf_term('feedback_merge_helper_text', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                </small>
            </div>
        </form>
        
        <div class="sf-modal-actions">
            <button type="button" class="sf-btn sf-btn-secondary" data-modal-close>
                <?= htmlspecialchars(sf_term('feedback_cancel', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" class="sf-btn sf-btn-primary" id="btnMergeFeedback">
                <?= htmlspecialchars(sf_term('feedback_merge_action', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
(function() {
    const BASE_URL = <?= json_encode($base, JSON_UNESCAPED_SLASHES) ?>;
    const CSRF_TOKEN = <?= json_encode(sf_csrf_token()) ?>;
    const IS_ADMIN = <?= json_encode($isAdmin) ?>;
    const FEEDBACK_DATA = <?= json_encode($feedbacks) ?>;
    
    // Translations
    const i18n = {
        deleteConfirm: <?= json_encode(sf_term('feedback_delete_confirm', $uiLang)) ?>,
        deletedSuccess: <?= json_encode(sf_term('feedback_deleted_success', $uiLang)) ?>,
        deleteError: <?= json_encode(sf_term('feedback_delete_error', $uiLang)) ?>,
        networkError: <?= json_encode(sf_term('feedback_network_error', $uiLang)) ?>,
        error: <?= json_encode(sf_term('feedback_error_title_required', $uiLang)) ?>,
        commentEmpty: <?= json_encode(sf_term('feedback_comment_empty', $uiLang)) ?>,
        commentAdded: <?= json_encode(sf_term('feedback_comment_added', $uiLang)) ?>,
        commentAddError: <?= json_encode(sf_term('feedback_comment_add_error', $uiLang)) ?>,
        commentDeleted: <?= json_encode(sf_term('feedback_comment_deleted', $uiLang)) ?>,
        commentDeleteError: <?= json_encode(sf_term('feedback_comment_delete_error', $uiLang)) ?>,
        mergeTargetRequired: <?= json_encode(sf_term('feedback_merge_target_required', $uiLang)) ?>,
        mergeSuccess: <?= json_encode(sf_term('feedback_merge_success', $uiLang)) ?>,
        mergeError: <?= json_encode(sf_term('feedback_merge_error', $uiLang)) ?>,
        selectTarget: <?= json_encode(sf_term('feedback_select_target', $uiLang)) ?>
    };
    
    // Modal handling
    function openModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.remove('hidden');
            modal.setAttribute('aria-hidden', 'false');
            document.body.style.overflow = 'hidden';
        }
    }
    
    function closeModal(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.add('hidden');
            modal.setAttribute('aria-hidden', 'true');
            document.body.style.overflow = '';
        }
    }
    
    // Setup modal close handlers
    document.querySelectorAll('[data-modal-close]').forEach(btn => {
        btn.addEventListener('click', function() {
            const modal = this.closest('.sf-modal');
            if (modal) {
                modal.classList.add('hidden');
                modal.setAttribute('aria-hidden', 'true');
                document.body.style.overflow = '';
            }
        });
    });
    
    // New feedback button
    document.getElementById('btnNewFeedback')?.addEventListener('click', function() {
        document.getElementById('formNewFeedback')?.reset();
        openModal('modalNewFeedback');
    });
    
    // Submit new feedback
    document.getElementById('btnSubmitFeedback')?.addEventListener('click', async function() {
        const form = document.getElementById('formNewFeedback');
        const formData = new FormData(form);
        
        try {
            const response = await fetch(BASE_URL + '/app/api/feedback_create.php', {
                method: 'POST',
                headers: {
                    'X-CSRF-Token': CSRF_TOKEN
                },
                body: formData
            });
            
            const data = await response.json();
            
            if (data.ok) {
                if (typeof window.sfToast === 'function') {
                    window.sfToast('success', <?= json_encode(sf_term('feedback_created_success', $uiLang)) ?>);
                }
                closeModal('modalNewFeedback');
                setTimeout(() => window.location.reload(), 500);
            } else {
                if (typeof window.sfToast === 'function') {
                    window.sfToast('danger', data.error || 'Error creating feedback');
                } else {
                    alert(data.error || 'Error creating feedback');
                }
            }
        } catch (error) {
            console.error('Error:', error);
            if (typeof window.sfToast === 'function') {
                window.sfToast('danger', 'Network error');
            } else {
                alert('Network error');
            }
        }
    });
    
    // Admin: Manage feedback buttons
    if (IS_ADMIN) {
        document.querySelectorAll('.btn-manage-feedback').forEach(btn => {
            btn.addEventListener('click', function() {
                const feedbackId = parseInt(this.dataset.feedbackId);
                const feedback = FEEDBACK_DATA.find(f => f.id == feedbackId);
                
                if (feedback) {
                    document.getElementById('manageFeedbackId').value = feedback.id;
                    document.getElementById('manageFeedbackTitle').textContent = feedback.title;
                    document.getElementById('manageFeedbackDescription').textContent = feedback.description;
                    document.getElementById('manageStatus').value = feedback.status;
                    document.getElementById('manageAdminNotes').value = feedback.admin_notes || '';
                    openModal('modalManageFeedback');
                }
            });
        });
        
        // Save managed feedback
        document.getElementById('btnSaveFeedback')?.addEventListener('click', async function() {
            const form = document.getElementById('formManageFeedback');
            const formData = new FormData(form);
            
            try {
                const response = await fetch(BASE_URL + '/app/api/feedback_update.php', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-Token': CSRF_TOKEN
                    },
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.ok) {
                    if (typeof window.sfToast === 'function') {
                        window.sfToast('success', <?= json_encode(sf_term('feedback_updated_success', $uiLang)) ?>);
                    }
                    closeModal('modalManageFeedback');
                    setTimeout(() => window.location.reload(), 500);
                } else {
                    if (typeof window.sfToast === 'function') {
                        window.sfToast('danger', data.error || 'Error updating feedback');
                    } else {
                        alert(data.error || 'Error updating feedback');
                    }
                }
            } catch (error) {
                console.error('Error:', error);
                if (typeof window.sfToast === 'function') {
                    window.sfToast('danger', 'Network error');
                } else {
                    alert('Network error');
                }
            }
        });
    }
    
    // Create Update from Feedback
    if (IS_ADMIN) {
        const SUPPORTED_LANGS_FB = <?= json_encode($supportedLangs ?? ['fi', 'sv', 'en', 'it', 'el']) ?>;

        // Language tab switching for create-update modal
        document.querySelectorAll('.sf-cu-lang-tab').forEach(tab => {
            tab.addEventListener('click', function() {
                const lang = this.dataset.lang;
                document.querySelectorAll('.sf-cu-lang-tab').forEach(t => {
                    t.classList.remove('sf-btn-primary');
                    t.classList.add('sf-btn-secondary');
                });
                this.classList.add('sf-btn-primary');
                this.classList.remove('sf-btn-secondary');
                document.querySelectorAll('.sf-cu-lang-panel').forEach(p => {
                    p.style.display = p.dataset.lang === lang ? '' : 'none';
                });
            });
        });

        document.getElementById('btnCreateUpdateFromFeedback')?.addEventListener('click', function() {
            // Pre-fill FI title/content from feedback title/description
            const feedbackId = parseInt(document.getElementById('manageFeedbackId').value, 10);
            const feedbackTitle = document.getElementById('manageFeedbackTitle').textContent.trim();
            const feedbackDesc = document.getElementById('manageFeedbackDescription').textContent.trim();
            const adminNotes = document.getElementById('manageAdminNotes').value.trim();

            // Reset fields
            SUPPORTED_LANGS_FB.forEach(lang => {
                const t = document.getElementById('cuTitle_' + lang);
                const c = document.getElementById('cuContent_' + lang);
                if (t) t.value = '';
                if (c) c.value = '';
            });
            document.getElementById('cuIsPublished').checked = false;

            // Pre-fill Finnish fields
            const fiTitle = document.getElementById('cuTitle_fi');
            const fiContent = document.getElementById('cuContent_fi');
            if (fiTitle) fiTitle.value = feedbackTitle;
            if (fiContent) fiContent.value = adminNotes || feedbackDesc;

            document.getElementById('createUpdateFeedbackId').value = feedbackId;

            // Activate first tab
            const firstTab = document.querySelector('.sf-cu-lang-tab');
            if (firstTab) firstTab.click();

            closeModal('modalManageFeedback');
            openModal('modalCreateUpdate');
        });

        document.getElementById('btnSaveCreateUpdate')?.addEventListener('click', async function() {
            const feedbackId = parseInt(document.getElementById('createUpdateFeedbackId').value, 10) || 0;
            const isPublished = document.getElementById('cuIsPublished').checked ? 1 : 0;

            const translations = {};
            SUPPORTED_LANGS_FB.forEach(lang => {
                const title = (document.getElementById('cuTitle_' + lang)?.value || '').trim();
                const content = (document.getElementById('cuContent_' + lang)?.value || '').trim();
                if (title || content) {
                    translations[lang] = { title, content };
                }
            });

            const formData = new FormData();
            formData.append('csrf_token', CSRF_TOKEN);
            formData.append('feedback_id', feedbackId);
            formData.append('is_published', isPublished);
            formData.append('translations', JSON.stringify(translations));

            try {
                const response = await fetch(BASE_URL + '/app/api/changelog_create.php', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': CSRF_TOKEN },
                    body: formData
                });
                const data = await response.json();
                if (data.ok) {
                    if (typeof window.sfToast === 'function') {
                        window.sfToast('success', <?= json_encode(sf_term('admin_updates_saved', $uiLang)) ?>);
                    }
                    closeModal('modalCreateUpdate');
                } else {
                    if (typeof window.sfToast === 'function') {
                        window.sfToast('danger', data.error || <?= json_encode(sf_term('updates_error_save', $uiLang)) ?>);
                    } else {
                        alert(data.error || <?= json_encode(sf_term('updates_error_save', $uiLang)) ?>);
                    }
                }
            } catch (e) {
                console.error(e);
                if (typeof window.sfToast === 'function') {
                    window.sfToast('danger', <?= json_encode(sf_term('updates_error_save', $uiLang)) ?>);
                }
            }
        });
    }
    
    // Delete feedback (admin or owner)
    document.querySelectorAll('.btn-delete-feedback').forEach(btn => {
        btn.addEventListener('click', async function() {
            const feedbackId = this.dataset.feedbackId;
            const feedbackTitle = this.dataset.feedbackTitle;
            
            // Confirmation dialog
            const confirmMessage = i18n.deleteConfirm.replace('{title}', feedbackTitle);
            if (!confirm(confirmMessage)) {
                return;
            }
            
            // Send delete request
            const formData = new FormData();
            formData.append('feedback_id', feedbackId);
            formData.append('csrf_token', CSRF_TOKEN);
            
            try {
                const response = await fetch(BASE_URL + '/app/api/feedback_delete.php', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.ok) {
                    // Show success message (toast or alert)
                    if (typeof window.sfToast === 'function') {
                        window.sfToast('success', i18n.deletedSuccess);
                    } else {
                        alert(i18n.deletedSuccess);
                    }
                    // Reload page
                    setTimeout(() => window.location.reload(), 500);
                } else {
                    // Show error message
                    if (typeof window.sfToast === 'function') {
                        window.sfToast('danger', data.error || i18n.deleteError);
                    } else {
                        alert(data.error || i18n.deleteError);
                    }
                }
            } catch (error) {
                console.error('Delete error:', error);
                alert(i18n.networkError);
            }
        });
    });
    
    // Helper function to create icon element
    function createIcon(filename, className = 'sf-icon-sm') {
        const img = document.createElement('img');
        img.src = BASE_URL + '/assets/img/icons/' + filename;
        img.alt = '';
        img.className = className;
        img.setAttribute('aria-hidden', 'true');
        return img;
    }
    
    // Toggle comments section
    document.querySelectorAll('.sf-feedback-comments-toggle').forEach(btn => {
        btn.addEventListener('click', function() {
            const feedbackId = this.dataset.feedbackId;
            const commentsList = document.getElementById('comments-' + feedbackId);
            const icon = this.querySelector('.sf-toggle-icon');
            
            // Clear existing content safely
            while (icon.firstChild) {
                icon.removeChild(icon.firstChild);
            }
            
            if (commentsList.style.display === 'none') {
                commentsList.style.display = 'flex';
                icon.appendChild(createIcon('chevron-up.svg'));
            } else {
                commentsList.style.display = 'none';
                icon.appendChild(createIcon('chevron-down.svg'));
            }
        });
    });
    
    // Add comment
    document.querySelectorAll('.sf-btn-send-comment').forEach(btn => {
        btn.addEventListener('click', async function() {
            const feedbackId = this.dataset.feedbackId;
            const form = this.closest('.sf-feedback-comment-form');
            const textarea = form.querySelector('textarea');
            const comment = textarea.value.trim();
            
            if (!comment) {
                if (typeof window.sfToast === 'function') {
                    window.sfToast('danger', i18n.commentEmpty);
                } else {
                    alert(i18n.commentEmpty);
                }
                return;
            }
            
            const formData = new FormData();
            formData.append('feedback_id', feedbackId);
            formData.append('comment', comment);
            formData.append('csrf_token', CSRF_TOKEN);
            
            try {
                const response = await fetch(BASE_URL + '/app/api/feedback_comment_add.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                
                if (data.ok) {
                    if (typeof window.sfToast === 'function') {
                        window.sfToast('success', i18n.commentAdded);
                    }
                    // Reload to show new comment
                    setTimeout(() => window.location.reload(), 500);
                } else {
                    if (typeof window.sfToast === 'function') {
                        window.sfToast('danger', data.error || i18n.commentAddError);
                    } else {
                        alert(data.error || i18n.commentAddError);
                    }
                }
            } catch (e) {
                console.error('Error:', e);
                if (typeof window.sfToast === 'function') {
                    window.sfToast('danger', i18n.networkError);
                } else {
                    alert(i18n.networkError);
                }
            }
        });
    });
    
    // Delete comment
    document.querySelectorAll('.sf-feedback-comment-delete').forEach(btn => {
        btn.addEventListener('click', async function() {
            const commentId = this.dataset.commentId;
            
            if (!confirm(<?= json_encode(sf_term('feedback_comment_delete_confirm', $uiLang)) ?>)) {
                return;
            }
            
            const formData = new FormData();
            formData.append('comment_id', commentId);
            formData.append('csrf_token', CSRF_TOKEN);
            
            try {
                const response = await fetch(BASE_URL + '/app/api/feedback_comment_delete.php', {
                    method: 'POST',
                    body: formData
                });
                const data = await response.json();
                
                if (data.ok) {
                    if (typeof window.sfToast === 'function') {
                        window.sfToast('success', i18n.commentDeleted);
                    }
                    // Reload to update comment count
                    setTimeout(() => window.location.reload(), 500);
                } else {
                    if (typeof window.sfToast === 'function') {
                        window.sfToast('danger', data.error || i18n.commentDeleteError);
                    } else {
                        alert(data.error || i18n.commentDeleteError);
                    }
                }
            } catch (e) {
                console.error('Error:', e);
                if (typeof window.sfToast === 'function') {
                    window.sfToast('danger', i18n.networkError);
                } else {
                    alert(i18n.networkError);
                }
            }
        });
    });
    
    // Admin filters
    if (IS_ADMIN) {
        const filterStatus = document.getElementById('filterStatus');
        const filterCategory = document.getElementById('filterCategory');
        
        if (filterStatus) {
            filterStatus.addEventListener('change', function() {
                const params = new URLSearchParams(window.location.search);
                if (this.value) {
                    params.set('status', this.value);
                } else {
                    params.delete('status');
                }
                window.location.search = params.toString();
            });
        }
        
        if (filterCategory) {
            filterCategory.addEventListener('change', function() {
                const params = new URLSearchParams(window.location.search);
                if (this.value) {
                    params.set('category', this.value);
                } else {
                    params.delete('category');
                }
                window.location.search = params.toString();
            });
        }
        
        // Admin: Merge feedback button
        document.querySelectorAll('.btn-merge-feedback').forEach(btn => {
            btn.addEventListener('click', function() {
                const sourceId = parseInt(this.dataset.feedbackId);
                const sourceTitle = this.dataset.feedbackTitle;
                
                document.getElementById('mergeSourceId').value = sourceId;
                document.getElementById('mergeSourceTitle').textContent = sourceTitle;
                
                // Populate target select with other feedbacks
                const targetSelect = document.getElementById('mergeTargetId');
                targetSelect.innerHTML = '<option value="">-- ' + i18n.selectTarget + ' --</option>';
                
                FEEDBACK_DATA.forEach(feedback => {
                    if (feedback.id !== sourceId) {
                        const option = document.createElement('option');
                        option.value = feedback.id;
                        option.textContent = '#' + feedback.id + ' - ' + feedback.title;
                        targetSelect.appendChild(option);
                    }
                });
                
                openModal('modalMergeFeedback');
            });
        });
        
        // Save merged feedback
        document.getElementById('btnMergeFeedback')?.addEventListener('click', async function() {
            const form = document.getElementById('formMergeFeedback');
            const formData = new FormData(form);
            
            if (!formData.get('target_id')) {
                if (typeof window.sfToast === 'function') {
                    window.sfToast('danger', i18n.mergeTargetRequired);
                } else {
                    alert(i18n.mergeTargetRequired);
                }
                return;
            }
            
            try {
                const response = await fetch(BASE_URL + '/app/api/feedback_merge.php', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-Token': CSRF_TOKEN
                    },
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.ok) {
                    if (typeof window.sfToast === 'function') {
                        window.sfToast('success', i18n.mergeSuccess);
                    }
                    closeModal('modalMergeFeedback');
                    setTimeout(() => window.location.reload(), 500);
                } else {
                    if (typeof window.sfToast === 'function') {
                        window.sfToast('danger', data.error || i18n.mergeError);
                    } else {
                        alert(data.error || i18n.mergeError);
                    }
                }
            } catch (error) {
                console.error('Error:', error);
                if (typeof window.sfToast === 'function') {
                    window.sfToast('danger', i18n.networkError);
                } else {
                    alert(i18n.networkError);
                }
            }
        });
    }
})();
</script>

<style>
/* Icon helper classes */
.sf-icon,
.sf-icon-feedback,
.sf-icon-orig {
    width: 1.2em;
    height: 1.2em;
    margin-right: 5px;
    vertical-align: middle;
    display: inline-block;
}

/* Default icon filter (white) */
.sf-icon {
    filter: brightness(0) invert(1);
}
.sf-icon-orig {
    filter: none;
}
/* Feedback icon filter (original color) */
.sf-icon-feedback {
    filter: brightness(0) invert(0);
}

.sf-icon-sm {
    width: 0.75em;
    height: 0.75em;
    vertical-align: middle;
    display: inline-block;
}

/* Pseudo-element icon base styles */
.sf-icon-before::before {
    content: '';
    display: inline-block;
    background-size: contain;
    background-repeat: no-repeat;
    background-position: center;
}

/* Filter select styles - modernized with dropdown arrow */
.sf-filter-select {
    min-width: 180px;
    padding: 0.5rem 2rem 0.5rem 0.75rem;
    background: #ffffff;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 0.875rem;
    font-weight: 500;
    color: #374151;
    cursor: pointer;
    transition: all 0.2s ease;
    appearance: none;
    background-image: url("data:image/svg+xml,%3Csvg width='12' height='8' viewBox='0 0 12 8' fill='none' xmlns='http://www.w3.org/2000/svg'%3E%3Cpath d='M1 1.5L6 6.5L11 1.5' stroke='%236b7280' stroke-width='2' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E");
    background-repeat: no-repeat;
    background-position: right 0.75rem center;
    background-size: 12px;
}

.sf-filter-select:hover {
    border-color: #9ca3af;
    background-color: #fafafa;
}

.sf-filter-select:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

/* Form input styles for modals */
.sf-form-input {
    width: 100%;
    padding: 0.5rem 0.75rem;
    border: 1px solid #d1d5db;
    border-radius: 8px;
    font-size: 1rem;
    background: white;
    transition: border-color 0.15s;
}

.sf-form-input:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
}

textarea.sf-form-input {
    resize: vertical;
    font-family: inherit;
}

/* Form group styles */
.sf-form-group {
    margin-bottom: 1.5rem;
}

.sf-form-group label {
    display: block;
    margin-bottom: 0.5rem;
    font-weight: 500;
    color: #334155;
}

/* Feedback card header */
.sf-feedback-card-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 1rem;
    gap: 1rem;
    flex-wrap: wrap;
}

.sf-feedback-card-badges {
    display: flex;
    gap: 0.5rem;
    flex-wrap: wrap;
    align-items: center;
}

.sf-feedback-card-actions {
    display: flex;
    gap: 0.5rem;
    align-items: center;
    margin-left: auto;
}

.sf-feedback-badge {
    display: inline-flex;
    align-items: center;
    padding: 0.375rem 0.875rem;
    border-radius: 9999px;
    font-size: 0.8125rem;
    font-weight: 600;
    color: white;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1);
    transition: all 0.2s ease;
}

.sf-feedback-badge:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 5px rgba(0, 0, 0, 0.15);
}

/* Status badge animations */
@keyframes sf-pulse {
    0%, 100% {
        opacity: 1;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1), 0 0 0 0 rgba(5, 150, 105, 0.7);
    }
    50% {
        opacity: 0.9;
        box-shadow: 0 1px 3px rgba(0, 0, 0, 0.1), 0 0 0 8px rgba(5, 150, 105, 0);
    }
}

@keyframes sf-spin {
    from {
        transform: rotate(0deg);
    }
    to {
        transform: rotate(360deg);
    }
}

.sf-status-new-pulse {
    animation: sf-pulse 2s ease-in-out infinite;
}

.sf-spinner-icon {
    display: inline-block;
    width: 1.2em;
    height: 1.2em;
    margin-right: 0.375rem;
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-top-color: white;
    border-radius: 50%;
    animation: sf-spin 0.8s linear infinite;
}

.sf-feedback-badge .sf-icon {
    width: 1.2em;
    height: 1.2em;
    margin-right: 0.375rem;
    vertical-align: middle;
}

.sf-feedback-card-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: #0f172a;
    margin: 0 0 0.75rem 0;
}

.sf-feedback-card-description {
    color: #475569;
    line-height: 1.6;
    margin: 0 0 1rem 0;
}

.sf-feedback-card-meta {
    display: flex;
    gap: 1rem;
    font-size: 0.8125rem;
    color: #64748b;
    flex-wrap: wrap;
    align-items: center;
    padding: 0.5rem 0;
}

.sf-feedback-card-meta > span {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
}

.sf-feedback-card-meta > span::before {
    content: '';
    width: 0.875rem;
    height: 0.875rem;
    display: inline-block;
    background-size: contain;
    background-repeat: no-repeat;
    background-position: center;
}

.sf-feedback-card-meta > span:first-child::before {
    background-image: url('<?= $base ?>/assets/img/icons/user.svg');
}

.sf-feedback-card-meta > span:nth-child(2)::before {
    background-image: url('<?= $base ?>/assets/img/icons/calendar.svg');
}

.sf-feedback-card-resolved {
    display: flex;
    gap: 1rem;
    font-size: 0.8125rem;
    margin-top: 0.75rem;
    padding: 0.75rem 1rem;
    background: linear-gradient(135deg, #d1fae5 0%, #ecfdf5 100%);
    border: 1px solid #a7f3d0;
    border-radius: 0.5rem;
    color: #065f46;
    flex-wrap: wrap;
    align-items: center;
    box-shadow: 0 1px 2px rgba(5, 150, 105, 0.05);
}

.sf-feedback-card-resolved > span {
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
}

.sf-feedback-card-resolved > span::before {
    content: '';
    width: 0.875rem;
    height: 0.875rem;
    display: inline-block;
    background-size: contain;
    background-repeat: no-repeat;
    background-position: center;
}

.sf-feedback-card-resolved > span:first-child::before {
    background-image: url('<?= $base ?>/assets/img/icons/check.svg');
}

.sf-feedback-card-resolved > span:nth-child(2)::before {
    background-image: url('<?= $base ?>/assets/img/icons/calendar.svg');
}

.sf-feedback-empty {
    text-align: center;
    padding: 3rem 1rem;
    color: #64748b;
}

.sf-feedback-display-text {
    background: #f8fafc;
    padding: 0.75rem;
    border-radius: 0.375rem;
    color: #475569;
    white-space: pre-wrap;
}

/* Comments section styles */
.sf-feedback-comments-section {
    margin-top: 1rem;
    border-top: 1px solid #e2e8f0;
    padding-top: 1rem;
}

.sf-feedback-comments-toggle {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    color: #475569;
    cursor: pointer;
    font-size: 0.875rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.625rem 1rem;
    transition: all 0.2s ease;
    width: 100%;
    justify-content: flex-start;
    border-radius: 0.5rem;
    font-weight: 500;
}

.sf-feedback-comments-toggle:hover {
    background: #e0e7ff;
    border-color: #c7d2fe;
    color: #4338ca;
}

.sf-toggle-icon {
    margin-left: 0.25rem;
}

.sf-feedback-comments-list {
    margin-top: 1rem;
    display: flex;
    flex-direction: column;
    gap: 0.75rem;
}

.sf-feedback-comment {
    background: linear-gradient(135deg, #f8fafc 0%, #f1f5f9 100%);
    border: 1px solid #e2e8f0;
    border-radius: 0.75rem;
    padding: 1rem;
    position: relative;
    transition: all 0.2s ease;
}

.sf-feedback-comment:hover {
    border-color: #cbd5e1;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.05);
}

.sf-feedback-comment-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 0.625rem;
    padding-bottom: 0.5rem;
    border-bottom: 1px solid #e2e8f0;
}

.sf-feedback-comment-author {
    font-weight: 600;
    color: #1e293b;
    font-size: 0.875rem;
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
}

.sf-feedback-comment-author::before {
    content: '';
    width: 0.875rem;
    height: 0.875rem;
    display: inline-block;
    background-image: url('<?= $base ?>/assets/img/icons/message-square.svg');
    background-size: contain;
    background-repeat: no-repeat;
    background-position: center;
}

.sf-feedback-comment-header > span:last-child {
    font-size: 0.75rem;
    color: #94a3b8;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
}

.sf-feedback-comment-header > span:last-child::before {
    content: '';
    width: 0.75rem;
    height: 0.75rem;
    display: inline-block;
    background-image: url('<?= $base ?>/assets/img/icons/clock.svg');
    background-size: contain;
    background-repeat: no-repeat;
    background-position: center;
}

.sf-feedback-comment-text {
    color: #334155;
    line-height: 1.5;
    font-size: 0.875rem;
}

.sf-feedback-comment-delete {
    position: absolute;
    top: 0.5rem;
    right: 0.5rem;
    background: none;
    border: none;
    color: #94a3b8;
    cursor: pointer;
    opacity: 0;
    transition: opacity 0.2s, color 0.2s;
    font-size: 1rem;
    padding: 0.25rem;
}

.sf-feedback-comment:hover .sf-feedback-comment-delete {
    opacity: 1;
}

.sf-feedback-comment-delete:hover {
    color: #ef4444;
}

.sf-feedback-comment-form {
    display: flex;
    gap: 0.75rem;
    margin-top: 1rem;
    align-items: flex-end;
    background: #ffffff;
    padding: 0.75rem;
    border-radius: 0.75rem;
    border: 1px solid #e2e8f0;
}

.sf-feedback-comment-form textarea {
    flex: 1;
    resize: none;
    border: 1px solid #d1d5db;
    border-radius: 0.5rem;
    padding: 0.625rem 0.875rem;
    font-size: 0.875rem;
    min-height: 60px;
    transition: all 0.2s ease;
    font-family: inherit;
    background: #fafafa;
}

.sf-feedback-comment-form textarea:focus {
    outline: none;
    border-color: #3b82f6;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.1);
    background: #ffffff;
}

.sf-feedback-comments-count {
    background: #e0e7ff;
    padding: 0.25rem 0.625rem;
    border-radius: 1rem;
    font-size: 0.75rem;
    color: #4338ca;
    font-weight: 600;
    display: inline-flex;
    align-items: center;
    gap: 0.25rem;
    border: 1px solid #c7d2fe;
}

/* Merged indicator */
.sf-feedback-merged {
    background: linear-gradient(135deg, #fef3c7 0%, #fef9e7 100%);
    border: 1px solid #fde047;
    color: #92400e;
    padding: 0.625rem 1rem;
    border-radius: 0.5rem;
    font-size: 0.8125rem;
    margin-top: 0.75rem;
    font-weight: 500;
    box-shadow: 0 1px 2px rgba(146, 64, 14, 0.05);
    display: inline-flex;
    align-items: center;
    gap: 0.375rem;
}

@media (max-width: 768px) {
    .sf-feedback-card-header {
        flex-direction: column;
        align-items: stretch;
    }
    
    .sf-feedback-card-badges {
        margin-bottom: 0.5rem;
    }
    
    .sf-feedback-card-actions {
        margin-left: 0;
    }
    
    /* Action buttons full width */
    .sf-feedback-card-actions {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        width: 100%;
    }
    
    .btn-manage-feedback,
    .btn-merge-feedback,
    .btn-delete-feedback {
        width: 100% !important;
        justify-content: center !important;
        padding: 0.75rem 1rem !important;
        font-size: 0.9rem !important;
        min-height: 44px; /* Apple/Google recommendation for touch targets */
    }
    
    .sf-feedback-card-meta,
    .sf-feedback-card-resolved {
        flex-direction: column;
        gap: 0.5rem;
        align-items: flex-start;
    }
    
    /* Modal on mobile */
    .sf-modal-content {
        width: 95%;
        max-width: 95%;
        margin: 1rem;
        max-height: 90vh;
        overflow-y: auto;
    }
    
    .sf-modal-header h3 {
        font-size: 1.25rem;
    }
    
    .sf-modal-actions {
        flex-direction: column;
        gap: 0.75rem;
    }
    
    .sf-modal-actions .sf-btn {
        width: 100%;
        justify-content: center;
        min-height: 44px;
    }
}

/* Very small screens */
@media (max-width: 480px) {
    .sf-feedback-badge {
        font-size: 0.75rem;
        padding: 0.3rem 0.7rem;
    }
    
    .sf-feedback-card-title {
        font-size: 1.1rem;
    }
    
    .btn-manage-feedback,
    .btn-merge-feedback,
    .btn-delete-feedback {
        padding: 0.875rem 1rem !important;
        font-size: 1rem !important;
        min-height: 48px; /* Larger touch target on small screens */
    }
    
    .sf-feedback-comments-toggle {
        font-size: 0.8125rem;
        padding: 0.5rem 0.75rem;
    }
}
</style>
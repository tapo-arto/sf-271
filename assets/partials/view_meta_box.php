<?php
// app/pages/partials/view_meta_box.php
// Erotettu osatiedostoksi selkeyden vuoksi. Sisällytetään view.php:hen.

// Tässä oletetaan, että muuttujat, kuten $flash, $currentUiLang, $typeLabel,
// ovat saatavilla `view.php`-pääsivulta.

// Tarvittavien funktioiden varmistus (esim. sf_term)
if (!function_exists('sf_term')) {
    // Varmista, että funktio on olemassa tai lataa se
    require_once __DIR__ . '/../../includes/statuses.php'; // Oletettu sijainti
}

$statusDef       = function_exists('sf_status_get') ? sf_status_get((string)($flash['state'] ?? '')) : null;
$metaStatusClass = trim((string)($statusDef['badge_class'] ?? 'sf-status--other'));
$statusLabel     = function_exists('sf_status_label') ? (sf_status_label($flash['state'], $currentUiLang) ?? '') : '';

?>
<div class="view-box meta-box">
    <div class="meta-status-top">
        <div class="meta-status-left" aria-hidden="true">
            <span class="meta-status-label">
                <?= htmlspecialchars(sf_term('view_status', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </span>
            <span class="status-pill <?= htmlspecialchars($metaStatusClass ?: '') ?>">
                <?= htmlspecialchars($statusLabel) ?>
            </span>
            <?php if (!empty($flash['is_archived'])): ?>
                <span class="status-pill status-pill-archived" style="margin-left: 8px;">
                    <?= htmlspecialchars(sf_term('status_archived', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </span>
            <?php endif; ?>
        </div>
    </div>
    <div class="sf-editing-indicator" data-flash-id="<?= (int)$flash['id'] ?>">
        <div class="sf-editing-spinner"></div>
        <span class="sf-editing-text"></span>
    </div>

    <?php if (($flash['state'] ?? '') === 'pending_supervisor'): ?>
    <!-- TARKISTAJA / REVIEWER SECTION -->
    <?php
    // Note: API endpoints verify user authentication but don't enforce role restrictions
    $canManageReviewers = function_exists('sf_is_admin_or_safety') && sf_is_admin_or_safety();
    
    // Fetch reviewers from flash_supervisors table
    $reviewers = [];
    try {
        $reviewerStmt = $pdo->prepare("
            SELECT 
                fs.id,
                fs.user_id,
                fs.assigned_at,
                u.first_name,
                u.last_name,
                u.email,
                DATE_FORMAT(fs.assigned_at, '%d.%m.%Y %H:%i') as assigned_at_formatted
            FROM flash_supervisors fs
            INNER JOIN sf_users u ON u.id = fs.user_id
            WHERE fs.flash_id = ?
            ORDER BY fs.assigned_at DESC
        ");
        $reviewerStmt->execute([$flash['id']]);
        $reviewers = $reviewerStmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Error fetching reviewers: ' . $e->getMessage());
    }
    ?>

    <h2 class="section-heading reviewer-section-heading">
        <span class="section-heading-icon" aria-hidden="true">
            <!-- User check icon -->
            <svg viewBox="0 0 24 24" focusable="false">
                <circle cx="12" cy="8" r="3" fill="none" stroke="currentColor" stroke-width="1.6"/>
                <path d="M6 21v-2a4 4 0 0 1 4-4h4a4 4 0 0 1 4 4v2" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
            </svg>
        </span>
        <span class="section-heading-text">
            <?= htmlspecialchars(sf_term('reviewer_section_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </span>
        <?php if ($canManageReviewers): ?>
            <span class="reviewer-actions">
                <button type="button" 
                        class="reviewer-action-btn" 
                        data-action="add"
                        data-flash-id="<?= (int)$flash['id'] ?>"
                        title="<?= htmlspecialchars(sf_term('add_reviewer', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                    <svg viewBox="0 0 24 24" focusable="false">
                        <line x1="12" y1="5" x2="12" y2="19" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        <line x1="5" y1="12" x2="19" y2="12" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                    </svg>
                </button>
                <button type="button" 
                        class="reviewer-action-btn" 
                        data-action="replace"
                        data-flash-id="<?= (int)$flash['id'] ?>"
                        title="<?= htmlspecialchars(sf_term('replace_reviewer', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                    <svg viewBox="0 0 24 24" focusable="false">
                        <path d="M21 12a9 9 0 1 1-9-9c2.52 0 4.93 1 6.74 2.74L21 8" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                        <path d="M21 3v5h-5" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
            </span>
        <?php endif; ?>
    </h2>

    <div class="meta-item">
        <?php if (!empty($reviewers)): ?>
            <div class="reviewer-list" id="reviewerList">
                <?php foreach ($reviewers as $reviewer):
                    $name = trim(($reviewer['first_name'] ?? '') . ' ' . ($reviewer['last_name'] ?? ''));
                    $email = $reviewer['email'] ?? '';
                    $assignedAt = $reviewer['assigned_at_formatted'] ?? '';
                ?>
                    <div class="reviewer-card" data-user-id="<?= (int)$reviewer['user_id'] ?>">
                        <div class="reviewer-info">
                            <div class="reviewer-name">
                                <?= htmlspecialchars($name !== '' ? $name : ('ID ' . (int)$reviewer['user_id']), ENT_QUOTES, 'UTF-8') ?>
                            </div>
                            <?php if ($email): ?>
                                <div class="reviewer-email">
                                    <?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            <?php endif; ?>
                            <?php if ($assignedAt): ?>
                                <div class="reviewer-assigned">
                                    <?= htmlspecialchars(sf_term('reviewer_assigned_at', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>: <?= htmlspecialchars($assignedAt, ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <?php if ($canManageReviewers): ?>
                            <button type="button" 
                                    class="reviewer-remove-btn" 
                                    data-user-id="<?= (int)$reviewer['user_id'] ?>"
                                    data-flash-id="<?= (int)$flash['id'] ?>"
                                    title="<?= htmlspecialchars(sf_term('remove_reviewer', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
                                <svg viewBox="0 0 24 24" focusable="false">
                                    <line x1="18" y1="6" x2="6" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                    <line x1="6" y1="6" x2="18" y2="18" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                                </svg>
                            </button>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="reviewer-empty" id="reviewerEmpty">
                <?= htmlspecialchars(sf_term('reviewer_no_reviewers', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>
    </div>

    <hr class="meta-separator">
    <?php endif; ?>

    <!-- SISÄLTÖ: Safetyflashin tiedot -->
    <h2 class="section-heading">
        <span class="section-heading-icon" aria-hidden="true">
            <!-- Dokumentti-ikoni -->
            <svg viewBox="0 0 24 24" focusable="false">
                <path d="M6 3h9l3 3v15H6V3z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linejoin="round"/>
                <path d="M9 9h6M9 13h6M9 17h4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
            </svg>
        </span>
        <span class="section-heading-text">
            <?= htmlspecialchars(sf_term('view_details_heading', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </span>
    </h2>

    <div class="meta-item">
        <strong><?= htmlspecialchars(sf_term('meta_title_internal', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>:</strong>
        <div><?= htmlspecialchars($flash['title'] ?? '') ?></div>
    </div>

    <div class="meta-item">
        <strong><?= htmlspecialchars(sf_term('meta_summary_short', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>:</strong>
        <div><?= nl2br(htmlspecialchars($flash['summary'] ?? '')) ?></div>
    </div>

    <div class="meta-item">
        <strong><?= htmlspecialchars(sf_term('meta_description_long', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>:</strong>
        <div><?= nl2br(htmlspecialchars($flash['description'] ?? '')) ?></div>
    </div>

    <?php if ($flash['type'] === 'green'): ?>
        <?php if (!empty($flash['root_causes'])): ?>
            <div class="meta-item">
                <strong><?= htmlspecialchars(sf_term('root_causes_label', $currentUiLang) ?? 'Juurisyyt', ENT_QUOTES, 'UTF-8') ?>:</strong>
                <div><?= nl2br(htmlspecialchars($flash['root_causes'])) ?></div>
            </div>
        <?php endif; ?>

        <?php if (!empty($flash['actions'])): ?>
            <div class="meta-item">
                <strong><?= htmlspecialchars(sf_term('actions_label', $currentUiLang) ?? 'Toimenpiteet', ENT_QUOTES, 'UTF-8') ?>:</strong>
                <div><?= nl2br(htmlspecialchars($flash['actions'])) ?></div>
            </div>
        <?php endif; ?>
    <?php endif; ?>

    <div class="meta-item">
        <strong><?= htmlspecialchars(sf_term('meta_type', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>:</strong>
        <div><?= htmlspecialchars($typeLabel) ?></div>
    </div>

    <div class="meta-item">
        <strong><?= htmlspecialchars(sf_term('meta_site', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>:</strong>
        <div>
            <?= htmlspecialchars($flash['site'] ?? '') ?>
            <?php if (!empty($flash['site_detail'])): ?>
                &nbsp;–&nbsp;<?= htmlspecialchars($flash['site_detail']) ?>
            <?php endif; ?>
        </div>
    </div>

    <div class="meta-item">
        <strong><?= htmlspecialchars(sf_term('meta_occurred_at', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>:</strong>
        <div><?= htmlspecialchars($flash['occurredFmt'] ?? '') ?></div>
    </div>

    <div class="meta-item" id="sfViewBodyPartsSection">
        <strong><?= htmlspecialchars(sf_term('body_parts_section_title', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>:</strong>
        <div id="sfInjuryTags" class="sf-injury-tags"></div>
    </div>

    <!-- JÄRJESTELMÄTIEDOT: kieli, luotu, muokattu -->
    <hr class="meta-separator">

    <h2 class="section-heading section-heading-system">
        <span class="section-heading-icon" aria-hidden="true">
            <!-- Ratas-ikoni -->
            <svg viewBox="0 0 24 24" focusable="false">
                <path d="M12 8a4 4 0 1 1 0 8 4 4 0 0 1 0-8z" fill="none" stroke="currentColor" stroke-width="1.6"/>
                <path d="M4 12h2M18 12h2M12 4v2M12 18v2M7 7l1.5 1.5M15.5 15.5L17 17M7 17l1.5-1.5M15.5 8.5L17 7"
                      fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/>
            </svg>
        </span>
        <span class="section-heading-text">
            <?= htmlspecialchars(sf_term('meta_system_heading', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </span>
    </h2>

    <div class="meta-item">
        <strong><?= htmlspecialchars(sf_term('meta_language', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>:</strong>
        <div><?= htmlspecialchars(ucfirst((string)($flash['lang'] ?? ''))) ?></div>
    </div>

    <div class="meta-item">
        <strong><?= htmlspecialchars(sf_term('meta_created_at', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>:</strong>
        <div><?= htmlspecialchars($flash['createdFmt'] ?? '') ?></div>
    </div>

    <div class="meta-item">
        <strong><?= htmlspecialchars(sf_term('meta_updated_at', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>:</strong>
        <div><?= htmlspecialchars($flash['updatedFmt'] ?? '') ?></div>
    </div>
</div>

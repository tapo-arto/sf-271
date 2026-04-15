<?php
// app/includes/footer.php
$base = rtrim($config['base_url'] ?? '/', '/');
$currentPage = $_GET['page'] ?? 'list';
$uiLang = $_SESSION['ui_lang'] ?? 'fi';

// Get user info for admin check (same as header.php)
$user = sf_current_user();
$isAdmin = $user && (int)$user['role_id'] === 1;
?>

<?php if ($currentPage !== 'form' && $currentPage !== 'form_language' && $currentPage !== 'view'): ?>

<!-- Bottom Navigation (Mobile) - 5 buttons: Dashboard, Lista, Uusi (center), Palaute, Profiili -->
<nav class="sf-bottom-nav" aria-label="<?= htmlspecialchars(sf_term('mobile_nav', $uiLang) ?? 'Mobiilinavigaatio') ?>">
    <!-- Button 1: Dashboard -->
    <a href="<?= $base ?>/index.php?page=dashboard" 
       class="sf-bottom-nav-item <?= $currentPage === 'dashboard' ? 'active' : '' ?>">
        <img src="<?= $base ?>/assets/img/icons/dashboard.svg" alt="" class="sf-bottom-nav-icon">
        <span><?= htmlspecialchars(sf_term('nav_dashboard', $uiLang) ?? 'Dashboard') ?></span>
    </a>

    <!-- Button 2: Lista -->
    <a href="<?= $base ?>/index.php?page=list" 
       class="sf-bottom-nav-item <?= $currentPage === 'list' ? 'active' : '' ?>">
        <img src="<?= $base ?>/assets/img/icons/list.svg" alt="" class="sf-bottom-nav-icon">
        <span><?= htmlspecialchars(sf_term('nav_list', $uiLang) ?? 'Lista') ?></span>
    </a>
    
    <!-- Button 3 (CENTER): UUSI SAFETYFLASH - Visually prominent -->
    <a href="<?= $base ?>/index.php?page=form" 
       class="sf-bottom-nav-cta <?= $currentPage === 'form' ? 'active' : '' ?>"
       aria-label="<?= htmlspecialchars(sf_term('nav_new_safetyflash', $uiLang) ?? 'Uusi Safetyflash') ?>">
        <img src="<?= $base ?>/assets/img/icons/add_new_icon.svg" 
             alt="" 
             class="sf-bottom-nav-cta-icon">
    </a>
    
    <!-- Button 4: Palaute -->
    <a href="<?= $base ?>/index.php?page=feedback" 
       class="sf-bottom-nav-item <?= $currentPage === 'feedback' ? 'active' : '' ?>">
        <img src="<?= $base ?>/assets/img/icons/feedback.svg" alt="" class="sf-bottom-nav-icon">
        <span><?= htmlspecialchars(sf_term('nav_feedback', $uiLang) ?? 'Palaute') ?></span>
    </a>
    
    <!-- Button 5: Profiili -->
    <button type="button" 
       class="sf-bottom-nav-item <?= $currentPage === 'profile' ? 'active' : '' ?>"
       data-modal-open="modalProfile">
        <img src="<?= $base ?>/assets/img/icons/profile.svg" alt="" class="sf-bottom-nav-icon">
        <span><?= htmlspecialchars(sf_term('nav_profile', $uiLang) ?? 'Profiili') ?></span>
    </button>
</nav>
<?php endif; ?>



<!-- Profiili-modal -->
<div class="sf-modal hidden" id="modalProfile" role="dialog" aria-modal="true" aria-labelledby="modalProfileTitle">
    <div class="sf-modal-content">
        <div class="sf-modal-header">
            <h2 id="modalProfileTitle"><?= htmlspecialchars(sf_term('profile_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?></h2>
            <button type="button" class="sf-modal-close-btn" data-modal-close aria-label="Close">×</button>
        </div>
        
        <!-- Välilehdet -->
        <div class="sf-profile-tabs">
            <button class="sf-profile-tab active" data-tab="basics"><?= htmlspecialchars(sf_term('profile_tab_basics', $uiLang), ENT_QUOTES, 'UTF-8') ?></button>
            <button class="sf-profile-tab" data-tab="settings"><?= htmlspecialchars(sf_term('profile_tab_settings', $uiLang), ENT_QUOTES, 'UTF-8') ?></button>
            <button class="sf-profile-tab" data-tab="password"><?= htmlspecialchars(sf_term('profile_tab_password', $uiLang), ENT_QUOTES, 'UTF-8') ?></button>
        </div>
        
        <!-- Välilehti 1: Perustiedot -->
        <div class="sf-profile-tab-content active" data-tab-content="basics">
            <form id="sfProfileModalForm">
                <?= sf_csrf_field() ?>
                
                <div class="sf-profile-section">
                    <h3><?= htmlspecialchars(sf_term('profile_personal_info', $uiLang), ENT_QUOTES, 'UTF-8') ?></h3>
                    
                    <div class="sf-field-row">
                        <div class="sf-field">
                            <label for="modalProfileFirst"><?= htmlspecialchars(sf_term('users_label_first_name', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
                            <input type="text" name="first_name" id="modalProfileFirst" class="sf-input" required>
                        </div>
                        <div class="sf-field">
                            <label for="modalProfileLast"><?= htmlspecialchars(sf_term('users_label_last_name', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
                            <input type="text" name="last_name" id="modalProfileLast" class="sf-input" required>
                        </div>
                    </div>
                    
                    <div class="sf-field">
                        <label for="modalProfileEmail"><?= htmlspecialchars(sf_term('users_label_email', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
                        <input type="email" name="email" id="modalProfileEmail" class="sf-input" required readonly>
                    </div>
                    
                    <div class="sf-field">
                        <label><?= htmlspecialchars(sf_term('users_label_role', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
                        <div class="sf-profile-readonly" id="modalProfileRole">-</div>
                    </div>
                </div>
                
                <div class="sf-modal-actions">
                    <button type="button" class="sf-btn sf-btn-secondary" data-modal-close>
                        <?= htmlspecialchars(sf_term('btn_cancel', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                    <button type="submit" class="sf-btn sf-btn-primary">
                        <?= htmlspecialchars(sf_term('btn_save', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Välilehti 2: Asetukset -->
        <div class="sf-profile-tab-content" data-tab-content="settings">
            <form id="sfProfileSettingsForm">
                <?= sf_csrf_field() ?>
                
                <div class="sf-profile-section">
                    <h3><?= htmlspecialchars(sf_term('profile_worksite_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?></h3>
                    
                    <div class="sf-field">
                        <label for="modalProfileWorksite"><?= htmlspecialchars(sf_term('users_label_home_worksite', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
                        <select name="home_worksite_id" id="modalProfileWorksite" class="sf-select">
                            <option value=""><?= htmlspecialchars(sf_term('users_home_worksite_none', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                            <!-- Worksites loaded dynamically -->
                        </select>
                        <p class="sf-help-text"><?= htmlspecialchars(sf_term('profile_worksite_help', $uiLang), ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </div>
                
                <div class="sf-profile-section">
                    <h3><?= htmlspecialchars(sf_term('profile_notifications_heading', $uiLang), ENT_QUOTES, 'UTF-8') ?></h3>
                    
                    <div class="sf-field">
                        <div class="sf-email-notification-field">
                            <label class="sf-email-notification-label" for="modalProfileEmailNotifications">
                                <?= htmlspecialchars(sf_term('email_notifications_label', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                            </label>
                            <label class="sf-toggle">
                                <input type="hidden" name="email_notifications_enabled" value="0">
                                <input type="checkbox" id="modalProfileEmailNotifications" name="email_notifications_enabled" value="1" checked>
                                <span class="sf-toggle-slider"></span>
                            </label>
                        </div>
                    </div>
                </div>
                
                <div class="sf-modal-actions">
                    <button type="button" class="sf-btn sf-btn-secondary" data-modal-close>
                        <?= htmlspecialchars(sf_term('btn_cancel', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                    <button type="submit" class="sf-btn sf-btn-primary">
                        <?= htmlspecialchars(sf_term('btn_save', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </div>
            </form>
        </div>
        
        <!-- Välilehti 3: Salasana -->
        <div class="sf-profile-tab-content" data-tab-content="password">
            <form id="sfPasswordModalForm">
                <?= sf_csrf_field() ?>
                
                <div class="sf-profile-section">
                    <h3><?= htmlspecialchars(sf_term('profile_change_password', $uiLang), ENT_QUOTES, 'UTF-8') ?></h3>

                    <div id="sfPasswordModalFeedback" class="sf-help-text" style="display:none; margin-bottom:12px; font-weight:600;"></div>
                    
                    <div class="sf-field">
                        <label for="modalCurrentPassword"><?= htmlspecialchars(sf_term('profile_current_password', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
                        <input type="password" name="current_password" id="modalCurrentPassword" class="sf-input" required>
                    </div>
                    
                    <div class="sf-field-row">
                        <div class="sf-field">
                            <label for="modalNewPassword"><?= htmlspecialchars(sf_term('profile_new_password', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
                            <input type="password" name="new_password" id="modalNewPassword" class="sf-input" required minlength="8">
                        </div>
                        <div class="sf-field">
                            <label for="modalConfirmPassword"><?= htmlspecialchars(sf_term('profile_confirm_password', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
                            <input type="password" name="confirm_password" id="modalConfirmPassword" class="sf-input" required minlength="8">
                        </div>
                    </div>
                    
                    <button type="submit" class="sf-btn sf-btn-secondary">
                        <?= htmlspecialchars(sf_term('profile_change_password', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </div>
            </form>
        </div>

        <?php if ($isAdmin): ?>
        <!-- Admin section (only for admins) -->
        <div class="sf-profile-admin-section">
            <h3><?= htmlspecialchars(sf_term('nav_admin', $uiLang) ?? 'Ylläpito', ENT_QUOTES, 'UTF-8') ?></h3>
            <div class="sf-profile-admin-links">
                <a href="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/index.php?page=playlist_manager" class="sf-profile-admin-link">
                    <img src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/img/icons/display.svg" alt="" class="sf-profile-admin-icon" aria-hidden="true">
                    <span><?= htmlspecialchars(sf_term('nav_display_playlists', $uiLang) ?? 'Infonäytöt', ENT_QUOTES, 'UTF-8') ?></span>
                </a>
                <a href="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/index.php?page=settings" class="sf-profile-admin-link">
                    <img src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/img/icons/settings.svg" alt="" class="sf-profile-admin-icon" aria-hidden="true">
                    <span><?= htmlspecialchars(sf_term('settings_heading', $uiLang) ?? 'Asetukset', ENT_QUOTES, 'UTF-8') ?></span>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <!-- Logout button -->
        <div class="sf-profile-logout-section">
            <a href="#sfLogoutModal" class="sf-btn sf-btn-danger sf-profile-logout-btn" data-modal-open="#sfLogoutModal">
                <img src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/img/icons/log_out.svg"
                     alt=""
                     class="sf-profile-logout-icon"
                     aria-hidden="true">
                <?= htmlspecialchars(sf_term('nav_logout', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </a>
        </div>
    </div>
</div>
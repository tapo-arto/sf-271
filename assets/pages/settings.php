<?php
// assets/pages/settings.php
declare(strict_types=1);

require_once __DIR__ . '/../../app/includes/protect.php';
require_once __DIR__ . '/../../app/includes/statuses.php';
require_once __DIR__ . '/../../app/includes/settings.php';
require_once __DIR__ . '/../../app/includes/log_app.php';

$baseUrl = rtrim($config['base_url'] ?? '', '/');

// Allow admin and safety team
if (!sf_is_admin_or_safety()) {
    http_response_code(403);
    echo 'Ei käyttöoikeutta asetussivulle.';
    exit;
}

// UI-kieli
$currentUiLang = $uiLang ?? ($_SESSION['ui_lang'] ?? 'fi');

// DB-yhteys
$mysqli = sf_db();

// Aktiivinen välilehti
$tab        = $_GET['tab'] ?? 'users';
$allowedTabs = ['users', 'worksites', 'image_library', 'audit_log', 'role_categories', 'email_logs', 'email', 'system', 'updates'];

if (!in_array($tab, $allowedTabs, true)) {
    $tab = 'users';
}
?>
<div class="sf-page-container">
    <div class="sf-page-header">
        <h1 class="sf-page-title">
            <?= htmlspecialchars(
                sf_term('settings_heading', $currentUiLang) ?? 'Asetukset',
                ENT_QUOTES,
                'UTF-8'
            ) ?>
        </h1>
    </div>

<div class="sf-settings-page">

<!-- Välilehdet -->
<div class="sf-tabs">

    <a href="<?= $baseUrl ?>/index.php?page=settings&tab=users"
       class="sf-tab <?= $tab === 'users' ? 'active' : '' ?>">
        <img src="<?= $baseUrl ?>/assets/img/icons/users.svg" alt="" class="sf-tab-icon" aria-hidden="true">
        <span><?= htmlspecialchars(
            sf_term('settings_tab_users', $currentUiLang) ?? 'Käyttäjät',
            ENT_QUOTES,
            'UTF-8'
        ) ?></span>
    </a>

    <a href="<?= $baseUrl ?>/index.php?page=settings&tab=worksites"
       class="sf-tab <?= $tab === 'worksites' ? 'active' : '' ?>">
        <img src="<?= $baseUrl ?>/assets/img/icons/worksite.svg" alt="" class="sf-tab-icon" aria-hidden="true">
        <span><?= htmlspecialchars(
            sf_term('settings_tab_worksites', $currentUiLang) ?? 'Työmaat',
            ENT_QUOTES,
            'UTF-8'
        ) ?></span>
    </a>

    <a href="<?= $baseUrl ?>/index.php?page=settings&tab=image_library"
       class="sf-tab <?= $tab === 'image_library' ? 'active' : '' ?>">
        <img src="<?= $baseUrl ?>/assets/img/icons/image.svg" alt="" class="sf-tab-icon" aria-hidden="true">
        <span><?= htmlspecialchars(
            sf_term('settings_tab_image_library', $currentUiLang) ?? 'Kuvapankki',
            ENT_QUOTES,
            'UTF-8'
        ) ?></span>
    </a>

    <a href="<?= $baseUrl ?>/index.php?page=settings&tab=audit_log"
       class="sf-tab <?= $tab === 'audit_log' ? 'active' : '' ?>">
        <img src="<?= $baseUrl ?>/assets/img/icons/calendar.svg" alt="" class="sf-tab-icon" aria-hidden="true">
        <span><?= htmlspecialchars(
            sf_term('settings_tab_audit_log', $currentUiLang) ?? 'Tapahtumaloki',
            ENT_QUOTES,
            'UTF-8'
        ) ?></span>
    </a>

    <a href="<?= $baseUrl ?>/index.php?page=settings&tab=role_categories"
       class="sf-tab <?= $tab === 'role_categories' ? 'active' : '' ?>">
        <img src="<?= $baseUrl ?>/assets/img/icons/users.svg" alt="" class="sf-tab-icon" aria-hidden="true">
        <span>Roolikategoriat</span>
    </a>

    <a href="<?= $baseUrl ?>/index.php?page=settings&tab=email_logs"
       class="sf-tab <?= $tab === 'email_logs' ? 'active' : '' ?>">
        <img src="<?= $baseUrl ?>/assets/img/icons/email.svg" alt="" class="sf-tab-icon" aria-hidden="true">
        <span><?= htmlspecialchars(
            sf_term('settings_tab_email_logs', $currentUiLang) ?? 'Sähköpostiloki',
            ENT_QUOTES,
            'UTF-8'
        ) ?></span>
    </a>

    <a href="<?= $baseUrl ?>/index.php?page=settings&tab=email"
       class="sf-tab <?= $tab === 'email' ? 'active' : '' ?>">
        <img src="<?= $baseUrl ?>/assets/img/icons/email.svg" alt="" class="sf-tab-icon" aria-hidden="true">
        <span><?= htmlspecialchars(
            sf_term('settings_tab_email_settings', $currentUiLang) ?? 'Sähköposti',
            ENT_QUOTES,
            'UTF-8'
        ) ?></span>
    </a>

    <a href="<?= $baseUrl ?>/index.php?page=settings&tab=system"
       class="sf-tab <?= $tab === 'system' ? 'active' : '' ?>">
        <img src="<?= $baseUrl ?>/assets/img/icons/screen.svg" alt="" class="sf-tab-icon" aria-hidden="true">
        <span><?= htmlspecialchars(
            sf_term('settings_tab_system', $currentUiLang) ?? 'Järjestelmä',
            ENT_QUOTES,
            'UTF-8'
        ) ?></span>
    </a>

    <a href="<?= $baseUrl ?>/index.php?page=settings&tab=updates"
       class="sf-tab <?= $tab === 'updates' ? 'active' : '' ?>">
        <img src="<?= $baseUrl ?>/assets/img/icons/changelog_icon.svg" alt="" class="sf-tab-icon" aria-hidden="true">
        <span><?= htmlspecialchars(
            sf_term('settings_tab_updates', $currentUiLang),
            ENT_QUOTES,
            'UTF-8'
        ) ?></span>
    </a>

</div>

    <div class="sf-tabs-content">
        <?php
        // Lataa aktiivinen välilehti
        $tabFile = __DIR__ . '/settings/tab_' . $tab . '.php';
        if (file_exists($tabFile)) {
            include $tabFile;
        } else {
            echo '<p>Välilehteä ei löydy.</p>';
        }
        ?>
    </div>
</div>
</div>

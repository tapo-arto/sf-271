<?php
// app/pages/settings/tab_worksites.php
declare(strict_types=1);

// Fallback image current value
$fallbackImagePath = sf_get_setting('display_fallback_image', '');
$fallbackImageUrl  = ($fallbackImagePath && $baseUrl)
    ? rtrim($baseUrl, '/') . '/' . ltrim($fallbackImagePath, '/')
    : '';

if (!function_exists('sf_worksite_format_datetime')) {
    function sf_worksite_format_datetime(?string $value): string {
        if ($value === null || trim($value) === '') {
            return '—';
        }
        $ts = strtotime($value);
        if ($ts === false) {
            return '—';
        }
        return date('d.m.Y H:i', $ts);
    }
}

if (!function_exists('sf_worksite_strtolower')) {
    function sf_worksite_strtolower(string $value): string {
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($value, 'UTF-8');
        }
        return strtolower($value);
    }
}

// Hae työmaat, niiden display API-avaimet ja aktiivisten flashien määrä
$worksites = [];
$worksitesRes = $mysqli->query(
    'SELECT w.id, w.name, w.site_type, w.is_active, w.created_at, w.updated_at,
            COALESCE(w.show_in_worksite_lists, 1) AS show_in_worksite_lists,
            COALESCE(w.show_in_display_targets, 1) AS show_in_display_targets,
            k.api_key AS display_api_key, k.id AS display_key_id,
            COUNT(t.id) AS active_flash_count
      FROM sf_worksites w
      LEFT JOIN sf_display_api_keys k ON k.worksite_id = w.id AND k.is_active = 1
      LEFT JOIN sf_flash_display_targets t ON t.display_key_id = k.id AND t.is_active = 1
      GROUP BY w.id, w.name, w.site_type, w.is_active, w.created_at, w.updated_at, w.show_in_worksite_lists, w.show_in_display_targets, k.api_key, k.id
      ORDER BY w.name ASC'
);
if (!$worksitesRes) {
    error_log('tab_worksites: primary query failed: ' . $mysqli->error);
    // Fallback if all columns are not yet migrated
    $worksitesRes = $mysqli->query('SELECT id, name, NULL AS site_type, is_active, NULL AS created_at, NULL AS updated_at, 1 AS show_in_worksite_lists, 1 AS show_in_display_targets, NULL AS display_api_key, NULL AS display_key_id, 0 AS active_flash_count FROM sf_worksites ORDER BY name ASC');
}
if ($worksitesRes) {
    while ($w = $worksitesRes->fetch_assoc()) {
        $worksites[] = $w;
    }
    $worksitesRes->free();
}

$visibilityListsDesc = (string)(sf_term('settings_worksites_visibility_lists_desc', $currentUiLang) ?? 'Tulee työmaavalintoihin safetyflashia luotaessa (lomake, suodattimet).');
$visibilityDisplaysDesc = (string)(sf_term('settings_worksites_visibility_displays_desc', $currentUiLang) ?? 'Tulee display-targets -valintoihin julkaisussa (Xibo / Intra / muu kohde).');
?>

<div class="sf-settings-section" style="margin-bottom:2rem;padding-bottom:1.5rem;border-bottom:1px solid #e2e8f0;">
    <h2>
        <img src="<?= $baseUrl ?>/assets/img/icons/display.svg" alt="" class="sf-heading-icon" aria-hidden="true">
        <?= htmlspecialchars(sf_term('display_fallback_heading', $currentUiLang) ?? 'Infonäyttöjen fallback-kuva', ENT_QUOTES, 'UTF-8') ?>
    </h2>
    <p style="margin-bottom:1rem;color:#64748b;font-size:0.9rem;">
        <?= htmlspecialchars(sf_term('display_fallback_description', $currentUiLang) ?? 'Näytetään kun playlistassa ei ole flasheja. Suositeltu koko 1920×1080. Näkyy 5 sekuntia.', ENT_QUOTES, 'UTF-8') ?>
    </p>

    <div id="sfFallbackPreview" style="margin-bottom:0.75rem;<?= $fallbackImageUrl ? '' : 'display:none;' ?>">
        <p style="font-size:0.85rem;color:#475569;margin-bottom:0.4rem;">
            <?= htmlspecialchars(sf_term('display_fallback_current', $currentUiLang) ?? 'Nykyinen kuva', ENT_QUOTES, 'UTF-8') ?>
        </p>
        <img id="sfFallbackImg" src="<?= htmlspecialchars($fallbackImageUrl, ENT_QUOTES, 'UTF-8') ?>" alt=""
             style="max-width:200px;border:1px solid #cbd5e1;border-radius:4px;">
    </div>
    <?php if (!$fallbackImageUrl): ?>
    <p id="sfFallbackNone" style="color:#94a3b8;font-size:0.85rem;margin-bottom:0.75rem;">
        <?= htmlspecialchars(sf_term('display_fallback_none', $currentUiLang) ?? 'Ei fallback-kuvaa asetettu', ENT_QUOTES, 'UTF-8') ?>
    </p>
    <?php endif; ?>

    <div style="display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;">
        <label class="sf-btn sf-btn-outline-primary sf-btn-sm" style="cursor:pointer;margin:0;">
            <?= htmlspecialchars(sf_term('display_fallback_choose', $currentUiLang) ?? 'Valitse kuva...', ENT_QUOTES, 'UTF-8') ?>
            <input type="file" id="sfFallbackFile" accept="image/jpeg,image/png,image/webp" style="display:none;">
        </label>
        <button type="button" id="sfFallbackRemove"
                class="sf-btn sf-btn-sm sf-btn-outline-danger"
                style="<?= $fallbackImageUrl ? '' : 'display:none;' ?>">
            <?= htmlspecialchars(sf_term('display_fallback_remove', $currentUiLang) ?? 'Poista', ENT_QUOTES, 'UTF-8') ?>
        </button>
    </div>
</div>

<script>
(function() {
    'use strict';
    var baseUrl      = <?= json_encode(rtrim($baseUrl, '/'), JSON_UNESCAPED_SLASHES) ?>;
    var csrfToken    = <?= json_encode($_SESSION['csrf_token'] ?? '', JSON_UNESCAPED_SLASHES) ?>;
    var apiUrl       = baseUrl + '/app/api/upload_display_fallback.php';
    var msgUploadErr = <?= json_encode(sf_term('save_error', $currentUiLang) ?? 'Upload failed', JSON_UNESCAPED_UNICODE) ?>;
    var msgRemoveErr = <?= json_encode(sf_term('save_error', $currentUiLang) ?? 'Remove failed', JSON_UNESCAPED_UNICODE) ?>;

    var fileInput    = document.getElementById('sfFallbackFile');
    var removeBtn    = document.getElementById('sfFallbackRemove');
    var previewWrap  = document.getElementById('sfFallbackPreview');
    var previewImg   = document.getElementById('sfFallbackImg');
    var noneMsg      = document.getElementById('sfFallbackNone');

    function showPreview(url) {
        if (previewImg)  previewImg.src = url;
        if (previewWrap) previewWrap.style.display = '';
        if (noneMsg)     noneMsg.style.display = 'none';
        if (removeBtn)   removeBtn.style.display = '';
    }

    function hidePreview() {
        if (previewWrap) previewWrap.style.display = 'none';
        if (noneMsg)     noneMsg.style.display = '';
        if (removeBtn)   removeBtn.style.display = 'none';
    }

    if (fileInput) {
        fileInput.addEventListener('change', function() {
            if (!fileInput.files || !fileInput.files.length) return;
            var fd = new FormData();
            fd.append('action', 'upload');
            fd.append('csrf_token', csrfToken);
            fd.append('image', fileInput.files[0]);

            var xhr = new XMLHttpRequest();
            xhr.open('POST', apiUrl, true);
            xhr.onload = function() {
                try {
                    var r = JSON.parse(xhr.responseText);
                    if (r.ok) {
                        showPreview(r.url);
                    } else {
                        alert(r.error || msgUploadErr);
                    }
                } catch(e) { alert(msgUploadErr); }
                fileInput.value = '';
            };
            xhr.onerror = function() { alert(msgUploadErr); fileInput.value = ''; };
            xhr.send(fd);
        });
    }

    if (removeBtn) {
        removeBtn.addEventListener('click', function() {
            var fd = new FormData();
            fd.append('action', 'remove');
            fd.append('csrf_token', csrfToken);

            var xhr = new XMLHttpRequest();
            xhr.open('POST', apiUrl, true);
            xhr.onload = function() {
                try {
                    var r = JSON.parse(xhr.responseText);
                    if (r.ok) {
                        hidePreview();
                    } else {
                        alert(r.error || msgRemoveErr);
                    }
                } catch(e) { alert(msgRemoveErr); }
            };
            xhr.onerror = function() { alert(msgRemoveErr); };
            xhr.send(fd);
        });
    }
})();
</script>

<div class="sf-worksites-toolbar">
    <h2>
        <img src="<?= $baseUrl ?>/assets/img/icons/worksite.svg" alt="" class="sf-heading-icon" aria-hidden="true">
        <?= htmlspecialchars(
            sf_term('settings_worksites_heading', $currentUiLang) ?? 'Työmaiden hallinta',
            ENT_QUOTES,
            'UTF-8'
        ) ?>
    </h2>
    <div class="sf-worksites-toolbar-actions">
        <button type="button" class="sf-btn sf-btn-sm sf-btn-primary" data-modal-open="#modalAddWorksite">
            + <?= htmlspecialchars(sf_term('settings_worksites_add_button', $currentUiLang) ?? 'Lisää uusi työmaa', ENT_QUOTES, 'UTF-8') ?>
        </button>
        <?php if (!empty($worksites)): ?>
            <details class="sf-export-menu">
                <summary class="sf-btn sf-btn-sm sf-btn-outline-primary">
                    <?= htmlspecialchars(sf_term('btn_export_worksites', $currentUiLang) ?? 'Vie työmaat', ENT_QUOTES, 'UTF-8') ?>
                </summary>
                <div class="sf-export-menu-items" role="menu">
                    <a href="<?= htmlspecialchars($baseUrl . '/app/api/export_worksites.php?format=csv', ENT_QUOTES, 'UTF-8') ?>"
                       class="sf-export-btn"
                       role="menuitem"
                       download>
                        <?= htmlspecialchars(sf_term('btn_export_csv', $currentUiLang) ?? 'Lataa CSV', ENT_QUOTES, 'UTF-8') ?>
                    </a>
                    <a href="<?= htmlspecialchars($baseUrl . '/app/api/export_worksites.php?format=json', ENT_QUOTES, 'UTF-8') ?>"
                       class="sf-export-btn"
                       role="menuitem"
                       download>
                        <?= htmlspecialchars(sf_term('btn_export_json', $currentUiLang) ?? 'Lataa JSON', ENT_QUOTES, 'UTF-8') ?>
                    </a>
                </div>
            </details>
        <?php endif; ?>
    </div>
</div>

<p class="sf-notice sf-notice-info" style="margin:0 0 1rem;">
    <?= htmlspecialchars(
        sf_term('settings_worksites_display_only_hint', $currentUiLang) ?? 'Voit lisätä myös pelkkiä näyttökohteita (esim. Intra), jotka eivät näy työmaavalinnoissa safetyflashia luotaessa. Poista sellaiselta rasti kohdasta Näytä työmaalistoissa ja jätä Näytä infonäytöissä päälle.',
        ENT_QUOTES,
        'UTF-8'
    ) ?>
</p>

<?php if (empty($worksites)): ?>
    <p class="sf-notice sf-notice-info">
        <?= htmlspecialchars(
            sf_term('settings_worksites_empty', $currentUiLang) ?? 'Ei työmaita. Lisää ensimmäinen työmaa yllä olevalla lomakkeella.',
            ENT_QUOTES,
            'UTF-8'
        ) ?>
    </p>
<?php else: ?>
<div class="sf-worksites-filters" aria-label="<?= htmlspecialchars(sf_term('users_filter_toggle', $currentUiLang) ?? 'Suodattimet', ENT_QUOTES, 'UTF-8') ?>">
    <div class="sf-worksites-search-row">
        <input type="search"
               id="sfWorksiteSearch"
               class="sf-input"
               placeholder="<?= htmlspecialchars(sf_term('settings_worksites_filter_search_placeholder', $currentUiLang) ?? 'Hae työmaan nimellä', ENT_QUOTES, 'UTF-8') ?>"
               aria-label="<?= htmlspecialchars(sf_term('settings_worksites_filter_search_placeholder', $currentUiLang) ?? 'Hae työmaan nimellä', ENT_QUOTES, 'UTF-8') ?>">
        <p id="sfWorksiteShowingCount" class="sf-worksites-showing-count" aria-live="polite">
            <?= htmlspecialchars(sprintf((sf_term('settings_worksites_showing_count', $currentUiLang) ?? 'Näytetään %d / %d työmaata'), count($worksites), count($worksites)), ENT_QUOTES, 'UTF-8') ?>
        </p>
    </div>
    <div class="sf-worksites-filter-chips" role="group" aria-label="<?= htmlspecialchars(sf_term('users_filter_toggle', $currentUiLang) ?? 'Suodattimet', ENT_QUOTES, 'UTF-8') ?>">
        <button type="button" class="sf-filter-chip is-active" data-filter="all"><?= htmlspecialchars(sf_term('settings_worksites_filter_all', $currentUiLang) ?? 'Kaikki', ENT_QUOTES, 'UTF-8') ?></button>
        <button type="button" class="sf-filter-chip" data-filter="active"><?= htmlspecialchars(sf_term('settings_worksites_filter_active', $currentUiLang) ?? 'Aktiiviset', ENT_QUOTES, 'UTF-8') ?></button>
        <button type="button" class="sf-filter-chip" data-filter="inactive"><?= htmlspecialchars(sf_term('settings_worksites_filter_inactive', $currentUiLang) ?? 'Passiiviset', ENT_QUOTES, 'UTF-8') ?></button>
        <button type="button" class="sf-filter-chip" data-filter="lists"><?= htmlspecialchars(sf_term('settings_worksites_filter_lists', $currentUiLang) ?? 'Näytetään työmaalistoissa', ENT_QUOTES, 'UTF-8') ?></button>
        <button type="button" class="sf-filter-chip" data-filter="displays"><?= htmlspecialchars(sf_term('settings_worksites_filter_displays', $currentUiLang) ?? 'Näytetään infonäytöissä', ENT_QUOTES, 'UTF-8') ?></button>
    </div>
</div>

<div class="sf-worksites-list" id="sfWorksiteList" role="list">
    <?php foreach ($worksites as $ws): ?>
        <?php
        try {
            $siteTypeKey = $ws['site_type'] ?? null;
            if ($siteTypeKey === 'tunnel') {
                $siteTypeLabel = sf_term('site_type_tunnel', $currentUiLang) ?? 'Tunnelityömaa';
            } elseif ($siteTypeKey === 'opencast') {
                $siteTypeLabel = sf_term('site_type_opencast', $currentUiLang) ?? 'Avolouhos';
            } elseif ($siteTypeKey === 'other') {
                $siteTypeLabel = sf_term('site_type_other', $currentUiLang) ?? 'Muut toimipisteet';
            } else {
                $siteTypeLabel = sf_term('site_type_unspecified', $currentUiLang) ?? 'Määrittämätön';
            }
            $flashCount = (int)($ws['active_flash_count'] ?? 0);
            $isActive = (int)$ws['is_active'] === 1;
            $showInLists = (int)($ws['show_in_worksite_lists'] ?? 1) === 1;
            $showInDisplays = (int)($ws['show_in_display_targets'] ?? 1) === 1;
            $worksiteId = (int)$ws['id'];
            $worksiteName = (string)($ws['name'] ?? '');
            $worksiteNameLower = sf_worksite_strtolower($worksiteName);
            $manageModalId = 'sfWorksiteManageModal' . $worksiteId;
            $playlistUrl = !empty($ws['display_key_id'])
                ? (($baseUrl ?? '') . '/index.php?page=playlist_manager&display_key_id=' . (int)$ws['display_key_id'])
                : '';
            $slideshowUrl = !empty($ws['display_api_key'])
                ? (rtrim((string)($baseUrl ?? ''), '/') . '/app/api/display_playlist.php?key=' . urlencode((string)$ws['display_api_key']) . '&format=slideshow')
                : '';
        } catch (Throwable $worksiteRenderError) {
            sf_app_log(
                'tab_worksites: row render failed for worksite id ' . (int)($ws['id'] ?? 0) . ': ' . $worksiteRenderError->getMessage(),
                LOG_LEVEL_ERROR
            );
            continue;
        }
        ?>
        <div class="sf-worksite-row"
             role="listitem"
             tabindex="0"
             data-worksite-id="<?= $worksiteId ?>"
             data-name="<?= htmlspecialchars($worksiteNameLower, ENT_QUOTES, 'UTF-8') ?>"
             data-active="<?= $isActive ? '1' : '0' ?>"
             data-lists="<?= $showInLists ? '1' : '0' ?>"
             data-displays="<?= $showInDisplays ? '1' : '0' ?>"
             data-modal="#<?= htmlspecialchars($manageModalId, ENT_QUOTES, 'UTF-8') ?>">
            <span class="sf-worksite-status-dot <?= $isActive ? 'is-active' : 'is-inactive' ?>"
                  title="<?= htmlspecialchars($isActive ? (sf_term('settings_worksites_status_active', $currentUiLang) ?? 'Aktiivinen') : (sf_term('settings_worksites_status_inactive', $currentUiLang) ?? 'Passiivinen'), ENT_QUOTES, 'UTF-8') ?>"
                  aria-label="<?= htmlspecialchars($isActive ? (sf_term('settings_worksites_status_active', $currentUiLang) ?? 'Aktiivinen') : (sf_term('settings_worksites_status_inactive', $currentUiLang) ?? 'Passiivinen'), ENT_QUOTES, 'UTF-8') ?>"></span>
            <div class="sf-worksite-row-main">
                <div class="sf-worksite-row-name"><?= htmlspecialchars($worksiteName, ENT_QUOTES, 'UTF-8') ?></div>
                <div class="sf-worksite-row-meta">
                    <?= htmlspecialchars($siteTypeLabel, ENT_QUOTES, 'UTF-8') ?>
                    ·
                    <?= htmlspecialchars(sprintf(sf_term('settings_worksites_meta_flash_count', $currentUiLang) ?? '%d ajolistassa', $flashCount), ENT_QUOTES, 'UTF-8') ?>
                </div>
            </div>
            <div class="sf-worksite-row-toggles" data-no-row-click>
                <label class="sf-toggle-compact" for="ws-row-lists-<?= $worksiteId ?>" title="<?= htmlspecialchars($visibilityListsDesc, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="checkbox"
                           id="ws-row-lists-<?= $worksiteId ?>"
                           class="sf-worksite-visibility-toggle"
                           data-worksite-id="<?= $worksiteId ?>"
                           data-field="show_in_worksite_lists"
                           <?= $showInLists ? 'checked' : '' ?>>
                    <span class="sf-toggle-slider"></span>
                    <span class="sf-toggle-label"><?= htmlspecialchars(sf_term('settings_worksites_toggle_lists_short', $currentUiLang) ?? 'Listat', ENT_QUOTES, 'UTF-8') ?></span>
                </label>
                <label class="sf-toggle-compact" for="ws-row-displays-<?= $worksiteId ?>" title="<?= htmlspecialchars($visibilityDisplaysDesc, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="checkbox"
                           id="ws-row-displays-<?= $worksiteId ?>"
                           class="sf-worksite-visibility-toggle"
                           data-worksite-id="<?= $worksiteId ?>"
                           data-field="show_in_display_targets"
                           <?= $showInDisplays ? 'checked' : '' ?>>
                    <span class="sf-toggle-slider"></span>
                    <span class="sf-toggle-label"><?= htmlspecialchars(sf_term('settings_worksites_toggle_displays_short', $currentUiLang) ?? 'Näytöt', ENT_QUOTES, 'UTF-8') ?></span>
                </label>
            </div>
            <button type="button"
                    class="sf-btn sf-btn-sm sf-btn-outline-primary sf-worksite-manage-btn"
                    data-no-row-click
                    data-modal-open="#<?= htmlspecialchars($manageModalId, ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars(sf_term('settings_worksites_manage_btn', $currentUiLang) ?? 'Hallinnoi', ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>

        <div class="sf-modal hidden sf-worksite-manage-modal" id="<?= htmlspecialchars($manageModalId, ENT_QUOTES, 'UTF-8') ?>" role="dialog" aria-modal="true" aria-labelledby="<?= htmlspecialchars($manageModalId, ENT_QUOTES, 'UTF-8') ?>Title">
            <div class="sf-modal-content sf-worksite-manage-content">
                <div class="sf-modal-header">
                    <h3 id="<?= htmlspecialchars($manageModalId, ENT_QUOTES, 'UTF-8') ?>Title"><?= htmlspecialchars($worksiteName, ENT_QUOTES, 'UTF-8') ?></h3>
                    <span class="sf-status-badge <?= $isActive ? 'is-active' : 'is-inactive' ?>">
                        <?= htmlspecialchars($isActive ? (sf_term('settings_worksites_status_active', $currentUiLang) ?? 'Aktiivinen') : (sf_term('settings_worksites_status_inactive', $currentUiLang) ?? 'Passiivinen'), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                    <button type="button" data-modal-close class="sf-modal-close" aria-label="<?= htmlspecialchars(sf_term('settings_worksites_close', $currentUiLang) ?? 'Sulje', ENT_QUOTES, 'UTF-8') ?>">✕</button>
                </div>
                <div class="sf-modal-body sf-worksite-manage-body">
                    <section class="sf-worksite-manage-section">
                        <h4><?= htmlspecialchars(sf_term('settings_worksites_basic_info', $currentUiLang) ?? 'Perustiedot', ENT_QUOTES, 'UTF-8') ?></h4>
                        <dl class="sf-worksite-manage-meta">
                            <div><dt><?= htmlspecialchars(sf_term('settings_worksites_col_name', $currentUiLang) ?? 'Nimi', ENT_QUOTES, 'UTF-8') ?></dt><dd><?= htmlspecialchars($worksiteName, ENT_QUOTES, 'UTF-8') ?></dd></div>
                            <div><dt><?= htmlspecialchars(sf_term('settings_worksites_site_type', $currentUiLang) ?? 'Työmaan tyyppi', ENT_QUOTES, 'UTF-8') ?></dt><dd><?= htmlspecialchars($siteTypeLabel, ENT_QUOTES, 'UTF-8') ?></dd></div>
                            <div><dt><?= htmlspecialchars(sf_term('settings_worksites_col_created', $currentUiLang) ?? 'Luotu', ENT_QUOTES, 'UTF-8') ?></dt><dd><?= htmlspecialchars(sf_worksite_format_datetime(isset($ws['created_at']) ? (string)$ws['created_at'] : null), ENT_QUOTES, 'UTF-8') ?></dd></div>
                            <div><dt><?= htmlspecialchars(sf_term('settings_worksites_col_updated', $currentUiLang) ?? 'Viimeksi päivitetty', ENT_QUOTES, 'UTF-8') ?></dt><dd><?= htmlspecialchars(sf_worksite_format_datetime(isset($ws['updated_at']) ? (string)$ws['updated_at'] : null), ENT_QUOTES, 'UTF-8') ?></dd></div>
                        </dl>
                        <button type="button"
                                class="sf-btn sf-btn-sm sf-btn-outline-secondary sf-ws-edit-btn"
                                data-ws-id="<?= $worksiteId ?>"
                                data-ws-name="<?= htmlspecialchars($worksiteName, ENT_QUOTES, 'UTF-8') ?>"
                                data-ws-site-type="<?= htmlspecialchars($ws['site_type'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars(sf_term('settings_worksites_action_edit', $currentUiLang) ?? 'Muokkaa', ENT_QUOTES, 'UTF-8') ?>
                        </button>
                    </section>

                    <section class="sf-worksite-manage-section">
                        <h4><?= htmlspecialchars(sf_term('settings_worksites_visibility', $currentUiLang) ?? 'Näkyvyys', ENT_QUOTES, 'UTF-8') ?></h4>
                        <div class="sf-worksite-modal-visibility">
                            <label class="sf-toggle-compact sf-toggle-compact-modal" for="ws-modal-lists-<?= $worksiteId ?>">
                                <input type="checkbox"
                                       id="ws-modal-lists-<?= $worksiteId ?>"
                                       class="sf-worksite-visibility-toggle"
                                       data-worksite-id="<?= $worksiteId ?>"
                                       data-field="show_in_worksite_lists"
                                       <?= $showInLists ? 'checked' : '' ?>>
                                <span class="sf-toggle-slider"></span>
                                <span class="sf-toggle-label"><?= htmlspecialchars(sf_term('settings_worksites_toggle_lists_short', $currentUiLang) ?? 'Listat', ENT_QUOTES, 'UTF-8') ?></span>
                            </label>
                            <p class="sf-worksite-help-text"><?= htmlspecialchars($visibilityListsDesc, ENT_QUOTES, 'UTF-8') ?></p>
                            <label class="sf-toggle-compact sf-toggle-compact-modal" for="ws-modal-displays-<?= $worksiteId ?>">
                                <input type="checkbox"
                                       id="ws-modal-displays-<?= $worksiteId ?>"
                                       class="sf-worksite-visibility-toggle"
                                       data-worksite-id="<?= $worksiteId ?>"
                                       data-field="show_in_display_targets"
                                       <?= $showInDisplays ? 'checked' : '' ?>>
                                <span class="sf-toggle-slider"></span>
                                <span class="sf-toggle-label"><?= htmlspecialchars(sf_term('settings_worksites_toggle_displays_short', $currentUiLang) ?? 'Näytöt', ENT_QUOTES, 'UTF-8') ?></span>
                            </label>
                            <p class="sf-worksite-help-text"><?= htmlspecialchars($visibilityDisplaysDesc, ENT_QUOTES, 'UTF-8') ?></p>
                        </div>
                    </section>

                    <section class="sf-worksite-manage-section">
                        <h4><?= htmlspecialchars(sf_term('settings_worksites_col_api_key', $currentUiLang) ?? 'API-avain', ENT_QUOTES, 'UTF-8') ?></h4>
                        <?php if (!empty($ws['display_api_key'])): ?>
                            <div class="sf-worksite-copy-row">
                                <code id="sfWsApiKeyText<?= $worksiteId ?>" class="sf-worksite-code"><?= htmlspecialchars((string)$ws['display_api_key'], ENT_QUOTES, 'UTF-8') ?></code>
                                <button type="button" class="sf-btn sf-btn-sm sf-btn-outline-primary sf-copy-btn" data-copy-target="sfWsApiKeyText<?= $worksiteId ?>" data-copy-feedback="sfWsApiCopied<?= $worksiteId ?>">
                                    <?= htmlspecialchars(sf_term('btn_copy_api_key', $currentUiLang) ?? 'Kopioi', ENT_QUOTES, 'UTF-8') ?>
                                </button>
                            </div>
                            <span class="sf-copy-feedback" id="sfWsApiCopied<?= $worksiteId ?>"><?= htmlspecialchars(sf_term('xibo_copied', $currentUiLang) ?? 'Kopioitu!', ENT_QUOTES, 'UTF-8') ?></span>

                            <div class="sf-worksite-copy-row">
                                <code id="sfWsSlideshowUrl<?= $worksiteId ?>" class="sf-worksite-code"><?= htmlspecialchars($slideshowUrl, ENT_QUOTES, 'UTF-8') ?></code>
                                <button type="button" class="sf-btn sf-btn-sm sf-btn-outline-primary sf-copy-btn" data-copy-target="sfWsSlideshowUrl<?= $worksiteId ?>" data-copy-feedback="sfWsSlideshowCopied<?= $worksiteId ?>">
                                    <?= htmlspecialchars(sf_term('btn_copy', $currentUiLang) ?? 'Kopioi', ENT_QUOTES, 'UTF-8') ?>
                                </button>
                            </div>
                            <span class="sf-copy-feedback" id="sfWsSlideshowCopied<?= $worksiteId ?>"><?= htmlspecialchars(sf_term('xibo_copied', $currentUiLang) ?? 'Kopioitu!', ENT_QUOTES, 'UTF-8') ?></span>

                            <button type="button" class="sf-btn sf-btn-sm sf-btn-outline-primary" data-modal-open="#xiboModal<?= $worksiteId ?>">
                                <?= htmlspecialchars(sf_term('xibo_col_heading', $currentUiLang) ?? 'Xibo-koodi', ENT_QUOTES, 'UTF-8') ?>
                            </button>
                        <?php else: ?>
                            <p class="sf-worksite-help-text"><?= htmlspecialchars(sf_term('settings_worksites_no_api_key', $currentUiLang) ?? 'Ei avainta', ENT_QUOTES, 'UTF-8') ?></p>
                        <?php endif; ?>
                    </section>

                    <section class="sf-worksite-manage-section">
                        <h4><?= htmlspecialchars(sf_term('settings_worksites_col_playlist', $currentUiLang) ?? 'Ajolista', ENT_QUOTES, 'UTF-8') ?></h4>
                        <p class="sf-worksite-help-text"><?= htmlspecialchars(sprintf((sf_term('settings_worksites_meta_flash_count', $currentUiLang) ?? '%d ajolistassa'), $flashCount), ENT_QUOTES, 'UTF-8') ?></p>
                        <?php if ($playlistUrl !== ''): ?>
                            <a href="<?= htmlspecialchars($playlistUrl, ENT_QUOTES, 'UTF-8') ?>" class="sf-btn sf-btn-sm sf-btn-outline-primary">
                                <?= htmlspecialchars(sf_term('settings_worksites_col_playlist', $currentUiLang) ?? 'Ajolista', ENT_QUOTES, 'UTF-8') ?>
                            </a>
                        <?php endif; ?>
                    </section>
                </div>
                <div class="sf-modal-footer sf-worksite-manage-footer">
                    <form method="post" class="sf-inline-form" action="app/actions/worksites_save.php" data-sf-ajax="1">
                        <input type="hidden" name="form_action" value="toggle">
                        <?= sf_csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $worksiteId ?>">
                        <button type="submit" class="sf-btn sf-btn-sm sf-btn-outline-secondary">
                            <?= htmlspecialchars($isActive ? (sf_term('settings_worksites_action_disable', $currentUiLang) ?? 'Passivoi') : (sf_term('settings_worksites_action_enable', $currentUiLang) ?? 'Aktivoi'), ENT_QUOTES, 'UTF-8') ?>
                        </button>
                    </form>
                    <button type="button" data-modal-close class="sf-btn sf-btn-sm sf-btn-secondary">
                        <?= htmlspecialchars(sf_term('settings_worksites_close', $currentUiLang) ?? 'Sulje', ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<script>
(function () {
    'use strict';

    var baseUrl = <?= json_encode(rtrim($baseUrl, '/'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var csrfToken = <?= json_encode($_SESSION['csrf_token'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var saveError = <?= json_encode(sf_term('save_error', $currentUiLang) ?? 'Tallennus epäonnistui', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var showingCountTemplate = <?= json_encode(sf_term('settings_worksites_showing_count', $currentUiLang) ?? 'Näytetään %d / %d työmaata', JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    function showError(message) {
        if (typeof window.sfToast === 'function') {
            window.sfToast('error', message || saveError);
        } else {
            alert(message || saveError);
        }
    }

    var closestFromEventTarget = window.sfClosestFromEventTarget || function (target, selector) {
        if (target && typeof target.closest === 'function') {
            return target.closest(selector);
        }
        if (target && target.parentElement && typeof target.parentElement.closest === 'function') {
            return target.parentElement.closest(selector);
        }
        return null;
    };
    window.sfClosestFromEventTarget = closestFromEventTarget;

    function getFocusableElements(modal) {
        if (!modal) return [];
        var all = modal.querySelectorAll('a[href], button:not([disabled]), textarea:not([disabled]), input:not([type="hidden"]):not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])');
        return Array.prototype.slice.call(all).filter(function (el) {
            return el.offsetParent !== null;
        });
    }

    function openWorksiteModal(selector) {
        if (!selector) return;
        var modal = document.querySelector(selector);
        if (!modal) return;
        modal.classList.remove('hidden');
        document.body.classList.add('sf-modal-open');
        var focusable = getFocusableElements(modal);
        if (focusable.length > 0) {
            focusable[0].focus({ preventScroll: true });
        }
    }

    if (document.__sfWsClickHandler) {
        document.removeEventListener('click', document.__sfWsClickHandler);
    }
    document.__sfWsClickHandler = function (event) {
        var row = closestFromEventTarget(event.target, '.sf-worksite-row');
        if (!row) return;
        if (closestFromEventTarget(event.target, '[data-no-row-click]')) return;
        openWorksiteModal(row.getAttribute('data-modal'));
    };
    document.addEventListener('click', document.__sfWsClickHandler);

    if (document.__sfWsKeydownHandler) {
        document.removeEventListener('keydown', document.__sfWsKeydownHandler);
    }
    document.__sfWsKeydownHandler = function (event) {
        var row = closestFromEventTarget(event.target, '.sf-worksite-row');
        if (row && (event.key === 'Enter' || event.key === ' ')) {
            event.preventDefault();
            if (!closestFromEventTarget(event.target, '[data-no-row-click]')) {
                openWorksiteModal(row.getAttribute('data-modal'));
            }
        }

        if (event.key !== 'Tab') return;
        var modal = document.querySelector('.sf-worksite-manage-modal:not(.hidden)');
        if (!modal) return;
        var focusable = getFocusableElements(modal);
        if (focusable.length === 0) return;
        var first = focusable[0];
        var last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    };
    document.addEventListener('keydown', document.__sfWsKeydownHandler);

    if (document.__sfWsChangeHandler) {
        document.removeEventListener('change', document.__sfWsChangeHandler);
    }
    document.__sfWsChangeHandler = function (event) {
        var input = event.target;
        if (!input || !input.classList || !input.classList.contains('sf-worksite-visibility-toggle')) return;

        var previousState = !input.checked;
        var worksiteId = input.getAttribute('data-worksite-id');
        var field = input.getAttribute('data-field');
        var row = document.querySelector('.sf-worksite-row[data-worksite-id="' + worksiteId + '"]');
        if (row) row.setAttribute('aria-busy', 'true');

        var relatedInputs = document.querySelectorAll('.sf-worksite-visibility-toggle[data-worksite-id="' + worksiteId + '"][data-field="' + field + '"]');
        relatedInputs.forEach(function (related) {
            related.disabled = true;
        });

        var fd = new FormData();
        fd.append('action', 'toggle_worksite_visibility');
        fd.append('worksite_id', worksiteId || '0');
        fd.append('field', field || '');
        fd.append('value', input.checked ? '1' : '0');
        fd.append('csrf_token', csrfToken || '');

        fetch(baseUrl + '/app/actions/worksites_save.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'X-Requested-With': 'fetch',
                'Accept': 'application/json'
            },
            body: fd
        }).then(function (response) {
            return response.json().then(function (json) {
                return { ok: response.ok, data: json };
            });
        }).then(function (result) {
            if (!result.ok || !result.data || result.data.ok === false) {
                throw new Error((result.data && (result.data.error || result.data.message)) || saveError);
            }

            var normalizedValue = Number(result.data.value) === 1;
            relatedInputs.forEach(function (related) {
                related.checked = normalizedValue;
            });

            if (row) {
                if (field === 'show_in_worksite_lists') row.setAttribute('data-lists', normalizedValue ? '1' : '0');
                if (field === 'show_in_display_targets') row.setAttribute('data-displays', normalizedValue ? '1' : '0');
            }
            applyFilters();
        }).catch(function (error) {
            relatedInputs.forEach(function (related) {
                related.checked = previousState;
            });
            showError(error && error.message ? error.message : saveError);
        }).finally(function () {
            if (row) row.removeAttribute('aria-busy');
            relatedInputs.forEach(function (related) {
                related.disabled = false;
            });
        });
    };
    document.addEventListener('change', document.__sfWsChangeHandler);

    var searchInput = document.getElementById('sfWorksiteSearch');
    var list = document.getElementById('sfWorksiteList');
    var showingCount = document.getElementById('sfWorksiteShowingCount');
    if (!list) return;

    var rows = Array.prototype.slice.call(list.querySelectorAll('.sf-worksite-row'));
    var chips = Array.prototype.slice.call(document.querySelectorAll('.sf-filter-chip'));
    var activeFilter = 'all';

    function matchesFilter(row, filter) {
        if (filter === 'active') return row.getAttribute('data-active') === '1';
        if (filter === 'inactive') return row.getAttribute('data-active') === '0';
        if (filter === 'lists') return row.getAttribute('data-lists') === '1';
        if (filter === 'displays') return row.getAttribute('data-displays') === '1';
        return true;
    }

    function formatShowingCount(visible, total) {
        var index = 0;
        return showingCountTemplate.replace(/%d/g, function () {
            index += 1;
            return String(index === 1 ? visible : total);
        });
    }

    function applyFilters() {
        var term = ((searchInput && searchInput.value) || '').toLowerCase().trim();
        rows.forEach(function (row) {
            var name = row.getAttribute('data-name') || '';
            var matchesSearch = term === '' || name.indexOf(term) !== -1;
            var matchesChip = matchesFilter(row, activeFilter);
            var isVisible = matchesSearch && matchesChip;
            row.hidden = !isVisible;
            row.setAttribute('aria-hidden', isVisible ? 'false' : 'true');
        });
        if (showingCount) {
            var visibleCount = rows.filter(function (row) {
                return !row.hidden;
            }).length;
            showingCount.textContent = formatShowingCount(visibleCount, rows.length);
        }
    }

    if (searchInput) {
        searchInput.addEventListener('input', applyFilters);
    }

    chips.forEach(function (chip) {
        chip.addEventListener('click', function () {
            chips.forEach(function (btn) { btn.classList.remove('is-active'); });
            chip.classList.add('is-active');
            activeFilter = chip.getAttribute('data-filter') || 'all';
            applyFilters();
        });
    });

    applyFilters();
})();
</script>
<?php endif; ?>

<!-- Add Worksite Modal -->
<div class="sf-modal hidden" id="modalAddWorksite" role="dialog" aria-modal="true" aria-labelledby="modalAddWorksiteTitle">
    <div class="sf-modal-content">
        <div class="sf-modal-header">
            <h3 id="modalAddWorksiteTitle">
                <?= htmlspecialchars(sf_term('settings_worksites_add_modal_title', $currentUiLang) ?? 'Lisää uusi työmaa', ENT_QUOTES, 'UTF-8') ?>
            </h3>
            <button type="button" data-modal-close class="sf-modal-close" aria-label="<?= htmlspecialchars(sf_term('btn_close', $currentUiLang) ?? 'Sulje', ENT_QUOTES, 'UTF-8') ?>">✕</button>
        </div>
        <form method="post" action="app/actions/worksites_save.php" data-sf-ajax="1" id="formAddWorksite">
            <div class="sf-modal-body" style="padding:1.25rem;">
                <input type="hidden" name="form_action" value="add">
                <input type="hidden" name="has_visibility_fields" value="1">
                <?= sf_csrf_field() ?>
                <div style="margin-bottom:1rem;">
                    <label for="ws-name" style="display:block;margin-bottom:0.35rem;font-weight:500;">
                        <?= htmlspecialchars(sf_term('settings_worksites_col_name', $currentUiLang) ?? 'Nimi', ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <input type="text" id="ws-name" name="name" required class="sf-input" style="width:100%;">
                </div>
                <div style="margin-bottom:1rem;">
                    <label for="ws-site-type" style="display:block;margin-bottom:0.35rem;font-weight:500;">
                        <?= htmlspecialchars(sf_term('settings_worksites_site_type', $currentUiLang) ?? 'Työmaan tyyppi', ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <select id="ws-site-type" name="site_type" class="sf-select" style="width:100%;">
                        <option value=""><?= htmlspecialchars(sf_term('site_type_unspecified', $currentUiLang) ?? 'Määrittämätön', ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="tunnel"><?= htmlspecialchars(sf_term('site_type_tunnel', $currentUiLang) ?? 'Tunnelityömaa', ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="opencast"><?= htmlspecialchars(sf_term('site_type_opencast', $currentUiLang) ?? 'Avolouhos', ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="other"><?= htmlspecialchars(sf_term('site_type_other', $currentUiLang) ?? 'Muut toimipisteet', ENT_QUOTES, 'UTF-8') ?></option>
                    </select>
                </div>
                <div style="margin-bottom:0.25rem;">
                    <p style="margin:0 0 0.5rem;font-weight:600;">
                        <?= htmlspecialchars(sf_term('settings_worksites_visibility_heading', $currentUiLang) ?? 'Näkyvyys', ENT_QUOTES, 'UTF-8') ?>
                    </p>
                    <div class="sf-worksite-modal-visibility">
                        <label class="sf-checkbox-label" for="ws-show-in-lists">
                            <input type="checkbox" id="ws-show-in-lists" name="show_in_worksite_lists" value="1" checked>
                            <?= htmlspecialchars(sf_term('settings_worksites_show_in_lists_label', $currentUiLang) ?? 'Näytä työmaalistoissa', ENT_QUOTES, 'UTF-8') ?>
                        </label>
                        <p class="sf-worksite-help-text"><?= htmlspecialchars($visibilityListsDesc, ENT_QUOTES, 'UTF-8') ?></p>
                        <label class="sf-checkbox-label" for="ws-show-in-displays">
                            <input type="checkbox" id="ws-show-in-displays" name="show_in_display_targets" value="1" checked>
                            <?= htmlspecialchars(sf_term('settings_worksites_show_in_displays_label', $currentUiLang) ?? 'Näytä infonäyttövalinnoissa', ENT_QUOTES, 'UTF-8') ?>
                        </label>
                        <p class="sf-worksite-help-text"><?= htmlspecialchars($visibilityDisplaysDesc, ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                </div>
            </div>
            <div class="sf-modal-footer" style="padding:1rem 1.25rem;display:flex;justify-content:flex-end;gap:0.5rem;">
                <button type="button" data-modal-close class="sf-btn sf-btn-secondary">
                    <?= htmlspecialchars(sf_term('btn_close', $currentUiLang) ?? 'Sulje', ENT_QUOTES, 'UTF-8') ?>
                </button>
                <button type="submit" class="sf-btn sf-btn-primary">
                    <?= htmlspecialchars(sf_term('settings_worksites_add_button', $currentUiLang) ?? 'Lisää uusi työmaa', ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </form>
    </div>
</div>

<?php
// Xibo modals - one per worksite that has an API key
foreach ($worksites as $ws):
    if (empty($ws['display_api_key'])) continue;
    $xiboKey = $ws['display_api_key'];
    $xiboLabel = htmlspecialchars($ws['name'], ENT_QUOTES, 'UTF-8');
    $xiboWsId = (int)$ws['id'];
    $playlistBase = rtrim($baseUrl ?? '', '/') . '/app/api/display_playlist.php';
    $htmlUrl = $playlistBase . '?key=' . urlencode($xiboKey) . '&format=html';
    $jsonUrl = $playlistBase . '?key=' . urlencode($xiboKey);
    $jsonApiUrl = $jsonUrl . '&format=json';
    $embeddedHtml = '<div id="sf-slideshow">' . "\n"
        . '  <div id="sf-slide" style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:#1a1a2e;">' . "\n"
        . '    <p style="color:#aaa;font-size:1.5em;">Ladataan&#8230;</p>' . "\n"
        . '  </div>' . "\n"
        . '</div>' . "\n\n"
        . '<script>' . "\n"
        . '(function(){' . "\n"
        . '  var API_URL = "' . $jsonApiUrl . '";' . "\n"
        . '  var REFRESH_MIN = 5;' . "\n"
        . '  var container = document.getElementById("sf-slide");' . "\n"
        . '  var current = 0, items = [], timer = null;' . "\n\n"
        . '  function setXiboDuration(s){' . "\n"
        . '    if(typeof xiboIC!=="undefined"&&xiboIC.setDuration) xiboIC.setDuration(s);' . "\n"
        . '  }' . "\n\n"
        . '  function expireXibo(){' . "\n"
        . '    if(typeof xiboIC!=="undefined"&&xiboIC.expireNow) xiboIC.expireNow();' . "\n"
        . '  }' . "\n\n"
        . '  function exitShow(){' . "\n"
        . '    var TRIGGER_CODE = \'menes_nyt\';' . "\n"
        . '    fetch(\'/trigger\', {' . "\n"
        . '      method: \'POST\',' . "\n"
        . '      headers: { \'Content-Type\': \'application/json\' },' . "\n"
        . '      body: JSON.stringify({ trigger: TRIGGER_CODE })' . "\n"
        . '    })' . "\n"
        . '    .then(function(r){ console.log(\'Webhook JSON POST status:\', r.status); })' . "\n"
        . '    .catch(function(err){ console.error(\'Webhook JSON POST error:\', err); });' . "\n"
        . '    expireXibo();' . "\n"
        . '  }' . "\n\n"
        . '  function load(){' . "\n"
        . '    var xhr = new XMLHttpRequest();' . "\n"
        . '    xhr.open("GET", API_URL, true);' . "\n"
        . '    xhr.onload = function(){' . "\n"
        . '      if(xhr.status===200){' . "\n"
        . '        try {' . "\n"
        . '          var data = JSON.parse(xhr.responseText);' . "\n"
        . '          if(data.ok && data.items && data.items.length > 0){' . "\n"
        . '            startSlideshow(data.items);' . "\n"
        . '          } else {' . "\n"
        . '            showEmpty(data.fallback_image || null);' . "\n"
        . '          }' . "\n"
        . '        } catch(e){ showError(); }' . "\n"
        . '      } else { showError(); }' . "\n"
        . '    };' . "\n"
        . '    xhr.onerror = function(){ showError(); };' . "\n"
        . '    xhr.send();' . "\n"
        . '  }' . "\n\n"
        . '  function startSlideshow(list){' . "\n"
        . '    items = list; current = 0; clearTimeout(timer);' . "\n"
        . '    var total = 0;' . "\n"
        . '    for(var i=0;i<items.length;i++) total += (items[i].duration_seconds||30);' . "\n"
        . '    setXiboDuration(total);' . "\n"
        . '    preload(function(){ showSlide(); });' . "\n"
        . '  }' . "\n\n"
        . '  function preload(cb){' . "\n"
        . '    var n=0, t=items.length;' . "\n"
        . '    if(!t){cb();return;}' . "\n"
        . '    for(var i=0;i<t;i++){var img=new Image();img.onload=img.onerror=function(){n++;if(n>=t)cb();};img.src=items[i].image_url;}' . "\n"
        . '    setTimeout(function(){if(n<t)cb();},8000);' . "\n"
        . '  }' . "\n\n"
        . '  function showSlide(){' . "\n"
        . '    if(!items.length) return;' . "\n"
        . '    var item = items[current];' . "\n"
        . '    container.innerHTML =' . "\n"
        . '      \'<img src="\' + item.image_url + \'" alt="" style="max-width:100%;max-height:100%;object-fit:contain;">\';' . "\n"
        . '    var dur = (item.duration_seconds || 10) * 1000;' . "\n"
        . '    if(current === items.length - 1){' . "\n"
        . '      clearTimeout(timer);' . "\n"
        . '      timer = setTimeout(exitShow, dur);' . "\n"
        . '    } else {' . "\n"
        . '      clearTimeout(timer);' . "\n"
        . '      timer = setTimeout(function(){' . "\n"
        . '        current = (current + 1) % items.length;' . "\n"
        . '        showSlide();' . "\n"
        . '      }, dur);' . "\n"
        . '    }' . "\n"
        . '  }' . "\n\n"
        . '  function showEmpty(fallbackUrl){' . "\n"
        . '    if(fallbackUrl){' . "\n"
        . '      setXiboDuration(5);' . "\n"
        . '      container.innerHTML =' . "\n"
        . '        \'<img src="\' + fallbackUrl + \'" alt="" style="max-width:100%;max-height:100%;object-fit:contain;">\';' . "\n"
        . '      setTimeout(expireXibo, 5000);' . "\n"
        . '    } else {' . "\n"
        . '      setXiboDuration(1);' . "\n"
        . '      container.innerHTML = "";' . "\n"
        . '      document.body.style.background = "transparent";' . "\n"
        . '      expireXibo();' . "\n"
        . '    }' . "\n"
        . '  }' . "\n\n"
        . '  function showError(){' . "\n"
        . '    setXiboDuration(10);' . "\n"
        . '    container.innerHTML =' . "\n"
        . '      \'<p style="color:#f66;font-size:1.2em;text-align:center;">Yhteysvirhe</p>\';' . "\n"
        . '    setTimeout(expireXibo, 10000);' . "\n"
        . '  }' . "\n\n"
        . '  load();' . "\n"
        . '  setInterval(load, REFRESH_MIN * 60 * 1000);' . "\n"
        . '})();' . "\n"
        . '</script>';
    $embeddedCss = 'body, html {' . "\n"
        . '  margin: 0;' . "\n"
        . '  padding: 0;' . "\n"
        . '  width: 100%;' . "\n"
        . '  height: 100%;' . "\n"
        . '  overflow: hidden;' . "\n"
        . '  background: #1a1a2e;' . "\n"
        . '  font-family: -apple-system, "Segoe UI", sans-serif;' . "\n"
        . '}' . "\n\n"
        . '#sf-slideshow {' . "\n"
        . '  width: 100%;' . "\n"
        . '  height: 100%;' . "\n"
        . '}' . "\n\n"
        . '#sf-slide {' . "\n"
        . '  width: 100%;' . "\n"
        . '  height: 100%;' . "\n"
        . '  display: flex;' . "\n"
        . '  align-items: center;' . "\n"
        . '  justify-content: center;' . "\n"
        . '}' . "\n\n"
        . '#sf-slide img {' . "\n"
        . '  max-width: 100%;' . "\n"
        . '  max-height: 100%;' . "\n"
        . '  object-fit: contain;' . "\n"
        . '  animation: sf-fadein 0.6s ease;' . "\n"
        . '}' . "\n\n"
        . '@keyframes sf-fadein {' . "\n"
        . '  from { opacity: 0; }' . "\n"
        . '  to   { opacity: 1; }' . "\n"
        . '}';
?>
<div class="sf-modal hidden" id="xiboModal<?= $xiboWsId ?>" role="dialog" aria-modal="true" aria-labelledby="xiboModalTitle<?= $xiboWsId ?>">
    <div class="sf-modal-content">
        <div class="sf-modal-header">
            <h3 id="xiboModalTitle<?= $xiboWsId ?>">
                <?= htmlspecialchars(sf_term('xibo_code_heading', $currentUiLang) ?? 'Xibo-integraatiokoodi', ENT_QUOTES, 'UTF-8') ?>
                — <?= $xiboLabel ?>
            </h3>
            <button type="button" data-modal-close class="sf-modal-close" aria-label="<?= htmlspecialchars(sf_term('btn_close', $currentUiLang) ?? 'Sulje', ENT_QUOTES, 'UTF-8') ?>">✕</button>
        </div>
        <div class="sf-modal-body" style="padding:1.25rem;">
            <p style="margin-bottom:1rem;color:var(--sf-text-secondary,#666);font-size:0.9rem;">
                <?= htmlspecialchars(sf_term('xibo_instructions', $currentUiLang) ?? 'Kopioi URL ja liitä se Xibo CMS:n Webpage-widgetin URL-kenttään', ENT_QUOTES, 'UTF-8') ?>
            </p>

            <div style="margin-bottom:1.25rem;">
                <strong style="display:block;margin-bottom:0.4rem;"><?= htmlspecialchars(sf_term('xibo_webpage_url_label', $currentUiLang) ?? 'Webpage Widget URL', ENT_QUOTES, 'UTF-8') ?></strong>
                <div style="display:flex;gap:0.5rem;align-items:stretch;">
                    <code id="xiboHtmlUrl<?= $xiboWsId ?>" style="flex:1;display:block;background:var(--sf-bg-secondary,#f5f5f5);padding:0.5rem 0.75rem;border-radius:4px;font-size:0.82rem;word-break:break-all;"><?= htmlspecialchars($htmlUrl, ENT_QUOTES, 'UTF-8') ?></code>
                    <button type="button" class="sf-btn sf-btn-sm sf-btn-outline-primary sf-xibo-copy-btn" data-copy-target="xiboHtmlUrl<?= $xiboWsId ?>" data-ws-id="<?= $xiboWsId ?>-url">
                        <?= htmlspecialchars(sf_term('xibo_copy_url', $currentUiLang) ?? 'Kopioi URL', ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </div>
                <span id="xiboCopied<?= $xiboWsId ?>-url" style="display:none;color:green;font-size:0.85rem;margin-top:0.25rem;"><?= htmlspecialchars(sf_term('xibo_copied', $currentUiLang) ?? 'Kopioitu!', ENT_QUOTES, 'UTF-8') ?></span>
            </div>

            <div style="margin-bottom:1.25rem;">
                <strong style="display:block;margin-bottom:0.25rem;"><?= htmlspecialchars(sf_term('xibo_embedded_html_label', $currentUiLang) ?? 'HTML-kenttä (Embedded Widget)', ENT_QUOTES, 'UTF-8') ?></strong>
                <p style="margin:0 0 0.5rem;color:var(--sf-text-secondary,#666);font-size:0.85rem;"><?= htmlspecialchars(sf_term('xibo_embedded_instructions', $currentUiLang) ?? 'Liitä HTML ja CSS Xibon Embedded Widget -kenttiin', ENT_QUOTES, 'UTF-8') ?></p>
                <pre id="xiboEmbedHtml<?= $xiboWsId ?>" style="background:var(--sf-bg-secondary,#f5f5f5);padding:0.5rem 0.75rem;border-radius:4px;font-size:0.78rem;overflow:auto;max-height:200px;white-space:pre-wrap;word-break:break-all;margin:0 0 0.4rem;"><code><?= htmlspecialchars($embeddedHtml, ENT_QUOTES, 'UTF-8') ?></code></pre>
                <div style="display:flex;gap:0.5rem;align-items:center;">
                    <button type="button" class="sf-btn sf-btn-sm sf-btn-outline-primary sf-xibo-copy-btn" data-copy-target="xiboEmbedHtml<?= $xiboWsId ?>" data-ws-id="<?= $xiboWsId ?>-html">
                        <?= htmlspecialchars(sf_term('xibo_copy_html', $currentUiLang) ?? 'Kopioi HTML', ENT_QUOTES, 'UTF-8') ?>
                    </button>
                    <span id="xiboCopied<?= $xiboWsId ?>-html" style="display:none;color:green;font-size:0.85rem;"><?= htmlspecialchars(sf_term('xibo_copied', $currentUiLang) ?? 'Kopioitu!', ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </div>

            <div style="margin-bottom:1rem;">
                <strong style="display:block;margin-bottom:0.4rem;"><?= htmlspecialchars(sf_term('xibo_embedded_css_label', $currentUiLang) ?? 'CSS-kenttä (Embedded Widget)', ENT_QUOTES, 'UTF-8') ?></strong>
                <pre id="xiboEmbedCss<?= $xiboWsId ?>" style="background:var(--sf-bg-secondary,#f5f5f5);padding:0.5rem 0.75rem;border-radius:4px;font-size:0.78rem;overflow:auto;max-height:200px;white-space:pre-wrap;word-break:break-all;margin:0 0 0.4rem;"><code><?= htmlspecialchars($embeddedCss, ENT_QUOTES, 'UTF-8') ?></code></pre>
                <div style="display:flex;gap:0.5rem;align-items:center;">
                    <button type="button" class="sf-btn sf-btn-sm sf-btn-outline-primary sf-xibo-copy-btn" data-copy-target="xiboEmbedCss<?= $xiboWsId ?>" data-ws-id="<?= $xiboWsId ?>-css">
                        <?= htmlspecialchars(sf_term('xibo_copy_css', $currentUiLang) ?? 'Kopioi CSS', ENT_QUOTES, 'UTF-8') ?>
                    </button>
                    <span id="xiboCopied<?= $xiboWsId ?>-css" style="display:none;color:green;font-size:0.85rem;"><?= htmlspecialchars(sf_term('xibo_copied', $currentUiLang) ?? 'Kopioitu!', ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </div>
        </div>
        <div class="sf-modal-footer" style="padding:1rem 1.25rem;text-align:right;">
            <button type="button" data-modal-close class="sf-btn sf-btn-secondary">
                <?= htmlspecialchars(sf_term('btn_close', $currentUiLang) ?? 'Sulje', ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>
<?php endforeach; ?>

<script>
(function () {
    var closestFromEventTarget = window.sfClosestFromEventTarget;
    if (typeof closestFromEventTarget !== 'function') return;

    if (document.__sfWsXiboCopyClickHandler) {
        document.removeEventListener('click', document.__sfWsXiboCopyClickHandler);
    }
    document.__sfWsXiboCopyClickHandler = function (e) {
        var btn = closestFromEventTarget(e.target, '.sf-xibo-copy-btn, .sf-copy-btn');
        if (!btn) return;
        var targetId = btn.getAttribute('data-copy-target');
        var wsId = btn.getAttribute('data-ws-id');
        var feedbackId = btn.getAttribute('data-copy-feedback');
        var el = document.getElementById(targetId);
        if (!el) return;
        var text = el.textContent;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                showCopied(wsId, feedbackId);
            }).catch(function () {
                fallbackCopy(text, wsId, feedbackId);
            });
        } else {
            fallbackCopy(text, wsId, feedbackId);
        }
    };
    document.addEventListener('click', document.__sfWsXiboCopyClickHandler);

    function fallbackCopy(text, wsId, feedbackId) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        try { document.execCommand('copy'); } catch (err) {}
        document.body.removeChild(ta);
        showCopied(wsId, feedbackId);
    }

    function showCopied(wsId, feedbackId) {
        var msg = feedbackId ? document.getElementById(feedbackId) : document.getElementById('xiboCopied' + wsId);
        if (!msg) return;
        msg.style.display = 'inline';
        setTimeout(function () { msg.style.display = 'none'; }, 2000);
    }
})();
</script>

<!-- Edit Worksite Modal -->
<div class="sf-modal hidden" id="modalEditWorksite" role="dialog" aria-modal="true" aria-labelledby="modalEditWorksiteTitle">
    <div class="sf-modal-content">
        <div class="sf-modal-header">
            <h3 id="modalEditWorksiteTitle">
                <?= htmlspecialchars(sf_term('settings_worksites_edit_title', $currentUiLang) ?? 'Muokkaa työmaata', ENT_QUOTES, 'UTF-8') ?>
            </h3>
            <button type="button" data-modal-close class="sf-modal-close" aria-label="<?= htmlspecialchars(sf_term('btn_close', $currentUiLang) ?? 'Sulje', ENT_QUOTES, 'UTF-8') ?>">✕</button>
        </div>
        <form method="post" action="app/actions/worksites_save.php" data-sf-ajax="1" id="formEditWorksite">
            <div class="sf-modal-body" style="padding:1.25rem;">
                <input type="hidden" name="form_action" value="edit">
                <?= sf_csrf_field() ?>
                <input type="hidden" name="id" id="editWsId">
                <div style="margin-bottom:1rem;">
                    <label for="editWsName" style="display:block;margin-bottom:0.35rem;font-weight:500;">
                        <?= htmlspecialchars(sf_term('settings_worksites_col_name', $currentUiLang) ?? 'Nimi', ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <input type="text" id="editWsName" name="name" required class="sf-input" style="width:100%;">
                </div>
                <div style="margin-bottom:0.5rem;">
                    <label for="editWsSiteType" style="display:block;margin-bottom:0.35rem;font-weight:500;">
                        <?= htmlspecialchars(sf_term('settings_worksites_site_type', $currentUiLang) ?? 'Työmaan tyyppi', ENT_QUOTES, 'UTF-8') ?>
                    </label>
                    <select id="editWsSiteType" name="site_type" class="sf-select" style="width:100%;">
                        <option value=""><?= htmlspecialchars(sf_term('site_type_unspecified', $currentUiLang) ?? 'Määrittämätön', ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="tunnel"><?= htmlspecialchars(sf_term('site_type_tunnel', $currentUiLang) ?? 'Tunnelityömaa', ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="opencast"><?= htmlspecialchars(sf_term('site_type_opencast', $currentUiLang) ?? 'Avolouhos', ENT_QUOTES, 'UTF-8') ?></option>
                        <option value="other"><?= htmlspecialchars(sf_term('site_type_other', $currentUiLang) ?? 'Muut toimipisteet', ENT_QUOTES, 'UTF-8') ?></option>
                    </select>
                </div>
            </div>
            <div class="sf-modal-footer" style="padding:1rem 1.25rem;display:flex;justify-content:flex-end;gap:0.5rem;">
                <button type="button" data-modal-close class="sf-btn sf-btn-secondary">
                    <?= htmlspecialchars(sf_term('btn_close', $currentUiLang) ?? 'Sulje', ENT_QUOTES, 'UTF-8') ?>
                </button>
                <button type="submit" class="sf-btn sf-btn-primary">
                    <?= htmlspecialchars(sf_term('btn_save', $currentUiLang) ?? 'Tallenna', ENT_QUOTES, 'UTF-8') ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    'use strict';
    var closestFromEventTarget = window.sfClosestFromEventTarget;
    if (typeof closestFromEventTarget !== 'function') return;

    if (document.__sfWsEditClickHandler) {
        document.removeEventListener('click', document.__sfWsEditClickHandler);
    }
    document.__sfWsEditClickHandler = function (e) {
        var btn = closestFromEventTarget(e.target, '.sf-ws-edit-btn');
        if (!btn) return;
        var modal = document.getElementById('modalEditWorksite');
        if (!modal) return;
        var idInput       = modal.querySelector('#editWsId');
        var nameInput     = modal.querySelector('#editWsName');
        var siteTypeInput = modal.querySelector('#editWsSiteType');
        if (idInput)       idInput.value       = btn.getAttribute('data-ws-id') || '';
        if (nameInput)     nameInput.value     = btn.getAttribute('data-ws-name') || '';
        if (siteTypeInput) siteTypeInput.value = btn.getAttribute('data-ws-site-type') || '';
        modal.classList.remove('hidden');
        if (nameInput) nameInput.focus();
    };
    document.addEventListener('click', document.__sfWsEditClickHandler);
})();
</script>

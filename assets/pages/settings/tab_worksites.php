<?php
// app/pages/settings/tab_worksites.php
declare(strict_types=1);

// Fallback image current value
$fallbackImagePath = sf_get_setting('display_fallback_image', '');
$fallbackImageUrl  = ($fallbackImagePath && $baseUrl)
    ? rtrim($baseUrl, '/') . '/' . ltrim($fallbackImagePath, '/')
    : '';

// Hae työmaat, niiden display API-avaimet ja aktiivisten flashien määrä
$worksites = [];
$worksitesRes = $mysqli->query(
    'SELECT w.id, w.name, w.site_type, w.is_active, k.api_key AS display_api_key, k.id AS display_key_id,
            COUNT(t.id) AS active_flash_count
     FROM sf_worksites w
     LEFT JOIN sf_display_api_keys k ON k.worksite_id = w.id AND k.is_active = 1
     LEFT JOIN sf_flash_display_targets t ON t.display_key_id = k.id AND t.is_active = 1
     GROUP BY w.id, w.name, w.site_type, w.is_active, k.api_key, k.id
     ORDER BY w.name ASC'
);
if (!$worksitesRes) {
    // Fallback if worksite_id or site_type column not yet migrated
    $worksitesRes = $mysqli->query('SELECT id, name, NULL AS site_type, is_active, 0 AS active_flash_count FROM sf_worksites ORDER BY name ASC');
}
if ($worksitesRes) {
    while ($w = $worksitesRes->fetch_assoc()) {
        $worksites[] = $w;
    }
    $worksitesRes->free();
}
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

<h2>
    <img src="<?= $baseUrl ?>/assets/img/icons/worksite.svg" alt="" class="sf-heading-icon" aria-hidden="true">
    <?= htmlspecialchars(
        sf_term('settings_worksites_heading', $currentUiLang) ?? 'Työmaiden hallinta',
        ENT_QUOTES,
        'UTF-8'
    ) ?>
</h2>

<form
    method="post"
    class="sf-form-inline"
action="app/actions/worksites_save.php"
    data-sf-ajax="1"
>
    <input type="hidden" name="form_action" value="add">
    <?= sf_csrf_field() ?>
    <label for="ws-name">
        <?= htmlspecialchars(
            sf_term('settings_worksites_add_label', $currentUiLang) ?? 'Uusi työmaa:',
            ENT_QUOTES,
            'UTF-8'
        ) ?>
    </label>
    <input type="text" id="ws-name" name="name" required>
    <label for="ws-site-type">
        <?= htmlspecialchars(
            sf_term('settings_worksites_site_type', $currentUiLang) ?? 'Työmaan tyyppi:',
            ENT_QUOTES,
            'UTF-8'
        ) ?>
    </label>
    <select id="ws-site-type" name="site_type">
        <option value=""><?= htmlspecialchars(sf_term('site_type_unspecified', $currentUiLang) ?? 'Määrittämätön', ENT_QUOTES, 'UTF-8') ?></option>
        <option value="tunnel"><?= htmlspecialchars(sf_term('site_type_tunnel', $currentUiLang) ?? 'Tunnelityömaa', ENT_QUOTES, 'UTF-8') ?></option>
        <option value="opencast"><?= htmlspecialchars(sf_term('site_type_opencast', $currentUiLang) ?? 'Avolouhos', ENT_QUOTES, 'UTF-8') ?></option>
        <option value="other"><?= htmlspecialchars(sf_term('site_type_other', $currentUiLang) ?? 'Muut toimipisteet', ENT_QUOTES, 'UTF-8') ?></option>
    </select>
    <button type="submit">
        <?= htmlspecialchars(
            sf_term('btn_add', $currentUiLang) ?? 'Lisää',
            ENT_QUOTES,
            'UTF-8'
        ) ?>
    </button>
</form>

<?php if (!empty($worksites)): ?>
<div class="sf-export-bar">
    <span class="sf-export-label">
        📥 <?= htmlspecialchars(sf_term('btn_export_worksites', $currentUiLang) ?? 'Vie työmaat', ENT_QUOTES, 'UTF-8') ?>
    </span>
    <div class="sf-export-buttons">
        <a href="<?= htmlspecialchars($baseUrl . '/app/api/export_worksites.php?format=csv', ENT_QUOTES, 'UTF-8') ?>"
           class="sf-btn sf-btn-sm sf-btn-outline-primary sf-export-btn"
           download>
            📄 <?= htmlspecialchars(sf_term('btn_export_csv', $currentUiLang) ?? 'Lataa CSV', ENT_QUOTES, 'UTF-8') ?>
        </a>
        <a href="<?= htmlspecialchars($baseUrl . '/app/api/export_worksites.php?format=json', ENT_QUOTES, 'UTF-8') ?>"
           class="sf-btn sf-btn-sm sf-btn-outline-primary sf-export-btn"
           download>
            { } <?= htmlspecialchars(sf_term('btn_export_json', $currentUiLang) ?? 'Lataa JSON', ENT_QUOTES, 'UTF-8') ?>
        </a>
    </div>
</div>
<?php endif; ?>

<?php if (empty($worksites)): ?>
    <p class="sf-notice sf-notice-info">
        <?= htmlspecialchars(
            sf_term('settings_worksites_empty', $currentUiLang) ?? 'Ei työmaita. Lisää ensimmäinen työmaa yllä olevalla lomakkeella.',
            ENT_QUOTES,
            'UTF-8'
        ) ?>
    </p>
<?php else: ?>
<table class="sf-table sf-table-worksites">
    <thead>
        <tr>
            <th>
                <?= htmlspecialchars(
                    sf_term('settings_worksites_col_name', $currentUiLang) ?? 'Nimi',
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </th>
            <th>
                <?= htmlspecialchars(
                    sf_term('settings_worksites_site_type', $currentUiLang) ?? 'Tyyppi',
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </th>
            <th>
                <?= htmlspecialchars(
                    sf_term('settings_worksites_col_active', $currentUiLang) ?? 'Aktiivinen',
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </th>
            <th>
                <img src="<?= $baseUrl ?>/assets/img/icons/display.svg" alt="" class="sf-icon" aria-hidden="true" style="width:16px;height:16px;vertical-align:middle;">
                <?= htmlspecialchars(
                    sf_term('settings_worksites_col_flashes', $currentUiLang) ?? 'Aktiiviset flashit',
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </th>
            <th>
                <?= htmlspecialchars(
                    sf_term('settings_worksites_col_actions', $currentUiLang) ?? 'Toiminnot',
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </th>
            <th>
                <img src="<?= $baseUrl ?>/assets/img/icons/playlist.svg" alt="" class="sf-icon" aria-hidden="true" style="width:16px;height:16px;vertical-align:middle;">
                <?= htmlspecialchars(
                    sf_term('settings_worksites_col_playlist', $currentUiLang) ?? 'Ajolista',
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </th>
            <th>
                <?= htmlspecialchars(
                    sf_term('xibo_col_heading', $currentUiLang) ?? 'Xibo-koodi',
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </th>
            <th>
                🔑 <?= htmlspecialchars(
                    sf_term('settings_worksites_col_api_key', $currentUiLang) ?? 'API-avain',
                    ENT_QUOTES,
                    'UTF-8'
                ) ?>
            </th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($worksites as $ws): ?>
            <tr class="<?= ((int)$ws['is_active'] === 1) ? '' : 'is-inactive' ?>">
                <td><?= htmlspecialchars($ws['name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                    <?php
                    $siteTypeKey = $ws['site_type'] ?? null;
                    if ($siteTypeKey === 'tunnel') {
                        echo htmlspecialchars(sf_term('site_type_tunnel', $currentUiLang) ?? 'Tunnelityömaa', ENT_QUOTES, 'UTF-8');
                    } elseif ($siteTypeKey === 'opencast') {
                        echo htmlspecialchars(sf_term('site_type_opencast', $currentUiLang) ?? 'Avolouhos', ENT_QUOTES, 'UTF-8');
                    } elseif ($siteTypeKey === 'other') {
                        echo htmlspecialchars(sf_term('site_type_other', $currentUiLang) ?? 'Muut toimipisteet', ENT_QUOTES, 'UTF-8');
                    } else {
                        echo '—';
                    }
                    ?>
                </td>
                <td>
    <?= ((int)$ws['is_active'] === 1)
        ? htmlspecialchars(sf_term('common_yes', $currentUiLang) ?? 'Kyllä', ENT_QUOTES, 'UTF-8')
        : htmlspecialchars(sf_term('common_no', $currentUiLang) ?? 'Ei', ENT_QUOTES, 'UTF-8') ?>
</td>
                <td>
                    <span class="sf-flash-count">
                        <?= (int)($ws['active_flash_count'] ?? 0) ?>
                    </span>
                </td>
                <td>
                    <form
                        method="post"
                        class="sf-inline-form"
                        action="app/actions/worksites_save.php"
                        data-sf-ajax="1"
                    >
                        <input type="hidden" name="form_action" value="toggle">
                        <?= sf_csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)$ws['id'] ?>">
                        <button type="submit" class="sf-btn sf-btn-sm <?= ((int)$ws['is_active'] === 1) ? 'sf-btn-outline-danger' : 'sf-btn-outline-primary' ?>">
                            <?php
                            if ((int)$ws['is_active'] === 1) {
                                echo htmlspecialchars(
                                    sf_term('settings_worksites_action_disable', $currentUiLang) ?? 'Passivoi',
                                    ENT_QUOTES,
                                    'UTF-8'
                                );
                            } else {
                                echo htmlspecialchars(
                                    sf_term('settings_worksites_action_enable', $currentUiLang) ?? 'Aktivoi',
                                    ENT_QUOTES,
                                    'UTF-8'
                                );
                            }
                            ?>
                        </button>
                    </form>
                    <button type="button"
                        class="sf-btn sf-btn-sm sf-btn-outline-secondary sf-ws-edit-btn"
                        data-ws-id="<?= (int)$ws['id'] ?>"
                        data-ws-name="<?= htmlspecialchars($ws['name'], ENT_QUOTES, 'UTF-8') ?>"
                        data-ws-site-type="<?= htmlspecialchars($ws['site_type'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                        ✏️ <?= htmlspecialchars(sf_term('settings_worksites_action_edit', $currentUiLang) ?? 'Muokkaa', ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </td>
                <td>
                    <?php if (!empty($ws['display_api_key'])): ?>
                        <?php if (!empty($ws['display_key_id'])): ?>
                        <a href="<?= htmlspecialchars(
                            ($baseUrl ?? '') . '/index.php?page=playlist_manager&display_key_id=' . (int)$ws['display_key_id'],
                            ENT_QUOTES,
                            'UTF-8'
                        ) ?>"
                           class="sf-btn sf-btn-outline-primary sf-btn-sm">
                            <img src="<?= $baseUrl ?>/assets/img/icons/playlist.svg" alt="" aria-hidden="true" style="width:14px;height:14px;vertical-align:middle;">
                            <?= htmlspecialchars(sf_term('settings_worksites_col_playlist', $currentUiLang) ?? 'Ajolista', ENT_QUOTES, 'UTF-8') ?>
                        </a>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if (!empty($ws['display_api_key'])): ?>
                        <button type="button"
                            class="sf-btn sf-btn-outline-primary sf-btn-sm"
                            data-modal-open="#xiboModal<?= (int)$ws['id'] ?>">
                            📋 <?= htmlspecialchars(sf_term('xibo_col_heading', $currentUiLang) ?? 'Xibo-koodi', ENT_QUOTES, 'UTF-8') ?>
                        </button>
                    <?php endif; ?>
                </td>
                <!-- API-avain pikakopiointi -->
                <td>
                    <?php if (!empty($ws['display_api_key'])): ?>
                        <div class="sf-api-key-cell">
                            <code class="sf-api-key-code"
                                  id="apiKey<?= (int)$ws['id'] ?>"
                                  title="<?= htmlspecialchars($ws['display_api_key'], ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars(
                                    mb_strlen($ws['display_api_key']) > 14
                                        ? mb_substr($ws['display_api_key'], 0, 12) . '…'
                                        : $ws['display_api_key'],
                                    ENT_QUOTES, 'UTF-8'
                                ) ?>
                            </code>
                            <button type="button"
                                class="sf-api-key-copy-btn sf-xibo-copy-btn"
                                data-copy-target="apiKeyFull<?= (int)$ws['id'] ?>"
                                data-ws-id="<?= (int)$ws['id'] ?>-apikey"
                                <?php $copyLabel = htmlspecialchars(sf_term('btn_copy_api_key', $currentUiLang) ?? 'Kopioi', ENT_QUOTES, 'UTF-8') ?>
                                title="<?= $copyLabel ?>">
                                📋 <?= $copyLabel ?>
                            </button>
                            <!-- Hidden full key for copy -->
                            <span id="apiKeyFull<?= (int)$ws['id'] ?>" style="display:none;"><?= htmlspecialchars($ws['display_api_key'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="sf-api-key-copied" id="xiboCopied<?= (int)$ws['id'] ?>-apikey">
                                ✅ <?= htmlspecialchars(sf_term('xibo_copied', $currentUiLang) ?? 'Kopioitu!', ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </div>
                    <?php else: ?>
                        <span class="sf-api-key-none">
                            <?= htmlspecialchars(sf_term('settings_worksites_no_api_key', $currentUiLang) ?? 'Ei avainta', ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>

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
                <strong style="display:block;margin-bottom:0.4rem;">▸ <?= htmlspecialchars(sf_term('xibo_webpage_url_label', $currentUiLang) ?? 'Webpage Widget URL', ENT_QUOTES, 'UTF-8') ?></strong>
                <div style="display:flex;gap:0.5rem;align-items:stretch;">
                    <code id="xiboHtmlUrl<?= $xiboWsId ?>" style="flex:1;display:block;background:var(--sf-bg-secondary,#f5f5f5);padding:0.5rem 0.75rem;border-radius:4px;font-size:0.82rem;word-break:break-all;"><?= htmlspecialchars($htmlUrl, ENT_QUOTES, 'UTF-8') ?></code>
                    <button type="button" class="sf-btn sf-btn-sm sf-btn-outline-primary sf-xibo-copy-btn" data-copy-target="xiboHtmlUrl<?= $xiboWsId ?>" data-ws-id="<?= $xiboWsId ?>-url">
                        📋 <?= htmlspecialchars(sf_term('xibo_copy_url', $currentUiLang) ?? 'Kopioi URL', ENT_QUOTES, 'UTF-8') ?>
                    </button>
                </div>
                <span id="xiboCopied<?= $xiboWsId ?>-url" style="display:none;color:green;font-size:0.85rem;margin-top:0.25rem;">✅ <?= htmlspecialchars(sf_term('xibo_copied', $currentUiLang) ?? 'Kopioitu!', ENT_QUOTES, 'UTF-8') ?></span>
            </div>

            <div style="margin-bottom:1.25rem;">
                <strong style="display:block;margin-bottom:0.25rem;">▸ <?= htmlspecialchars(sf_term('xibo_embedded_html_label', $currentUiLang) ?? 'HTML-kenttä (Embedded Widget)', ENT_QUOTES, 'UTF-8') ?></strong>
                <p style="margin:0 0 0.5rem;color:var(--sf-text-secondary,#666);font-size:0.85rem;">ℹ️ <?= htmlspecialchars(sf_term('xibo_embedded_instructions', $currentUiLang) ?? 'Liitä HTML ja CSS Xibon Embedded Widget -kenttiin', ENT_QUOTES, 'UTF-8') ?></p>
                <pre id="xiboEmbedHtml<?= $xiboWsId ?>" style="background:var(--sf-bg-secondary,#f5f5f5);padding:0.5rem 0.75rem;border-radius:4px;font-size:0.78rem;overflow:auto;max-height:200px;white-space:pre-wrap;word-break:break-all;margin:0 0 0.4rem;"><code><?= htmlspecialchars($embeddedHtml, ENT_QUOTES, 'UTF-8') ?></code></pre>
                <div style="display:flex;gap:0.5rem;align-items:center;">
                    <button type="button" class="sf-btn sf-btn-sm sf-btn-outline-primary sf-xibo-copy-btn" data-copy-target="xiboEmbedHtml<?= $xiboWsId ?>" data-ws-id="<?= $xiboWsId ?>-html">
                        📋 <?= htmlspecialchars(sf_term('xibo_copy_html', $currentUiLang) ?? 'Kopioi HTML', ENT_QUOTES, 'UTF-8') ?>
                    </button>
                    <span id="xiboCopied<?= $xiboWsId ?>-html" style="display:none;color:green;font-size:0.85rem;">✅ <?= htmlspecialchars(sf_term('xibo_copied', $currentUiLang) ?? 'Kopioitu!', ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            </div>

            <div style="margin-bottom:1rem;">
                <strong style="display:block;margin-bottom:0.4rem;">▸ <?= htmlspecialchars(sf_term('xibo_embedded_css_label', $currentUiLang) ?? 'CSS-kenttä (Embedded Widget)', ENT_QUOTES, 'UTF-8') ?></strong>
                <pre id="xiboEmbedCss<?= $xiboWsId ?>" style="background:var(--sf-bg-secondary,#f5f5f5);padding:0.5rem 0.75rem;border-radius:4px;font-size:0.78rem;overflow:auto;max-height:200px;white-space:pre-wrap;word-break:break-all;margin:0 0 0.4rem;"><code><?= htmlspecialchars($embeddedCss, ENT_QUOTES, 'UTF-8') ?></code></pre>
                <div style="display:flex;gap:0.5rem;align-items:center;">
                    <button type="button" class="sf-btn sf-btn-sm sf-btn-outline-primary sf-xibo-copy-btn" data-copy-target="xiboEmbedCss<?= $xiboWsId ?>" data-ws-id="<?= $xiboWsId ?>-css">
                        📋 <?= htmlspecialchars(sf_term('xibo_copy_css', $currentUiLang) ?? 'Kopioi CSS', ENT_QUOTES, 'UTF-8') ?>
                    </button>
                    <span id="xiboCopied<?= $xiboWsId ?>-css" style="display:none;color:green;font-size:0.85rem;">✅ <?= htmlspecialchars(sf_term('xibo_copied', $currentUiLang) ?? 'Kopioitu!', ENT_QUOTES, 'UTF-8') ?></span>
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
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.sf-xibo-copy-btn');
        if (!btn) return;
        var targetId = btn.getAttribute('data-copy-target');
        var wsId = btn.getAttribute('data-ws-id');
        var el = document.getElementById(targetId);
        if (!el) return;
        var text = el.textContent;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).then(function () {
                showCopied(wsId);
            }).catch(function () {
                fallbackCopy(text, wsId);
            });
        } else {
            fallbackCopy(text, wsId);
        }
    });

    function fallbackCopy(text, wsId) {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        document.body.appendChild(ta);
        ta.focus();
        ta.select();
        try { document.execCommand('copy'); } catch (err) {}
        document.body.removeChild(ta);
        showCopied(wsId);
    }

    function showCopied(wsId) {
        var msg = document.getElementById('xiboCopied' + wsId);
        if (!msg) return;
        msg.style.display = 'inline';
        setTimeout(function () { msg.style.display = 'none'; }, 2000);
    }
})();
</script>
<?php endif; ?>

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
    if (document.__sfWsEditListenerAttached) return;
    document.__sfWsEditListenerAttached = true;
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.sf-ws-edit-btn');
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
    });
})();
</script>
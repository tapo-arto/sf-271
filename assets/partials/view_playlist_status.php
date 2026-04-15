<?php
/**
 * SafetyFlash - View Page: Playlist Status Display
 * 
 * Näyttää flashin tilan infonäyttö-playlistassa.
 * Vain julkaistuille flasheille. Admineille, turvatiimille ja viestinnälle
 * toiminnot poistaa/palauttaa.
 * 
 * @package SafetyFlash
 * @subpackage Partials
 * @created 2026-02-19
 * 
 * Required variables:
 * @var array $flash Flash data from database
 * @var string $currentUiLang Current UI language
 * @var int $id Flash ID
 * @var bool $isAdmin User is admin
 * @var bool $isSafety User is safety team
 * @var bool $isComms User is communications team
 */

// Näytetään vain julkaistuille flasheille
if (!isset($flash['state']) || $flash['state'] !== 'published') {
    return;
}

// Tarkista onko flashilla aktiivisia display target -rivejä
$hasActiveTargets = false;
if (isset($pdo)) {
    try {
        $stmtActiveCount = $pdo->prepare("SELECT 1 FROM sf_flash_display_targets WHERE flash_id = ? AND is_active = 1 LIMIT 1");
        $stmtActiveCount->execute([(int)$id]);
        $hasActiveTargets = $stmtActiveCount->fetch() !== false;
    } catch (Throwable $eac) {
        // Silently ignore — migration may not be applied yet
    }
}

if (!$hasActiveTargets) {
    return;
}

// Määritä playlist-status
$displayStatus = 'active'; // oletus
$displayExpiresAt = $flash['display_expires_at'] ?? null;
$displayRemovedAt = $flash['display_removed_at'] ?? null;

if ($displayRemovedAt !== null) {
    $displayStatus = 'removed';
} elseif ($displayExpiresAt !== null && strtotime($displayExpiresAt) < time()) {
    $displayStatus = 'expired';
}

// Oikeudet hallintaan (admin, turvatiimi, viestintä)
$canManage = $isAdmin || $isSafety || $isComms;

// Hae työmaan API-avain ja label aktiivisista display-kohteista
$worksiteApiKey = null;
$worksiteLabel = null;
$activeDisplayLabels = [];
$allScreensSelected = false;
if (isset($pdo) && $displayStatus === 'active') {
    try {
        $stmtApiKey = $pdo->prepare("
            SELECT k.api_key, k.label
            FROM sf_flash_display_targets t
            JOIN sf_display_api_keys k ON k.id = t.display_key_id
            WHERE t.flash_id = ? AND t.is_active = 1 AND k.is_active = 1
            ORDER BY k.sort_order ASC, k.label ASC, k.id ASC
            LIMIT 1
        ");
        $stmtApiKey->execute([(int)$id]);
        $keyRow = $stmtApiKey->fetch(PDO::FETCH_ASSOC);
        $worksiteApiKey = $keyRow ? ($keyRow['api_key'] ?? null) : null;
        $worksiteLabel = $keyRow ? ($keyRow['label'] ?? null) : null;

        $stmtLabels = $pdo->prepare("
            SELECT DISTINCT COALESCE(NULLIF(k.label, ''), NULLIF(k.site, ''), CONCAT('#', k.id)) AS display_name
            FROM sf_flash_display_targets t
            JOIN sf_display_api_keys k ON k.id = t.display_key_id
            WHERE t.flash_id = ? AND t.is_active = 1 AND k.is_active = 1
            ORDER BY display_name ASC
        ");
        $stmtLabels->execute([(int)$id]);
        $activeDisplayLabels = $stmtLabels->fetchAll(PDO::FETCH_COLUMN);

        $stmtTotal = $pdo->prepare("
            SELECT COUNT(*)
            FROM sf_display_api_keys
            WHERE is_active = 1
        ");
        $stmtTotal->execute();
        $totalDisplays = (int)$stmtTotal->fetchColumn();

        if ($totalDisplays > 0 && count($activeDisplayLabels) >= $totalDisplays) {
            $allScreensSelected = true;
        }
    } catch (Throwable $ek) {
        // Silently ignore — migration may not be applied yet
    }
}

?>

<div class="sf-playlist-status-card sf-playlist-status-<?= htmlspecialchars($displayStatus, ENT_QUOTES, 'UTF-8') ?>">
    <div class="sf-playlist-status-header">
        <h4>
            <?php if ($displayStatus === 'active'): ?>
                <span class="sf-status-icon">
                    <img src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/img/icons/display.svg" alt="" style="width:18px;height:18px;vertical-align:middle;">
                </span>
                <?= htmlspecialchars(sf_term('playlist_status_active', $currentUiLang) ?? 'Näytetään infonäytöillä', ENT_QUOTES, 'UTF-8') ?>
            <?php elseif ($displayStatus === 'expired'): ?>
                <span class="sf-status-icon">
                    <img src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/img/icons/pending.svg" alt="" style="width:18px;height:18px;vertical-align:middle;">
                </span>
                <?= htmlspecialchars(sf_term('playlist_status_expired', $currentUiLang) ?? 'Vanhentunut', ENT_QUOTES, 'UTF-8') ?>
            <?php else: ?>
                <span class="sf-status-icon">
                    <img src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/img/icons/empty-data.svg" alt="" style="width:18px;height:18px;vertical-align:middle;">
                </span>
                <?= htmlspecialchars(sf_term('playlist_status_removed', $currentUiLang) ?? 'Poistettu playlistasta', ENT_QUOTES, 'UTF-8') ?>
            <?php endif; ?>
        </h4>
    </div>

<?php if ($displayStatus === 'active' && ($allScreensSelected || !empty($activeDisplayLabels))): ?>
    <p class="sf-playlist-displays">
        <?php if ($allScreensSelected): ?>
            <?= htmlspecialchars(sf_term('display_all_screens', $currentUiLang) ?? 'Kaikki näytöt', ENT_QUOTES, 'UTF-8') ?>
        <?php else: ?>
            <?= htmlspecialchars(implode(', ', $activeDisplayLabels), ENT_QUOTES, 'UTF-8') ?>
        <?php endif; ?>
    </p>
<?php endif; ?>

    <div class="sf-playlist-status-body">
        <?php if ($displayStatus === 'active'): ?>
            <?php if ($displayExpiresAt): ?>
                <?php
                $expiryDate = new DateTime($displayExpiresAt);
                $now = new DateTime();
                $interval = $now->diff($expiryDate);
                
                if ($interval->days > 0) {
                    $remainingText = sprintf(
                        sf_term('playlist_expires_in_days', $currentUiLang) ?? 'Vanhenee %d päivän kuluttua',
                        $interval->days
                    );
                } else {
                    $remainingText = sf_term('playlist_expires_today', $currentUiLang) ?? 'Vanhenee tänään';
                }
                ?>
                <p class="sf-playlist-expires">
                    <?= htmlspecialchars($remainingText, ENT_QUOTES, 'UTF-8') ?>
                    <br>
                    <small><?= htmlspecialchars($expiryDate->format('d.m.Y H:i'), ENT_QUOTES, 'UTF-8') ?></small>
                </p>
            <?php else: ?>
                <p class="sf-playlist-no-limit">
                    <?= htmlspecialchars(sf_term('playlist_no_expiry', $currentUiLang) ?? 'Ei vanhenemisaikaa', ENT_QUOTES, 'UTF-8') ?>
                </p>
            <?php endif; ?>
        <?php elseif ($displayStatus === 'expired'): ?>
            <p class="sf-playlist-expired-at">
                <?= htmlspecialchars(sf_term('playlist_expired_at', $currentUiLang) ?? 'Vanheni', ENT_QUOTES, 'UTF-8') ?>:
                <br>
                <small><?= htmlspecialchars(date('d.m.Y H:i', strtotime($displayExpiresAt)), ENT_QUOTES, 'UTF-8') ?></small>
            </p>
        <?php else: ?>
            <p class="sf-playlist-removed-at">
                <?= htmlspecialchars(sf_term('playlist_removed_at', $currentUiLang) ?? 'Poistettu', ENT_QUOTES, 'UTF-8') ?>:
                <br>
                <small><?= htmlspecialchars(date('d.m.Y H:i', strtotime($displayRemovedAt)), ENT_QUOTES, 'UTF-8') ?></small>
            </p>
        <?php endif; ?>
    </div>
    
    <?php if ($worksiteApiKey || $canManage): ?>
        <div class="sf-playlist-actions">
            <?php if ($worksiteApiKey): ?>
                <button type="button"
                   data-modal-open="#modalKatsoAjolista"
                   class="sf-btn sf-btn-outline-primary">
                    <img src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/img/icons/display.svg" alt="" aria-hidden="true" style="width:14px;height:14px;vertical-align:middle;">
                    <?= htmlspecialchars(sf_term('btn_view_playlist', $currentUiLang) ?? 'Katso ajolista', ENT_QUOTES, 'UTF-8') ?>
                </button>
            <?php endif; ?>
            <?php if ($canManage): ?>
                <?php if ($displayStatus !== 'removed'): ?>
                    <button 
                        type="button" 
                        id="btnRemoveFromPlaylist" 
                        class="sf-btn-outline-danger"
                        data-flash-id="<?= (int)$id ?>"
                    >
                        <?= htmlspecialchars(sf_term('btn_remove_from_playlist', $currentUiLang) ?? 'Poista playlistasta', ENT_QUOTES, 'UTF-8') ?>
                    </button>
                <?php else: ?>
                    <button 
                        type="button" 
                        id="btnRestoreToPlaylist" 
                        class="sf-btn-outline-primary"
                        data-flash-id="<?= (int)$id ?>"
                    >
                        <?= htmlspecialchars(sf_term('btn_restore_to_playlist', $currentUiLang) ?? 'Palauta playlistaan', ENT_QUOTES, 'UTF-8') ?>
                    </button>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<?php if ($canManage && $displayStatus !== 'removed'): ?>
<!-- Poista playlistasta -vahvistusmodaali -->
<div class="sf-modal hidden" id="modalRemoveFromPlaylist" role="dialog" aria-modal="true" aria-labelledby="modalRemoveFromPlaylistTitle">
    <div class="sf-modal-content sf-modal-confirm">
        <div class="sf-modal-header">
            <h3 id="modalRemoveFromPlaylistTitle">
                <?= htmlspecialchars(sf_term('confirm_remove_playlist_title', $currentUiLang) ?? 'Poista playlistasta', ENT_QUOTES, 'UTF-8') ?>
            </h3>
            <button type="button" class="sf-modal-close" data-modal-close aria-label="<?= htmlspecialchars(sf_term('btn_close', $currentUiLang) ?? 'Sulje', ENT_QUOTES, 'UTF-8') ?>">✕</button>
        </div>
        <div class="sf-modal-body">
            <p><?= htmlspecialchars(sf_term('confirm_remove_from_playlist', $currentUiLang) ?? 'Haluatko varmasti poistaa flashin infonäyttö-playlistasta?', ENT_QUOTES, 'UTF-8') ?></p>
        </div>
        <div class="sf-modal-actions">
            <button type="button" class="sf-btn sf-btn-secondary" data-modal-close><?= htmlspecialchars(sf_term('btn_cancel', $currentUiLang) ?? 'Peruuta', ENT_QUOTES, 'UTF-8') ?></button>
            <button type="button" class="sf-btn sf-btn-danger" id="btnConfirmRemoveFromPlaylist" data-flash-id="<?= (int)$id ?>"><?= htmlspecialchars(sf_term('btn_remove_from_playlist', $currentUiLang) ?? 'Poista', ENT_QUOTES, 'UTF-8') ?></button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php if ($worksiteApiKey): ?>
<!-- Katso ajolista -modaali -->
<div class="sf-modal hidden" id="modalKatsoAjolista" role="dialog" aria-modal="true" aria-labelledby="modalKatsoAjolistaTitle">
    <div class="sf-modal-content sf-pm-preview-modal">
        <div class="sf-modal-header">
            <h3 id="modalKatsoAjolistaTitle">
                <img src="<?= htmlspecialchars($base, ENT_QUOTES, 'UTF-8') ?>/assets/img/icons/display.svg" alt="" aria-hidden="true" style="width:18px;height:18px;vertical-align:middle;margin-right:6px;">
                <?= htmlspecialchars(sf_term('btn_view_playlist', $currentUiLang) ?? 'Katso ajolista', ENT_QUOTES, 'UTF-8') ?>
                <?php if ($worksiteLabel): ?> — <?= htmlspecialchars($worksiteLabel, ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
            </h3>
            <button type="button" data-modal-close class="sf-modal-close" aria-label="<?= htmlspecialchars(sf_term('btn_close', $currentUiLang) ?? 'Sulje', ENT_QUOTES, 'UTF-8') ?>">✕</button>
        </div>
        <div class="sf-playlist-nav" id="sfPlaylistNav">
            <button type="button" id="btnPlaylistPrev" class="sf-playlist-nav-btn"
                title="<?= htmlspecialchars(sf_term('btn_playlist_prev', $currentUiLang) ?? 'Edellinen', ENT_QUOTES, 'UTF-8') ?>"
                aria-label="<?= htmlspecialchars(sf_term('btn_playlist_prev', $currentUiLang) ?? 'Edellinen', ENT_QUOTES, 'UTF-8') ?>">&#9664;</button>
            <span id="sfPlaylistCounter" class="sf-playlist-counter">&#x2013; / &#x2013;</span>
            <button type="button" id="btnPlaylistPause" class="sf-playlist-nav-btn"
                data-label-pause="<?= htmlspecialchars(sf_term('btn_playlist_pause', $currentUiLang) ?? 'Pysäytä', ENT_QUOTES, 'UTF-8') ?>"
                data-label-resume="<?= htmlspecialchars(sf_term('btn_playlist_resume', $currentUiLang) ?? 'Jatka', ENT_QUOTES, 'UTF-8') ?>"
                title="<?= htmlspecialchars(sf_term('btn_playlist_pause', $currentUiLang) ?? 'Pysäytä', ENT_QUOTES, 'UTF-8') ?>"
                aria-label="<?= htmlspecialchars(sf_term('btn_playlist_pause', $currentUiLang) ?? 'Pysäytä', ENT_QUOTES, 'UTF-8') ?>"
                aria-pressed="false">&#x23F8;</button>
            <button type="button" id="btnPlaylistNext" class="sf-playlist-nav-btn"
                title="<?= htmlspecialchars(sf_term('btn_playlist_next', $currentUiLang) ?? 'Seuraava', ENT_QUOTES, 'UTF-8') ?>"
                aria-label="<?= htmlspecialchars(sf_term('btn_playlist_next', $currentUiLang) ?? 'Seuraava', ENT_QUOTES, 'UTF-8') ?>">&#9654;</button>
        </div>
        <div class="sf-pm-preview-body">
            <iframe src="<?= htmlspecialchars("{$base}/app/api/display_playlist.php?key={$worksiteApiKey}&format=html", ENT_QUOTES, 'UTF-8') ?>"
                    title="<?= htmlspecialchars($worksiteLabel ?? 'Ajolista', ENT_QUOTES, 'UTF-8') ?>"
                    class="sf-pm-preview-iframe"
                    sandbox="allow-scripts allow-same-origin"
                    loading="lazy"></iframe>
        </div>
    </div>
</div>
<?php endif; ?>
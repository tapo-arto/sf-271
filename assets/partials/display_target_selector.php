<?php
/**
 * SafetyFlash - Display Target Selector Partial
 *
 * Näyttää kaikki aktiiviset näytöt maa/kieliryhmitetyillä chip-napeilla,
 * hakukentällä yksittäisten näyttöjen löytämiseen sekä valintanäytöllä.
 *
 * Odottaa muuttujia:
 *   $flash        — array, kieliversion data (id, lang, title)
 *   $pdo          — PDO-yhteys
 *   $currentUiLang — string, UI-kieli
 *   $context      — string, 'publish' | 'safety_team'
 *
 * Valinnainen override:
 *   $preselectedIds — array, jos asetettu ennen includeaa, käytetään sellaisenaan
 *
 * @package SafetyFlash
 * @subpackage Partials
 * @created 2026-02-19
 * @updated 2026-02-23 - country/lang group chips + search + selection display
 */

// Flashin oma kieliversiokohtainen ID (EI translation_group_id)
$flashId = (int)($flash['id'] ?? 0);

// Hae KAIKKI aktiiviset näytöt (join worksites for site_type)
$availableDisplays = [];
try {
    $stmtDisplays = $pdo->prepare("
        SELECT k.id, k.site, k.site_group, k.label, k.lang, k.sort_order,
               COALESCE(w.site_type, '') AS site_type
        FROM sf_display_api_keys k
        LEFT JOIN sf_worksites w ON w.id = k.worksite_id
        WHERE k.is_active = 1
        ORDER BY k.lang ASC, k.sort_order ASC, k.label ASC
    ");
    $stmtDisplays->execute();
    $availableDisplays = $stmtDisplays->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $eDtSel) {
    // Silently ignore — taulu saattaa puuttua ennen migraatiota
}

// Hae esivalinnat — käytä annettua $preselectedIds jos asetettu, muuten hae kannasta
if (!isset($preselectedIds)) {
    $preselectedIds = [];
    if ($flashId > 0) {
        try {
            $stmtPre = $pdo->prepare("
                SELECT display_key_id FROM sf_flash_display_targets
                WHERE flash_id = ?
            ");
            $stmtPre->execute([$flashId]);
            $preselectedIds = $stmtPre->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $eDtSelPre) {
            // Silently ignore
        }
    }
}
$preselectedIds = array_map('intval', $preselectedIds);

// Maa/kielikartta
$dtLangMap = [
    'fi' => ['flag' => '🇫🇮', 'name' => sf_term('country_finland', $currentUiLang)],
    'sv' => ['flag' => '🇸🇪', 'name' => sf_term('country_sweden', $currentUiLang)],
    'en' => ['flag' => '🇬🇧', 'name' => sf_term('country_uk', $currentUiLang)],
    'it' => ['flag' => '🇮🇹', 'name' => sf_term('country_italy', $currentUiLang)],
    'el' => ['flag' => '🇬🇷', 'name' => sf_term('country_greece', $currentUiLang)],
];

// Ryhmittele näytöt kielen mukaan
$dtByLang = [];
foreach ($availableDisplays as $dtDisp) {
    $dtLang = $dtDisp['lang'] ?: 'fi';
    $dtByLang[$dtLang][] = $dtDisp;
}
?>

<div class="sf-display-target-selector">
    <?php if (empty($availableDisplays)): ?>
        <p class="sf-help-text sf-help-text-muted">—</p>
    <?php else: ?>

        <?php if (!empty($dtByLang)): ?>
        <div class="sf-dt-lang-chips">
            <?php foreach ($dtByLang as $dtLang => $dtLangDisplays): ?>
                <?php $dtLInfo = $dtLangMap[$dtLang] ?? ['flag' => '🌐', 'name' => strtoupper($dtLang)]; ?>
                <?php
                // Kaikki kyseisen kielen näytöt valittuna?
                $dtLangIds = array_map('intval', array_column($dtLangDisplays, 'id'));
                $dtAllSelected = !empty($dtLangIds) && empty(array_diff($dtLangIds, $preselectedIds));
                ?>
                <button type="button"
                        class="sf-dt-lang-chip<?= $dtAllSelected ? ' sf-dt-lang-chip-active' : '' ?>"
                        data-lang="<?= htmlspecialchars($dtLang, ENT_QUOTES, 'UTF-8') ?>">
                    <?= $dtLInfo['flag'] ?> <?= htmlspecialchars($dtLInfo['name'], ENT_QUOTES, 'UTF-8') ?>
                    <span class="sf-dt-lang-count">(<?= count($dtLangDisplays) ?>)</span>
                </button>
            <?php endforeach; ?>
            <?php
            // Special quick-select chips
            $dtTunnelIds   = array_map('intval', array_column(array_filter($availableDisplays, fn($d) => ($d['site_type'] ?? '') === 'tunnel'),   'id'));
            $dtOpencastIds = array_map('intval', array_column(array_filter($availableDisplays, fn($d) => ($d['site_type'] ?? '') === 'opencast'), 'id'));
            $dtOtherIds    = array_map('intval', array_column(array_filter($availableDisplays, fn($d) => ($d['site_type'] ?? '') === 'other'),    'id'));
            $dtAllIds      = array_map('intval', array_column($availableDisplays, 'id'));
            $dtAllChipActive      = !empty($dtAllIds)      && empty(array_diff($dtAllIds,      $preselectedIds));
            $dtTunnelChipActive   = !empty($dtTunnelIds)   && empty(array_diff($dtTunnelIds,   $preselectedIds));
            $dtOpencastChipActive = !empty($dtOpencastIds) && empty(array_diff($dtOpencastIds, $preselectedIds));
            $dtOtherChipActive    = !empty($dtOtherIds)    && empty(array_diff($dtOtherIds,    $preselectedIds));
            ?>
            <button type="button"
                    class="sf-dt-special-chip<?= $dtAllChipActive ? ' sf-dt-lang-chip-active' : '' ?>"
                    data-select="all">
                <?= htmlspecialchars(sf_term('comms_screens_all', $currentUiLang) ?? 'Kaikki näytöt', ENT_QUOTES, 'UTF-8') ?>
                <span class="sf-dt-lang-count">(<?= count($dtAllIds) ?>)</span>
            </button>
            <?php if (!empty($dtTunnelIds)): ?>
            <button type="button"
                    class="sf-dt-special-chip<?= $dtTunnelChipActive ? ' sf-dt-lang-chip-active' : '' ?>"
                    data-select="tunnel">
                <?= htmlspecialchars(sf_term('site_type_tunnel', $currentUiLang) ?? 'Tunnelityömaat', ENT_QUOTES, 'UTF-8') ?>
                <span class="sf-dt-lang-count">(<?= count($dtTunnelIds) ?>)</span>
            </button>
            <?php endif; ?>
            <?php if (!empty($dtOpencastIds)): ?>
            <button type="button"
                    class="sf-dt-special-chip<?= $dtOpencastChipActive ? ' sf-dt-lang-chip-active' : '' ?>"
                    data-select="opencast">
                <?= htmlspecialchars(sf_term('site_type_opencast', $currentUiLang) ?? 'Avolouhokset', ENT_QUOTES, 'UTF-8') ?>
                <span class="sf-dt-lang-count">(<?= count($dtOpencastIds) ?>)</span>
            </button>
            <?php endif; ?>
            <?php if (!empty($dtOtherIds)): ?>
            <button type="button"
                    class="sf-dt-special-chip<?= $dtOtherChipActive ? ' sf-dt-lang-chip-active' : '' ?>"
                    data-select="other">
                <?= htmlspecialchars(sf_term('site_type_other', $currentUiLang) ?? 'Muut toimipisteet', ENT_QUOTES, 'UTF-8') ?>
                <span class="sf-dt-lang-count">(<?= count($dtOtherIds) ?>)</span>
            </button>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- Hakukenttä -->
        <div class="sf-dt-search-row">
            <input type="text"
                   class="sf-dt-search-input"
                   placeholder="🔍 <?= htmlspecialchars(sf_term('comms_search_worksites', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                   autocomplete="off">
            <p class="sf-dt-search-hint"><?= htmlspecialchars(sf_term('comms_search_hint', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></p>
        </div>

        <!-- Hakutulokset (piilotettu oletuksena) -->
        <div class="sf-dt-search-results hidden">
            <?php foreach ($availableDisplays as $dtDisplay): ?>
                <?php $dtIsChecked = in_array((int)$dtDisplay['id'], $preselectedIds, true); ?>
                <label class="sf-dt-result-item hidden"
                       data-search="<?= htmlspecialchars(strtolower($dtDisplay['label'] ?? $dtDisplay['site']), ENT_QUOTES, 'UTF-8') ?>"
                       data-lang="<?= htmlspecialchars($dtDisplay['lang'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                       data-type="<?= htmlspecialchars($dtDisplay['site_type'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    <input type="checkbox"
                           class="sf-display-chip-input dt-display-chip-cb"
                           name="display_targets[<?= $flashId ?>][]"
                           value="<?= (int)$dtDisplay['id'] ?>"
                           data-label="<?= htmlspecialchars($dtDisplay['label'] ?? $dtDisplay['site'], ENT_QUOTES, 'UTF-8') ?>"
                           data-lang="<?= htmlspecialchars($dtDisplay['lang'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           data-type="<?= htmlspecialchars($dtDisplay['site_type'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                           <?= $dtIsChecked ? 'checked' : '' ?>>
                    <span class="sf-ws-name"><?= htmlspecialchars($dtDisplay['label'] ?? $dtDisplay['site'], ENT_QUOTES, 'UTF-8') ?></span>
                </label>
            <?php endforeach; ?>
        </div>

        <!-- Valintanäyttö -->
        <div class="sf-dt-selection-display<?= empty($preselectedIds) ? ' hidden' : '' ?>">
            <div class="sf-dt-selection-label"><?= htmlspecialchars(sf_term('comms_your_selection', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></div>
            <div class="sf-dt-selection-tags"></div>
            <button type="button" class="sf-dt-clear-all-btn">
                <?= htmlspecialchars(sf_term('clear_all_selections', $currentUiLang) ?? 'Tyhjennä kaikki', ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>

    <?php endif; ?>
</div>
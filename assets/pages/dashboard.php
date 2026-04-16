<?php
// assets/pages/dashboard.php
declare(strict_types=1);

require_once __DIR__ . '/../../app/includes/protect.php';
require_once __DIR__ . '/../../app/includes/statuses.php';
require_once __DIR__ . '/../../app/includes/helpers.php';

$baseUrl = rtrim($config['base_url'] ?? '', '/');
$uiLang = $_SESSION['ui_lang'] ?? 'fi';

$user = sf_current_user();
$isAdmin = $user && (int)($user['role_id'] ?? 0) === 1;
$userId = (int)($user['id'] ?? 0);

try {
    $pdo = Database::getInstance();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<p>' . htmlspecialchars(sf_term('db_error', $uiLang), ENT_QUOTES, 'UTF-8') . '</p>';
    exit;
}

// --- Original Type Statistics (default: all time) ---
$originalStats = ['red' => 0, 'yellow' => 0, 'total' => 0];
try {
    $stmt = $pdo->prepare("
        SELECT 
            COALESCE(original_type, type) as original_type,
            COUNT(DISTINCT COALESCE(translation_group_id, id)) as count
        FROM sf_flashes 
        WHERE state = 'published'
        GROUP BY COALESCE(original_type, type)
    ");
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $type = $row['original_type'] ?? '';
        $count = (int)($row['count'] ?? 0);
        if (isset($originalStats[$type])) $originalStats[$type] = $count;
        if ($type !== 'green') { // Don't count green in original stats
            $originalStats['total'] += $count;
        }
    }
} catch (Throwable $e) {}

// --- Worksite Statistics (Top 15) - Default: All Time ---
$worksiteStats = [];
$maxCount = 0;
try {
    $stmt = $pdo->prepare("
        SELECT site, COUNT(DISTINCT COALESCE(translation_group_id, id)) as count 
        FROM sf_flashes 
        WHERE state = 'published' 
        AND site IS NOT NULL 
        AND site != '' 
        GROUP BY site 
        ORDER BY count DESC 
        LIMIT 15
    ");
    $stmt->execute();
    $worksiteStats = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($worksiteStats as $ws) {
        $maxCount = max($maxCount, (int)($ws['count'] ?? 0));
    }
} catch (Throwable $e) {}

// --- Active Statistics (Current Type, excluding archived) ---
$activeStats = ['red' => 0, 'yellow' => 0, 'green' => 0];
try {
    $stmt = $pdo->prepare("
        SELECT type, COUNT(DISTINCT COALESCE(translation_group_id, id)) as count 
        FROM sf_flashes 
        WHERE state = 'published' AND is_archived = 0 
        GROUP BY type
    ");
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $type = $row['type'] ?? '';
        $count = (int)($row['count'] ?? 0);
        if (isset($activeStats[$type])) $activeStats[$type] = $count;
    }
} catch (Throwable $e) {}

// --- Available Years (for dynamic year dropdown) ---
$availableYears = [];
$currentYear = (int)date('Y');
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT YEAR(created_at) as year 
        FROM sf_flashes 
        WHERE created_at IS NOT NULL 
        ORDER BY year DESC
    ");
    $stmt->execute();
    $availableYears = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'year');
} catch (Throwable $e) {}


// --- Archived Count ---
$archivedCount = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(DISTINCT COALESCE(translation_group_id, id)) as count 
        FROM sf_flashes 
        WHERE state = 'published' AND is_archived = 1
    ");
    $stmt->execute();
    $archivedCount = (int)($stmt->fetchColumn() ?: 0);
} catch (Throwable $e) {}

// --- Injury Heatmap: helper to load and sanitise a body-map SVG ---
function dashboardLoadBodySvg(string $svgFile): string
{
    $expectedDir = realpath(__DIR__ . '/../../assets/img/body-map');
    $realFile    = realpath($svgFile);
    if (
        $realFile === false || $expectedDir === false
        || strncmp($realFile, $expectedDir . DIRECTORY_SEPARATOR, strlen($expectedDir) + 1) !== 0
    ) {
        return '';
    }
    $raw = file_get_contents($realFile);
    if ($raw === false || $raw === '') {
        return '';
    }
    $raw = preg_replace('/<\?xml[^?]*\?>\s*/', '', $raw);
    if (!preg_match('/<svg[^>]*>(.*?)<\/svg>/s', $raw, $cm)) {
        return '';
    }
    $inner = trim($cm[1]);
    $inner = preg_replace('/<script[^>]*>.*?<\/script>/si', '', $inner);
    $inner = preg_replace('/\s+on\w+="[^"]*"/i', '', $inner);
    $inner = preg_replace('/\s+on\w+=\'[^\']*\'/i', '', $inner);
    $inner = preg_replace('/\s+on\w+=\S+/i', '', $inner);
    $inner = preg_replace('/href\s*=\s*["\']?\s*javascript:[^"\'>\s]*/i', '', $inner);
    $inner = preg_replace('/\s+fill="[^"]*"/', '', $inner);
    $inner = preg_replace('/\s+stroke="[^"]*"/', '', $inner);
    $inner = preg_replace('/\s+stroke-width="[^"]*"/', '', $inner);
    return $inner;
}

// --- Injury Heatmap: initial body-part counts (all time, all sites) ---
$injuryBodyParts = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            bp.svg_id,
            bp.category,
            COUNT(DISTINCT COALESCE(f.translation_group_id, f.id)) AS cnt
        FROM body_parts bp
        LEFT JOIN incident_body_part ibp ON ibp.body_part_id = bp.id
        LEFT JOIN sf_flashes f
            ON  f.id    = ibp.incident_id
            AND f.state = 'published'
        GROUP BY bp.id, bp.svg_id, bp.category
        ORDER BY bp.sort_order
    ");
    $stmt->execute();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $injuryBodyParts[] = [
            'svg_id'   => $row['svg_id'],
            'name'     => sf_bp_term($row['svg_id'], $uiLang),
            'category' => sf_bp_category_term($row['category'], $uiLang),
            'count'    => (int)$row['cnt'],
        ];
    }
} catch (Throwable $e) {}

// --- Injury Heatmap: worksites that have injury data ---
$injurySites = [];
try {
    $stmt = $pdo->prepare("
        SELECT DISTINCT f.site
        FROM   sf_flashes f
        INNER JOIN incident_body_part ibp ON ibp.incident_id = f.id
        WHERE  f.state = 'published'
          AND  f.site IS NOT NULL
          AND  f.site <> ''
        ORDER BY f.site ASC
    ");
    $stmt->execute();
    $injurySites = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'site');
} catch (Throwable $e) {}

// --- Injury Heatmap: recent flashes with at least one injury annotation ---
$injuryRecentFlashes = [];
try {
    $stmt = $pdo->prepare("
        SELECT
            f.id,
            f.type,
            COALESCE(NULLIF(f.title_short, ''), f.title) AS title,
            f.site,
            f.updated_at
        FROM sf_flashes f
        INNER JOIN incident_body_part ibp ON ibp.incident_id = f.id
        INNER JOIN (
            SELECT
                COALESCE(translation_group_id, id) AS group_id,
                MIN(CASE WHEN lang = :ui_lang THEN id END) AS ui_lang_id,
                -- Parent = original flash (no group or is root of its group)
                MIN(CASE WHEN translation_group_id IS NULL OR translation_group_id = id THEN id END) AS parent_id,
                MIN(id) AS any_id
            FROM sf_flashes
            WHERE state = 'published'
            GROUP BY COALESCE(translation_group_id, id)
        ) grp ON f.id = COALESCE(grp.ui_lang_id, grp.parent_id, grp.any_id)
        WHERE f.state = 'published'
        ORDER BY f.updated_at DESC
        LIMIT 200
    ");
    $stmt->execute([':ui_lang' => $uiLang]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($rows)) {
        $ids          = array_map(static fn($r) => (int)$r['id'], $rows);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $bpStmt       = $pdo->prepare("
            SELECT ibp.incident_id, bp.svg_id
            FROM   incident_body_part ibp
            INNER JOIN body_parts bp ON bp.id = ibp.body_part_id
            WHERE  ibp.incident_id IN ($placeholders)
        ");
        $bpStmt->execute($ids);
        $bpMap = [];
        foreach ($bpStmt->fetchAll(PDO::FETCH_ASSOC) as $bpRow) {
            $bpMap[(int)$bpRow['incident_id']][] = $bpRow['svg_id'];
        }
        foreach ($rows as $row) {
            $fid                   = (int)$row['id'];
            $injuryRecentFlashes[] = [
                'id'         => $fid,
                'type'       => $row['type']       ?? '',
                'title'      => $row['title']      ?? '',
                'site'       => $row['site']       ?? '',
                'updated_at' => $row['updated_at'] ?? '',
                'body_parts' => $bpMap[$fid]       ?? [],
            ];
        }
    }
} catch (Throwable $e) {}

// Load SVG inner content for the heatmap figures
$bpDir          = __DIR__ . '/../../assets/img/body-map/';
$heatmapFrontSvg = dashboardLoadBodySvg($bpDir . 'front.svg');
$heatmapBackSvg  = dashboardLoadBodySvg($bpDir . 'back.svg');

// --- Waiting for You (items relevant to current user) ---
$waitingItems = [];
try {
    // Build query based on user role - only using hardcoded conditions
    $whereParts = [];
    $params = [];
    
    // For creators: show their drafts and request_info items
    // Note: $userId is already cast to int on line 14, so it's safe
    if ($userId > 0) {
        $whereParts[] = "(created_by = :user_id AND state IN ('draft', 'request_info'))";
        $params[':user_id'] = $userId;
    }
    
    // For admins/reviewers: show pending_review and pending_supervisor items
    if ($isAdmin) {
        $whereParts[] = "(state IN ('pending_review', 'pending_supervisor'))";
    }
    
    // Execute query if there are conditions
    // Note: $whereParts only contains hardcoded strings from above, no user input
    if (!empty($whereParts)) {
        $whereClause = implode(' OR ', $whereParts);
        $sql = "
            SELECT id, type, title, title_short, site, state, created_by, updated_at 
            FROM sf_flashes 
            WHERE (" . $whereClause . ")
            ORDER BY updated_at DESC 
            LIMIT 10
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $waitingItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {}

?>

<div class="sf-page-container">
    <div class="sf-page-header sf-page-header--with-action">
        <h1 class="sf-page-title"><?= htmlspecialchars(sf_term('dashboard_title', $uiLang), ENT_QUOTES, 'UTF-8') ?></h1>
        <button id="sf-report-btn" class="sf-report-btn" type="button">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
            <?= htmlspecialchars(sf_term('dashboard_report_btn', $uiLang), ENT_QUOTES, 'UTF-8') ?>
        </button>
    </div>

    <div class="sf-dashboard">

        <!-- ========== STATISTICS SECTION (MAIN) ========== -->
        <div class="sf-dashboard-stats-section">
            
            <!-- Statistics Card -->
            <div class="sf-content-card">
                <div class="sf-section-header">
                    <span class="sf-section-icon">
                        <img src="<?= htmlspecialchars($baseUrl) ?>/assets/img/icons/dashboard.svg" alt="" style="width: 2rem; height: 2rem;">
                    </span>
                    <span class="sf-section-title" style="font-size: 1.125rem;">
                        <?= htmlspecialchars(sf_term('dashboard_statistics', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>

            <!-- Time Filter -->
            <div class="sf-time-filter-container">
                <div class="sf-time-filter-dropdowns">
                    <div class="sf-time-filter-group">
                        <label for="sf-filter-month" class="sf-filter-label">
                            <?= htmlspecialchars(sf_term('dashboard_filter_month', $uiLang), ENT_QUOTES, 'UTF-8') ?>:
                        </label>
                        <select id="sf-filter-month" class="sf-filter-select">
                            <option value=""><?= htmlspecialchars(sf_term('dashboard_filter_select_month', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                            <?php for ($m = 1; $m <= 12; $m++): ?>
                                <option value="<?= $m ?>"><?= htmlspecialchars(sf_term("month_$m", $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endfor; ?>
                        </select>
                    </div>

                    <div class="sf-time-filter-group">
                        <label for="sf-filter-year" class="sf-filter-label">
                            <?= htmlspecialchars(sf_term('dashboard_filter_year', $uiLang), ENT_QUOTES, 'UTF-8') ?>:
                        </label>
                        <select id="sf-filter-year" class="sf-filter-select">
                            <option value=""><?= htmlspecialchars(sf_term('dashboard_filter_all_years', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                            <?php foreach ($availableYears as $year): ?>
                                <option value="<?= (int)$year ?>">
                                    <?= (int)$year ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="sf-time-filter-quick">
                    <span class="sf-filter-quick-label">
                        <?= htmlspecialchars(sf_term('dashboard_filter_quick', $uiLang), ENT_QUOTES, 'UTF-8') ?>:
                    </span>
                    <div class="sf-time-filter-buttons">
                        <button class="sf-time-filter-btn" data-period="thismonth">
                            <?= htmlspecialchars(sf_term('dashboard_time_filter_thismonth', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                        </button>
                        <button class="sf-time-filter-btn" data-period="3months">
                            <?= htmlspecialchars(sf_term('dashboard_time_filter_3months', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                        </button>
                        <button class="sf-time-filter-btn" data-period="6months">
                            <?= htmlspecialchars(sf_term('dashboard_time_filter_6months', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                        </button>
                        <button class="sf-time-filter-btn" data-period="thisyear">
                            <?= htmlspecialchars(sf_term('dashboard_time_filter_thisyear', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                        </button>
                        <button class="sf-time-filter-btn sf-active" data-period="all">
                            <?= htmlspecialchars(sf_term('dashboard_time_filter_all', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                        </button>
                    </div>
                </div>
            </div>

            <!-- By Type Statistics (No Links) -->
            <h3 class="sf-subsection-title" style="font-size: 1.0625rem;">
                <?= htmlspecialchars(sf_term('dashboard_by_type', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </h3>

            <div class="sf-dashboard-stats sf-dashboard-stats--compact">
                <div class="sf-stat-card sf-stat-card--red sf-stat-card--no-link">
                    <span class="sf-stat-icon">
                        <img src="<?= htmlspecialchars($baseUrl) ?>/assets/img/icons/type-red.svg" alt="">
                    </span>
                    <span class="sf-stat-label"><?= htmlspecialchars(sf_term('dashboard_stat_red', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="sf-stat-count" data-stat="red"><?= (int)$originalStats['red'] ?></span>
                </div>

                <div class="sf-stat-card sf-stat-card--yellow sf-stat-card--no-link">
                    <span class="sf-stat-icon">
                        <img src="<?= htmlspecialchars($baseUrl) ?>/assets/img/icons/type-yellow.svg" alt="">
                    </span>
                    <span class="sf-stat-label"><?= htmlspecialchars(sf_term('dashboard_stat_yellow', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="sf-stat-count" data-stat="yellow"><?= (int)$originalStats['yellow'] ?></span>
                </div>

                <div class="sf-stat-card sf-stat-card--total sf-stat-card--no-link">
                    <span class="sf-stat-icon">
                        <img src="<?= htmlspecialchars($baseUrl) ?>/assets/img/icons/stats-total.svg" alt="">
                    </span>
                    <span class="sf-stat-label"><?= htmlspecialchars(sf_term('dashboard_stat_total', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="sf-stat-count" data-stat="total"><?= (int)$originalStats['total'] ?></span>
                </div>
            </div>
            
            </div> <!-- End Statistics Card -->
            
            <!-- Worksite Statistics Card -->
            <div class="sf-content-card">
                <div class="sf-section-header">
                    <span class="sf-section-icon">
                        <img src="<?= htmlspecialchars($baseUrl) ?>/assets/img/icons/worksite-stats.svg" alt="" style="width: 2rem; height: 2rem;">
                    </span>
                    <span class="sf-section-title" style="font-size: 1.125rem;">
                        <?= htmlspecialchars(sf_term('dashboard_by_worksite', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>


            <?php if (!empty($worksiteStats)): ?>
                <div class="sf-worksite-bars">
                    <?php foreach ($worksiteStats as $index => $ws): 
                        $siteName = $ws['site'] ?? '';
                        $siteCount = (int)($ws['count'] ?? 0);
                        $barWidth = $maxCount > 0 ? round(($siteCount / $maxCount) * 100) : 0;
                        $isHidden = $index >= 5 ? 'sf-worksite-hidden' : '';
                    ?>
                        <a href="<?= htmlspecialchars($baseUrl) ?>/index.php?page=list&site=<?= urlencode($siteName) ?>" class="sf-worksite-bar-row <?= $isHidden ?>" style="--bar-delay: <?= $index * 0.08 ?>s;">
                            <span class="sf-worksite-name"><?= htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8') ?></span>
                            <div class="sf-worksite-bar-wrap">
                                <div class="sf-worksite-bar" style="--bar-width: <?= $barWidth ?>%;">
                                    <span class="sf-worksite-count"><?= $siteCount ?></span>
                                </div>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>

                <?php if (count($worksiteStats) > 5): ?>
                    <button class="sf-worksite-show-all" 
                            data-show-text="<?= htmlspecialchars(sf_term('dashboard_show_all', $uiLang), ENT_QUOTES, 'UTF-8') ?>"
                            data-hide-text="<?= htmlspecialchars(sf_term('dashboard_show_less', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
                        <span class="sf-toggle-text"><?= htmlspecialchars(sf_term('dashboard_show_all', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="sf-toggle-icon">▼</span>
                    </button>
                <?php endif; ?>
            <?php else: ?>
                <div class="sf-pending-empty">
                    <img src="<?= htmlspecialchars($baseUrl) ?>/assets/img/icons/empty-data.svg" alt="" style="width: 2rem; height: 2rem; opacity: 0.4;">
                    <span><?= htmlspecialchars(sf_term('dashboard_no_data', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            <?php endif; ?>
            
            </div> <!-- End Worksite Statistics Card -->
            
        </div> <!-- End sf-dashboard-stats-section -->

        <!-- ========== COMPACT CARDS SECTION ========== -->
        <div class="sf-compact-cards">
            
            <!-- Waiting for You (Compact) -->
            <div class="sf-content-card">
                <div class="sf-section-header">
                    <span class="sf-section-icon">
                        <img src="<?= htmlspecialchars($baseUrl) ?>/assets/img/icons/pending.svg" alt="" style="width: 1.25rem; height: 1.25rem;">
                    </span>
                    <span class="sf-section-title"><?= htmlspecialchars(sf_term('dashboard_pending_title', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
                </div>

                <?php if (empty($waitingItems)): ?>
                    <div class="sf-pending-empty">
                        <img src="<?= htmlspecialchars($baseUrl) ?>/assets/img/icons/pending.svg" alt="" style="width: 2rem; height: 2rem; opacity: 0.4;">
                        <span><?= htmlspecialchars(sf_term('dashboard_pending_empty', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                <?php else: ?>
                    <div class="sf-recent-compact-list">
                        <?php foreach ($waitingItems as $item): 
                            $itemTitle = !empty($item['title_short']) ? $item['title_short'] : $item['title'];
                            $itemType = $item['type'] ?? '';
                            $itemId = (int)($item['id'] ?? 0);
                            $itemSite = $item['site'] ?? '';
                            $itemState = $item['state'] ?? '';
                            $itemTime = $item['updated_at'] ?? '';
                            $stateLabel = sf_status_label($itemState, $uiLang);
                        ?>
                            <a href="<?= htmlspecialchars($baseUrl) ?>/index.php?page=view&id=<?= $itemId ?>" class="sf-recent-compact-item">
                                <span class="sf-type-dot sf-type-dot--<?= htmlspecialchars($itemType) ?>"></span>
                                <div class="sf-recent-compact-content">
                                    <div class="sf-recent-compact-title"><?= htmlspecialchars($itemTitle, ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="sf-recent-compact-meta">
                                        <span><?= htmlspecialchars($stateLabel, ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php if (!empty($itemSite)): ?>
                                            <span>·</span>
                                            <span><?= htmlspecialchars($itemSite, ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($itemTime)): ?>
                                            <span>·</span>
                                            <span class="sf-recent-compact-time"><?= htmlspecialchars(sf_time_ago($itemTime, $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <div style="margin-top: 1rem; text-align: center;">
                    <a href="<?= htmlspecialchars($baseUrl) ?>/index.php?page=list" class="sf-show-all-link">
                        <?= htmlspecialchars(sf_term('dashboard_show_all', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                    </a>
                </div>
            </div>

            <!-- Injury Heatmap (replaces Recent) -->
            <div class="sf-content-card sf-injury-card" id="sf-injury-card">
                <div class="sf-section-header sf-section-header--wrap">
                    <span class="sf-section-icon">
                        <img src="<?= htmlspecialchars($baseUrl) ?>/assets/img/icons/injury_icon.svg" alt="" style="width: 1.25rem; height: 1.25rem;">
                    </span>
                    <span class="sf-section-title"><?= htmlspecialchars(sf_term('dashboard_injury_title', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
                    <div class="sf-injury-site-wrap">
                        <select id="sf-injury-site-filter" class="sf-filter-select sf-filter-select--sm" aria-label="<?= htmlspecialchars(sf_term('dashboard_injury_all_sites', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
                            <option value=""><?= htmlspecialchars(sf_term('dashboard_injury_all_sites', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                            <?php foreach ($injurySites as $injurySite): ?>
                                <option value="<?= htmlspecialchars($injurySite, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($injurySite, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="sf-injury-layout">
                    <!-- Top-left (60%): SVG Body Figures (front + back) -->
                    <div class="sf-injury-svg-col">
                        <p class="sf-injury-svg-hint">
                            <span class="sf-injury-svg-hint-icon" aria-hidden="true">ℹ</span>
                            <?= htmlspecialchars(sf_term('dashboard_injury_click_hint', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                        </p>
                        <div class="sf-injury-svg-figures">
                            <svg class="sf-heatmap-body-svg" id="sf-heatmap-svg-front"
                                 viewBox="0 0 261.58 620.34" xmlns="http://www.w3.org/2000/svg"
                                 role="img" aria-label="<?= htmlspecialchars(sf_term('body_map_front_label', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
                                <?= $heatmapFrontSvg ?>
                            </svg>
                            <svg class="sf-heatmap-body-svg sf-heatmap-body-svg--back" id="sf-heatmap-svg-back"
                                 viewBox="0 0 261.58 597.52" xmlns="http://www.w3.org/2000/svg"
                                 role="img" aria-label="<?= htmlspecialchars(sf_term('body_map_back_label', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
                                <?= $heatmapBackSvg ?>
                            </svg>
                        </div>
                    </div>

                    <!-- Top-right (40%): Bar chart -->
                    <div class="sf-injury-chart-col">
                        <div class="sf-injury-chart-section">
                            <h4 class="sf-injury-section-label"><?= htmlspecialchars(sf_term('dashboard_injury_chart_title', $uiLang), ENT_QUOTES, 'UTF-8') ?></h4>
                            <div id="sf-injury-chart" class="sf-injury-chart"></div>
                        </div>
                    </div>

                    <!-- Bottom (100%): Recent injury list -->
                    <div class="sf-injury-list-col">
                        <div class="sf-injury-list-section">
                            <div class="sf-injury-list-header">
                                <h4 class="sf-injury-section-label"><?= htmlspecialchars(sf_term('dashboard_injury_recent_title', $uiLang), ENT_QUOTES, 'UTF-8') ?></h4>
                                <span class="sf-injury-active-filter" id="sf-injury-active-filter"></span>
                            </div>
                            <div class="sf-recent-compact-list" id="sf-injury-flash-list"></div>
                            <button class="sf-injury-show-all-btn" id="sf-injury-show-all-btn" style="display:none;" aria-haspopup="dialog">
                                <span class="sf-injury-show-all-text"></span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

        </div>

    </div>
</div>

<!-- ========== REPORT MODAL ========== -->
<div class="sf-report-modal" id="sf-report-modal" role="dialog" aria-modal="true" aria-labelledby="sf-report-modal-title" style="display:none;">
    <div class="sf-report-modal-backdrop" id="sf-report-modal-backdrop"></div>
    <div class="sf-report-modal-box">
        <div class="sf-report-modal-header">
            <h2 class="sf-report-modal-title" id="sf-report-modal-title">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path><polyline points="14 2 14 8 20 8"></polyline><line x1="16" y1="13" x2="8" y2="13"></line><line x1="16" y1="17" x2="8" y2="17"></line><polyline points="10 9 9 9 8 9"></polyline></svg>
                <?= htmlspecialchars(sf_term('dashboard_report_title', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </h2>
            <button class="sf-report-modal-close" id="sf-report-modal-close" aria-label="<?= htmlspecialchars(sf_term('dashboard_injury_modal_close', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
                <span aria-hidden="true">✕</span>
            </button>
        </div>
        <div class="sf-report-modal-body">

            <!-- Period selection -->
            <div class="sf-report-modal-section">
                <h3 class="sf-report-modal-section-title"><?= htmlspecialchars(sf_term('dashboard_report_period_label', $uiLang), ENT_QUOTES, 'UTF-8') ?></h3>
                <div class="sf-report-quick-btns">
                    <button class="sf-report-quick-btn" data-period="thismonth" type="button"><?= htmlspecialchars(sf_term('dashboard_time_filter_thismonth', $uiLang), ENT_QUOTES, 'UTF-8') ?></button>
                    <button class="sf-report-quick-btn" data-period="3months" type="button"><?= htmlspecialchars(sf_term('dashboard_time_filter_3months', $uiLang), ENT_QUOTES, 'UTF-8') ?></button>
                    <button class="sf-report-quick-btn" data-period="6months" type="button"><?= htmlspecialchars(sf_term('dashboard_time_filter_6months', $uiLang), ENT_QUOTES, 'UTF-8') ?></button>
                    <button class="sf-report-quick-btn" data-period="thisyear" type="button"><?= htmlspecialchars(sf_term('dashboard_time_filter_thisyear', $uiLang), ENT_QUOTES, 'UTF-8') ?></button>
                    <button class="sf-report-quick-btn sf-active" data-period="all" type="button"><?= htmlspecialchars(sf_term('dashboard_time_filter_all', $uiLang), ENT_QUOTES, 'UTF-8') ?></button>
                </div>
                <div class="sf-report-date-row">
                    <div class="sf-report-date-group">
                        <label for="sf-report-start-date" class="sf-report-label"><?= htmlspecialchars(sf_term('dashboard_report_start_date', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
                        <input type="date" id="sf-report-start-date" class="sf-report-date-input" />
                    </div>
                    <div class="sf-report-date-group">
                        <label for="sf-report-end-date" class="sf-report-label"><?= htmlspecialchars(sf_term('dashboard_report_end_date', $uiLang), ENT_QUOTES, 'UTF-8') ?></label>
                        <input type="date" id="sf-report-end-date" class="sf-report-date-input" />
                    </div>
                </div>
            </div>

            <!-- Site filter -->
            <div class="sf-report-modal-section">
                <h3 class="sf-report-modal-section-title"><?= htmlspecialchars(sf_term('dashboard_report_site_filter', $uiLang), ENT_QUOTES, 'UTF-8') ?></h3>
                <select id="sf-report-site" class="sf-report-select">
                    <option value=""><?= htmlspecialchars(sf_term('dashboard_report_all_sites', $uiLang), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php foreach ($worksiteStats as $ws): ?>
                        <option value="<?= htmlspecialchars($ws['site'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($ws['site'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <!-- Content selection -->
            <div class="sf-report-modal-section">
                <h3 class="sf-report-modal-section-title"><?= htmlspecialchars(sf_term('dashboard_report_content_label', $uiLang), ENT_QUOTES, 'UTF-8') ?></h3>
                <div class="sf-report-checkboxes">
                    <label class="sf-report-checkbox-label">
                        <input type="checkbox" id="sf-report-include-stats" checked>
                        <span><?= htmlspecialchars(sf_term('dashboard_report_include_stats', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
                    </label>
                    <label class="sf-report-checkbox-label">
                        <input type="checkbox" id="sf-report-include-worksites" checked>
                        <span><?= htmlspecialchars(sf_term('dashboard_report_include_worksites', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
                    </label>
                    <label class="sf-report-checkbox-label">
                        <input type="checkbox" id="sf-report-include-injuries" checked>
                        <span><?= htmlspecialchars(sf_term('dashboard_report_include_injuries', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
                    </label>
                    <label class="sf-report-checkbox-label">
                        <input type="checkbox" id="sf-report-include-recent" checked>
                        <span><?= htmlspecialchars(sf_term('dashboard_report_include_recent', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
                    </label>
                </div>
            </div>
        </div>
        <div class="sf-report-modal-footer">
            <button id="sf-report-cancel-btn" class="sf-report-cancel-btn" type="button">
                <?= htmlspecialchars(sf_term('dashboard_report_cancel', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button id="sf-report-generate-btn" class="sf-report-generate-btn" type="button">
                <span class="sf-report-btn-spinner" style="display:none;" aria-hidden="true"></span>
                <span class="sf-report-btn-text"><?= htmlspecialchars(sf_term('dashboard_report_generate', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
            </button>
        </div>
    </div>
</div>

<!-- ========== INJURY MODAL ========== -->
<div class="sf-injury-modal" id="sf-injury-modal" role="dialog" aria-modal="true" aria-labelledby="sf-injury-modal-title" style="display:none;">
    <div class="sf-injury-modal-backdrop" id="sf-injury-modal-backdrop"></div>
    <div class="sf-injury-modal-box">
        <!-- Modal header -->
        <div class="sf-injury-modal-header">
            <h2 class="sf-injury-modal-title" id="sf-injury-modal-title">
                <?= htmlspecialchars(sf_term('dashboard_injury_modal_title', $uiLang), ENT_QUOTES, 'UTF-8') ?>
            </h2>
            <button class="sf-injury-modal-close" id="sf-injury-modal-close" aria-label="<?= htmlspecialchars(sf_term('dashboard_injury_modal_close', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
                <span aria-hidden="true">✕</span>
            </button>
        </div>
        <!-- Modal body: two-column layout -->
        <div class="sf-injury-modal-body">
            <!-- Left column: sticky SVG body figures -->
            <div class="sf-injury-modal-svg-col">
                <p class="sf-injury-svg-hint">
                    <span class="sf-injury-svg-hint-icon" aria-hidden="true">ℹ</span>
                    <?= htmlspecialchars(sf_term('dashboard_injury_click_hint', $uiLang), ENT_QUOTES, 'UTF-8') ?>
                </p>
                <div class="sf-injury-svg-figures">
                    <svg class="sf-heatmap-body-svg" id="sf-modal-heatmap-svg-front"
                         viewBox="0 0 261.58 620.34" xmlns="http://www.w3.org/2000/svg"
                         role="img" aria-label="<?= htmlspecialchars(sf_term('body_map_front_label', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
                        <?= $heatmapFrontSvg ?>
                    </svg>
                    <svg class="sf-heatmap-body-svg sf-heatmap-body-svg--back" id="sf-modal-heatmap-svg-back"
                         viewBox="0 0 261.58 597.52" xmlns="http://www.w3.org/2000/svg"
                         role="img" aria-label="<?= htmlspecialchars(sf_term('body_map_back_label', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
                        <?= $heatmapBackSvg ?>
                    </svg>
                </div>
                <div class="sf-injury-modal-active-filter-wrap">
                    <span class="sf-injury-active-filter" id="sf-injury-modal-active-filter"></span>
                </div>
            </div>
            <!-- Right column: scrollable full list -->
            <div class="sf-injury-modal-list-col">
                <div class="sf-recent-compact-list sf-injury-modal-list" id="sf-injury-modal-flash-list"></div>
            </div>
        </div>
    </div>
</div>

<script>
window.SF_INJURY_INITIAL_DATA = <?= json_encode([
    'bodyPartCounts' => $injuryBodyParts,
    'recentFlashes'  => $injuryRecentFlashes,
    'sites'          => $injurySites,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
window.SF_INJURY_I18N = {
    empty:         <?= json_encode(sf_term('dashboard_injury_empty', $uiLang),          JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
    noMatch:       <?= json_encode(sf_term('dashboard_injury_no_match', $uiLang),       JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
    activeFilter:  <?= json_encode(sf_term('dashboard_injury_active_filter', $uiLang),  JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
    today:         <?= json_encode(sf_term('time_ago_today', $uiLang),                  JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
    yesterday:     <?= json_encode(sf_term('time_ago_yesterday', $uiLang),              JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
    daysAgo:       <?= json_encode(sf_term('time_ago_days', $uiLang),                   JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
    showAllCount:  <?= json_encode(sf_term('dashboard_injury_show_all_count', $uiLang), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
};
window.SF_REPORT_I18N = {
    generating:    <?= json_encode(sf_term('dashboard_report_generating', $uiLang),    JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
    generate:      <?= json_encode(sf_term('dashboard_report_generate', $uiLang),      JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
    error:         <?= json_encode(sf_term('dashboard_report_error', $uiLang),         JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
    selectContent: <?= json_encode(sf_term('dashboard_report_select_content', $uiLang),JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>,
};
window.SF_CSRF_TOKEN = <?= json_encode(sf_csrf_token(), JSON_HEX_TAG | JSON_HEX_AMP) ?>;
window.SF_REPORT_SITES = <?= json_encode($worksiteStats ? array_column($worksiteStats, 'site') : [], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= htmlspecialchars($baseUrl, ENT_QUOTES, 'UTF-8') ?>/assets/js/dashboard-report.js"></script>

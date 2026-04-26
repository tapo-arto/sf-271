<?php
// assets/pages/list.php
declare(strict_types=1);
ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/../../app/includes/protect.php';
require_once __DIR__ . '/../../app/includes/statuses.php';

$baseUrl = rtrim($config['base_url'] ?? '', '/');

// Default language constant
define('DEFAULT_LANG', 'fi');

// Käyttöliittymän kieli
$uiLang = $_SESSION['ui_lang'] ?? DEFAULT_LANG;

// Tarkista onko käyttäjä ylläpitäjä
$user = sf_current_user();
$isAdmin = $user && (int)($user['role_id'] ?? 0) === 1;

// POISTETTU: Ei enää käytetä "recently processed" logiikkaa

// Tarkista onko juuri luotu flash (URL-parametrista)
$bgProcessId = isset($_GET['bg_process']) ? (int)$_GET['bg_process'] : 0;

// --- DB connection ---
try {
    $pdo = Database::getInstance();
} catch (Throwable $e) {
    http_response_code(500);
    echo '<p>' . htmlspecialchars(sf_term('db_error', $uiLang), ENT_QUOTES, 'UTF-8') . '</p>';
    exit;
}

// Tuetut kielet ja lippukuvat (käytetään 'el' kreikalle)
$supportedLangs = [
    'fi' => ['label' => 'FI', 'icon' => 'finnish-flag.png'],
    'sv' => ['label' => 'SV', 'icon' => 'swedish-flag.png'],
    'en' => ['label' => 'EN', 'icon' => 'english-flag.png'],
    'it' => ['label' => 'IT', 'icon' => 'italian-flag.png'],
    'el' => ['label' => 'EL', 'icon' => 'greece-flag.png'],
];

// Tarkista onko käyttäjällä kotityömaa ja ohjaa automaattisesti suodatettuun näkymään
$homeWorksiteName = '';
if ($user) {
    $homeWorksiteId = $user['home_worksite_id'] ?? null;
    if ($homeWorksiteId) {
        $stmtWs = $pdo->prepare("SELECT name FROM sf_worksites WHERE id = :id LIMIT 1");
        $stmtWs->execute([':id' => $homeWorksiteId]);
        $wsRow = $stmtWs->fetch();
        if ($wsRow && ! empty($wsRow['name'])) {
            $homeWorksiteName = $wsRow['name'];
        }
    }
}

// Filters (from URL)
$type           = $_GET['type']           ?? '';
$originalType   = $_GET['original_type']  ?? '';
$onlyOriginals  = isset($_GET['only_originals']) && $_GET['only_originals'] === '1';
$state          = $_GET['state']          ?? '';
// Jos URL:ssa ei ole 'site'-parametria lainkaan, käytetään oletuksena kotityömaa-suodatusta SQL:ssä.
// Jos URL:ssa on tyhjä 'site' (?site=), käyttäjä haluaa nähdä kaikki työmaat (ei suodatusta).
if (!isset($_GET['site'])) {
    $site = $homeWorksiteName; // Oletussuodatus palvelinpuolella
} else {
    $site = $_GET['site']; // Tyhjä = kaikki työmaat, muuten suodatetaan
}
$q            = trim((string)($_GET['q']    ?? ''));
$from         = $_GET['date_from']     ?? '';
$to           = $_GET['date_to']       ?? '';
$archived     = $_GET['archived']      ?? '';

// Pagination
$perPage     = 30;
$currentPage = max(1, (int)($_GET['p'] ?? 1));
$offset      = ($currentPage - 1) * $perPage;

// Sorting parameters
$sortField = $_GET['sort']  ?? 'created';
$sortOrder = $_GET['order'] ?? 'desc';

// Validate sort field
$validSortFields = ['created', 'occurred', 'updated'];
if (!in_array($sortField, $validSortFields)) {
    $sortField = 'created';
}

// Validate sort order
if (!in_array($sortOrder, ['asc', 'desc'])) {
    $sortOrder = 'desc';
}

// Tarkista onko käyttäjällä mitään suodattimia URL:ssa
$hasAnyFilter = isset($_GET['type']) || isset($_GET['original_type']) || isset($_GET['only_originals']) || isset($_GET['state']) || isset($_GET['site']) || isset($_GET['q']) || isset($_GET['date_from']) || isset($_GET['date_to']) || isset($_GET['archived']);

// $siteIsDefault kertoo, tuliko $site URL-parametrista vai oletuksena kotityömaasta.
// Käytetään UI-elementtien (chip aktiivisuus, clear-nappi) renderöinnissä.
$siteIsDefault = !isset($_GET['site']);
$autoSite = $site;
// --- Työmaat dropdownia varten (kaikki aktiiviset työmaat) ---
$sites = $pdo->query("SELECT name FROM sf_worksites WHERE is_active = 1 AND show_in_worksite_lists = 1 ORDER BY name ASC")
             ->fetchAll(PDO::FETCH_COLUMN);

// --- Build SQL ---
$where  = [];
$params = [];

// Define current user ID early so it can be used in site filter bypass below
$currentUserId = (int)($user['id'] ?? 0);

if ($type !== '') {
    $where[]         = "f.type = :type";
    $params[':type'] = $type;
}

if ($originalType !== '') {
    $where[]                   = "(f.original_type = :original_type OR (f.original_type IS NULL AND f.type = :original_type2))";
    $params[':original_type']  = $originalType;
    $params[':original_type2'] = $originalType;
}

if ($onlyOriginals) {
    $where[] = "(f.translation_group_id IS NULL OR f.translation_group_id = f.id)";
}

if ($state !== '') {
    $where[]          = "f.state = :state";
    $params[':state'] = $state;
}

if ($site !== '') {
    $where[]         = "f.site = :site";
    $params[':site'] = $site;
}

if ($q !== '') {
    $escapedQ = addcslashes($q, '%_\\');
    $where[]       = "(f.title LIKE :q1
                   OR f.title_short LIKE :q2
                   OR f.summary LIKE :q3
                   OR f.description LIKE :q4)";
    $qVal = "%" . $escapedQ . "%";
    $params[':q1'] = $qVal;
    $params[':q2'] = $qVal;
    $params[':q3'] = $qVal;
    $params[':q4'] = $qVal;
}

if ($from !== '') {
    $where[]         = "f.occurred_at >= :from";
    $params[':from'] = "$from 00:00:00";
}

if ($to !== '') {
    $where[]       = "f.occurred_at <= :to";
    $params[':to'] = "$to 23:59:59";
}

// Archived filter: default is to hide archived unless specifically requested
if ($archived === 'only') {
    $where[] = "f.is_archived = 1";
} elseif ($archived === 'all') {
    // Show both archived and non-archived - no filter
} else {
    // Default: hide archived
    $where[] = "f.is_archived = 0";
}

// $currentUserId is defined above (before site filter); used below for unread comments

// Default fallback: if creation time is not available, use epoch
$currentUserCreatedAt = '1970-01-01 00:00:00';

// Fetch current user's created_at so new users do not inherit all historical comments
if ($currentUserId > 0) {
    try {
        $stmtUserCreated = $pdo->prepare("SELECT created_at FROM sf_users WHERE id = :id LIMIT 1");
        $stmtUserCreated->execute([':id' => $currentUserId]);
        $userCreatedRow = $stmtUserCreated->fetch();

        if (!empty($userCreatedRow['created_at'])) {
            $currentUserCreatedAt = $userCreatedRow['created_at'];
        }
    } catch (Throwable $e) {
        error_log('list.php: Failed to fetch current user created_at: ' . $e->getMessage());
    }
}

// --- Role-based visibility (SQL) ---
// Replaces the PHP-level visibility filter. Admin sees everything; others see
// only flashes they have access to based on state and role.
$roleId   = $user ? (int)$user['role_id'] : 0;
$isSafety = $roleId === 3;
$isComms  = $roleId === 4;

if (!$isAdmin) {
    $isSafetyInt = $isSafety ? 1 : 0;  // 0 or 1 — safe to inline; not user-supplied
    $isCommsInt  = $isComms  ? 1 : 0;  // 0 or 1

    // JSON_CONTAINS requires its second argument to be a valid JSON document.
    // selected_approvers may be stored as numeric array [5,12] or string array
    // ["5","12"] depending on which code path saved it. We check both forms so
    // that existing records with string IDs are matched correctly.
    $where[] = "(
        f.state = 'published'
        OR f.created_by = :vis_uid
        OR ({$isSafetyInt} = 1 AND f.state != 'draft')
        OR ({$isCommsInt} = 1 AND f.state IN ('to_comms', 'awaiting_publish'))
        OR (
            f.selected_approvers IS NOT NULL
            AND JSON_VALID(f.selected_approvers)
            AND (
                JSON_CONTAINS(f.selected_approvers, :vis_uid_json)
                OR JSON_CONTAINS(f.selected_approvers, :vis_uid_json_str)
                OR (
                    JSON_TYPE(JSON_EXTRACT(f.selected_approvers, '$.approver_ids')) = 'ARRAY'
                    AND (
                        JSON_CONTAINS(JSON_EXTRACT(f.selected_approvers, '$.approver_ids'), :vis_uid_json2)
                        OR JSON_CONTAINS(JSON_EXTRACT(f.selected_approvers, '$.approver_ids'), :vis_uid_json_str2)
                    )
                )
            )
        )
        OR EXISTS (
            SELECT 1
            FROM safetyflash_logs sl
            WHERE sl.flash_id = COALESCE(f.translation_group_id, f.id)
              AND sl.user_id = :vis_uid_logs
        )
    )";

    $params[':vis_uid']              = $currentUserId;
    $params[':vis_uid_json']         = (string)$currentUserId;
    $params[':vis_uid_json_str']     = json_encode((string)$currentUserId);
    $params[':vis_uid_json2']        = (string)$currentUserId;
    $params[':vis_uid_json_str2']    = json_encode((string)$currentUserId);
    $params[':vis_uid_logs']         = $currentUserId;
}

// --- Sort field mapping ---
$sortColumnMap = [
    'created'  => 'f.created_at',
    'occurred' => 'f.occurred_at',
    'updated'  => 'f.updated_at',
];
$sortColumn  = $sortColumnMap[$sortField] ?? 'f.created_at';
$sortDirSQL  = $sortOrder === 'asc' ? 'ASC' : 'DESC';

$whereSql = $where ? ("WHERE " . implode(" AND ", $where)) : "";

// --- Step 1: Count distinct visible groups ---
$countSql = "SELECT COUNT(*) FROM (
    SELECT COALESCE(f.translation_group_id, f.id) AS group_id
    FROM sf_flashes f
    {$whereSql}
    GROUP BY COALESCE(f.translation_group_id, f.id)
) AS g";

$countStmt = $pdo->prepare($countSql);
$countStmt->execute($params);
$totalGroups = (int)$countStmt->fetchColumn();
$totalPages  = max(1, (int)ceil($totalGroups / $perPage));
// Clamp current page
if ($currentPage > $totalPages) {
    $currentPage = $totalPages;
    $offset      = ($currentPage - 1) * $perPage;
}

// --- Step 2: Fetch paginated group IDs in sort order ---
// LIMIT/OFFSET cannot be bound as parameters with native prepared statements
// (EMULATE_PREPARES=false). Both values are strictly cast to int above.
$groupIdSql = "SELECT COALESCE(f.translation_group_id, f.id) AS group_id,
                      MAX({$sortColumn}) AS sort_val
               FROM sf_flashes f
               {$whereSql}
               GROUP BY COALESCE(f.translation_group_id, f.id)
               ORDER BY sort_val {$sortDirSQL}
               LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;

$groupIdStmt = $pdo->prepare($groupIdSql);
$groupIdStmt->execute($params);
$groupIdRows  = $groupIdStmt->fetchAll();
$groupIdsList = array_column($groupIdRows, 'group_id');

// --- Step 3: Fetch all flash rows for the paginated groups ---
$fetched = [];
if (!empty($groupIdsList)) {
    $placeholders = implode(',', array_fill(0, count($groupIdsList), '?'));
    $dataSql = "SELECT f.*,
                DATE_FORMAT(f.occurred_at, '%d.%m.%Y %H:%i') AS occurredFmt,
                DATE_FORMAT(f.updated_at, '%d.%m.%Y %H:%i') AS updatedFmt,
                (SELECT COUNT(*)
                 FROM safetyflash_logs sl
                 WHERE sl.flash_id = COALESCE(f.translation_group_id, f.id)
                   AND sl.event_type = 'comment_added'
                   AND sl.created_at > GREATEST(
                       COALESCE(
                           (SELECT last_read_at FROM sf_flash_reads r
                            WHERE r.flash_id = COALESCE(f.translation_group_id, f.id)
                              AND r.user_id = ?),
                           '1970-01-01 00:00:00'
                       ),
                       ?,
                       DATE_SUB(NOW(), INTERVAL 7 DAY)
                   )
                ) AS new_comment_count
                FROM sf_flashes f
                WHERE COALESCE(f.translation_group_id, f.id) IN ({$placeholders})";

    $dataStmt = $pdo->prepare($dataSql);
    // $dataParams intentionally omits the filter params from $params because
    // the data query's WHERE clause only references group IDs (already filtered
    // in Step 2). The only additional params needed are for the unread-comment
    // correlated subquery (current_user_id and current_user_created_at).
    $dataParams = array_merge(
        [$currentUserId, $currentUserCreatedAt],
        array_map('intval', $groupIdsList)
    );
    $dataStmt->execute($dataParams);
    $fetched = $dataStmt->fetchAll();
}

// Hae prosessoinnissa olevat flashit (vain polling-dataa varten, ei näytetä kortteja)
$processingFlashes = [];
try {
    $procSql = "SELECT f.id, f.processing_status
                FROM sf_flashes f 
                WHERE f.is_processing = 1 
                ORDER BY f.created_at DESC 
                LIMIT 20";
    $procStmt = $pdo->query($procSql);
    $processingFlashes = $procStmt->fetchAll();
} catch (Throwable $e) {
    error_log('list.php: Error fetching processing flashes: ' . $e->getMessage());
}

$groups = [];
foreach ($fetched as $r) {
    $groupId = !empty($r['translation_group_id']) ? (int)$r['translation_group_id'] : (int)$r['id'];
    $groups[$groupId][] = $r;
}

$statePriority = [
    'published'         => 100,
    'awaiting_publish'  => 95,
    'to_comms'          => 90,
    'pending_supervisor'=> 85,
    'request_info'      => 80,
    'reviewed'          => 70,
    'pending_review'    => 60,
    'draft'             => 50,
];
$defaultPriority = 10;

// Get user's UI language for prioritizing translations
$userLang = $_SESSION['ui_lang'] ?? DEFAULT_LANG;

// Language labels for badge display
$langLabels = [
    'fi' => 'FI',
    'sv' => 'SV', 
    'en' => 'EN',
    'it' => 'IT',
    'el' => 'EL'
];

// Build one representative row per group (preferred language or highest-priority state).
// Then restore the SQL sort order that was determined by the paginated group ID query.
$rowsByGroup = [];
foreach ($groups as $gid => $items) {
    // Try to find a version in user's preferred language
    $preferredVersion = null;
    foreach ($items as $item) {
        if (($item['lang'] ?? DEFAULT_LANG) === $userLang) {
            $preferredVersion = $item;
            break;
        }
    }

    if ($preferredVersion !== null) {
        $rowsByGroup[$gid] = $preferredVersion;
        continue;
    }

    // Fall back to highest priority state
    usort($items, function($a, $b) use ($statePriority, $defaultPriority) {
        $pa = $statePriority[$a['state']] ?? $defaultPriority;
        $pb = $statePriority[$b['state']] ?? $defaultPriority;
        if ($pa !== $pb) return $pb <=> $pa;
        return strtotime($b['created_at'] ?? 0) <=> strtotime($a['created_at'] ?? 0);
    });
    $rowsByGroup[$gid] = $items[0];
}

// Restore SQL sort order from $groupIdsList
$rows = [];
foreach ($groupIdsList as $gid) {
    if (isset($rowsByGroup[(int)$gid])) {
        $rows[] = $rowsByGroup[(int)$gid];
    }
}

// Helpers
function typeBadgeClass($t) {
    return [
        'red'    => 'badge-red',
        'yellow' => 'badge-yellow',
        'green'  => 'badge-green',
    ][$t] ?? 'badge-default';
}



/**
 * Fetch all translations for multiple flash groups in a single query.
 * 
 * This function optimizes the N+1 query problem by fetching translations
 * for all groups at once, instead of making a separate query for each group.
 * 
 * @param PDO $pdo Database connection
 * @param array $groupIds Array of translation group IDs
 * @return array Associative array with group IDs as keys, each containing
 *               a map of language codes to flash IDs
 */
function sf_get_all_translations(PDO $pdo, array $groupIds): array {
    if (empty($groupIds)) {
        return [];
    }
    
    // Ensure all group IDs are integers for security
    $groupIds = array_map('intval', array_values($groupIds));
    
    $placeholders = implode(',', array_fill(0, count($groupIds), '?'));
    $sql = "SELECT id, lang, COALESCE(translation_group_id, id) as group_id 
            FROM sf_flashes 
            WHERE COALESCE(translation_group_id, id) IN ($placeholders)";
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($groupIds);
    $rows = $stmt->fetchAll();
    
    $translations = [];
    foreach ($rows as $r) {
        $gid = (int)$r['group_id'];
        if (!isset($translations[$gid])) {
            $translations[$gid] = [];
        }
        $translations[$gid][$r['lang']] = (int)$r['id'];
    }
    
    return $translations;
}

$currentUiLang = $uiLang ?? DEFAULT_LANG;
?>

<div class="sf-page-container">
    <div class="sf-page-header">
        <h1 class="sf-page-title">
            <?= htmlspecialchars(sf_term('list_heading', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </h1>
    </div>

<div class="sf-list-page">

<!-- FILTER HEADER WITH TOGGLE, SEARCH, AND CLEAR BUTTON -->
<div class="sf-filter-header">
    <!-- ARCHIVED/ACTIVE TOGGLE (Segmented Control) -->
    <div class="sf-archived-toggle" role="group" aria-label="<?= htmlspecialchars(sf_term('filter_archived', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
        <button type="button" 
                class="sf-toggle-btn<?= $archived === '' ? ' active' : '' ?>" 
                data-archived-value="" 
                aria-pressed="<?= $archived === '' ? 'true' : 'false' ?>">
            <?= htmlspecialchars(sf_term('filter_chip_archived_active', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </button>
        <button type="button" 
                class="sf-toggle-btn<?= $archived === 'only' ? ' active' : '' ?>" 
                data-archived-value="only" 
                aria-pressed="<?= $archived === 'only' ? 'true' : 'false' ?>">
            <?= htmlspecialchars(sf_term('filter_chip_archived_only', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </button>
        <button type="button" 
                class="sf-toggle-btn<?= $archived === 'all' ? ' active' : '' ?>" 
                data-archived-value="all" 
                aria-pressed="<?= $archived === 'all' ? 'true' : 'false' ?>">
            <?= htmlspecialchars(sf_term('filter_chip_archived_all', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </button>
    </div>

    <!-- SEARCH AND CLEAR BUTTON -->
    <div class="sf-filter-search">
        <input
            type="text"
            id="sf-search-input"
            placeholder="<?= htmlspecialchars(sf_term('filter_search_placeholder', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
            value="<?= htmlspecialchars($q) ?>"
            aria-label="<?= htmlspecialchars(sf_term('filter_search_placeholder', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
        >
        <!-- Search button -->
        <button type="button" class="sf-search-btn" id="sf-search-btn" title="<?= htmlspecialchars(sf_term('filter_search', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
        </button>
        
        <!-- Clear filters -->
        <?php $hasExplicitFilters = ($type !== '' || $originalType !== '' || $onlyOriginals || $state !== '' || (!$siteIsDefault && $site !== '') || $q !== '' || $from !== '' || $to !== '' || $archived !== ''); ?>
        <button type="button" class="sf-filter-clear-btn<?= !$hasExplicitFilters ? ' hidden' : '' ?>" id="sf-clear-all-btn" title="<?= htmlspecialchars(sf_term('filter_clear_all', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="18" y1="6" x2="6" y2="18"></line>
                <line x1="6" y1="6" x2="18" y2="18"></line>
            </svg>
        </button>
        
        <!-- Reset button -->
        <button type="button" class="sf-filter-reset-btn<?= !$hasExplicitFilters ? ' hidden' : '' ?>" id="sf-reset-all-btn" title="<?= htmlspecialchars(sf_term('filter_reset', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M3 12a9 9 0 1 0 9-9 9.75 9.75 0 0 0-6.74 2.74L3 8"/>
                <path d="M3 3v5h5"/>
            </svg>
            <span><?= htmlspecialchars(sf_term('filter_reset', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
        </button>
    </div>

    <!-- DESKTOP VIEW TOGGLE (inline buttons for desktop) -->
    <div class="sf-view-toggle" id="sfViewToggle">
        <button type="button" data-view="grid" title="<?= htmlspecialchars(sf_term('view_grid', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars(sf_term('view_grid', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="7" height="7"></rect>
                <rect x="14" y="3" width="7" height="7"></rect>
                <rect x="3" y="14" width="7" height="7"></rect>
                <rect x="14" y="14" width="7" height="7"></rect>
            </svg>
        </button>
        <button type="button" data-view="list" title="<?= htmlspecialchars(sf_term('view_list', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars(sf_term('view_list', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="18" height="6" rx="1"></rect>
                <rect x="3" y="11" width="18" height="6" rx="1"></rect>
            </svg>
        </button>
        <button type="button" data-view="compact" title="<?= htmlspecialchars(sf_term('view_compact', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars(sf_term('view_compact', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="3" y1="6" x2="21" y2="6"></line>
                <line x1="3" y1="12" x2="21" y2="12"></line>
                <line x1="3" y1="18" x2="21" y2="18"></line>
            </svg>
        </button>
    </div>


</div>

<!-- FILTER CHIPS -->
<div class="sf-filter-chips">
    <button type="button" class="sf-chip<?= $type !== '' ? ' active' : '' ?>" data-filter="type" data-value="<?= htmlspecialchars($type) ?>">
        <span class="chip-label">
            <?php if ($type === 'red'): ?>
                <?= htmlspecialchars(sf_term('first_release', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            <?php elseif ($type === 'yellow'): ?>
                <?= htmlspecialchars(sf_term('dangerous_situation', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            <?php elseif ($type === 'green'): ?>
                <?= htmlspecialchars(sf_term('investigation_report', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            <?php else: ?>
                <?= htmlspecialchars(sf_term('filter_chip_type_all', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            <?php endif; ?>
        </span>
        <svg class="chip-arrow" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="6,9 12,15 18,9"/>
        </svg>
    </button>

    <button type="button" class="sf-chip<?= $originalType !== '' ? ' active' : '' ?>" data-filter="original_type" data-value="<?= htmlspecialchars($originalType) ?>">
        <span class="chip-label">
            <?php if ($originalType === 'red'): ?>
                <?= htmlspecialchars(sf_term('first_release', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            <?php elseif ($originalType === 'yellow'): ?>
                <?= htmlspecialchars(sf_term('dangerous_situation', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            <?php else: ?>
                <?= htmlspecialchars(sf_term('filter_chip_original_type_all', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            <?php endif; ?>
        </span>
        <svg class="chip-arrow" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="6,9 12,15 18,9"/>
        </svg>
    </button>

    <button type="button" class="sf-chip<?= $state !== '' ? ' active' : '' ?>" data-filter="state" data-value="<?= htmlspecialchars($state) ?>">
        <span class="chip-label">
            <?php if ($state !== ''): ?>
                <?= htmlspecialchars(sf_status_label($state, $currentUiLang)) ?>
            <?php else: ?>
                <?= htmlspecialchars(sf_term('filter_chip_state_all', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            <?php endif; ?>
        </span>
        <svg class="chip-arrow" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="6,9 12,15 18,9"/>
        </svg>
    </button>

    <button type="button" class="sf-chip<?= (!$siteIsDefault && $site !== '') ? ' active' : '' ?>" data-filter="site" data-value="<?= htmlspecialchars($site) ?>">
        <span class="chip-label">
            <?php if (!$siteIsDefault && $site !== ''): ?>
                <?= htmlspecialchars($site) ?>
            <?php else: ?>
                <?= htmlspecialchars(sf_term('filter_chip_site_all', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            <?php endif; ?>
        </span>
        <svg class="chip-arrow" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="6,9 12,15 18,9"/>
        </svg>
    </button>

    <button type="button" class="sf-chip<?= ($from !== '' || $to !== '') ? ' active' : '' ?>" data-filter="date" data-from="<?= htmlspecialchars($from) ?>" data-to="<?= htmlspecialchars($to) ?>">
        <span class="chip-label">
            <?php if ($from !== '' || $to !== ''): ?>
                <?php 
                if ($from !== '' && $to !== '') {
                    $fromFormatted = date('d.m.', strtotime($from));
                    $toFormatted = date('d.m.Y', strtotime($to));
                    echo htmlspecialchars($fromFormatted . ' - ' . $toFormatted);
                } elseif ($from !== '') {
                    echo htmlspecialchars(date('d.m.Y', strtotime($from)) . ' →');
                } else {
                    echo htmlspecialchars('→ ' . date('d.m.Y', strtotime($to)));
                }
                ?>
            <?php else: ?>
                <?= htmlspecialchars(sf_term('filter_chip_date', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            <?php endif; ?>
        </span>
        <svg class="chip-arrow" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="6,9 12,15 18,9"/>
        </svg>
    </button>

    <!-- Separator -->
    <span class="sf-chip-separator">|</span>

    <!-- Sort chip -->
    <button type="button" class="sf-chip sf-chip-sort" data-filter="sort" data-sort="<?= htmlspecialchars($sortField) ?>" data-order="<?= htmlspecialchars($sortOrder) ?>">
        <span class="chip-icon"><?= $sortOrder === 'desc' ? '↓' : '↑' ?></span>
        <span class="chip-label">
            <?php 
            $sortLabel = '';
            switch ($sortField) {
                case 'occurred':
                    $sortLabel = sf_term('sort_occurred', $currentUiLang);
                    break;
                case 'updated':
                    $sortLabel = sf_term('sort_updated', $currentUiLang);
                    break;
                case 'created':
                default:
                    $sortLabel = sf_term('sort_created', $currentUiLang);
                    break;
            }
            echo htmlspecialchars($sortLabel, ENT_QUOTES, 'UTF-8');
            ?>
        </span>
        <svg class="chip-arrow" width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="6,9 12,15 18,9"/>
        </svg>
    </button>
</div>

<!-- HIDDEN FILTER FIELDS (for JavaScript access) -->
<div style="display: none;">
    <select id="f-type" name="type">
        <option value="">
            <?= htmlspecialchars(sf_term('filter_all', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </option>
        <option value="red" <?= $type === 'red' ? 'selected' : '' ?>>
            <?= htmlspecialchars(sf_term('first_release', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </option>
        <option value="yellow" <?= $type === 'yellow' ? 'selected' : '' ?>>
            <?= htmlspecialchars(sf_term('dangerous_situation', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </option>
        <option value="green" <?= $type === 'green' ? 'selected' : '' ?>>
            <?= htmlspecialchars(sf_term('investigation_report', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </option>
    </select>

    <select id="f-original-type" name="original_type">
        <option value="">
            <?= htmlspecialchars(sf_term('filter_all', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </option>
        <option value="red" <?= $originalType === 'red' ? 'selected' : '' ?>>
            <?= htmlspecialchars(sf_term('first_release', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </option>
        <option value="yellow" <?= $originalType === 'yellow' ? 'selected' : '' ?>>
            <?= htmlspecialchars(sf_term('dangerous_situation', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </option>
    </select>

    <select id="f-state" name="state">
        <option value="">
            <?= htmlspecialchars(sf_term('filter_all', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </option>
        <option value="draft" <?= $state==='draft' ? 'selected' : '' ?>>
            <?= htmlspecialchars(sf_status_label('draft', $currentUiLang)) ?>
        </option>
        <option value="pending_supervisor" <?= $state==='pending_supervisor' ? 'selected' : '' ?>>
    <?= htmlspecialchars(sf_status_label('pending_supervisor', $currentUiLang)) ?>
</option>
        <option value="pending_review" <?= $state==='pending_review' ? 'selected' : '' ?>>
            <?= htmlspecialchars(sf_status_label('pending_review', $currentUiLang)) ?>
        </option>
        <option value="to_comms" <?= $state==='to_comms' ? 'selected' : '' ?>>
            <?= htmlspecialchars(sf_status_label('to_comms', $currentUiLang)) ?>
        </option>
        <option value="awaiting_publish" <?= $state==='awaiting_publish' ? 'selected' : '' ?>>
            <?= htmlspecialchars(sf_status_label('awaiting_publish', $currentUiLang)) ?>
        </option>
        <option value="published" <?= $state==='published' ? 'selected' : '' ?>>
            <?= htmlspecialchars(sf_status_label('published', $currentUiLang)) ?>
        </option>
    </select>

    <select id="f-site" name="site">
        <option value="">
            <?= htmlspecialchars(sf_term('filter_all', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </option>
        <?php foreach ($sites as $s): ?>
            <option value="<?= htmlspecialchars($s) ?>"
                <?= ($site !== '' ? $site : $autoSite) === $s ? 'selected' : '' ?>>
                <?= htmlspecialchars($s) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <input
        id="f-q"
        type="text"
        name="q"
        value="<?= htmlspecialchars($q) ?>"
    >

    <input id="f-from" type="date" name="date_from" value="<?= htmlspecialchars($from) ?>">

    <input id="f-to" type="date" name="date_to" value="<?= htmlspecialchars($to) ?>">

    <select id="f-archived" name="archived">
        <option value="" <?= $archived === '' ? 'selected' : '' ?>>
            <?= htmlspecialchars(sf_term('filter_show_active', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </option>
        <option value="only" <?= $archived === 'only' ? 'selected' : '' ?>>
            <?= htmlspecialchars(sf_term('filter_archived', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </option>
        <option value="all" <?= $archived === 'all' ? 'selected' : '' ?>>
            <?= htmlspecialchars(sf_term('filter_all', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </option>
    </select>
</div>

<!-- FILTER BAR -->
<form method="get" class="filters">
    <input type="hidden" name="page" value="list">

    <!-- Mobiili: Toggle-nappi suodattimille -->
    <button type="button" class="filters-toggle" id="filtersToggle">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <!-- polygon korjattu -->
            <polygon points="22,3 2,3 10,12.46 10,19 14,21 14,12.46"/>
        </svg>

        <span><?= htmlspecialchars(sf_term('list_filters', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>

        <svg class="toggle-arrow" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="6,9 12,15 18,9"/>
        </svg>
    </button>

    <div class="filters-grid" id="filtersGrid" role="search" aria-label="<?= htmlspecialchars(sf_term('list_filters', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
            <div class="filter-item">
                <label>
                    <?= htmlspecialchars(sf_term('filter_type', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <select name="type">
                    <option value="">
                        <?= htmlspecialchars(sf_term('filter_all', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                    <option value="red" <?= $type === 'red' ? 'selected' : '' ?>>
                        <?= htmlspecialchars(sf_term('first_release', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                    <option value="yellow" <?= $type === 'yellow' ? 'selected' : '' ?>>
                        <?= htmlspecialchars(sf_term('dangerous_situation', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                    <option value="green" <?= $type === 'green' ? 'selected' : '' ?>>
                        <?= htmlspecialchars(sf_term('investigation_report', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                </select>
            </div>

            <div class="filter-item">
                <label>
                    <?= htmlspecialchars(sf_term('filter_original_type', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <select name="original_type">
                    <option value="">
                        <?= htmlspecialchars(sf_term('filter_all', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                    <option value="red" <?= $originalType === 'red' ? 'selected' : '' ?>>
                        <?= htmlspecialchars(sf_term('first_release', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                    <option value="yellow" <?= $originalType === 'yellow' ? 'selected' : '' ?>>
                        <?= htmlspecialchars(sf_term('dangerous_situation', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                </select>
            </div>

            <div class="filter-item filter-item-checkbox">
                <label class="filter-checkbox-label">
                    <input id="f-only-originals" type="checkbox" name="only_originals" value="1" <?= $onlyOriginals ? 'checked' : '' ?>>
                    <?= htmlspecialchars(sf_term('filter_only_originals', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </label>
            </div>

            <div class="filter-item">
                <label>
                    <?= htmlspecialchars(sf_term('filter_state', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <select name="state">
                    <option value="">
                        <?= htmlspecialchars(sf_term('filter_all', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                    <option value="draft" <?= $state==='draft' ? 'selected' : '' ?>>
                        <?= htmlspecialchars(sf_status_label('draft', $currentUiLang)) ?>
                    </option>
                    <option value="pending_review" <?= $state==='pending_review' ? 'selected' : '' ?>>
                        <?= htmlspecialchars(sf_status_label('pending_review', $currentUiLang)) ?>
                    </option>
                    <option value="reviewed" <?= $state==='reviewed' ? 'selected' : '' ?>>
                        <?= htmlspecialchars(sf_status_label('reviewed', $currentUiLang)) ?>
                    </option>

                    <option value="to_comms" <?= $state==='to_comms' ? 'selected' : '' ?>>
                        <?= htmlspecialchars(sf_status_label('to_comms', $currentUiLang)) ?>
                    </option>
                    <option value="published" <?= $state==='published' ? 'selected' : '' ?>>
                        <?= htmlspecialchars(sf_status_label('published', $currentUiLang)) ?>
                    </option>
                </select>
            </div>

            <div class="filter-item">
                <label>
                    <?= htmlspecialchars(sf_term('filter_site', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <select name="site">
                    <option value="">
                        <?= htmlspecialchars(sf_term('filter_all', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                    <?php foreach ($sites as $s): ?>
                        <option value="<?= htmlspecialchars($s) ?>"
                            <?= ($site !== '' ? $site : $autoSite) === $s ? 'selected' : '' ?>>
                            <?= htmlspecialchars($s) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="filter-item filter-search">
                <label>
                    <?= htmlspecialchars(sf_term('filter_search_label', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <div class="search-row">
                    <input
                        type="text"
                        name="q"
                        placeholder="<?= htmlspecialchars(sf_term('filter_search_placeholder', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                        value="<?= htmlspecialchars($q) ?>"
                        aria-label="<?= htmlspecialchars(sf_term('filter_search_label', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>"
                    >
                    <button class="btn-icon" type="submit">
                        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <circle cx="11" cy="11" r="7"/><line x1="21" y1="21" x2="16.65" y2="16.65"/>
                        </svg>
                    </button>
                </div>
            </div>

            <div class="filter-item">
                <label>
                    <?= htmlspecialchars(sf_term('filter_date_from', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <input type="date" name="date_from" value="<?= htmlspecialchars($from) ?>">
            </div>

            <div class="filter-item">
                <label>
                    <?= htmlspecialchars(sf_term('filter_date_to', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <input type="date" name="date_to" value="<?= htmlspecialchars($to) ?>">
            </div>

            <div class="filter-item">
                <label>
                    <?= htmlspecialchars(sf_term('filter_archived', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </label>
                <select name="archived">
                    <option value="" <?= $archived === '' ? 'selected' : '' ?>>
                        <?= htmlspecialchars(sf_term('filter_show_active', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                    <option value="only" <?= $archived === 'only' ? 'selected' : '' ?>>
                        <?= htmlspecialchars(sf_term('filter_archived', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                    <option value="all" <?= $archived === 'all' ? 'selected' : '' ?>>
                        <?= htmlspecialchars(sf_term('filter_all', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                    </option>
                </select>
            </div>

            <div class="filter-actions">
                <button class="btn-primary" type="submit" id="filter-submit-btn">
                    <?= htmlspecialchars(sf_term('filter_apply', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </button>
                <a class="btn-secondary" href="<?= $baseUrl ?>/index.php?page=list" id="filter-clear-btn">
                    <?= htmlspecialchars(sf_term('filter_clear', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
                </a>
            </div>
        </div>
    </form>

    <!-- LIST -->
<!-- ADMIN: MONIVALINTAPOISTO -->
<?php if ($isAdmin): ?>
<div class="sf-bulk-actions" id="sfBulkActions" hidden>
    <div class="sf-bulk-bar">
        <label class="sf-bulk-select-all">
            <input type="checkbox" id="sfSelectAll">
            <span><?= htmlspecialchars(sf_term('select_all', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>
        </label>

        <span class="sf-bulk-count" id="sfBulkCount"><?= htmlspecialchars(sf_term('selected_count_zero', $uiLang), ENT_QUOTES, 'UTF-8') ?></span>

        <button type="button" class="sf-btn sf-btn-danger" id="sfBulkDelete" disabled>
            <img src="<?= $baseUrl ?>/assets/img/icons/delete_icon.svg" alt="" class="btn-icon-img">
            <?= htmlspecialchars(sf_term('btn_delete_selected', $uiLang), ENT_QUOTES, 'UTF-8') ?>
        </button>
    </div>
</div>
<?php endif; ?>

<!-- LIST -->
<div class="sf-list-container view-list">
<div class="skeleton-wrapper">
    <!-- Skeleton loading cards (shown while data is loading, overlays content) -->
    <div class="skeleton-container" id="skeletonContainer">
        <?php for ($i = 0; $i < 5; $i++): ?>
        <div class="skeleton-card">
            <div class="skeleton skeleton-thumb"></div>
            <div class="card-mid">
                <div class="skeleton skeleton-badge"></div>
                <div class="skeleton skeleton-title"></div>
                <div class="skeleton skeleton-text"></div>
                <div class="skeleton skeleton-meta"></div>
            </div>
        </div>
        <?php endfor; ?>
    </div>

    <!-- Actual content (underneath skeleton until loaded) -->
    <div class="cards-container">
    <div class="card-list">

<?php 
// Tyypin avaimet käännöksiä varten
$typeKeyMap = [
    'red'    => 'first_release',
    'yellow' => 'dangerous_situation',
    'green'  => 'investigation_report',
];

// Fetch all translations at once to avoid N+1 queries
$allGroupIds = [];
foreach ($rows as $r) {
    $gid = !empty($r['translation_group_id']) ? (int)$r['translation_group_id'] : (int)$r['id'];
    $allGroupIds[$gid] = $gid; // Use as key to avoid duplicates
}
$allTranslations = sf_get_all_translations($pdo, array_values($allGroupIds));

// Batch-fetch body parts for all displayed flashes to avoid N+1 queries
$allBodyParts = [];
if (!empty($rows)) {
    $flashIds = array_map(fn($r) => (int)$r['id'], $rows);
    $bpPlaceholders = implode(',', array_fill(0, count($flashIds), '?'));
    try {
        $bpBatchStmt = $pdo->prepare(
            "SELECT ibp.incident_id, bp.svg_id, bp.name
             FROM incident_body_part ibp
             JOIN body_parts bp ON bp.id = ibp.body_part_id
             WHERE ibp.incident_id IN ({$bpPlaceholders})
             ORDER BY bp.sort_order ASC"
        );
        $bpBatchStmt->execute(array_map('intval', $flashIds));
        foreach ($bpBatchStmt->fetchAll(PDO::FETCH_ASSOC) as $bpRow) {
            $allBodyParts[(int)$bpRow['incident_id']][] = $bpRow;
        }
    } catch (Throwable $e) {
        error_log('list.php: Error fetching body parts: ' . $e->getMessage());
    }
}

// PROSESSOINNISSA OLEVAT KORTIT - POISTETTU
// Käytetään vain pollausta taustalla, ei näytetä kortteja listassa
?>

<?php if (empty($rows) && empty($processingFlashes)): ?>
    <div class="no-results-box">
        <div class="no-results-icon-wrap">
            <svg class="no-results-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                <circle cx="11" cy="11" r="8"/>
                <line x1="21" y1="21" x2="16.65" y2="16.65"/>
                <line x1="8" y1="11" x2="14" y2="11"/>
            </svg>
        </div>

        <p class="no-results-text">
            <?= htmlspecialchars(sf_term('no_results', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </p>

        <p class="no-results-hint">
            <?= htmlspecialchars(sf_term('no_results_hint', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </p>

        <a href="<?= $baseUrl ?>/index.php?page=form" class="no-results-cta">
            <img src="<?= $baseUrl ?>/assets/img/icons/add_new_icon.png"
                 alt=""
                 class="no-results-cta-icon"
                 aria-hidden="true">
            <span><?= htmlspecialchars(sf_term('nav_new_safetyflash', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
        </a>
    </div>
<?php endif; ?>

<?php foreach ($rows as $r):
    $badgeClass = typeBadgeClass($r['type']);

    $typeKey   = $typeKeyMap[$r['type']] ?? null;
    $typeLabel = $typeKey ? sf_term($typeKey, $currentUiLang) : 'Safetyflash';

    $stateText = sf_status_label($r['state'], $currentUiLang);
    $stateDef = function_exists('sf_status_get') ? sf_status_get((string)($r['state'] ?? '')) : null;
    $stateClass = trim((string)($stateDef['badge_class'] ?? 'sf-status--other'));

    // Check preview status for generating indicator
    $previewStatus = $r['preview_status'] ?? 'completed';
    $isGeneratingPreview = ($previewStatus === 'pending' || $previewStatus === 'processing');

    // Cache-buster listan thumbnaileille, jotta muokattu preview päivittyy varmasti
    $sfListCacheBust = static function (string $url, ?string $absPath): string {
        if (!empty($absPath) && is_file($absPath)) {
            $version = (string) filemtime($absPath);
            return $url . (strpos($url, '?') === false ? '?' : '&') . 'v=' . rawurlencode($version);
        }
        return $url;
    };

    $thumb = "$baseUrl/assets/img/camera-placeholder.png";
    $thumbAbsPath = null;

    if (!empty($r['preview_filename'])) {
        $previewFilename = basename((string) $r['preview_filename']);
        $previewAbsPathCandidate = __DIR__ . '/../../uploads/previews/' . $previewFilename;

        if (is_file($previewAbsPathCandidate)) {
            $thumb = "$baseUrl/uploads/previews/" . $previewFilename;
            $thumbAbsPath = $previewAbsPathCandidate;
        }
    } elseif (!empty($r['display_snapshot_preview'])) {
        $snapshotFilename = basename((string) $r['display_snapshot_preview']);
        $snapshotAbsPathCandidate = __DIR__ . '/../../uploads/previews/' . $snapshotFilename;

        if (is_file($snapshotAbsPathCandidate)) {
            $thumb = "$baseUrl/uploads/previews/" . $snapshotFilename;
            $thumbAbsPath = $snapshotAbsPathCandidate;
        }
    }

    $thumb = $sfListCacheBust($thumb, $thumbAbsPath);

    $title = trim((string)($r['title'] ?? ''));
    if ($title === '') {
        $title = trim((string)($r['title_short'] ?? ''));
    }
    if ($title === '') {
        $title = trim((string)($r['summary'] ?? '')) ?: sf_term('no_title', $uiLang);
    }

    $siteText    = $r['site'] . (!empty($r['site_detail']) ? " – " . $r['site_detail'] : "");
    $groupId     = !empty($r['translation_group_id']) ? (int)$r['translation_group_id'] : (int)$r['id'];
    $translations = $allTranslations[$groupId] ?? [];
    $baseLang    = $r['lang'] ?: 'fi';
    
    // Tarkista onko tämä kortti prosessoinnissa TAI juuri luotu (bg_process parametri)
    $isProcessing = false;

    // Tapa 1: Tarkista is_processing tietokannasta
    foreach ($processingFlashes as $proc) {
        if ((int)$proc['id'] === (int)$r['id']) {
            $isProcessing = true;
            break;
        }
    }

    // Tapa 2: Tarkista bg_process URL-parametri (juuri luotu flash)
    // Tämä yliajaa tietokantatilanteen jos kortti on bg_process parametrissa
    if ($bgProcessId > 0 && (int)$r['id'] === $bgProcessId) {
        $isProcessing = true;
    }

    // Lisää piilotusluokka jos prosessoinnissa
    $hiddenClass = $isProcessing ? ' sf-card-hidden-processing' : '';

    // EI animaatiota sivun latauksessa - animaatio tulee vasta kun polling paljastaa kortin
    $newCardClass = '';
    
    // Data-attribuutit suodatusta varten
    // Tapahtumapäivämäärä suodatusta varten (occurred_at, fallback to created_at)
    $cardDate = '';
    if (!empty($r['occurred_at'])) {
        $timestamp = strtotime($r['occurred_at']);
        if ($timestamp !== false) {
            $cardDate = date('Y-m-d', $timestamp);  // YYYY-MM-DD muoto
        }
    }
    
    // Jos occurred_at on tyhjä, käytä created_at fallbackina
    if (empty($cardDate) && !empty($r['created_at'])) {
        $timestamp = strtotime($r['created_at']);
        if ($timestamp !== false) {
            $cardDate = date('Y-m-d', $timestamp);
        }
    }
    
    $cardArchived = !empty($r['is_archived']) && (int)$r['is_archived'] === 1 ? '1' : '0';
    
    // Compact date format for mobile view (d.m.Y)
    $compactDate = !empty($r['occurred_at']) ? date('d.m.Y', strtotime($r['occurred_at'])) : '';
?>
<div class="card type-<?= htmlspecialchars($r['type'] ?? '') ?><?= $hiddenClass ?><?= $newCardClass ?>" 
     data-flash-id="<?= (int)$r['id'] ?>"
     data-preview-status="<?= htmlspecialchars($previewStatus) ?>"
     data-type="<?= htmlspecialchars($r['type'] ?? '') ?>"
     data-state="<?= htmlspecialchars($r['state'] ?? '') ?>"
     data-site="<?= htmlspecialchars($r['site'] ?? '') ?>"
     data-title="<?= htmlspecialchars($title) ?>"
     data-date="<?= htmlspecialchars($cardDate) ?>"
     data-archived="<?= htmlspecialchars($cardArchived) ?>"
     data-created="<?= htmlspecialchars($r['created_at'] ?? '') ?>"
     data-occurred="<?= htmlspecialchars($r['occurred_at'] ?? '') ?>"
     data-updated="<?= htmlspecialchars($r['updated_at'] ?? '') ?>">

<div class="card-thumb-wrapper">
    <a href="<?= $baseUrl ?>/index.php?page=view&id=<?= (int)$r['id'] ?>" class="card-thumb">
        <?php if ($isGeneratingPreview): ?>
            <div class="sf-generating-overlay sf-generating-spinner-only">
                <div class="sf-generating-spinner"></div>
            </div>
        <?php endif; ?>
        <img src="<?= htmlspecialchars($thumb) ?>" 
             alt="thumb"
             loading="lazy"
             decoding="async"
             class="sf-card-image <?= $isGeneratingPreview ? 'sf-preview-pending' : '' ?>">
    </a>

    <!-- Checkbox #1: GRID-näkymää varten (thumbnailin sisällä) -->
    <?php if ($isAdmin): ?>
        <label class="card-checkbox card-checkbox-thumb" onclick="event.stopPropagation();">
            <input
                type="checkbox"
                class="sf-flash-checkbox"
                value="<?= (int)$r['id'] ?>"
                onclick="event.stopPropagation();"
            >
        </label>
    <?php endif; ?>

    <?php if (!empty($r['new_comment_count']) && (int)$r['new_comment_count'] > 0): ?>
        <span class="comment-badge"
              title="<?= (int)$r['new_comment_count'] ?> <?= htmlspecialchars(sf_term('new_comments', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
            <svg class="comment-badge-icon" viewBox="0 0 100 100" fill="currentColor">
                <path d="M100 10.495v67.2c0 2.212-1.793 4.005-4.005 4.005H68.53c-1.063 0-2.082.422-2.833 1.174L51.412 97.167c-1.564 1.565-4.1 1.565-5.665 0L31.453 82.874c-.751-.751-1.77-1.173-2.833-1.173H4.005C1.793 81.7 0 79.907 0 77.695v-67.2C0 8.283 1.793 6.49 4.005 6.49h91.99C98.207 6.49 100 8.283 100 10.495z"/>
            </svg>
            <span class="comment-badge-count"><?= (int)$r['new_comment_count'] ?></span>
        </span>
    <?php endif; ?>
</div>

<!-- Checkbox #2: LIST/COMPACT-näkymää varten (kortin päätasolla) -->
<?php if ($isAdmin): ?>
    <label class="card-checkbox card-checkbox-main" onclick="event.stopPropagation();">
        <input
            type="checkbox"
            class="sf-flash-checkbox"
            value="<?= (int)$r['id'] ?>"
            onclick="event.stopPropagation();"
        >
    </label>
<?php endif; ?>

    <!-- Type indicator for compact view -->
    <div class="type-indicator <?= htmlspecialchars($r['type'] ?? 'default') ?>"></div>

    <div class="card-mid">
        <div class="card-top">
            <div class="left">
                <span class="badge <?= htmlspecialchars($badgeClass) ?>">
                    <?= htmlspecialchars($typeLabel) ?>
                </span>
                <span class="status <?= htmlspecialchars($stateClass) ?>">
                    <?= htmlspecialchars($stateText) ?>
                </span>
                <?php if (!empty($r['is_archived']) && (int)$r['is_archived'] === 1): ?>
                    <span class="badge badge-archived">
                        <?= htmlspecialchars(sf_term('archived', $currentUiLang) ?: 'ARKISTOITU') ?>
                    </span>
                <?php endif; ?>
                <?php 
                // Show language badge if this is a fallback (not user's preferred language)
                $flashLang = $r['lang'] ?? DEFAULT_LANG;
                $isPreferredLang = ($flashLang === $userLang);
                if (!$isPreferredLang): 
                    // Multi-level fallback for notice text: 
                    // 1) User's UI language → 2) Default language (fi) → 3) English hardcoded
                    $fallbackNoticeText = sf_term('lang_fallback_notice', $currentUiLang) ?? sf_term('lang_fallback_notice', DEFAULT_LANG) ?? 'No translation available';
                ?>
                    <span class="sf-lang-badge sf-lang-fallback" title="<?= htmlspecialchars($fallbackNoticeText) ?>">
                        <?= $langLabels[$flashLang] ?? strtoupper($flashLang) ?>
                    </span>
                <?php endif; ?>

                <?php if (!empty($r['new_comment_count']) && (int)$r['new_comment_count'] > 0): ?>
                    <span class="comment-badge-mobile"
                          title="<?= (int)$r['new_comment_count'] ?> <?= htmlspecialchars(sf_term('new_comments', $uiLang), ENT_QUOTES, 'UTF-8') ?>">
                        <svg class="comment-badge-mobile-icon" viewBox="0 0 100 100" fill="currentColor" aria-hidden="true">
                            <path d="M100 10.495v67.2c0 2.212-1.793 4.005-4.005 4.005H68.53c-1.063 0-2.082.422-2.833 1.174L51.412 97.167c-1.564 1.565-4.1 1.565-5.665 0L31.453 82.874c-.751-.751-1.77-1.173-2.833-1.173H4.005C1.793 81.7 0 79.907 0 77.695v-67.2C0 8.283 1.793 6.49 4.005 6.49h91.99C98.207 6.49 100 8.283 100 10.495z"/>
                        </svg>
                        <span class="comment-badge-mobile-count"><?= (int)$r['new_comment_count'] ?></span>
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <div class="card-title"><?= htmlspecialchars($title) ?></div>
        <div class="card-site"><?= htmlspecialchars($siteText) ?></div>
        
        <!-- Compact meta: worksite + date inline for mobile compact view -->
        <div class="card-compact-meta">
            <span class="compact-site"><?= htmlspecialchars($siteText) ?></span>
            <span class="meta-separator"> · </span>
            <span class="compact-date"><?= htmlspecialchars($compactDate) ?></span>
        </div>

        <div class="card-meta">
            <span class="card-date">
                <svg class="meta-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                     stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                    <line x1="16" y1="2" x2="16" y2="6"></line>
                    <line x1="8" y1="2" x2="8" y2="6"></line>
                    <line x1="3" y1="10" x2="21" y2="10"></line>
                </svg>
                <?= htmlspecialchars($r['occurredFmt'] ?? '') ?>
            </span>

            <span class="card-updated">
                <?= htmlspecialchars(sf_term('card_modified', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>:
                <?= htmlspecialchars($r['updatedFmt'] ?? '') ?>
            </span>

            <?php
            // Show original type badge only when it differs from current type (i.e. the report has been converted)
            $origType = $r['original_type'] ?? null;
            if (!empty($origType) && $origType !== $r['type']):
                $origTypeKey   = $typeKeyMap[$origType] ?? null;
                $origTypeLabel = $origTypeKey ? sf_term($origTypeKey, $currentUiLang) : null;
                if ($origTypeLabel):
            ?>
                <span class="card-original-type">
                    <span class="card-original-type-label"><?= htmlspecialchars(sf_term('settings_original_type_label', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>:</span>
                    <span class="badge badge-original-type badge-original-<?= htmlspecialchars($origType) ?>"><?= htmlspecialchars($origTypeLabel) ?></span>
                </span>
            <?php endif; endif; ?>
         </div>

         <?php
         // Body parts – show as small pills when any are recorded for this flash
         $flashBodyParts = $allBodyParts[(int)$r['id']] ?? [];
         if (!empty($flashBodyParts)):
         ?>
         <div class="card-body-parts">
             <svg class="card-body-parts-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                 <path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"></path>
                 <circle cx="9" cy="7" r="4"></circle>
                 <line x1="23" y1="11" x2="17" y2="11"></line>
             </svg>
             <?php foreach ($flashBodyParts as $bp):
                 $bpTermKey = str_replace('-', '_', $bp['svg_id']);
                 $bpLabel   = sf_term($bpTermKey, $currentUiLang);
                 if ($bpLabel === $bpTermKey) {
                     $bpLabel = $bp['name']; // fallback to DB name if term missing
                 }
             ?>
             <span class="card-body-part-tag" title="<?= htmlspecialchars($bpLabel) ?>">
                 <?= htmlspecialchars($bpLabel) ?>
             </span>
             <?php endforeach; ?>
         </div>
         <?php endif; ?>
         
         <!-- Editing indicator placeholder (populated by JavaScript) -->
         <div class="sf-editing-indicator" data-flash-id="<?= (int)$r['id'] ?>">
             <div class="sf-editing-spinner"></div>
             <span class="sf-editing-text"></span>
         </div>
    </div>

    <div class="card-actions">
        <a href="<?= $baseUrl ?>/index.php?page=view&id=<?= (int)$r['id'] ?>" class="open-btn">
            <?= htmlspecialchars(sf_term('card_open', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        </a>

        <div class="card-lang-actions">
            <?php if (isset($supportedLangs[$baseLang])): ?>
               <a href="<?= $baseUrl ?>/index.php?page=view&id=<?= (int)$r['id'] ?>" class="lang-flag-link">
                    <img class="list-lang-flag"
                         src="<?= $baseUrl ?>/assets/img/<?= $supportedLangs[$baseLang]['icon'] ?>"
                         alt="<?= htmlspecialchars($supportedLangs[$baseLang]['label']) ?>">
                </a>
            <?php endif; ?>

            <?php foreach ($supportedLangs as $langCode => $langData): ?>
                <?php if ($langCode !== $baseLang && isset($translations[$langCode])): ?>
                    <a href="<?= $baseUrl ?>/index.php?page=view&id=<?= (int)$translations[$langCode] ?>" class="lang-flag-link">
                        <img class="list-lang-flag"
                             src="<?= $baseUrl ?>/assets/img/<?= $langData['icon'] ?>"
                             alt="<?= htmlspecialchars($langData['label']) ?>">
                    </a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        
        <!-- Compact mobile status indicator -->
        <div class="sf-compact-status <?= htmlspecialchars($stateClass) ?>">
            <?php if ($r['state'] === 'published'): ?>
                <img src="<?= $baseUrl ?>/assets/img/icons/publish.svg" 
                     alt="Julkaistu" 
                     class="sf-compact-status-check">
            <?php else: ?>
                <div class="sf-compact-status-spinner"></div>
            <?php endif; ?>
        </div>
        
        <!-- Chevron for navigation -->
        <svg class="sf-compact-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M9 18l6-6-6-6"/>
        </svg>
    </div>
</div>
<?php endforeach; ?>    </div> <!-- .card-list -->
</div> <!-- .cards-container -->
</div> <!-- .skeleton-wrapper -->
</div> <!-- .sf-list-container -->

<?php if ($totalPages > 1): ?>
<?php
// Build base query params for pagination links (preserve all active filters)
$paginationBase = $_GET;
unset($paginationBase['p']);

function sf_pagination_url(array $base, int $page): string {
    $p = $base;
    if ($page > 1) {
        $p['p'] = $page;
    }
    return '?' . http_build_query($p);
}

// Determine page window (show at most 5 page numbers around current page)
$windowSize  = 2; // pages on each side of current
$pageStart   = max(1, $currentPage - $windowSize);
$pageEnd     = min($totalPages, $currentPage + $windowSize);
?>
<nav class="sf-pagination" aria-label="<?= htmlspecialchars(sf_term('pagination_page', $currentUiLang) . ' ' . $currentPage . ' ' . sf_term('pagination_of', $currentUiLang) . ' ' . $totalPages, ENT_QUOTES, 'UTF-8') ?>">
    <?php if ($currentPage > 1): ?>
        <a href="<?= htmlspecialchars(sf_pagination_url($paginationBase, $currentPage - 1), ENT_QUOTES, 'UTF-8') ?>"
           class="sf-page-btn sf-page-prev"
           aria-label="<?= htmlspecialchars(sf_term('pagination_prev', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
            <span><?= htmlspecialchars(sf_term('pagination_prev', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
        </a>
    <?php else: ?>
        <span class="sf-page-btn sf-page-prev disabled" aria-disabled="true">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="15 18 9 12 15 6"/></svg>
            <span><?= htmlspecialchars(sf_term('pagination_prev', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
        </span>
    <?php endif; ?>

    <div class="sf-page-numbers">
        <?php if ($pageStart > 1): ?>
            <a href="<?= htmlspecialchars(sf_pagination_url($paginationBase, 1), ENT_QUOTES, 'UTF-8') ?>" class="sf-page-num">1</a>
            <?php if ($pageStart > 2): ?>
                <span class="sf-page-ellipsis">&hellip;</span>
            <?php endif; ?>
        <?php endif; ?>

        <?php for ($p = $pageStart; $p <= $pageEnd; $p++): ?>
            <?php if ($p === $currentPage): ?>
                <span class="sf-page-num active" aria-current="page"><?= $p ?></span>
            <?php else: ?>
                <a href="<?= htmlspecialchars(sf_pagination_url($paginationBase, $p), ENT_QUOTES, 'UTF-8') ?>" class="sf-page-num"><?= $p ?></a>
            <?php endif; ?>
        <?php endfor; ?>

        <?php if ($pageEnd < $totalPages): ?>
            <?php if ($pageEnd < $totalPages - 1): ?>
                <span class="sf-page-ellipsis">&hellip;</span>
            <?php endif; ?>
            <a href="<?= htmlspecialchars(sf_pagination_url($paginationBase, $totalPages), ENT_QUOTES, 'UTF-8') ?>" class="sf-page-num"><?= $totalPages ?></a>
        <?php endif; ?>
    </div>

    <span class="sf-page-info">
        <?= htmlspecialchars(sf_term('pagination_page', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        <?= $currentPage ?>
        <?= htmlspecialchars(sf_term('pagination_of', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
        <?= $totalPages ?>
    </span>

    <?php if ($currentPage < $totalPages): ?>
        <a href="<?= htmlspecialchars(sf_pagination_url($paginationBase, $currentPage + 1), ENT_QUOTES, 'UTF-8') ?>"
           class="sf-page-btn sf-page-next"
           aria-label="<?= htmlspecialchars(sf_term('pagination_next', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
            <span><?= htmlspecialchars(sf_term('pagination_next', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
        </a>
    <?php else: ?>
        <span class="sf-page-btn sf-page-next disabled" aria-disabled="true">
            <span><?= htmlspecialchars(sf_term('pagination_next', $currentUiLang), ENT_QUOTES, 'UTF-8') ?></span>
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><polyline points="9 18 15 12 9 6"/></svg>
        </span>
    <?php endif; ?>
</nav>
<?php endif; ?>

<?php if ($isAdmin): ?>
<!-- POISTOVAHVISTUS-MODAALI -->
<div class="sf-modal hidden" id="modalBulkDelete" role="dialog" aria-modal="true">
    <div class="sf-modal-content">
        <h2><?= htmlspecialchars(sf_term('confirm_delete_title', $uiLang), ENT_QUOTES, 'UTF-8') ?></h2>
        <p id="sfBulkDeleteText"><?= htmlspecialchars(sf_term('bulk_delete_confirm_text', $uiLang), ENT_QUOTES, 'UTF-8') ?></p>
        <div class="sf-bulk-delete-list" id="sfBulkDeleteList"></div>
        <div class="sf-modal-actions">
            <button type="button" class="sf-btn sf-btn-secondary" data-modal-close="modalBulkDelete"><?= htmlspecialchars(sf_term('btn_cancel', $uiLang), ENT_QUOTES, 'UTF-8') ?></button>
            <button type="button" class="sf-btn sf-btn-danger" id="sfConfirmBulkDelete"><?= htmlspecialchars(sf_term('btn_delete_permanently', $uiLang), ENT_QUOTES, 'UTF-8') ?></button>
        </div>
    </div>
</div>

<script>
// Näytä toast jos bulk-poisto onnistui
(function() {
    const urlParams = new URLSearchParams(window.location.search);
    const notice = urlParams.get('notice');
    const count = parseInt(urlParams.get('count'), 10) || 0;
    
    if (notice === 'bulk_deleted' && count > 0) {
        // Näytä toast kun sivu on ladattu
        window.addEventListener('DOMContentLoaded', function() {
            <?php
            // Hae käännökset PHP:ssa (ei ternary operaattorilla PHP-tageissa)
            $single_msg = sf_term('bulk_deleted_single', $uiLang);
            if (empty($single_msg)) {
                $single_msg = '1 SafetyFlash poistettu';
            }
            
            $plural_msg = sf_term('bulk_deleted_plural', $uiLang);
            if (empty($plural_msg)) {
                $plural_msg = 'SafetyFlashia poistettu';
            }
            ?>
            
            const message = count === 1 
                ? <?= json_encode($single_msg, JSON_UNESCAPED_UNICODE) ?>
                : count + ' ' + <?= json_encode($plural_msg, JSON_UNESCAPED_UNICODE) ?>;
            
            if (typeof window.sfToast === 'function') {
                window.sfToast('success', message);
            }
            
            // Poista notice-parametrit URL:sta (puhdistaa osoiterivin)
            const newUrl = new URL(window.location.href);
            newUrl.searchParams. delete('notice');
            newUrl.searchParams.delete('count');
            window.history.replaceState({}, '', newUrl.toString());
        });
    }
})();
</script><script>
(function() {
    const SF_I18N = {
        deleting: <?= json_encode(sf_term('deleting', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
        delete_error: <?= json_encode(sf_term('delete_error', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
        error_prefix: <?= json_encode(sf_term('error_prefix', $uiLang), JSON_UNESCAPED_UNICODE) ?>
    };
    const selectAll = document.getElementById('sfSelectAll');
    const bulkActions = document.getElementById('sfBulkActions');
    const bulkCount = document.getElementById('sfBulkCount');
    const bulkDeleteBtn = document.getElementById('sfBulkDelete');
    const bulkBar = document.querySelector('.sf-bulk-bar');
    const checkboxes = () => document.querySelectorAll('.sf-flash-checkbox');
    const modal = document.getElementById('modalBulkDelete');
    const confirmBtn = document.getElementById('sfConfirmBulkDelete');
    const deleteList = document.getElementById('sfBulkDeleteList');

    function updateCount() {
        const checked = document.querySelectorAll('.sf-flash-checkbox:checked');
        const count = checked.length;

        if (bulkCount) {
            bulkCount.textContent = count + ' ' + <?= json_encode(sf_term('bulk_selected_suffix', $uiLang), JSON_UNESCAPED_UNICODE) ?>;
        }

        if (bulkDeleteBtn) {
            bulkDeleteBtn.disabled = count === 0;
        }

        if (bulkActions) {
            bulkActions.hidden = count === 0;
        }

        if (bulkBar) {
            if (count > 0) {
                bulkBar.classList.add('has-selections');
            } else {
                bulkBar.classList.remove('has-selections');
            }
        }

        if (selectAll) {
            const all = checkboxes();
            selectAll.checked = count > 0 && count === all.length && all.length > 0;
        }
    }

    if (selectAll) {
        selectAll.addEventListener('change', function() {
            checkboxes().forEach(cb => {
                cb.checked = this.checked;
            });
            updateCount();
        });
    }

    document.addEventListener('change', function(e) {
        if (e.target.classList.contains('sf-flash-checkbox')) {
            updateCount();
        }
    });

    updateCount();

    if (bulkDeleteBtn) {
        bulkDeleteBtn.addEventListener('click', function() {
            const checked = document.querySelectorAll('.sf-flash-checkbox:checked');
            if (checked.length === 0) return;

            let html = '<ul>';
            checked.forEach(cb => {
                const card = cb.closest('.card');
                const title = card?.querySelector('.card-title')?.textContent || 'ID: ' + cb.value;
                html += '<li>' + title + '</li>';
            });
            html += '</ul>';
            deleteList.innerHTML = html;

            modal.classList.remove('hidden');
        });
    }

    document.querySelectorAll('[data-modal-close="modalBulkDelete"]').forEach(btn => {
        btn.addEventListener('click', () => modal.classList.add('hidden'));
    });

    if (confirmBtn) {
        confirmBtn.addEventListener('click', async function() {
            const checked = document.querySelectorAll('.sf-flash-checkbox:checked');
            const ids = Array.from(checked).map(cb => parseInt(cb.value));

            if (ids.length === 0) return;

confirmBtn.disabled = true;
            confirmBtn.innerHTML = '<span class="btn-spinner"></span> ' + SF_I18N.deleting;

try {
const csrfToken = '<?= sf_csrf_token() ?>';

const response = await fetch('app/actions/bulk_delete.php', {
    method: 'POST',
    credentials: 'include',
    headers: {
        'Content-Type': 'application/json',
        'Accept': 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': csrfToken
    },
    body: JSON.stringify({
        ids: ids,
        csrf_token: csrfToken
    })
});

    const raw = await response.text();
    let result = null;
    try {
        result = raw ? JSON.parse(raw) : null;
    } catch (e) {
        console.error('Bulk delete non-JSON response:', raw);
        throw new Error(raw || 'Invalid server response');
    }

    // Yhtenäinen tulkinta (backendiä on ollut eri versioita: success vs ok)
    const isSuccess = !!(result && (result.success === true || result.ok === true));

    // Autentikointi / sessio vanhentunut
    if (response.status === 401) {
        window.location.href = (window.SF_BASE_URL || '') + '/assets/pages/login.php?reason=expired';
        return;
    }

    if (isSuccess) {
        // Uudelleenohjaa samalle sivulle notice-parametrilla
        const url = new URL(window.location.href);
        url.searchParams.set('notice', 'bulk_deleted');
        url.searchParams.set('count', String(result.deleted || ids.length));
        window.location.href = url.toString();
    } else {
        const msg = (result && (result.error || result.message)) || 'Tuntematon virhe';
if (typeof window.sfToast === 'function') window.sfToast('error', msg);
else alert(SF_I18N.error_prefix + ' ' + msg);
    }
} catch (err) {
    console.error('Bulk delete error:', err);
if (typeof window.sfToast === 'function') window.sfToast('error', SF_I18N.delete_error);
else alert(SF_I18N.delete_error);
} finally {
    confirmBtn.disabled = false;
            confirmBtn.textContent = <?= json_encode(sf_term('btn_delete_permanently', $uiLang), JSON_UNESCAPED_UNICODE) ?>;
}
        });
    }
})();
// Lisää tämä bulk delete scriptin JÄLKEEN

// Checkbox-valittu kortin korostus (fallback :has() -selektorille)
document.addEventListener('change', function (e) {
    if (e.target.classList.contains('sf-flash-checkbox')) {
        const card = e.target.closest('.card');
        if (card) {
            card.classList.toggle('sf-card-selected', e.target.checked);
        }
    }
});
</script>


<?php endif; ?>

<!-- VIEW TOGGLE FAB (Floating Action Button with Speed Dial) -->
<div class="sf-view-fab-container" id="sfViewFabContainer">
    <!-- Main FAB button - shows current view icon -->
    <button type="button" class="sf-view-fab" id="sfViewFab" aria-label="<?= htmlspecialchars(sf_term('view_toggle', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>" aria-expanded="false">
        <!-- Current view icon will be injected here by JavaScript -->
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" class="sf-view-fab-icon" data-view="list">
            <rect x="3" y="3" width="18" height="6" rx="1"></rect>
            <rect x="3" y="11" width="18" height="6" rx="1"></rect>
        </svg>
    </button>
    
    <!-- Speed dial options (hidden by default) -->
    <div class="sf-view-fab-options" id="sfViewFabOptions">
        <button type="button" class="sf-view-fab-option" data-view="grid" title="<?= htmlspecialchars(sf_term('view_grid', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars(sf_term('view_grid', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="7" height="7"></rect>
                <rect x="14" y="3" width="7" height="7"></rect>
                <rect x="3" y="14" width="7" height="7"></rect>
                <rect x="14" y="14" width="7" height="7"></rect>
            </svg>
        </button>
        <button type="button" class="sf-view-fab-option" data-view="list" title="<?= htmlspecialchars(sf_term('view_list', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars(sf_term('view_list', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="18" height="6" rx="1"></rect>
                <rect x="3" y="11" width="18" height="6" rx="1"></rect>
            </svg>
        </button>
        <button type="button" class="sf-view-fab-option" data-view="compact" title="<?= htmlspecialchars(sf_term('view_compact', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars(sf_term('view_compact', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <line x1="3" y1="6" x2="21" y2="6"></line>
                <line x1="3" y1="12" x2="21" y2="12"></line>
                <line x1="3" y1="18" x2="21" y2="18"></line>
            </svg>
        </button>
    </div>
    
    <!-- Backdrop for closing speed dial when clicking outside -->
    <div class="sf-view-fab-backdrop" id="sfViewFabBackdrop"></div>
</div>

<!-- Suodattimien toggle (kaikille käyttäjille) -->
<script>
(function() {
    const toggle = document.getElementById('filtersToggle');
    const grid = document.getElementById('filtersGrid');

    if (toggle && grid) {
        toggle.addEventListener('click', function() {
            toggle.classList.toggle('open');
            grid.classList.toggle('open');
        });

        // Jos on aktiivisia suodattimia, avaa automaattisesti
        const hasFilters = <?= json_encode(
            $type !== '' ||
            $state !== '' ||
            (!$siteIsDefault && $site !== '') ||
            $q !== '' ||
            $from !== '' ||
            $to !== ''
        ) ?>;

        if (hasFilters) {
            toggle.classList.add('open');
            grid.classList.add('open');
        }
    }
})();
</script>

<!-- Prosessoitavien flashien tilan seuranta - bg_process parametrilla -->
<script>
(function() {
    const baseUrl = <?= json_encode($baseUrl) ?>;
    
    // Hae bg_process parametri URL:sta
    const urlParams = new URLSearchParams(window.location.search);
    const bgProcessId = urlParams.get('bg_process');
    
    // Jos ei ole bg_process parametria, ei tarvita pollausta
    if (!bgProcessId) return;
    
    const flashId = parseInt(bgProcessId, 10);
    if (isNaN(flashId) || flashId <= 0) return;
    
    const i18n = {
        processing: <?= json_encode(sf_term('processing_flash', $uiLang), JSON_UNESCAPED_UNICODE) ?>,
        complete: <?= json_encode(sf_term('processing_complete', $uiLang), JSON_UNESCAPED_UNICODE) ?>
    };
    
    let pollInterval = 1500;
    const maxInterval = 8000;
    let pollCount = 0;
    const maxPolls = 30; // Lopeta 30 yrityksen jälkeen (noin 2-3 min)
    
    async function checkStatus() {
        try {
            const response = await fetch(baseUrl + '/app/api/check_processing_status.php?flash_id=' + flashId);
            if (!response.ok) return null;
            return await response.json();
        } catch (e) {
            console.error('Status check error:', e);
            return null;
        }
    }
    
    async function pollProcessing() {
        pollCount++;
        
        // Lopeta jos liikaa yrityksiä
        if (pollCount > maxPolls) {
            console.warn('Max polls reached for flash', flashId);
            // Näytä kortti joka tapauksessa, mutta ilman success-toastia
            const card = document.querySelector(`.card[data-flash-id="${flashId}"]`);
            if (card && card.classList.contains('sf-card-hidden-processing')) {
                card.classList.remove('sf-card-hidden-processing');
                // Ei animaatiota jos timeout
            }
            // Poista bg_process parametri
            const newUrl = new URL(window.location.href);
            newUrl.searchParams.delete('bg_process');
            window.history.replaceState({}, '', newUrl.toString());
            return;
        }
        
        const status = await checkStatus();
        
        if (status && status.is_processing === false) {
            // Valmis! Näytä kortti animaatiolla
            showCard();
        } else if (status === null) {
            // Virhe API-kutsussa - yritä uudelleen hitaammin
            pollInterval = Math.min(pollInterval * 1.3, maxInterval);
            setTimeout(pollProcessing, pollInterval);
        } else {
            // Vielä prosessoidaan - jatka pollausta
            pollInterval = Math.min(pollInterval * 1.3, maxInterval);
            setTimeout(pollProcessing, pollInterval);
        }
    }
    
    function showCard() {
        const card = document.querySelector(`.card[data-flash-id="${flashId}"]`);
        
        if (card && card.classList.contains('sf-card-hidden-processing')) {
            // Poista piilotus ja lisää animaatio
            card.classList.remove('sf-card-hidden-processing');
            card.classList.add('sf-card-appear');
            
            // Poista animaatioluokka 1s jälkeen
            setTimeout(() => {
                card.classList.remove('sf-card-appear');
            }, 1000);
            
            // Näytä toast
            if (typeof window.sfToast === 'function') {
                window.sfToast('success', '✓ ' + i18n.complete);
            }
            
            // Poista bg_process parametri URL:sta (estää uudelleen-animoinnin)
            const newUrl = new URL(window.location.href);
            newUrl.searchParams.delete('bg_process');
            window.history.replaceState({}, '', newUrl.toString());
        } else if (!card) {
            // Korttia ei löydy DOM:sta - poista bg_process ja lataa uudelleen
            // Tämä voi tapahtua jos kortti ei ole näkyvissä suodattimien takia
            console.warn('Card not found in DOM, reloading page');
            const newUrl = new URL(window.location.href);
            newUrl.searchParams.delete('bg_process');
            window.location.href = newUrl.toString();
        } else {
            // Kortti löytyy mutta ei ole piilotettu - poista vain bg_process
            const newUrl = new URL(window.location.href);
            newUrl.searchParams.delete('bg_process');
            window.history.replaceState({}, '', newUrl.toString());
        }
    }
    
    // Aloita polling pienellä viiveellä
    setTimeout(pollProcessing, 500);
})();
</script>

<!-- BOTTOM SHEET (Mobile) -->
<div class="sf-bottom-sheet" id="sfBottomSheet">
    <div class="sf-bottom-sheet-backdrop" id="sfBottomSheetBackdrop"></div>
    <div class="sf-bottom-sheet-content" id="sfBottomSheetContent">
        <div class="sf-bottom-sheet-handle"></div>
        <div class="sf-bottom-sheet-header">
            <h3 id="sfBottomSheetTitle"></h3>
        </div>
        <div class="sf-bottom-sheet-body" id="sfBottomSheetBody">
            <!-- Content will be dynamically populated -->
        </div>
        <div class="sf-bottom-sheet-footer">
            <button type="button" class="sf-btn-secondary" id="sfBottomSheetClear">
                <?= htmlspecialchars(sf_term('filter_clear', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
            <button type="button" class="sf-btn-primary" id="sfBottomSheetDone">
                <?= htmlspecialchars(sf_term('filter_done', $currentUiLang), ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </div>
</div>
</div>
<!-- Copy Toast Inline Styles (fallback) -->
<style>
#sfCopyToast {
    position: fixed;
    top: 80px;
    right: 20px;
    z-index: 100001;
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 12px 18px;
    border-radius: 12px;
    background: linear-gradient(135deg, #10b981 0%, #059669 100%);
    color: #fff;
    font-size: 14px;
    font-weight: 600;
    box-shadow: 0 8px 24px rgba(16, 185, 129, 0.3), 
                0 4px 8px rgba(0, 0, 0, 0.15);
    opacity: 0;
    transform:  translateX(100px);
    transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
    pointer-events: none;
}

#sfCopyToast.error {
    background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
    box-shadow: 0 8px 24px rgba(239, 68, 68, 0.3), 
                0 4px 8px rgba(0, 0, 0, 0.15);
}

/* Show toast animation */
#sfCopyToast[style*="opacity: 1"] {
    opacity: 1 !important;
    transform: translateX(0) !important;
}

/* Spinner in toast */
#sfCopyToast .sf-spinner {
    width: 16px;
    height: 16px;
    border: 2px solid rgba(255, 255, 255, 0.3);
    border-top-color: #fff;
    border-radius:  50%;
    animation: spin 0.6s linear infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* Mobile responsive */
@media (max-width:  768px) {
    #sfCopyToast {
        top: 70px;
        right: 10px;
        left: 10px;
        max-width: calc(100% - 20px);
        font-size: 13px;
        padding: 10px 14px;
    }
}
</style>

</div> <!-- .sf-list-page -->

<!-- Skeleton loading logic -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const skeletonContainer = document.getElementById('skeletonContainer');
    
    if (skeletonContainer) {
        setTimeout(function() {
            skeletonContainer.classList.add('loaded');
        }, 300);
    }
});
</script>

<!-- Client-side filtering -->
<script>
// Pass translations to JavaScript
window.SF_LIST_I18N = {
    currentLang: <?= json_encode($currentUiLang, JSON_UNESCAPED_UNICODE) ?>,
    filterNoResults: <?= json_encode(sf_term('filter_no_results', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    noResultsHint: <?= json_encode(sf_term('no_results_hint', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    filterResultsCount: <?= json_encode(sf_term('filter_results_count', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    typeRed: <?= json_encode(sf_term('first_release', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    typeYellow: <?= json_encode(sf_term('dangerous_situation', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    typeGreen: <?= json_encode(sf_term('investigation_report', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    filterType: <?= json_encode(sf_term('filter_type', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    filterState: <?= json_encode(sf_term('filter_state', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    filterSite: <?= json_encode(sf_term('filter_site', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    filterDate: <?= json_encode(sf_term('filter_chip_date', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    filterChipTypeAll: <?= json_encode(sf_term('filter_chip_type_all', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    filterChipOriginalTypeAll: <?= json_encode(sf_term('filter_chip_original_type_all', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    filterChipStateAll: <?= json_encode(sf_term('filter_chip_state_all', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    filterChipSiteAll: <?= json_encode(sf_term('filter_chip_site_all', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    filterDateFrom: <?= json_encode(sf_term('date_from', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    filterDateTo: <?= json_encode(sf_term('date_to', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    filterApply: <?= json_encode(sf_term('filter_apply', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    dateTimespanHeader: <?= json_encode(sf_term('date_timespan_header', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    datePresetAll: <?= json_encode(sf_term('date_preset_all', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    datePreset7days: <?= json_encode(sf_term('date_preset_7days', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    datePreset7daysShort: <?= json_encode(sf_term('date_preset_7days_short', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    datePreset30days: <?= json_encode(sf_term('date_preset_30days', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    datePreset30daysShort: <?= json_encode(sf_term('date_preset_30days_short', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    datePresetMonth: <?= json_encode(sf_term('date_preset_month', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    datePresetMonthShort: <?= json_encode(sf_term('date_preset_month_short', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    datePresetYear: <?= json_encode(sf_term('date_preset_year', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    datePresetCustom: <?= json_encode(sf_term('date_preset_custom', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    dateMonthHeader: <?= json_encode(sf_term('date_month_header', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    dateClear: <?= json_encode(sf_term('date_clear', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    monthNamesShort: ["Tammi","Helmi","Maalis","Huhti","Touko","Kesä","Heinä","Elo","Syys","Loka","Marras","Joulu"],
    sortBy: <?= json_encode(sf_term('sort_by', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    sortCreated: <?= json_encode(sf_term('sort_created', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    sortOccurred: <?= json_encode(sf_term('sort_occurred', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    sortUpdated: <?= json_encode(sf_term('sort_updated', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    sortNewest: <?= json_encode(sf_term('sort_newest', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    sortOldest: <?= json_encode(sf_term('sort_oldest', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>,
    editingIndicator: <?= json_encode(sf_term('editing_indicator', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>
};
</script>

<!-- Editing indicator polling -->
<script src="<?= sf_asset_url('assets/js/editing-indicator.js', $baseUrl) ?>"></script>

<!-- Copy to Clipboard -->
<link rel="stylesheet" href="<?= sf_asset_url('assets/css/copy-to-clipboard.css', $baseUrl) ?>">
<script src="<?= sf_asset_url('assets/js/copy-to-clipboard.js', $baseUrl) ?>"></script>

<script>
// Initialize copy buttons for list items
document.addEventListener('DOMContentLoaded', function() {
    if (window.SafetyFlashCopy) {
        // Set base URL for use in copy button
        window.SF_BASE_URL = window.SF_BASE_URL || <?= json_encode($baseUrl) ?>;
        
        // Load translations
        window.SF_I18N = window.SF_I18N || {};
        window.SF_I18N.copy_image = <?= json_encode(sf_term('copy_image', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>;
        window.SF_I18N.copying_image = <?= json_encode(sf_term('copying_image', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>;
        window.SF_I18N.image_copied = <?= json_encode(sf_term('image_copied', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>;
        window.SF_I18N.copy_failed = <?= json_encode(sf_term('copy_failed', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>;
        window.SF_I18N.preview_error = <?= json_encode(sf_term('preview_generation_error', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>;
        window.SF_I18N.refresh_page = <?= json_encode(sf_term('preview_refresh_page', $currentUiLang), JSON_UNESCAPED_UNICODE) ?>;

        // Add copy buttons to all card thumbnails
        const cardThumbs = document.querySelectorAll('.card-thumb');
        cardThumbs.forEach(function(thumb) {
            // Skip if already has a copy button
            if (thumb.querySelector('.sf-copy-btn')) return;
            
            // Get the image element
            const img = thumb.querySelector('.sf-card-image');
            if (!img) return;

            // Add copy button with compact styling - POSITION BOTTOM LEFT
            window.SafetyFlashCopy.addCopyButton(thumb, {
                label: window.SF_I18N.copy_image,
                copyingLabel: window.SF_I18N.copying_image,
                successMessage: window.SF_I18N.image_copied,
                errorMessage: window.SF_I18N.copy_failed,
                position: 'bottom-left',  // Changed from top-right
                className: 'sf-copy-btn-compact',
                // Use the existing copy-icon.svg
                iconSvg: '<img src="' + encodeURI(window.SF_BASE_URL || '') + '/assets/img/icons/copy-icon.svg" alt="" width="14" height="14">'
            });
        });
    }
});
</script>

<!-- Mobile: Whole card clickable -->
<script>
// Mobile: Make entire card clickable (except copy button and checkbox)
// MOBILE_BREAKPOINT: 768px (matches CSS and list-views.js)
document.addEventListener('DOMContentLoaded', function() {
    const MOBILE_BREAKPOINT = 768; // px
    if (window.innerWidth <= MOBILE_BREAKPOINT) {
        const cards = document.querySelectorAll('.card');
        cards.forEach(function(card) {
            card.addEventListener('click', function(e) {
                // Don't navigate if clicking on copy button, checkbox, or links
                if (e.target.closest('.sf-copy-btn') || 
                    e.target.closest('input[type="checkbox"]') ||
                    e.target.closest('a')) {
                    return;
                }
                
                // Get the view URL from the open button
                const openBtn = card.querySelector('.open-btn');
                if (openBtn && openBtn.href) {
                    window.location.href = openBtn.href;
                }
            });
        });
    }
});
</script>
<!-- Preview Polling Module -->
<script src="<?= sf_asset_url('assets/js/preview-polling.js', $baseUrl) ?>"></script>

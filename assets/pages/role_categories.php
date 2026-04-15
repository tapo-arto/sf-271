<?php
// assets/pages/role_categories.php
declare(strict_types=1);

require_once __DIR__ . '/../../app/includes/protect.php';

// Allow admin and safety team
if (!sf_is_admin_or_safety()) {
    http_response_code(403);
    echo 'Ei käyttöoikeutta. Vain pääkäyttäjät ja turvatiimi voivat hallita roolikategorioita.';
    exit;
}

$mysqli = sf_db();

// Get all role categories with user counts
$sql = "SELECT rc.id,
               rc.name,
               rc.type,
               rc.worksite,
               rc.is_active,
               rc.created_at,
               COUNT(urc.user_id) as user_count
        FROM role_categories rc
        LEFT JOIN user_role_categories urc ON rc.id = urc.role_category_id
        GROUP BY rc.id
        ORDER BY rc.type, rc.name";
$categories = [];
$res = $mysqli->query($sql);
while ($row = $res->fetch_assoc()) {
    $categories[] = $row;
}

// Get all active users for assignment
$usersRes = $mysqli->query("
    SELECT id, first_name, last_name, email 
    FROM sf_users 
    WHERE is_active = 1 
    ORDER BY last_name, first_name
");
$users = [];
while ($u = $usersRes->fetch_assoc()) {
    $users[] = $u;
}

// Get all worksites for dropdown
$worksitesRes = $mysqli->query("SELECT name FROM sf_worksites WHERE is_active = 1 ORDER BY name ASC");
$worksites = [];
while ($w = $worksitesRes->fetch_assoc()) {
    $worksites[] = $w['name'];
}

$baseUrl = rtrim($config['base_url'] ?? '', '/');
?>
<link rel="stylesheet" href="<?= sf_asset_url('assets/css/role-categories.css', $baseUrl) ?>">

<div class="sf-page-container">
    <div class="sf-page-header">
        <h1 class="sf-page-title">Roolikategoriat</h1>
    </div>
    
<div class="sf-role-categories-page">
    
    <div class="sf-info-box">
        <p><strong>Info:</strong> Roolikategoriat määrittävät, ketkä käyttäjät voivat hyväksyä SafetyFlasheja ennen kuin ne menevät turvatiimille.</p>
        <ul>
            <li><strong>Työmaavastaava (site manager):</strong> Hyväksyy flashit ennen turvatiimin tarkastusta</li>
            <li><strong>Hyväksyjä (approver):</strong> Voi hyväksyä flasheja</li>
            <li><strong>Tarkastaja (reviewer):</strong> Tarkastaa flasheja</li>
        </ul>
    </div>

    <!-- Worksite Filter -->
    <div class="sf-filter-bar">
        <label for="sfWorksiteFilter" class="sf-filter-label">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M22 3H2l8 9.46V19l4 2v-8.54L22 3z"/>
            </svg>
            Suodata työmaan mukaan:
        </label>
        <select id="sfWorksiteFilter" class="sf-filter-select">
            <option value="">Kaikki työmaat</option>
            <option value="__global__">Globaalit kategoriat (ei työmaata)</option>
            <?php 
            // Get unique worksites from categories
            $uniqueWorksites = array_unique(array_filter(array_column($categories, 'worksite')));
            sort($uniqueWorksites);
            foreach ($uniqueWorksites as $ws): 
            ?>
                <option value="<?= htmlspecialchars($ws) ?>">
                    <?= htmlspecialchars($ws) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <span id="sfFilterCount" class="sf-filter-count"></span>
    </div>

    <div class="sf-categories-header">
        <button class="sf-btn sf-btn-primary" id="sfAddCategoryBtn">
            + Lisää uusi kategoria
        </button>
    </div>

    <div class="sf-categories-grid">
        <?php foreach ($categories as $cat): ?>
            <div class="sf-category-card" 
                 data-id="<?= (int)$cat['id']; ?>"
                 data-worksite="<?= $cat['worksite'] ? htmlspecialchars($cat['worksite']) : '__global__' ?>">
                <div class="sf-category-header">
                    <h3><?= htmlspecialchars($cat['name']) ?></h3>
                    <span class="sf-category-badge sf-category-badge-<?= htmlspecialchars($cat['type']) ?>">
                        <?= htmlspecialchars($cat['type']) ?>
                    </span>
                </div>
                <div class="sf-category-body">
                    <div class="sf-category-info">
                        <div class="sf-category-info-row">
                            <span class="sf-category-label">Työmaa:</span>
                            <span class="sf-category-value">
                                <?= $cat['worksite'] ? htmlspecialchars($cat['worksite']) : '<em>Kaikki työmaat</em>' ?>
                            </span>
                        </div>
                        <div class="sf-category-info-row">
                            <span class="sf-category-label">Käyttäjiä:</span>
                            <span class="sf-category-value"><?= (int)$cat['user_count'] ?></span>
                        </div>
                        <div class="sf-category-info-row">
                            <span class="sf-category-label">Tila:</span>
                            <span class="sf-category-value">
                                <?= $cat['is_active'] ? '✓ Aktiivinen' : '✗ Ei aktiivinen' ?>
                            </span>
                        </div>
                    </div>
                </div>
                <div class="sf-category-actions">
                    <button class="sf-btn-small sf-manage-users-btn" 
                            data-id="<?= (int)$cat['id']; ?>"
                            data-name="<?= htmlspecialchars($cat['name']); ?>">
                        Hallinnoi käyttäjiä
                    </button>
                    <button class="sf-btn-small sf-edit-category-btn" 
                            data-id="<?= (int)$cat['id']; ?>">
                        Muokkaa
                    </button>
                    <button class="sf-btn-small sf-btn-danger sf-delete-category-btn" 
                            data-id="<?= (int)$cat['id']; ?>"
                            data-name="<?= htmlspecialchars($cat['name']); ?>">
                        Poista
                    </button>
                </div>
            </div>
        <?php endforeach; ?>
        
        <?php if (empty($categories)): ?>
            <div class="sf-empty-state">
                <p>Ei roolikategorioita. Luo ensimmäinen kategoria klikkaamalla "Lisää uusi kategoria".</p>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Add/Edit Category Modal -->
<div id="sfCategoryModal" class="sf-modal" style="display: none;">
    <div class="sf-modal-content">
        <div class="sf-modal-header">
            <h2 id="sfCategoryModalTitle">Lisää kategoria</h2>
            <button class="sf-modal-close" id="sfCategoryModalClose">&times;</button>
        </div>
        <div class="sf-modal-body">
            <form id="sfCategoryForm">
                <input type="hidden" id="categoryId" name="id" value="">
                
                <div class="sf-form-group">
                    <label for="categoryName">Nimi *</label>
                    <input type="text" id="categoryName" name="name" required 
                           placeholder="Esim. Työmaavastaavat - Siilinjärvi">
                </div>
                
                <div class="sf-form-group">
                    <label for="categoryType">Tyyppi *</label>
                    <select id="categoryType" name="type" required>
                        <option value="">Valitse tyyppi</option>
                        <option value="supervisor">Työmaavastaava (supervisor)</option>
                        <option value="approver">Hyväksyjä (approver)</option>
                        <option value="reviewer">Tarkastaja (reviewer)</option>
                    </select>
                </div>
                
                <div class="sf-form-group">
                    <label for="categoryWorksite">Työmaa</label>
                    <select id="categoryWorksite" name="worksite">
                        <option value="">Kaikki työmaat</option>
                        <?php foreach ($worksites as $ws): ?>
                            <option value="<?= htmlspecialchars($ws) ?>">
                                <?= htmlspecialchars($ws) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small>Jätä tyhjäksi jos kategoria koskee kaikkia työmaita</small>
                </div>
                
                <div class="sf-form-group">
                    <label>
                        <input type="checkbox" id="categoryIsActive" name="is_active" checked>
                        Aktiivinen
                    </label>
                </div>
                
                <div class="sf-form-actions">
                    <button type="button" class="sf-btn sf-btn-secondary" id="sfCategoryFormCancel">
                        Peruuta
                    </button>
                    <button type="submit" class="sf-btn sf-btn-primary">
                        Tallenna
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Manage Users Modal -->
<div id="sfManageUsersModal" class="sf-modal" style="display: none;">
    <div class="sf-modal-content sf-modal-large">
        <div class="sf-modal-header">
            <h2 id="sfManageUsersModalTitle">Hallinnoi käyttäjiä</h2>
            <button class="sf-modal-close" id="sfManageUsersModalClose">&times;</button>
        </div>
        <div class="sf-modal-body">
            <input type="hidden" id="manageCategoryId" value="">
            
            <div class="sf-manage-users-container">
                <div class="sf-manage-users-section">
                    <h3>Nykyiset käyttäjät</h3>
                    <div id="sfCurrentUsersList" class="sf-users-list">
                        <!-- Populated by JavaScript -->
                    </div>
                </div>
                
                <div class="sf-manage-users-section">
                    <h3>Lisää käyttäjä</h3>
                    <select id="sfAddUserSelect" class="sf-select">
                        <option value="">Valitse käyttäjä</option>
                        <?php foreach ($users as $u): ?>
                            <option value="<?= (int)$u['id'] ?>">
                                <?= htmlspecialchars($u['first_name'] . ' ' . $u['last_name'] . ' (' . $u['email'] . ')') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <button class="sf-btn sf-btn-primary" id="sfAddUserBtn" style="margin-top: 10px;">
                        Lisää käyttäjä
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
</div>

<script>
window.SF_BASE_URL = <?php echo json_encode($baseUrl); ?>;
window.SF_ALL_USERS = <?php echo json_encode($users); ?>;
</script>
<script src="<?= sf_asset_url('assets/js/role-categories.js', $baseUrl) ?>"></script>
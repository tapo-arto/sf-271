<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../assets/lib/sf_terms.php';
require_once __DIR__ .  '/../includes/protect.php';   // auth + CSRF (POST)
require_once __DIR__ . '/../includes/log_app.php';
require_once __DIR__ . '/../includes/audit_log.php';

// Allow admins (and optionally role 3 if you use it as admin-like)
sf_require_role([1, 3]);

$currentUser = sf_current_user();

function sf_is_fetch(): bool {
    $xrw = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
    if ($xrw === 'xmlhttprequest') return true;
    $accept = strtolower($_SERVER['HTTP_ACCEPT'] ?? '');
    return strpos($accept, 'application/json') !== false;
}

function sf_json(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

$base = rtrim((string)($config['base_url'] ?? ''), '/');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    if (sf_is_fetch()) sf_json(['ok' => false, 'error' => 'Method not allowed'], 405);
    header("Location: {$base}/index.php?page=settings&tab=worksites");
    exit;
}

// DB connection (mysqli)
$db = $config['db'] ?? null;
if (!is_array($db)) {
    if (sf_is_fetch()) sf_json(['ok' => false, 'error' => 'DB config missing'], 500);
    exit('DB config missing');
}

$mysqli = new mysqli((string)$db['host'], (string)$db['user'], (string)$db['pass'], (string)$db['name']);
if ($mysqli->connect_errno) {
    if (sf_is_fetch()) sf_json(['ok' => false, 'error' => $mysqli->connect_error], 500);
    exit('DB connect failed');
}
$mysqli->set_charset((string)($db['charset'] ?? 'utf8mb4'));

// Accept both "form_action" (settings tab) and legacy "action"
$action = (string)($_POST['form_action'] ?? ($_POST['action'] ?? ''));

try {
    // ---------------------------------------------------------------------
    // ADD (used by settings tab: form_action=add, field: name)
    // ---------------------------------------------------------------------
if ($action === 'add') {
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') {
        if (sf_is_fetch()) sf_json(['ok' => false, 'error' => 'Missing fields'], 400);
        header("Location: {$base}/index.php?page=settings&tab=worksites&notice=error");
        exit;
    }

    $allowedSiteTypes = ['tunnel', 'opencast', 'other'];
    $siteTypeRaw = trim((string)($_POST['site_type'] ?? ''));
    $siteType = in_array($siteTypeRaw, $allowedSiteTypes, true) ? $siteTypeRaw : null;

    // Mikäli kenttää ei ole POST:ssa lainkaan (esim. vanha API-kutsu),
    // käytä oletusta 1 jotta nykykäytös säilyy. Jos kenttä on POST:ssa
    // ja arvo on 0, tulkitaan se tarkoitukselliseksi pois-valinnaksi.
    $hasListsField = array_key_exists('show_in_worksite_lists_present', $_POST);
    $hasDisplaysField = array_key_exists('show_in_display_targets_present', $_POST);
    $showInWorksiteLists = $hasListsField ? (isset($_POST['show_in_worksite_lists']) ? 1 : 0) : 1;
    $showInDisplayTargets = $hasDisplaysField ? (isset($_POST['show_in_display_targets']) ? 1 : 0) : 1;

    // Insert name, is_active, and optional site_type.
    $stmt = $mysqli->prepare(
        "INSERT INTO sf_worksites
            (name, site_type, is_active, show_in_worksite_lists, show_in_display_targets)
         VALUES (?, ?, 1, ?, ?)"
    );
    if (!$stmt) {
        throw new Exception('Prepare failed: ' .  $mysqli->error);
    }
    $stmt->bind_param('ssii', $name, $siteType, $showInWorksiteLists, $showInDisplayTargets);
    $ok = $stmt->execute();
    $newWorksiteId = $mysqli->insert_id;
    $stmt->close();

    // ========== AUTO-CREATE DISPLAY API KEY ==========
    if ($ok && $newWorksiteId > 0) {
        try {
            // Slugify name
            $slug = strtolower((string)preg_replace(
                ['/[äÄ]/u', '/[öÖ]/u', '/[åÅ]/u', '/[^a-z0-9]+/i'],
                ['a', 'o', 'a', '_'],
                $name
            ));
            $slug = trim($slug, '_');

            // Language and site_group by name
            $dispLang = 'fi';
            $siteGroup = 'Suomi';
            if (stripos($name, 'Hellas') !== false) {
                $dispLang = 'el';
                $siteGroup = 'Kreikka';
            } elseif (stripos($name, 'Italia') !== false) {
                $dispLang = 'it';
                $siteGroup = 'Italia';
            }

            $stmtKey = $mysqli->prepare(
                "INSERT INTO sf_display_api_keys (api_key, site, label, lang, site_group, worksite_id, is_active, created_at)
                 VALUES (CONCAT('sf_dk_', MD5(CONCAT(?, NOW(), RAND()))), ?, ?, ?, ?, ?, 1, NOW())"
            );
            if ($stmtKey) {
                $stmtKey->bind_param('sssssi', $name, $slug, $name, $dispLang, $siteGroup, $newWorksiteId);
                $stmtKey->execute();
                $stmtKey->close();
            }
        } catch (Throwable $ek) {
            sf_app_log('worksites_save: auto-create display key failed: ' . $ek->getMessage(), LOG_LEVEL_ERROR);
        }
    }
    // ================================================

    // ========== AUDIT LOG ==========
    if ($ok) {
        sf_audit_log(
            'worksite_created',
            'worksite',
            (int)$newWorksiteId,
            [
                'name' => $name,
                'site_type' => $siteType,
                'is_active' => 1,
                'show_in_worksite_lists' => $showInWorksiteLists,
                'show_in_display_targets' => $showInDisplayTargets,
            ],
            $currentUser ?  (int)$currentUser['id'] : null
        );
    }
    // ================================

$uiLang = $_SESSION['ui_lang'] ?? 'fi';
$msg = $ok
    ? (sf_term('worksite_added', $uiLang) ?: 'Työmaa lisätty.')
    : (sf_term('error', $uiLang) ?: 'Toiminto epäonnistui.');

    if (sf_is_fetch()) {
        sf_json([
            'ok' => (bool)$ok,
            'success' => (bool)$ok,
            'notice' => $ok ? 'worksite_added' : 'error',
            'message' => $msg
        ], $ok ?  200 : 500);
    }

    header("Location:  {$base}/index.php?page=settings&tab=worksites&notice=" . ($ok ?  "worksite_added" : "error"));
    exit;
}

    if ($action === 'toggle_worksite_visibility') {
        $id = (int)($_POST['id'] ?? 0);
        $field = (string)($_POST['field'] ?? '');
        $fieldMap = [
            'show_in_worksite_lists' => 'show_in_worksite_lists',
            'show_in_display_targets' => 'show_in_display_targets',
        ];
        $column = $fieldMap[$field] ?? null;
        $ok = false;

        if ($id > 0 && $column !== null) {
            // Sarake on whitelistattu, joten interpolointi on turvallista.
            $stmt = $mysqli->prepare("UPDATE sf_worksites SET {$column} = CASE WHEN {$column} = 1 THEN 0 ELSE 1 END WHERE id = ?");
            if ($stmt) {
                $stmt->bind_param('i', $id);
                $ok = $stmt->execute();
                $stmt->close();

                if ($ok) {
                    sf_audit_log(
                        'worksite_visibility_toggled',
                        'worksite',
                        $id,
                        ['field' => $field],
                        $currentUser ? (int)$currentUser['id'] : null
                    );
                }
            }
        }

        if (sf_is_fetch()) {
            sf_json([
                'ok' => $ok,
                'success' => $ok,
                'notice' => $ok ? 'worksite_saved' : 'error',
                'message' => $ok
                    ? (sf_term('worksite_saved', $_SESSION['ui_lang'] ?? 'fi') ?: 'Työmaa tallennettu.')
                    : (sf_term('error', $_SESSION['ui_lang'] ?? 'fi') ?: 'Toiminto epäonnistui.'),
            ], $ok ? 200 : 400);
        }
        header("Location: {$base}/index.php?page=settings&tab=worksites");
        exit;
    }

    // ---------------------------------------------------------------------
    // TOGGLE (used by settings tab: form_action=toggle, field: id)
    // ---------------------------------------------------------------------
    if ($action === 'toggle') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            if (sf_is_fetch()) sf_json(['ok' => false, 'error' => 'Invalid ID'], 400);
            header("Location: {$base}/index.php?page=settings&tab=worksites&notice=error");
            exit;
        }

// Flip active flag
$stmt = $mysqli->prepare("UPDATE sf_worksites SET is_active = 1 - is_active WHERE id = ?");
if (!$stmt) {
    throw new Exception('Prepare failed:  ' . $mysqli->error);
}
$stmt->bind_param('i', $id);
$ok = $stmt->execute();
$stmt->close();

// Fetch new state for notification/UI (works with and without mysqlnd)
$newActive = null;
$worksiteName = null;
$stmt2 = $mysqli->prepare("SELECT name, is_active FROM sf_worksites WHERE id = ? LIMIT 1");
if ($stmt2) {
    $stmt2->bind_param('i', $id);
    $stmt2->execute();

    if (method_exists($stmt2, 'get_result')) {
        // mysqlnd available
        $res2 = $stmt2->get_result();
        $row2 = $res2 ? $res2->fetch_assoc() : null;
        $newActive = $row2 ? (int)($row2['is_active'] ??  0) : null;
        $worksiteName = $row2 ? ($row2['name'] ?? null) : null;
    } else {
        // portable fallback (no mysqlnd)
        $nameVal = null;
        $isActiveVal = null;
        $stmt2->bind_result($nameVal, $isActiveVal);
        if ($stmt2->fetch()) {
            $newActive = (int)$isActiveVal;
            $worksiteName = $nameVal;
        }
    }

    $stmt2->close();
}

// ========== AUDIT LOG ==========
if ($ok) {
    sf_audit_log(
        'worksite_updated',
        'worksite',
        $id,
        [
            'name' => $worksiteName,
            'is_active' => $newActive,
            'action' => 'toggle',
        ],
        $currentUser ? (int)$currentUser['id'] : null
    );
}
// ================================

// ========== SYNC DISPLAY IS_ACTIVE ==========
if ($ok && $newActive !== null) {
    try {
        $stmtSync = $mysqli->prepare(
            "UPDATE sf_display_api_keys SET is_active = ? WHERE worksite_id = ?"
        );
        if ($stmtSync) {
            $stmtSync->bind_param('ii', $newActive, $id);
            $stmtSync->execute();
            $stmtSync->close();
        }
    } catch (Throwable $es) {
        sf_app_log('worksites_save: sync display is_active failed: ' . $es->getMessage(), LOG_LEVEL_ERROR);
    }
}
// ============================================

$notice = ($newActive === 0) ? 'worksite_disabled' : 'worksite_enabled';
$uiLang = $_SESSION['ui_lang'] ?? 'fi';
$msg    = sf_term($notice, $uiLang) ?: (($newActive === 0) ? 'Työmaa asetettu passiiviseksi.' : 'Työmaa aktivoitu.');

if (sf_is_fetch()) {
    sf_json([
        'ok' => (bool)$ok,
        'success' => (bool)$ok,
        'notice' => $notice,
        'message' => $msg,
        'is_active' => $newActive,
        'id' => $id
    ], $ok ? 200 : 500);
}

header("Location: {$base}/index.php?page=settings&tab=worksites&notice={$notice}");
exit;
    }  

    // ---------------------------------------------------------------------
    // DELETE (optional; if you add a delete button later)
    // ---------------------------------------------------------------------
    if ($action === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) {
            if (sf_is_fetch()) sf_json(['ok' => false, 'error' => 'Invalid ID'], 400);
            header("Location: {$base}/index.php?page=settings&tab=worksites&notice=error");
            exit;
        }

        // Hae nimi ennen poistoa audit-lokia varten
        $worksiteName = null;
        $stmtName = $mysqli->prepare("SELECT name FROM sf_worksites WHERE id = ?  LIMIT 1");
        if ($stmtName) {
            $stmtName->bind_param('i', $id);
            $stmtName->execute();
            if (method_exists($stmtName, 'get_result')) {
                $resName = $stmtName->get_result();
                $rowName = $resName ? $resName->fetch_assoc() : null;
                $worksiteName = $rowName ? ($rowName['name'] ?? null) : null;
            } else {
                $nameVal = null;
                $stmtName->bind_result($nameVal);
                if ($stmtName->fetch()) {
                    $worksiteName = $nameVal;
                }
            }
            $stmtName->close();
        }

        $stmt = $mysqli->prepare("DELETE FROM sf_worksites WHERE id = ?");
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $mysqli->error);
        }
        $stmt->bind_param('i', $id);
        $ok = $stmt->execute();
        $stmt->close();

        // ========== AUDIT LOG ==========
        if ($ok) {
            sf_audit_log(
                'worksite_deleted',
                'worksite',
                $id,
                [
                    'name' => $worksiteName,
                ],
                $currentUser ? (int)$currentUser['id'] :  null
            );
        }
        // ================================

        // ========== DEACTIVATE DISPLAY ON DELETE ==========
        if ($ok) {
            try {
                $stmtDel = $mysqli->prepare(
                    "UPDATE sf_display_api_keys SET is_active = 0 WHERE worksite_id = ?"
                );
                if ($stmtDel) {
                    $stmtDel->bind_param('i', $id);
                    $stmtDel->execute();
                    $stmtDel->close();
                }
            } catch (Throwable $ed) {
                sf_app_log('worksites_save: deactivate display on delete failed: ' . $ed->getMessage(), LOG_LEVEL_ERROR);
            }
        }
        // ==================================================

        if (sf_is_fetch()) sf_json(['ok' => (bool)$ok, 'notice' => 'deleted']);
        header("Location: {$base}/index.php?page=settings&tab=worksites&notice=deleted");
        exit;
    }

    // ---------------------------------------------------------------------
    // EDIT (used by settings tab: form_action=edit, fields: id, name, site_type)
    // ---------------------------------------------------------------------
    if ($action === 'edit') {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim((string)($_POST['name'] ?? ''));
        if ($id <= 0 || $name === '') {
            if (sf_is_fetch()) {
                sf_json(['ok' => false, 'error' => 'Missing fields'], 400);
            }
            header("Location: {$base}/index.php?page=settings&tab=worksites&notice=error");
            exit;
        }

        $allowedSiteTypes = ['tunnel', 'opencast', 'other'];
        $siteTypeRaw = trim((string)($_POST['site_type'] ?? ''));
        $siteType = in_array($siteTypeRaw, $allowedSiteTypes, true) ? $siteTypeRaw : null;

        $oldName = null;
        $stmtOld = $mysqli->prepare("SELECT name FROM sf_worksites WHERE id = ? LIMIT 1");
        if (!$stmtOld) {
            throw new Exception('Prepare failed: ' . $mysqli->error);
        }
        $stmtOld->bind_param('i', $id);
        $stmtOld->execute();

        if (method_exists($stmtOld, 'get_result')) {
            $resOld = $stmtOld->get_result();
            $rowOld = $resOld ? $resOld->fetch_assoc() : null;
            $oldName = $rowOld['name'] ?? null;
        } else {
            $oldNameValue = null;
            $stmtOld->bind_result($oldNameValue);
            if ($stmtOld->fetch()) {
                $oldName = $oldNameValue;
            }
        }
        $stmtOld->close();

        if ($oldName === null) {
            if (sf_is_fetch()) {
                sf_json(['ok' => false, 'error' => 'Worksite not found'], 404);
            }
            header("Location: {$base}/index.php?page=settings&tab=worksites&notice=error");
            exit;
        }

        $stmt = $mysqli->prepare("UPDATE sf_worksites SET name = ?, site_type = ? WHERE id = ?");
        if (!$stmt) {
            throw new Exception('Prepare failed: ' . $mysqli->error);
        }
        $stmt->bind_param('ssi', $name, $siteType, $id);
        $ok = $stmt->execute();
        $stmt->close();

        if ($ok && $oldName !== $name) {
            $stmtRoleCategories = $mysqli->prepare("
                UPDATE role_categories
                SET worksite = ?
                WHERE worksite = ?
            ");
            if ($stmtRoleCategories) {
                $stmtRoleCategories->bind_param('ss', $name, $oldName);
                $stmtRoleCategories->execute();
                $stmtRoleCategories->close();
            }

            $slug = strtolower((string)preg_replace(
                ['/[äÄ]/u', '/[öÖ]/u', '/[åÅ]/u', '/[^a-z0-9]+/i'],
                ['a', 'o', 'a', '_'],
                $name
            ));
            $slug = trim($slug, '_');

            $stmtDisplayKeys = $mysqli->prepare("
                UPDATE sf_display_api_keys
                SET label = ?, site = ?
                WHERE worksite_id = ?
            ");
            if ($stmtDisplayKeys) {
                $stmtDisplayKeys->bind_param('ssi', $name, $slug, $id);
                $stmtDisplayKeys->execute();
                $stmtDisplayKeys->close();
            }
        }

        if ($ok) {
            sf_audit_log(
                'worksite_updated',
                'worksite',
                $id,
                [
                    'name' => $name,
                    'old_name' => $oldName,
                    'site_type' => $siteType,
                    'action' => 'edit',
                ],
                $currentUser ? (int)$currentUser['id'] : null
            );
        }

        $uiLang = $_SESSION['ui_lang'] ?? 'fi';
        $msg = $ok
            ? (sf_term('worksite_saved', $uiLang) ?: 'Työmaa tallennettu.')
            : (sf_term('error', $uiLang) ?: 'Toiminto epäonnistui.');

        if (sf_is_fetch()) {
            sf_json([
                'ok' => (bool)$ok,
                'success' => (bool)$ok,
                'notice' => $ok ? 'worksite_saved' : 'error',
                'message' => $msg,
            ], $ok ? 200 : 500);
        }

        header("Location: {$base}/index.php?page=settings&tab=worksites&notice=" . ($ok ? 'worksite_saved' : 'error'));
        exit;
    }

    // Unknown action
    if (sf_is_fetch()) sf_json(['ok' => false, 'error' => 'Unknown action'], 400);
    header("Location: {$base}/index.php?page=settings&tab=worksites&notice=error");
    exit;

} catch (Throwable $e) {
    sf_app_log('worksites_save error: ' . $e->getMessage(), LOG_LEVEL_WARNING);
    if (sf_is_fetch()) sf_json(['ok' => false, 'error' => 'Server error'], 500);
    header("Location: {$base}/index.php?page=settings&tab=worksites&notice=error");
    exit;
} finally {
    $mysqli->close();
}

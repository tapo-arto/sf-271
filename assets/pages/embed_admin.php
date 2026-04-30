<?php
// embed_admin.php – admin page for embed token management
// Loaded via index.php?page=embed_admin (already auth-protected by sf_require_login())
declare(strict_types=1);

require_once __DIR__ . '/../../assets/lib/Database.php';
require_once __DIR__ . '/../../app/services/EmbedToken.php';
require_once __DIR__ . '/../../app/includes/csrf.php';

$user    = sf_current_user();
$isAdmin = $user && (int)($user['role_id'] ?? 0) === 1;

if (!$isAdmin) {
    http_response_code(403);
    echo '<p class="alert alert--error">Pääsy evätty.</p>';
    exit;
}

$pdo     = Database::getInstance();
$baseUrl = rtrim($config['base_url'] ?? '', '/');
$uiLang  = $_SESSION['ui_lang'] ?? 'fi';

$notice       = '';
$newToken     = null;
$newEmbedCode = null;

// ============================================================
// Handle POST actions
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    sf_csrf_check();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        $label         = trim((string)($_POST['label'] ?? ''));
        $viewType      = in_array($_POST['view_type'] ?? '', ['carousel', 'archive'], true)
                         ? (string)$_POST['view_type'] : 'carousel';
        $rawSiteId     = (int)($_POST['site_id'] ?? 0);
        $siteId        = $rawSiteId > 0 ? $rawSiteId : null;
        $allowedOrigin = trim((string)($_POST['allowed_origin'] ?? ''));
        $expiryDays    = max(1, min(365, (int)($_POST['expiry_days'] ?? 30)));
        $interval      = max(5, min(60, (int)($_POST['interval'] ?? 15)));

        // Validate origin
        $parsed = parse_url($allowedOrigin);
        if (empty($allowedOrigin) || !isset($parsed['scheme'], $parsed['host'])) {
            $notice = 'error:Virheellinen Origin-URL. Muoto: https://intra.yritys.fi';
        } else {
            // Generate cryptographically secure UUID v4
            $bytes = random_bytes(16);
            $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40); // version 4
            $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80); // variant RFC 4122
            $jti = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));

            $exp     = time() + ($expiryDays * 86400);
            $nbf     = time() - 5;

            $payload = [
                'iss'  => 'sf-embed',
                'aud'  => $allowedOrigin,
                'view' => $viewType,
                'site' => $siteId !== null ? (string)$siteId : '*',
                'exp'  => $exp,
                'nbf'  => $nbf,
                'kid'  => 'v1',
                'jti'  => $jti,
            ];

            try {
                $tokenStr = EmbedToken::issue($payload, 'v1');

                $stmt = $pdo->prepare('
                    INSERT INTO sf_embed_tokens
                        (jti, label, view_type, site_id, allowed_origin, created_by, expires_at)
                    VALUES
                        (:jti, :label, :view_type, :site_id, :allowed_origin, :created_by, :expires_at)
                ');
                $stmt->execute([
                    ':jti'            => $jti,
                    ':label'          => $label !== '' ? $label : 'Embed ' . date('Y-m-d'),
                    ':view_type'      => $viewType,
                    ':site_id'        => $siteId,
                    ':allowed_origin' => $allowedOrigin,
                    ':created_by'     => (int)($user['id'] ?? 0),
                    ':expires_at'     => date('Y-m-d H:i:s', $exp),
                ]);

                $newToken  = $tokenStr;
                $publicUrl = $baseUrl . '/public.php?t=' . urlencode($tokenStr);
                if ($viewType === 'carousel') {
                    $publicUrl .= '&interval=' . $interval;
                }

                if ($viewType === 'carousel') {
                    $newEmbedCode = '<div style="position:relative;width:100%;padding-top:56.25%;">' . "\n"
                        . '  <iframe src="' . htmlspecialchars($publicUrl, ENT_QUOTES, 'UTF-8') . '"' . "\n"
                        . '          style="position:absolute;inset:0;width:100%;height:100%;border:0;"' . "\n"
                        . '          loading="lazy" referrerpolicy="no-referrer"' . "\n"
                        . '          sandbox="allow-scripts allow-same-origin"' . "\n"
                        . '          title="SafetyFlash"></iframe>' . "\n"
                        . '</div>';
                } else {
                    $newEmbedCode = '<iframe id="sfEmbed" src="' . htmlspecialchars($publicUrl, ENT_QUOTES, 'UTF-8') . '"' . "\n"
                        . '        style="width:100%;border:0;" title="SafetyFlash"></iframe>' . "\n"
                        . '<script>' . "\n"
                        . 'window.addEventListener(\'message\', function(e) {' . "\n"
                        . '  if (e.origin !== ' . json_encode($baseUrl) . ') return;' . "\n"
                        . '  if (e.data && e.data.type === \'sf-embed-height\') {' . "\n"
                        . '    document.getElementById(\'sfEmbed\').style.height = e.data.height + \'px\';' . "\n"
                        . '  }' . "\n"
                        . '});' . "\n"
                        . '<\/script>';
                }

                $notice = 'success:Token luotu onnistuneesti.';
            } catch (\Throwable $e) {
                $notice = 'error:Token luonti epäonnistui: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            }
        }

    } elseif ($action === 'revoke') {
        $revokeJti = trim((string)($_POST['jti'] ?? ''));
        if ($revokeJti !== '') {
            try {
                EmbedToken::revoke($revokeJti, $pdo);
                $notice = 'success:Token peruttu.';
            } catch (\Throwable $e) {
                $notice = 'error:Perutus epäonnistui: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
            }
        }
    }
}

// ============================================================
// Load data for the page
// ============================================================
$tokens = $pdo->query("
    SELECT t.*, u.name AS creator_name
    FROM sf_embed_tokens t
    LEFT JOIN sf_users u ON u.id = t.created_by
    ORDER BY t.created_at DESC
    LIMIT 100
")->fetchAll(\PDO::FETCH_ASSOC);

$sites = $pdo->query(
    "SELECT id, name FROM sf_worksites WHERE is_active = 1 AND show_in_worksite_lists = 1 ORDER BY name ASC"
)->fetchAll(\PDO::FETCH_ASSOC);

$noticeType = '';
$noticeMsg  = '';
if ($notice !== '') {
    [$noticeType, $noticeMsg] = explode(':', $notice, 2);
}

$csrfField = sf_csrf_field();
?>

<div class="page-header">
    <h1 class="page-title">Upotustokenit</h1>
</div>

<?php if ($noticeMsg !== ''): ?>
<div class="alert alert--<?= $noticeType === 'success' ? 'success' : 'error' ?>" role="alert">
    <?= htmlspecialchars($noticeMsg, ENT_QUOTES, 'UTF-8') ?>
</div>
<?php endif; ?>

<?php if ($newToken !== null): ?>
<!-- One-time token display -->
<div class="card card--highlight" style="margin-bottom:1.5rem;">
    <h2 style="margin-bottom:0.75rem;">✅ Token luotu</h2>
    <p style="margin-bottom:0.5rem;color:var(--color-muted);">
        Kopioi token nyt – sitä ei näytetä uudelleen.
    </p>
    <div style="display:flex;gap:0.5rem;align-items:center;margin-bottom:1rem;flex-wrap:wrap;">
        <code id="new-token-value" style="
            font-family:monospace;
            font-size:0.75rem;
            background:var(--color-surface-2,#1e293b);
            padding:0.5rem 0.75rem;
            border-radius:0.375rem;
            word-break:break-all;
            flex:1 1 auto;
        "><?= htmlspecialchars($newToken, ENT_QUOTES, 'UTF-8') ?></code>
        <button type="button"
                onclick="navigator.clipboard.writeText(document.getElementById('new-token-value').textContent)"
                class="btn btn--sm btn--secondary">
            Kopioi token
        </button>
    </div>

    <h3 style="margin-bottom:0.5rem;">Iframe-upotuskoodi</h3>
    <textarea readonly rows="6" style="
        width:100%;
        font-family:monospace;
        font-size:0.75rem;
        background:var(--color-surface-2,#1e293b);
        color:inherit;
        border:1px solid var(--color-border,#334155);
        border-radius:0.375rem;
        padding:0.5rem 0.75rem;
        resize:vertical;
    "><?= htmlspecialchars($newEmbedCode ?? '', ENT_QUOTES, 'UTF-8') ?></textarea>
    <button type="button"
            onclick="navigator.clipboard.writeText(document.querySelector('.card--highlight textarea').value)"
            class="btn btn--sm btn--secondary"
            style="margin-top:0.5rem;">
        Kopioi upotuskoodi
    </button>
</div>
<?php endif; ?>

<!-- Create form -->
<div class="card" style="margin-bottom:2rem;">
    <h2 style="margin-bottom:1rem;">Luo uusi upotus</h2>
    <form method="POST" action="">
        <?= $csrfField ?>
        <input type="hidden" name="action" value="create">

        <div class="form-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:1rem;">
            <label class="form-group">
                <span>Nimi / tunniste</span>
                <input type="text" name="label" class="form-control" placeholder="esim. Intranet karuselli" maxlength="120">
            </label>

            <label class="form-group">
                <span>Näkymätyyppi</span>
                <select name="view_type" class="form-control" id="ea-view-type">
                    <option value="carousel">Karuselli</option>
                    <option value="archive">Arkisto</option>
                </select>
            </label>

            <label class="form-group">
                <span>Työmaa (tyhjä = kaikki)</span>
                <select name="site_id" class="form-control">
                    <option value="">Kaikki työmaat</option>
                    <?php foreach ($sites as $s): ?>
                    <option value="<?= (int)$s['id'] ?>">
                        <?= htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8') ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </label>

            <label class="form-group">
                <span>Sallittu Origin <small>(pakollinen)</small></span>
                <input type="url" name="allowed_origin" class="form-control" required
                       placeholder="https://intra.yritys.fi" maxlength="255">
            </label>

            <label class="form-group">
                <span>Voimassaolo (päivää)</span>
                <input type="number" name="expiry_days" class="form-control" value="30" min="1" max="365">
            </label>

            <label class="form-group" id="ea-interval-group">
                <span>Karuselli-intervalli (s)</span>
                <input type="number" name="interval" class="form-control" value="15" min="5" max="60">
            </label>
        </div>

        <button type="submit" class="btn btn--primary" style="margin-top:1rem;">
            Luo token
        </button>
    </form>
</div>

<!-- Token list -->
<div class="card">
    <h2 style="margin-bottom:1rem;">Aktiiviset tokenit (<?= count($tokens) ?>)</h2>

    <?php if (empty($tokens)): ?>
        <p style="color:var(--color-muted);">Ei tokeneita.</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
        <table class="data-table" style="width:100%;border-collapse:collapse;font-size:0.875rem;">
            <thead>
                <tr>
                    <th>Nimi</th>
                    <th>Tyyppi</th>
                    <th>Origin</th>
                    <th>Luotu</th>
                    <th>Vanhenee</th>
                    <th>Käytetty</th>
                    <th>Tila</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($tokens as $t):
                $isRevoked = $t['revoked_at'] !== null;
                $isExpired = strtotime($t['expires_at']) < time();
                $status    = $isRevoked ? 'Peruttu' : ($isExpired ? 'Vanhentunut' : 'Aktiivinen');
                $statusCls = $isRevoked ? 'badge--error' : ($isExpired ? 'badge--warning' : 'badge--success');
            ?>
            <tr style="border-bottom:1px solid var(--color-border,#334155);">
                <td style="padding:0.5rem 0.75rem;">
                    <?= htmlspecialchars($t['label'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                    <br><small style="color:var(--color-muted);">
                        <?= htmlspecialchars($t['creator_name'] ?? '', ENT_QUOTES, 'UTF-8') ?>
                    </small>
                </td>
                <td style="padding:0.5rem 0.75rem;"><?= htmlspecialchars($t['view_type'], ENT_QUOTES, 'UTF-8') ?></td>
                <td style="padding:0.5rem 0.75rem;word-break:break-all;max-width:200px;">
                    <?= htmlspecialchars($t['allowed_origin'], ENT_QUOTES, 'UTF-8') ?>
                </td>
                <td style="padding:0.5rem 0.75rem;" title="<?= htmlspecialchars($t['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars(substr($t['created_at'] ?? '', 0, 10), ENT_QUOTES, 'UTF-8') ?>
                </td>
                <td style="padding:0.5rem 0.75rem;" title="<?= htmlspecialchars($t['expires_at'] ?? '', ENT_QUOTES, 'UTF-8') ?>">
                    <?= htmlspecialchars(substr($t['expires_at'] ?? '', 0, 10), ENT_QUOTES, 'UTF-8') ?>
                </td>
                <td style="padding:0.5rem 0.75rem;">
                    <?= (int)($t['use_count'] ?? 0) ?>
                    <?php if ($t['last_used_at']): ?>
                    <br><small style="color:var(--color-muted);">
                        <?= htmlspecialchars(substr($t['last_used_at'], 0, 16), ENT_QUOTES, 'UTF-8') ?>
                    </small>
                    <?php endif; ?>
                </td>
                <td style="padding:0.5rem 0.75rem;">
                    <span class="badge <?= $statusCls ?>"><?= $status ?></span>
                </td>
                <td style="padding:0.5rem 0.75rem;">
                    <?php if (!$isRevoked && !$isExpired): ?>
                    <form method="POST" action="" style="display:inline;"
                          onsubmit="return confirm('Perutaanko token?');">
                        <?= $csrfField ?>
                        <input type="hidden" name="action" value="revoke">
                        <input type="hidden" name="jti" value="<?= htmlspecialchars($t['jti'], ENT_QUOTES, 'UTF-8') ?>">
                        <button type="submit" class="btn btn--sm btn--danger">Peruuta</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<script>
(function () {
  var viewType = document.getElementById('ea-view-type');
  var intervalGroup = document.getElementById('ea-interval-group');
  if (viewType && intervalGroup) {
    function toggle() {
      intervalGroup.style.display = viewType.value === 'carousel' ? '' : 'none';
    }
    viewType.addEventListener('change', toggle);
    toggle();
  }
})();
</script>

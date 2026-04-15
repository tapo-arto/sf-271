<?php
// assets/pages/worksites.php
declare(strict_types=1);

require_once __DIR__ . '/../../app/includes/protect.php';
require_once __DIR__ . '/../../app/includes/statuses.php';

$user = sf_current_user();
if (!$user || !in_array((int)$user['role_id'], [1, 3], true)) {
    http_response_code(403);
    echo "Ei käyttöoikeutta työmaiden hallintaan.";
    exit;
}

$baseUrl = rtrim($config['base_url'] ?? '', '/');

try {
    $pdo = new PDO(
        'mysql:host=' . $config['db']['host'] .
        ';dbname=' . $config['db']['name'] .
        ';charset=' . $config['db']['charset'],
        $config['db']['user'],
        $config['db']['pass'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (Throwable $e) {
    http_response_code(500);
    echo '<p>Tietokantavirhe.</p>';
    exit;
}

// Käsittele lomaketoiminnot (lisää / disabloi / aktivoi)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'add') {
        $name = trim((string)($_POST['name'] ?? ''));
        if ($name !== '') {
            $stmt = $pdo->prepare("INSERT INTO sf_worksites (name, is_active, created_at) VALUES (:name, 1, NOW())");
            $stmt->execute([':name' => $name]);
        }
    } elseif ($action === 'toggle' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        $stmt = $pdo->prepare("UPDATE sf_worksites SET is_active = 1 - is_active WHERE id = :id");
        $stmt->execute([':id' => $id]);
    }

    header('Location: ' . $baseUrl . '/index.php?page=worksites');
    exit;
}

// Hae kaikki työmaat
$stmt = $pdo->query("SELECT id, name, is_active, created_at FROM sf_worksites ORDER BY name ASC");
$worksites = $stmt->fetchAll();
?>
<div class="sf-page-container">
    <div class="sf-page-header">
        <h1 class="sf-page-title">Työmaiden hallinta</h1>
    </div>

<div class="sf-worksites-page">

    <form method="post" class="sf-form-inline">
        <input type="hidden" name="action" value="add">
        <label for="ws-name">Uusi työmaa:</label>
        <input type="text" id="ws-name" name="name" required>
        <button type="submit">Lisää</button>
    </form>

    <table class="sf-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Nimi</th>
                <th>Aktiivinen</th>
                <th>Luotu</th>
                <th>Toiminnot</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($worksites as $ws): ?>
            <tr>
                <td><?= (int)$ws['id'] ?></td>
                <td><?= htmlspecialchars($ws['name'], ENT_QUOTES, 'UTF-8') ?></td>
                <td><?= $ws['is_active'] ? 'Kyllä' : 'Ei' ?></td>
                <td><?= htmlspecialchars($ws['created_at'] ?? '', ENT_QUOTES, 'UTF-8') ?></td>
                <td>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="action" value="toggle">
                        <input type="hidden" name="id" value="<?= (int)$ws['id'] ?>">
                        <button type="submit">
                            <?= $ws['is_active'] ? 'Passivoi' : 'Aktivoi' ?>
                        </button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
</div>
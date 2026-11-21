<?php
// Version 1.1
// Admin-Seite: Spielmodi ein- und ausschalten (z. B. für die Testphase).
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/auth.php';

$user = $_SESSION['discord_user'] ?? null;
if (!$user) { header('Location: /index.php'); exit; }
if (!auth_is_admin()) {
    http_response_code(403);
    exit('Kein Zugriff. <a href="/lobby.php">Zur Lobby</a>');
}

$modes = [
    'points' => 'Punkte-Board',
    'hearts' => 'Der Dümmste Fliegt',
    'eingestuhlt' => 'Eingeschult',
    'dumm_drauf' => 'Hau drauf',
];

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($modes as $key => $label) {
        $on = isset($_POST['on'][$key]) ? 1 : 0;
        db()->prepare('INSERT INTO kr_modes (mode_key, enabled) VALUES (?, ?) ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)')
            ->execute([$key, $on]);
    }
    $msg = 'Gespeichert.';
}

$enabled = [];
foreach ($modes as $key => $label) {
    $enabled[$key] = mode_enabled($key);
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Modi verwalten – <?= htmlspecialchars(defined('APP_NAME') ? APP_NAME : 'KittysRätsel') ?></title>
    <link rel="stylesheet" href="/css/base.css?v=1.2">
    <link rel="stylesheet" href="/css/lobby.css?v=1.1">
</head>
<body>
<div class="page">
    <header class="topbar">
        <div class="brand">Modi verwalten</div>
        <a class="btn btn-ghost btn-small" href="/lobby.php">Zur Lobby</a>
    </header>

    <?php if ($msg): ?><div class="card msg" style="margin-bottom:18px;"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

    <section class="card">
        <h2>Welche Modi sind für alle sichtbar?</h2>
        <p class="muted">Ausgeschaltete Modi sieht nur der Admin, und zwar mit dem Zusatz „(Test)“.</p>
        <form method="post" class="invite-form">
            <?php foreach ($modes as $key => $label): ?>
                <label class="remember">
                    <input type="checkbox" name="on[<?= htmlspecialchars($key) ?>]" value="1" <?= $enabled[$key] ? 'checked' : '' ?>>
                    <?= htmlspecialchars($label) ?>
                </label>
            <?php endforeach; ?>
            <button type="submit" class="btn btn-block">Speichern</button>
        </form>
    </section>
</div>
</body>
</html>
<!-- Version 1.1 -->
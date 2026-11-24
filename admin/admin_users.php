<?php
// Version 1.0
// Admin-Seite: Benutzer suchen und die Rolle „Lehrer“ vergeben oder entziehen.
// Lehrer bekommen automatisch das Lehrer-Badge (nicht selbst wählbar).
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/auth.php';

$user = $_SESSION['discord_user'] ?? null;
if (!$user) { header('Location: /index.php'); exit; }
if (!auth_is_admin()) {
    http_response_code(403);
    exit('Kein Zugriff. <a href="/lobby.php">Zur Lobby</a>');
}

// Speichern (danach Weiterleitung, damit ein Neuladen nichts doppelt schreibt)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $key = (string)($_POST['user_key'] ?? '');
    $on  = isset($_POST['teacher']) ? 1 : 0;
    if ($key !== '') {
        db()->prepare('UPDATE kr_profiles SET is_teacher = ? WHERE user_key = ?')->execute([$on, $key]);
        $_SESSION['admin_users_msg'] = $on ? 'Als Lehrer gesetzt.' : 'Lehrer-Rolle entzogen.';
    }
    header('Location: /admin/admin_users.php?' . http_build_query(['q' => (string)($_POST['q'] ?? '')]));
    exit;
}

$msg = (string)($_SESSION['admin_users_msg'] ?? '');
unset($_SESSION['admin_users_msg']);

// Suche nach Anzeigename oder @-Name
$q = trim((string)($_GET['q'] ?? ''));
$sql = 'SELECT user_key, display_name, handle, is_teacher FROM kr_profiles';
$params = [];
if ($q !== '') {
    $like = '%' . addcslashes($q, '%_\\') . '%';
    $sql .= ' WHERE display_name LIKE ? OR handle LIKE ?';
    $params = [$like, $like];
}
$sql .= ' ORDER BY display_name LIMIT 100';
$st = db()->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Benutzer &amp; Lehrer – Admin</title>
    <link rel="stylesheet" href="/css/base.css?v=1.6">
    <link rel="stylesheet" href="/css/lobby.css?v=1.1">
</head>
<body>
<div class="page">
    <header class="topbar">
        <div class="brand">Benutzer &amp; Lehrer</div>
        <a class="btn btn-ghost btn-small" href="/lobby.php">Zur Lobby</a>
    </header>

    <section class="card">
        <h2>Suchen</h2>
        <form method="get" class="join-row">
            <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" placeholder="Name oder @benutzername">
            <button type="submit" class="btn btn-small">Suchen</button>
        </form>
        <?php if ($msg): ?><p class="muted" style="margin-top:10px;"><?= htmlspecialchars($msg) ?></p><?php endif; ?>
    </section>

    <section class="card">
        <h2>Benutzer</h2>
        <?php if (!$rows): ?>
            <p class="muted">Keine Benutzer gefunden.</p>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
            <form method="post" class="room" style="display:flex;gap:12px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-bottom:8px;">
                <input type="hidden" name="user_key" value="<?= htmlspecialchars((string)$r['user_key']) ?>">
                <input type="hidden" name="q" value="<?= htmlspecialchars($q) ?>">
                <div>
                    <strong><?= htmlspecialchars($r['display_name'] ?: 'Spieler') ?></strong>
                    <?php if (!empty($r['handle'])): ?><span class="muted">@<?= htmlspecialchars((string)$r['handle']) ?></span><?php endif; ?>
                </div>
                <div style="display:flex;gap:10px;align-items:center;">
                    <label><input type="checkbox" name="teacher" value="1" <?= !empty($r['is_teacher']) ? 'checked' : '' ?>> Lehrer</label>
                    <button type="submit" class="btn btn-small">Speichern</button>
                </div>
            </form>
        <?php endforeach; ?>
    </section>
</div>
</body>
</html>
<!-- Version 1.0 -->
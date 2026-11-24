<?php
// Version 1.0
// Lehrer: Gast-Link für den eigenen Raum anzeigen, kopieren und bei Bedarf erneuern.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$user = $_SESSION['discord_user'] ?? null;
if (!$user || !empty($user['guest'])) { header('Location: /index.php'); exit; }
$me = (string)$user['id'];

$st = db()->prepare('SELECT is_teacher FROM kr_profiles WHERE user_key = ?');
$st->execute([$me]);
if ((int)$st->fetchColumn() !== 1) {
    http_response_code(403);
    exit('Nur für Lehrkräfte. <a href="/lobby.php">Zur Lobby</a>');
}

$st = db()->prepare('SELECT id, code, guest_token FROM kr_rooms WHERE host_id = ? ORDER BY created_at DESC LIMIT 1');
$st->execute([$me]);
$room = $st->fetch();
if (!$room) {
    exit('Du hast noch keinen Raum. <a href="/lobby.php">Zur Lobby</a>');
}

// Link erneuern: alte Links werden ungültig
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['new'])) {
    db()->prepare('UPDATE kr_rooms SET guest_token = ? WHERE id = ?')->execute([bin2hex(random_bytes(12)), $room['id']]);
    header('Location: /gast_link.php');
    exit;
}

// Beim ersten Aufruf einen Schlüssel erzeugen
if (empty($room['guest_token'])) {
    db()->prepare('UPDATE kr_rooms SET guest_token = ? WHERE id = ?')->execute([bin2hex(random_bytes(12)), $room['id']]);
    $room['guest_token'] = db()->query('SELECT guest_token FROM kr_rooms WHERE id = ' . (int)$room['id'])->fetchColumn();
}

$link = 'https://kittysquiz.app/gast.php?code=' . urlencode((string)$room['code']) . '&t=' . urlencode((string)$room['guest_token']);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gast-Link – <?= htmlspecialchars(defined('APP_NAME') ? APP_NAME : 'KittysRätsel') ?></title>
    <link rel="stylesheet" href="/css/base.css?v=1.6">
    <link rel="stylesheet" href="/css/lobby.css?v=1.1">
</head>
<body>
<div class="page">
    <header class="topbar">
        <div class="brand">Gast-Link</div>
        <a class="btn btn-ghost btn-small" href="/lobby.php">Zur Lobby</a>
    </header>
    <section class="card" style="max-width:560px;margin:0 auto;">
        <h2>Raum <?= htmlspecialchars((string)$room['code']) ?></h2>
        <p class="muted">Schüler öffnen diesen Link, geben ihren Namen ein und sind dabei. Sie brauchen kein Konto.</p>
        <div class="join-row" style="margin-top:12px;">
            <input type="text" id="gastLink" readonly value="<?= htmlspecialchars($link) ?>">
            <button type="button" class="btn btn-small" id="copyBtn">Kopieren</button>
        </div>
        <form method="post" style="margin-top:16px;" onsubmit="return confirm('Alte Links werden dann ungültig. Wirklich neu erzeugen?');">
            <input type="hidden" name="new" value="1">
            <button type="submit" class="btn btn-ghost btn-small">Neuen Link erzeugen</button>
        </form>
    </section>
</div>
<script>
document.getElementById('copyBtn').addEventListener('click', function () {
    var v = document.getElementById('gastLink').value;
    if (navigator.clipboard) navigator.clipboard.writeText(v);
    else { var i = document.getElementById('gastLink'); i.select(); document.execCommand('copy'); }
    this.textContent = 'Kopiert';
});
</script>
</body>
</html>
<!-- Version 1.0 -->
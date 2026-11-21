<?php
// Version 2.5
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
remember_restore();
require_email();

$user = $_SESSION['discord_user'] ?? null;
if (!$user) {
    header('Location: index.php');
    exit;
}
$me = $user['id'];

// Raum erstellen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $code = strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 6));
    db()->prepare('INSERT INTO kr_rooms (code, host_id) VALUES (?, ?)')->execute([$code, $me]);
    header('Location: /modes/punkte/board.php?code=' . $code);
    exit;
}

// Räume laden, bei denen der Host dabei ist
$stmt = db()->prepare('SELECT code, created_at FROM kr_rooms WHERE host_id = ? ORDER BY created_at DESC');
$stmt->execute([$me]);
$myRooms = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lobby – <?= APP_NAME ?></title>
    <style>
        body { font-family: system-ui, sans-serif; background: #1f1438; color: #f5f0ff; margin: 0; padding: 30px; }
        a { color: #7fe7d9; text-decoration: none; }
        a:hover { text-decoration: underline; }
        h1 { margin-top: 0; }
        .panel { background: rgba(255,255,255,.06); border-radius: 14px; padding: 20px; margin-bottom: 24px; max-width: 600px; }
        .btn { padding: 10px 16px; border-radius: 8px; border: none; background: #7b2ff7; color: #fff; font-weight: bold; cursor: pointer; text-decoration: none; display: inline-block; }
        .btn.grey { background: #444; }
        .btn:hover { filter: brightness(1.1); }
        input { padding: 10px; border-radius: 8px; border: none; background: #3b2a6b; color: #fff; width: 100%; box-sizing: border-box; margin-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        th, td { padding: 10px; text-align: left; border-bottom: 1px solid rgba(255,255,255,.1); }
        .code { font-family: monospace; font-size: 1.2em; font-weight: bold; }
    </style>
</head>
<body>
    <h1>Lobby</h1>
    <div id="rejoin" style="display:none;align-items:center;gap:10px;flex-wrap:wrap;max-width:600px;margin:14px auto;padding:12px 16px;border-radius:14px;background:rgba(255,210,63,.15);border:1px solid #ffd23f;"><span id="rejoinText" style="flex:1;"></span><button id="rejoinBtn" type="button" style="padding:8px 14px;border:none;border-radius:10px;background:#ffd23f;color:#2a1b00;font-weight:bold;cursor:pointer;">Wieder beitreten</button><button id="rejoinX" type="button" title="Ausblenden" style="padding:6px 10px;border:none;border-radius:10px;background:#4a4a66;color:#fff;cursor:pointer;">×</button></div>


    <div class="panel">
        <h2>Neuen Raum erstellen</h2>
        <form method="post">
            <input type="hidden" name="action" value="create">
            <button type="submit" class="btn">Neuen Raum gründen</button>
        </form>
    </div>

    <div class="panel">
        <h2>Raum beitreten</h2>
        <form method="get" action="/modes/punkte/board.php">
            <input type="text" name="code" placeholder="Raum-Code (z.B. AB12CD)" maxlength="8" style="text-transform:uppercase;" required>
            <button type="submit" class="btn">Beitreten</button>
        </form>
    </div>

    <div class="panel">
        <h2>Deine aktiven Räume (als Host)</h2>
        <?php if (!$myRooms): ?>
            <p class="muted">Du hast noch keine Räume erstellt.</p>
        <?php else: ?>
            <table>
                <tr><th>Code</th><th>Erstellt am</th><th></th></tr>
                <?php foreach ($myRooms as $r): ?>
                    <tr>
                        <td class="code"><?= htmlspecialchars($r['code']) ?></td>
                        <td><?= date('d.m.Y H:i', strtotime($r['created_at'])) ?></td>
                        <td>
                            <a href="/modes/punkte/board.php?code=<?= htmlspecialchars($r['code']) ?>" class="btn small">Öffnen</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>

    <p><a href="/shared/fragen/fragen.php">Kategorien & Fragen verwalten</a> | <a href="verfuegbarkeit.php">Verfügbarkeit planen</a> | <a href="logout.php">Ausloggen</a></p>
<footer style="position:fixed;left:0;right:0;bottom:10px;text-align:center;font-size:14px;opacity:.8;z-index:5;"><a href="/impressum.php" style="color:#9ff0ff;">Impressum</a> · <a href="/datenschutz.php" style="color:#9ff0ff;">Datenschutz</a></footer>
<script>
(function () {
    const PAGES = {
        points: '/modes/punkte/board.php',
        hearts: '/modes/herzen/herzen.php',
        eingestuhlt: '/modes/eingestuhlt/eingestuhlt.php',
        dumm_drauf: '/modes/hau_drauf/dumm_drauf.php'
    };
    const box = document.getElementById('rejoin');
    let data = null;
    try { data = JSON.parse(localStorage.getItem('kr_last_room') || 'null'); } catch (e) {}
    if (!data || !data.code || Date.now() - data.t > 24 * 3600 * 1000) return;

    const clear = () => { localStorage.removeItem('kr_last_room'); box.style.display = 'none'; };
    document.getElementById('rejoinText').textContent = 'Du warst zuletzt in Raum ' + data.code + '.';
    document.getElementById('rejoinX').onclick = clear;
    box.style.display = 'flex';

    document.getElementById('rejoinBtn').onclick = async () => {
        try {
            const res = await fetch('/room_mode.php?code=' + encodeURIComponent(data.code), { cache: 'no-store' });
            if (!res.ok) { clear(); alert('Dieser Raum existiert nicht mehr.'); return; }
            const j = await res.json();
            location.href = (PAGES[j.mode] || PAGES.points) + '?code=' + encodeURIComponent(data.code);
        } catch (e) {
            alert('Verbindungsfehler, bitte nochmal versuchen.');
        }
    };
})();
</script>
<script src="/cookie-hinweis.js"></script>
</body>
</html>
<!-- Version 2.5 -->
<?php
// Version 2.3
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$user = $_SESSION['discord_user'] ?? null;
if (!$user) { header('Location: index.php'); exit; }

$code = strtoupper($_GET['code'] ?? $_POST['code'] ?? '');
$stmt = db()->prepare('SELECT id, code, host_id, mode FROM kr_rooms WHERE code = ?');
$stmt->execute([$code]);
$room = $stmt->fetch();
if (!$room) die('Raum nicht gefunden. <a href="lobby.php">Zurück</a>');

$isHost = $room['host_id'] === $user['id'];
$roomId = (int)$room['id'];
$currentMode = $room['mode'] ?? 'points';

// --- MODUS WECHSELN (Muss VOR der Weiterleitung stehen!) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['switch_mode'])) {
    $newMode = $_POST['switch_mode'];
    if ($isHost && in_array($newMode, ['points', 'hearts', 'eingestuhlt', 'dumm_drauf'], true)) {
        db()->prepare('UPDATE kr_rooms SET mode = ? WHERE code = ?')->execute([$newMode, $code]);
        if ($newMode === 'points') { header('Location: board.php?code=' . $code); exit; }
        elseif ($newMode === 'eingestuhlt') { header('Location: eingestuhlt.php?code=' . $code); exit; }
        elseif ($newMode === 'dumm_drauf') { header('Location: dumm_drauf.php?code=' . $code); exit; }
        else { header('Location: herzen.php?code=' . $code); exit; }
    }
}

if ($currentMode !== 'hearts') {
    if ($currentMode === 'eingestuhlt') { header('Location: eingestuhlt.php?code=' . urlencode($code)); exit; }
    elseif ($currentMode === 'dumm_drauf') { header('Location: dumm_drauf.php?code=' . urlencode($code)); exit; }
    else { header('Location: board.php?code=' . urlencode($code)); exit; }
}

db()->prepare('
    INSERT INTO kr_room_players (room_id, discord_id, username, avatar, team_id, last_seen)
    VALUES (?, ?, ?, ?, NULL, NOW())
    ON DUPLICATE KEY UPDATE username = VALUES(username), avatar = VALUES(avatar), last_seen = NOW()
')->execute([$roomId, $user['id'], $user['name'] ?? $user['username'], $user['avatar']]);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Der Dümmste Fliegt – <?= htmlspecialchars($code) ?></title>
    <style>
        body { font-family: system-ui, sans-serif; background: #1f1438; color: #f5f0ff; margin: 0; padding: 20px; }
        h1 { margin: 10px 0; }
        .panel { background: rgba(255,255,255,.06); border-radius: 14px; padding: 20px; margin-bottom: 20px; }
        .btn { padding: 10px 16px; border-radius: 8px; border: none; background: #7b2ff7; color: #fff; font-weight: bold; cursor: pointer; }
        .btn:hover { filter: brightness(1.1); }
        .btn.grey { background: #444; }
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; }
        .pcard { background: #2a1e4a; border-radius: 12px; padding: 15px; text-align: center; border: 2px solid #555; }
        .pcard.current { border-color: #ffd23f; box-shadow: 0 0 15px rgba(255, 210, 63, 0.4); }
        .pcard.out { opacity: 0.4; filter: grayscale(1); }
        .pav { width: 80px; height: 80px; border-radius: 50%; background: #111; margin: 0 auto 10px; display: block; }
        .hearts { font-size: 24px; letter-spacing: 2px; margin: 10px 0; }
        .heart-on { color: #ff3b5c; }
        .heart-off { color: #444; }
        .q-box { text-align: center; margin: 20px 0; padding: 20px; background: rgba(0,0,0,0.3); border-radius: 12px; }
        .q-text { font-size: 24px; font-weight: bold; }
    </style>
</head>
<body>
    <?php require __DIR__ . '/modus_auswahl.php'; ?>

    <h1 style="margin-top: 50px;">Der Dümmste Fliegt – Raum <?= htmlspecialchars($code) ?></h1>

    <div class="panel">
        <h2>Status</h2>
        <div id="status">Lade ...</div>
        <div id="questionBox" class="q-box" style="display:none;">
            <div class="q-text" id="qText"></div>
        </div>
        <?php if ($isHost): ?>
            <div style="margin-top: 15px;">
                <button class="btn" id="startBtn" onclick="api('start_hearts')">Spiel starten</button>
                <button class="btn grey" onclick="api('next_hearts')">Nächste Frage</button>
            </div>
        <?php endif; ?>
    </div>

    <div class="grid" id="playersGrid"></div>

    <script>
        const code = <?= json_encode($code) ?>;
        const isHost = <?= json_encode($isHost) ?>;

        async function api(action) {
            if (!isHost) return;
            const body = new URLSearchParams({ code, action });
            await fetch('board_api.php', { method: 'POST', body });
            load();
        }

        async function load() {
            try {
                const res = await fetch('overlay_herzen_api.php?code=' + encodeURIComponent(code));
                if (res.ok) {
                    const s = await res.json();
                    draw(s);
                }
            } catch (e) {}
            setTimeout(load, 2000);
        }

        function draw(s) {
            document.getElementById('status').textContent = 'Phase: ' + s.phase + ' | Runde ' + s.round;
            
            const qBox = document.getElementById('questionBox');
            if (s.question) {
                qBox.style.display = 'block';
                document.getElementById('qText').textContent = s.question;
            } else {
                qBox.style.display = 'none';
            }

            const grid = document.getElementById('playersGrid');
            grid.innerHTML = '';
            
            for (const p of s.players) {
                const card = document.createElement('div');
                card.className = 'pcard' + (p.current ? ' current' : '') + (p.out ? ' out' : '');
                
                const img = document.createElement('img');
                img.src = p.avatar;
                img.className = 'pav';
                
                const name = document.createElement('div');
                name.textContent = p.name;
                name.style.fontWeight = 'bold';
                
                const hearts = document.createElement('div');
                hearts.className = 'hearts';
                for (let i = 0; i < 3; i++) {
                    const h = document.createElement('span');
                    h.className = i < p.hearts ? 'heart-on' : 'heart-off';
                    h.textContent = '♥';
                    hearts.append(h);
                }

                card.append(img, name, hearts);
                grid.append(card);
            }
        }

        load();
    </script>
<script>
// Alle Spieler im Raum folgen dem Moduswechsel des Hosts
(function () {
    const CUR = 'hearts';
    const PAGES = { points: 'board.php', hearts: 'herzen.php', dumm_drauf: 'dumm_drauf.php', eingestuhlt: 'eingestuhlt.php' };
    const code = new URLSearchParams(location.search).get('code') || '';
    async function checkMode() {
        try {
            const res = await fetch('room_mode.php?code=' + encodeURIComponent(code), { cache: 'no-store' });
            if (res.ok) {
                const j = await res.json();
                if (j.mode && j.mode !== CUR && PAGES[j.mode]) {
                    location.href = PAGES[j.mode] + '?code=' + encodeURIComponent(code);
                    return;
                }
            }
        } catch (e) {}
        setTimeout(checkMode, 2000);
    }
    checkMode();
})();
</script>
</body>
</html>
<!-- Version 2.3 -->
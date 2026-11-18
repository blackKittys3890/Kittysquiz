<?php
// Version 1.3
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$user = $_SESSION['discord_user'] ?? null;
if (!$user) { header('Location: index.php'); exit; }

$code = strtoupper($_GET['code'] ?? $_POST['code'] ?? '');
$st = db()->prepare('SELECT id, code, host_id, mode FROM kr_rooms WHERE code = ?');
$st->execute([$code]);
$room = $st->fetch();
if (!$room) die('Raum nicht gefunden. <a href="lobby.php">Zurück</a>');
$isHost = $room['host_id'] === $user['id'];
$currentMode = $room['mode'] ?? 'points';

// --- MODUS WECHSELN (Muss VOR der Weiterleitung stehen!) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['switch_mode'])) {
    $newMode = $_POST['switch_mode'];
    if ($isHost && in_array($newMode, ['points', 'hearts', 'eingestuhlt', 'dumm_drauf'], true)) {
        db()->prepare('UPDATE kr_rooms SET mode = ? WHERE code = ?')->execute([$newMode, $code]);
        if ($newMode === 'points') { header('Location: board.php?code=' . $code); exit; }
        elseif ($newMode === 'hearts') { header('Location: herzen.php?code=' . $code); exit; }
        elseif ($newMode === 'eingestuhlt') { header('Location: eingestuhlt.php?code=' . $code); exit; }
        else { header('Location: dumm_drauf.php?code=' . $code); exit; }
    }
}

if ($currentMode !== 'dumm_drauf') {
    if ($currentMode === 'hearts') { header('Location: herzen.php?code=' . urlencode($code)); exit; }
    elseif ($currentMode === 'eingestuhlt') { header('Location: eingestuhlt.php?code=' . urlencode($code)); exit; }
    else { header('Location: board.php?code=' . urlencode($code)); exit; }
}

db()->prepare('
    INSERT INTO kr_room_players (room_id, discord_id, username, avatar, team_id, last_seen)
    VALUES (?, ?, ?, ?, NULL, NOW())
    ON DUPLICATE KEY UPDATE username = VALUES(username), avatar = VALUES(avatar), last_seen = NOW()
')->execute([(int)$room['id'], $user['id'], $user['name'] ?? $user['username'], $user['avatar']]);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dumm drauf – <?= htmlspecialchars($code) ?></title>
    <style>
        .pl .status { font-size: 14px; opacity: .8; margin-top: 2px; }
        body { margin: 0; padding: 22px; min-height: 100vh; font-family: system-ui, sans-serif; color: #fff;
            background: linear-gradient(160deg, #1d1b3a, #2c1046 60%, #14233b); }
        a { color: #9ff0ff; }
        .top { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; }
        .score { font-size: 26px; font-weight: 900; color: #ffd23f; }
        .prog { opacity: .75; font-size: 15px; }
        .card { max-width: 900px; margin: 26px auto; background: rgba(255,255,255,.07); border: 2px solid #ffd23f55;
            border-radius: 22px; padding: 28px; text-align: center; }
        .word { font-size: 56px; font-weight: 900; letter-spacing: 1px; margin: 6px 0 16px; }
        .tip { font-size: 22px; margin: 10px 0; padding: 12px 16px; border-radius: 14px; background: rgba(0,0,0,.25); }
        .tip b { color: #ffd23f; }
        .hint { opacity: .7; font-size: 15px; margin-top: 12px; }
        .sol { margin-top: 18px; font-size: 26px; font-weight: 800; color: #9ff0ff; }
        .pointe { margin-top: 12px; font-size: 20px; font-style: italic; opacity: .95; }
        .btn { padding: 12px 18px; margin: 6px; border: none; border-radius: 12px; font-weight: 900; font-size: 16px; cursor: pointer;
            background: #7b2ff7; color: #fff; }
        .btn.gold { background: #ffd23f; color: #2a1b00; }
        .btn.green { background: #2bb673; }
        .btn.grey { background: #4a4a66; }
        .btn.red { background: #ff4d6d; }
        .btn:disabled { opacity: .4; cursor: default; }
        .players { display: flex; flex-wrap: wrap; justify-content: center; gap: 14px; margin-top: 10px; }
        .pl { text-align: center; font-size: 15px; }
        .pl img { width: 58px; height: 58px; border-radius: 50%; border: 3px solid #9ff0ff; display: block; margin: 0 auto 4px; background: #222; }
        .pl.me img { border-color: #ffd23f; }
        .big { font-size: 34px; font-weight: 900; color: #ffd23f; margin: 16px 0; }
        .lobby { font-size: 22px; opacity: .85; }
        .flash { color: #ffd23f; min-height: 22px; margin-top: 8px; }
    </style>
</head>
<body>
    <?php require __DIR__ . '/modus_auswahl.php'; ?>

    <div class="top" style="margin-top: 50px;">
        <div><a href="lobby.php">← Lobby</a> · <b>Dumm drauf</b> · Raum <?= htmlspecialchars($code) ?></div>
        <div class="score" id="score"></div>
    </div>

    <div class="card" id="card"></div>

    <div class="players" id="players"></div>

    <script>
        const code = <?= json_encode($code) ?>;
        const isHost = <?= json_encode($isHost) ?>;
        let S = null;
        let lastSig = '';

        function el(tag, cls, text) { const e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; }
        function btn(label, cls, fn, disabled = false) { const b = el('button', 'btn ' + (cls || ''), label); b.type = 'button'; b.disabled = disabled; b.onclick = fn; return b; }

        const ERR = { running: 'Das Spiel läuft schon.', noterms: 'Keine Begriffe mehr übrig.', noactive: 'Gerade nicht möglich.', maxtip: 'Alle Tipps sind schon offen.', host: 'Nur der Host darf das.' };

        async function act(action, data = {}) {
            const body = new URLSearchParams({ code, action, ...data });
            const res = await fetch('dd_api.php', { method: 'POST', body, cache: 'no-store' });
            if (!res.ok) { const j = await res.json().catch(() => ({})); alert(ERR[j.error] || 'Fehler.'); }
            load();
        }

        async function load() {
            try {
                const res = await fetch('dd_api.php?code=' + encodeURIComponent(code), { cache: 'no-store' });
                if (res.ok) { S = await res.json(); draw(); }
            } catch (e) {}
            setTimeout(load, 1000);
        }

        function draw() {
            const sig = JSON.stringify(S);
            if (sig === lastSig) return;
            lastSig = sig;

            document.getElementById('score').textContent = S.score + ' Punkte';

            const card = document.getElementById('card');
            card.innerHTML = '';

            if (S.phase === 'lobby') {
                card.append(el('div', 'lobby', 'Dumm drauf wartet auf den Start.'));
                if (isHost) {
                    card.append(el('div', 'hint', 'Wie viele Begriffe sollen gespielt werden?'));
                    const sel = document.createElement('select');
                    sel.id = 'totalSel';
                    for (const n of [5, 10, 15]) sel.append(new Option(n + ' Begriffe', String(n), n === 10, n === 10));
                    card.append(sel, btn('Spiel starten', 'gold', () => act('start', { total: sel.value })));
                }
            }
            else if (S.phase === 'over') {
                card.append(el('div', 'big', 'Schluss! Ergebnis: ' + S.score + ' Punkte'));
                card.append(el('div', 'hint', 'Ihr habt ' + S.done + ' Begriffe bearbeitet.'));
                if (isHost) card.append(btn('Neues Spiel', 'gold', () => act('start', { total: 10 })));
            }
            else if (S.term) {
                card.append(el('div', 'prog', `Begriff ${S.done + (S.phase === 'play' ? 1 : 0)} von ${S.total}`));
                card.append(el('div', 'word', S.term.word));

                for (let i = 0; i < S.term.tips.length; i++) {
                    const t = el('div', 'tip');
                    t.innerHTML = '';
                    t.append(el('b', '', `Tipp ${i + 1}: `), document.createTextNode(S.term.tips[i]));
                    card.append(t);
                }

                if (S.phase === 'play') {
                    card.append(el('div', 'hint', `Wert bei richtiger Lösung jetzt: ${S.nextValue} Punkte`));
                    if (isHost) {
                        const row = el('div');
                        row.append(
                            btn(S.tipLevel >= 3 ? 'Keine Tipps mehr' : `Tipp ${S.tipLevel + 1} zeigen`, 'grey',
                                () => act('tip'), S.tipLevel >= 3),
                            btn('✓ Gelöst', 'green', () => act('solved')),
                            btn('Überspringen', 'red', () => act('skip')),
                        );
                        card.append(row);
                    } else {
                        card.append(el('div', 'hint', 'Ruft Lösungen laut in den Raum. Der Host klickt, wenn ihr es habt.'));
                    }
                }

                if (S.phase === 'reveal') {
                    card.append(el('div', 'sol', 'Auflösung: ' + S.term.solution));
                    card.append(el('div', 'pointe', '„' + S.term.pointe + '“'));
                    card.append(el('div', 'flash', S.lastResult === 'solved' ? '🎉 Gelöst!' : 'Diesmal nicht.'));
                    if (isHost) {
                        card.append(btn(S.done >= S.total ? 'Ergebnis zeigen' : 'Nächster Begriff', 'gold', () => act('next')));
                    }
                }

                if (isHost && S.phase !== 'over') {
                    card.append(el('div', 'hint', ''));
                    card.append(btn('Spiel beenden', 'grey', () => { if (confirm('Spiel beenden?')) act('reset'); }));
                }
            }

            const pl = document.getElementById('players');
            pl.innerHTML = '';
            for (const p of S.players) {
                const box = el('div', 'pl' + (p.isMe ? ' me' : ''));
                const img = el('img'); img.src = p.avatar; img.alt = '';
                const status = el('div', 'status', (p.cam ? '📹' : '🚫') + ' ' + (p.mic ? '🎤' : '🔇'));
                box.append(img, el('div', '', p.name), status);
                pl.append(box);
            }
        }

        load();
    </script>
<script>
// Alle Spieler im Raum folgen dem Moduswechsel des Hosts
(function () {
    const CUR = 'dumm_drauf';
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
<!-- Version 1.3 -->
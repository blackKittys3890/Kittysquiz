<?php
// Version 4.1
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/teams.php';

$user = $_SESSION['discord_user'] ?? null;
if (!$user) {
    header('Location: index.php');
    exit;
}

$code = strtoupper($_GET['code'] ?? '');
$stmt = db()->prepare('SELECT id, host_id FROM kr_rooms WHERE code = ?');
$stmt->execute([$code]);
$room = $stmt->fetch();
if (!$room) {
    die('Raum nicht gefunden. <a href="lobby.php">Zurück</a>');
}
$roomId = (int)$room['id'];
$isHost = $room['host_id'] === $user['id'];

ensureTeams($roomId);

// Spieler eintragen oder wieder sichtbar machen. Das Team bleibt beim erneuten Betreten erhalten.
db()->prepare('
    INSERT INTO kr_room_players (room_id, discord_id, username, avatar, team_id, last_seen)
    VALUES (?, ?, ?, ?, NULL, NOW())
    ON DUPLICATE KEY UPDATE username = VALUES(username), avatar = VALUES(avatar), last_seen = NOW()
')->execute([$roomId, $user['id'], $user['username'], $user['avatar']]);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Spielfeld <?= htmlspecialchars($code) ?> – <?= APP_NAME ?></title>
    <style>
        body { font-family: system-ui, sans-serif; background: #1f1438; color: #f5f0ff; margin: 0; padding: 30px; }
        a { color: #7fe7d9; }
        h1 { margin: 14px 0 0; }
        .muted { opacity: .6; font-size: 14px; }

        .topbar { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
        .btn {
            padding: 10px 16px; border: none; border-radius: 10px;
            background: #7b2ff7; color: #fff; font-weight: bold; cursor: pointer; text-decoration: none; display: inline-block;
        }
        .btn:hover:not(:disabled) { filter: brightness(1.1); }
        .btn:disabled { opacity: .4; cursor: default; }
        .btn.danger { background: #ff3b5c; }
        .btn.grey   { background: #444; }
        .btn.camBtn { background: #3ddbc4; color: #1f1438; }
        .btn.camBtn.active { background: #ff3ea5; color: #fff; }
        .btn.hostBtn { background: #f5b400; color: #1f1438; }
        .btn.block  { display: block; width: 100%; margin-top: 8px; box-sizing: border-box; }
        .btn.small  { padding: 6px 10px; font-size: 13px; }

        .scores { display: flex; gap: 16px; flex-wrap: wrap; margin: 16px 0 24px; }
        .score {
            flex: 1 1 180px; padding: 14px 18px; border-radius: 14px; font-weight: bold; font-size: 18px;
            color: #fff; text-shadow: 0 1px 3px rgba(0,0,0,.5); box-shadow: 0 6px 16px rgba(0,0,0,.3);
        }

        .board { display: grid; grid-template-columns: repeat(6, 1fr); gap: 10px; }
        .head {
            background: linear-gradient(180deg, #ff3b5c, #b0123a);
            border-radius: 12px; padding: 14px 8px; text-align: center; font-weight: bold;
            min-height: 52px; display: flex; align-items: center; justify-content: center;
        }
        .head.clickable { cursor: pointer; }
        .head.clickable:hover { filter: brightness(1.15); }

        .pts {
            background: #2c1d52; border-radius: 10px; padding: 18px 8px; text-align: center;
            font-style: italic; font-weight: bold; color: #c9b8ff; min-height: 58px;
            display: flex; align-items: center; justify-content: center;
        }
        .pts.clickable { cursor: pointer; background: #3b2a6b; }
        .pts.clickable:hover { filter: brightness(1.2); }
        .pts.open { outline: 3px solid #3ddbc4; }
        .pts.played { background: #120b22; color: #3ddbc4; font-style: normal; font-size: 22px; }

        .camBar { display: flex; align-items: center; gap: 14px; margin-top: 26px; }
        .strip { display: flex; gap: 12px; overflow-x: auto; padding: 8px 2px 14px; margin-top: 10px; }
        .stile {
            position: relative; flex: 0 0 220px; aspect-ratio: 4 / 3;
            background: #2c1d52; border-radius: 14px; overflow: hidden; border: 3px solid #666;
        }
        .sbadge {
            position: absolute; top: 8px; left: 8px; z-index: 2;
            padding: 3px 8px; border-radius: 7px; font-size: 11px; font-weight: bold; color: #fff;
            background: #555; max-width: calc(100% - 16px); overflow: hidden; white-space: nowrap; text-overflow: ellipsis;
        }
        .sbadge.host { background: #f5b400; color: #1f1438; }
        .scam {
            position: absolute; inset: 0; display: flex; align-items: center; justify-content: center;
            background: radial-gradient(circle at top, #3b2a6b, #1a1030);
        }
        .scam video { width: 100%; height: 100%; object-fit: cover; }
        .scam .placeholder { width: 64px; height: 64px; border-radius: 50%; opacity: .85; }
        .spill {
            position: absolute; left: 8px; right: 8px; bottom: 8px; height: 40px;
            display: flex; align-items: center; gap: 8px; padding-right: 10px;
            border-radius: 20px; background: linear-gradient(90deg, #ff3ea5, #7b2ff7);
        }
        .spill img {
            width: 42px; height: 42px; border-radius: 50%;
            border: 2px solid #3ddbc4; margin-left: -4px; flex-shrink: 0; background: #1a1030;
        }
        .spill .name {
            font-weight: bold; font-size: 14px; color: #fff;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }

        .spill .mic { margin-left: auto; font-size: 14px; flex-shrink: 0; }

        /* Modale Fenster */
        .modal {
            position: fixed; inset: 0; background: rgba(0,0,0,.6);
            display: none; align-items: center; justify-content: center; padding: 20px; z-index: 40;
        }
        .modal .box {
            background: #2c1d52; border-radius: 16px; padding: 24px;
            width: min(480px, 100%); max-height: 85vh; overflow: auto; box-sizing: border-box;
        }
        .modal h3 { margin-top: 0; }
        .modal .section { margin-top: 20px; }
        .prow {
            display: flex; align-items: center; justify-content: space-between; gap: 10px;
            padding: 8px 0; border-bottom: 1px solid rgba(255,255,255,.1);
        }
        .pbtns { display: flex; gap: 6px; flex-wrap: wrap; }
        .swatch { width: 18px; height: 18px; border-radius: 50%; display: inline-block; margin-right: 8px; flex-shrink: 0; }
        .tsel { padding: 6px 8px; border-radius: 8px; border: none; background: #3b2a6b; color: #fff; }
        .addTeam { display: flex; gap: 8px; margin-top: 12px; flex-wrap: wrap; align-items: center; }
        .addTeam input[type=text] { flex: 1; min-width: 140px; padding: 10px; border: none; border-radius: 10px; }
        .addTeam input[type=color] { width: 52px; height: 42px; border: none; background: none; padding: 0; }
        .row { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 10px; }

        /* Frage für alle */
        .question {
            position: fixed; inset: 0; background: rgba(10,5,25,.93);
            display: none; align-items: center; justify-content: center; padding: 30px; z-index: 30;
        }
        .qbox {
            background: linear-gradient(160deg, #2c1d52, #1a1030);
            border: 3px solid #3ddbc4; border-radius: 20px; padding: 36px;
            width: min(900px, 100%); text-align: center; box-sizing: border-box;
        }
        .qCategory { font-size: 18px; opacity: .85; margin-bottom: 14px; }
        .qText { font-size: 32px; font-weight: bold; white-space: pre-wrap; line-height: 1.3; }
        .qPreview { margin-top: 16px; font-size: 14px; opacity: .7; }
        .qAnswer {
            margin-top: 24px; padding: 14px; border-radius: 12px;
            background: rgba(255,210,0,.15); border: 2px dashed #f5b400;
            font-size: 20px; white-space: pre-wrap;
        }
        .qWait { margin-top: 24px; opacity: .7; font-size: 18px; }
        .qButtons { display: flex; gap: 12px; flex-wrap: wrap; justify-content: center; margin-top: 28px; }
        .qButtons .btn { min-width: 170px; }
    </style>
</head>
<body>
    <div class="topbar">
        <?php if ($isHost): ?>
            <button class="btn hostBtn" id="hostBtn" type="button">☰ Host-Menü</button>
        <?php endif; ?>
        <a href="lobby.php">← Lobby</a>
    </div>

    <h1>Spielfeld – Raum <?= htmlspecialchars($code) ?></h1>

    <div class="scores" id="scores"></div>

    <div class="board" id="board"></div>

    <div class="camBar">
        <button class="btn camBtn" id="camBtn" type="button">Kamera an</button>
        <button class="btn camBtn" id="micBtn" type="button">Mikrofon an</button>
        <span class="muted">Alle Spieler im Raum</span>
    </div>
    <div class="strip" id="strip"></div>

    <!-- Offene Frage -->
    <div class="question" id="questionOverlay">
        <div class="qbox">
            <div class="qCategory" id="qCategory"></div>
            <div class="qText" id="qText"></div>
            <div class="qPreview" id="qPreview" style="display:none">Vorschau – nur du siehst die Frage, bis du sie anzeigst.</div>
            <div class="qAnswer" id="qAnswer" style="display:none"></div>
            <div class="qButtons" id="qButtons" style="display:none"></div>
            <div class="qWait" id="qWait">Warte auf den Host …</div>
        </div>
    </div>

    <!-- Host-Menü -->
    <div class="modal" id="hostModal">
        <div class="box">
            <h3>Host-Menü</h3>

            <div class="section">
                <strong>Teams</strong>
                <div id="hostTeams"></div>
                <form class="addTeam" id="addTeamForm">
                    <input type="text" id="newTeamName" maxlength="40" placeholder="Teamname" required>
                    <input type="color" id="newTeamColor" value="#22c55e">
                    <button class="btn small" type="submit">Team hinzufügen</button>
                </form>
            </div>

            <div class="section">
                <strong>Spieler zuordnen</strong>
                <div id="hostPlayers"></div>
            </div>

            <div class="section">
                <strong>Punkte testen</strong>
                <div id="hostScores"></div>
                <div class="row"><button class="btn danger small" id="resetBtn" type="button">Punkte aller Teams auf 0</button></div>
            </div>

            <button class="btn block grey" id="hostClose" type="button">Schließen</button>
        </div>
    </div>

    <!-- Kategorie-Auswahl -->
    <div class="modal" id="picker">
        <div class="box">
            <h3 id="pickerTitle"></h3>
            <div id="pickerList"></div>
            <button class="btn block grey" id="pickerClose" type="button">Abbrechen</button>
        </div>
    </div>

    <!-- Punkte-Entscheidung beim Tausch -->
    <div class="modal" id="keepDialog">
        <div class="box">
            <h3>Punkte behalten?</h3>
            <p>Die Kategorie wird getauscht. Sollen die bisher erspielten Punkte bleiben?</p>
            <button class="btn block" id="keepYes" type="button">Punkte behalten</button>
            <button class="btn block danger" id="keepNo" type="button">Punkte zurücksetzen</button>
            <button class="btn block grey" id="keepCancel" type="button">Abbrechen</button>
        </div>
    </div>

    <script src="kr-cams.js"></script>
    <script>
        const roomCode = <?= json_encode($code) ?>;
        const myId = <?= json_encode($user['id']) ?>;
        const isHost = <?= json_encode($isHost) ?>;
        const POINTS = [100, 200, 300, 500];

        const ERRORS = {
            busy: 'Es ist gerade schon eine Frage offen.',
            played: 'Dieses Feld wurde schon gespielt.',
            slot: 'Für diese Spalte ist noch keine Kategorie gesetzt.',
            noquestion: 'In dieser Kategorie gibt es für diesen Punktwert noch keine Frage.',
            noactive: 'Es ist gerade keine Frage offen.',
            input: 'Ungültige Eingabe.',
            host: 'Nur der Host darf das.',
            host_no_team: 'Der Host hat kein Team.',
            login: 'Bitte neu einloggen.',
            room: 'Raum nicht gefunden.',
            team_name: 'Teamname: 1 bis 40 Zeichen.',
            color: 'Ungültige Farbe.',
            team_max: 'Maximal 8 Teams sind möglich.',
            team_last: 'Mindestens ein Team muss bestehen bleiben.',
        };

        const boardEl = document.getElementById('board');
        const scoresEl = document.getElementById('scores');
        const picker = document.getElementById('picker');
        const pickerTitle = document.getElementById('pickerTitle');
        const pickerList = document.getElementById('pickerList');
        const keepDialog = document.getElementById('keepDialog');
        const overlay = document.getElementById('questionOverlay');
        const qButtons = document.getElementById('qButtons');
        const stripEl = document.getElementById('strip');
        const camBtn = document.getElementById('camBtn');
        const micBtn = document.getElementById('micBtn');
        const hostModal = document.getElementById('hostModal');
        const hostTeams = document.getElementById('hostTeams');
        const hostPlayers = document.getElementById('hostPlayers');
        const hostScores = document.getElementById('hostScores');

        let state = null;
        let lastSig = '';
        let lastCamSig = '';
        let latestPlayers = [];
        let pending = null;
        let hostSig = '';

        // ---------- Hilfsfunktionen ----------

        function el(tag, cls = '', text = '') {
            const e = document.createElement(tag);
            if (cls) e.className = cls;
            if (text) e.textContent = text;
            return e;
        }

        function makeBtn(label, cls, onClick) {
            const b = el('button', cls, label);
            b.type = 'button';
            b.addEventListener('click', onClick);
            return b;
        }

        // ---------- Netzwerk ----------

        async function api(action, data = {}) {
            const body = new URLSearchParams({ code: roomCode, action, ...data });
            try {
                const res = await fetch('board_api.php', { method: 'POST', body, cache: 'no-store' });
                if (res.ok) return { ok: true };
                const json = await res.json().catch(() => ({}));
                return { ok: false, error: ERRORS[json.error] || 'Fehler beim Speichern.' };
            } catch (e) {
                return { ok: false, error: 'Netzwerkfehler.' };
            }
        }

        async function refresh() {
            try {
                const res = await fetch('board_api.php?code=' + encodeURIComponent(roomCode), { cache: 'no-store' });
                if (res.ok) {
                    state = await res.json();
                    render();
                }
            } catch (e) {
                // nächster Durchlauf versucht es erneut
            }
        }

        // ---------- Darstellung ----------

        function render() {
            const sig = JSON.stringify(state);
            if (sig === lastSig) return;
            lastSig = sig;

            renderScores();
            renderBoard();
            renderQuestion();
            maybeRenderHostMenu();
        }

        function renderScores() {
            scoresEl.innerHTML = '';
            for (const t of state.teams) {
                const card = el('div', 'score', `${t.name}: ${t.points}`);
                card.style.background = t.color;
                scoresEl.append(card);
            }
        }

        function renderBoard() {
            boardEl.innerHTML = '';

            for (let slot = 0; slot < 6; slot++) {
                const entry = state.board.find(b => b.slot === slot) || null;
                const head = el('div', 'head' + (isHost ? ' clickable' : ''),
                    entry ? entry.name : (isHost ? '+ Kategorie wählen' : '—'));
                if (isHost) head.addEventListener('click', () => openPicker(slot, entry));
                boardEl.append(head);
            }

            const playedKeys = new Set(state.played.map(p => p.slot + ':' + p.points));
            for (const pts of POINTS) {
                for (let slot = 0; slot < 6; slot++) {
                    const entry = state.board.find(b => b.slot === slot) || null;
                    const done = playedKeys.has(slot + ':' + pts);
                    const isOpen = !!state.active && state.active.slot === slot && state.active.points === pts;
                    const canOpen = isHost && entry && !done && !state.active;
                    const canUnplay = isHost && done;

                    const cell = el('div', 'pts'
                        + (done ? ' played' : '')
                        + (isOpen ? ' open' : '')
                        + (canOpen || canUnplay ? ' clickable' : ''),
                        done ? '✓' : String(pts));
                    if (canOpen) cell.addEventListener('click', () => openQuestion(slot, pts));
                    if (canUnplay) {
                        cell.title = 'Klicken, um das Feld für eine Wiederholung freizugeben';
                        cell.addEventListener('click', () => unplayField(slot, pts));
                    }
                    boardEl.append(cell);
                }
            }
        }

        function renderQuestion() {
            const a = state.active;

            // Nicht-Hosts sehen die Frage erst, wenn der Host sie anzeigt
            if (!a || (!isHost && !a.revealed)) {
                overlay.style.display = 'none';
                return;
            }

            overlay.style.display = 'flex';
            document.getElementById('qCategory').textContent = `${a.category} · ${a.points} Punkte`;
            document.getElementById('qText').textContent = a.question || '';

            const ans = document.getElementById('qAnswer');
            if (isHost && a.answer) {
                ans.style.display = '';
                ans.textContent = 'Antwort: ' + a.answer;
            } else {
                ans.style.display = 'none';
            }

            const showPreview = isHost && !a.revealed;
            document.getElementById('qPreview').style.display = showPreview ? '' : 'none';
            document.getElementById('qWait').style.display = isHost ? 'none' : '';

            qButtons.innerHTML = '';
            if (isHost) {
                qButtons.style.display = 'flex';
                if (!a.revealed) {
                    qButtons.append(makeBtn('Für alle anzeigen', 'btn', revealQuestion));
                } else {
                    for (const t of state.teams) {
                        const b = makeBtn(`${t.name} war richtig`, 'btn', () => resolveQuestion(t.id));
                        b.style.background = t.color;
                        qButtons.append(b);
                    }
                    qButtons.append(makeBtn('Niemand', 'btn grey', () => resolveQuestion(0)));
                }
                qButtons.append(makeBtn('Abbrechen', 'btn grey', cancelQuestion));
            } else {
                qButtons.style.display = 'none';
            }
        }

        // ---------- Frage öffnen, anzeigen und auflösen (Host) ----------

        async function openQuestion(slot, points) {
            const r = await api('open_question', { slot, points });
            if (!r.ok) alert(r.error);
            refresh();
        }

        async function unplayField(slot, points) {
            if (!confirm(`Feld ${points} wieder freigeben? Die Frage kann dann erneut gespielt werden.`)) return;
            const r = await api('unplay', { slot, points });
            if (!r.ok) alert(r.error);
            refresh();
        }

        async function revealQuestion() {
            const r = await api('reveal');
            if (!r.ok) alert(r.error);
            refresh();
        }

        async function resolveQuestion(teamId) {
            const r = await api('resolve', { team_id: teamId });
            if (!r.ok) alert(r.error);
            refresh();
        }

        async function cancelQuestion() {
            const r = await api('cancel_question');
            if (!r.ok) alert(r.error);
            refresh();
        }

        // ---------- Kategorie wechseln (Host) ----------

        function openPicker(slot, entry) {
            pickerTitle.textContent = 'Kategorie für Spalte ' + (slot + 1);
            pickerList.innerHTML = '';

            if (state.categories.length === 0) {
                pickerList.innerHTML = '<p>Noch keine Kategorien vorhanden. Lege sie zuerst unter fragen.php an.</p>';
            }
            for (const c of state.categories) {
                const b = makeBtn(c.name + (entry && entry.category_id === c.id ? ' (aktuell)' : ''), 'btn block',
                    () => pick(slot, c.id, entry));
                pickerList.append(b);
            }
            picker.style.display = 'flex';
        }

        function closePicker() {
            picker.style.display = 'none';
        }

        function pick(slot, catId, entry) {
            closePicker();
            if (entry && entry.category_id === catId) return;

            if (!entry) {
                setSlot(slot, catId, true);
                return;
            }
            pending = { slot, catId };
            keepDialog.style.display = 'flex';
        }

        async function setSlot(slot, catId, keep) {
            const r = await api('set_slot', { slot, category_id: catId, keep_points: keep ? '1' : '0' });
            if (!r.ok) alert(r.error);
            refresh();
        }

        function finishPending(keep) {
            keepDialog.style.display = 'none';
            if (pending) setSlot(pending.slot, pending.catId, keep);
            pending = null;
        }

        document.getElementById('pickerClose').addEventListener('click', closePicker);
        document.getElementById('keepYes').addEventListener('click', () => finishPending(true));
        document.getElementById('keepNo').addEventListener('click', () => finishPending(false));
        document.getElementById('keepCancel').addEventListener('click', () => {
            pending = null;
            keepDialog.style.display = 'none';
        });

        // ---------- Host-Menü ----------

        function openHostMenu() {
            hostModal.style.display = 'flex';
            hostSig = '';
            maybeRenderHostMenu();
        }

        // Nur neu aufbauen, wenn sich Teams, Punkte oder Spieler geändert haben
        function maybeRenderHostMenu() {
            if (!isHost || hostModal.style.display !== 'flex' || !state) return;
            const sig = JSON.stringify([state.teams, latestPlayers.map(p => [p.discord_id, p.username, p.team_id])]);
            if (sig === hostSig) return;
            hostSig = sig;
            renderHostTeams();
            renderHostPlayers();
            renderHostScores();
        }

        function renderHostTeams() {
            hostTeams.innerHTML = '';
            for (const t of state.teams) {
                const row = el('div', 'prow');
                const left = el('span', '');
                left.style.display = 'flex';
                left.style.alignItems = 'center';
                const sw = el('span', 'swatch');
                sw.style.background = t.color;
                left.append(sw, el('span', '', t.name));
                const del = makeBtn('Löschen', 'btn danger small', () => deleteTeam(t.id, t.name));
                del.disabled = state.teams.length <= 1;
                row.append(left, del);
                hostTeams.append(row);
            }
        }

        function renderHostPlayers() {
            hostPlayers.innerHTML = '';
            const others = latestPlayers.filter(p => !p.is_host);
            if (others.length === 0) {
                hostPlayers.append(el('p', 'muted', 'Noch keine Mitspieler.'));
                return;
            }

            for (const p of others) {
                const row = el('div', 'prow');
                row.append(el('span', '', p.username));

                const sel = document.createElement('select');
                sel.className = 'tsel';
                sel.append(new Option('Kein Team', '0'));
                for (const t of state.teams) {
                    sel.append(new Option(t.name, String(t.id)));
                }
                sel.value = p.team_id ? String(p.team_id) : '0';
                sel.addEventListener('change', () => setTeam(p.discord_id, Number(sel.value)));

                row.append(sel);
                hostPlayers.append(row);
            }
        }

        function renderHostScores() {
            hostScores.innerHTML = '';
            for (const t of state.teams) {
                const row = el('div', 'prow');
                const name = el('span', '', `${t.name} (${t.points})`);
                const btns = el('div', 'pbtns');
                for (const d of [-100, 100, 200, 300]) {
                    btns.append(makeBtn((d > 0 ? '+' : '') + d, 'btn small', async () => {
                        await api('add_points', { team_id: t.id, delta: d });
                        hostSig = '';
                        refresh();
                    }));
                }
                row.append(name, btns);
                hostScores.append(row);
            }
        }

        async function setTeam(discordId, teamId) {
            try {
                const res = await fetch('team.php', {
                    method: 'POST',
                    body: new URLSearchParams({ code: roomCode, discord_id: discordId, team_id: String(teamId) }),
                });
                if (!res.ok) {
                    alert('Team konnte nicht geändert werden.');
                    return;
                }
            } catch (e) {
                alert('Netzwerkfehler.');
                return;
            }
            // Sofort anzeigen, ohne auf den nächsten Abruf zu warten
            const p = latestPlayers.find(x => x.discord_id === discordId);
            if (p) {
                const t = state.teams.find(x => x.id === teamId);
                p.team_id = teamId || null;
                p.team_name = t ? t.name : null;
                p.team_color = t ? t.color : null;
            }
            lastCamSig = '';
            renderCams(latestPlayers);
        }

        async function deleteTeam(teamId, name) {
            if (!confirm(`Team "${name}" löschen? Seine Mitglieder landen ohne Team.`)) return;
            const r = await api('delete_team', { team_id: teamId });
            if (!r.ok) alert(r.error);
            hostSig = '';
            refresh();
        }

        if (isHost) {
            document.getElementById('hostBtn').addEventListener('click', openHostMenu);
            document.getElementById('hostClose').addEventListener('click', () => {
                hostModal.style.display = 'none';
            });

            document.getElementById('addTeamForm').addEventListener('submit', async (e) => {
                e.preventDefault();
                const name = document.getElementById('newTeamName').value.trim();
                const color = document.getElementById('newTeamColor').value;
                const r = await api('add_team', { name, color });
                if (!r.ok) {
                    alert(r.error);
                } else {
                    document.getElementById('newTeamName').value = '';
                }
                hostSig = '';
                refresh();
            });

            document.getElementById('resetBtn').addEventListener('click', async () => {
                if (!confirm('Punkte aller Teams auf 0 setzen?')) return;
                await api('reset_scores');
                hostSig = '';
                refresh();
            });
        }

        // ---------- Kameraleiste ----------

        function buildStripTile(p) {
            const isMe = p.discord_id === myId;
            const tile = el('div', 'stile');

            const badge = el('span', 'sbadge');
            if (p.is_host) {
                badge.classList.add('host');
                badge.textContent = '👑 Host';
                tile.style.borderColor = '#f5b400';
            } else if (p.team_id) {
                badge.textContent = p.team_name || 'Team';
                badge.style.background = p.team_color || '#555';
                tile.style.borderColor = p.team_color || '#666';
            } else {
                badge.textContent = 'KEIN TEAM';
                tile.style.borderColor = '#666';
            }
            tile.append(badge);

            const cam = el('div', 'scam');
            const video = KRCams.createVideo(p.discord_id, isMe);
            if (video) {
                cam.append(video);
            } else {
                const img = el('img', 'placeholder');
                img.src = p.avatar_url;
                img.alt = '';
                cam.append(img);
            }

            const pill = el('div', 'spill');
            const avatar = el('img');
            avatar.src = p.avatar_url;
            avatar.alt = '';
            const mic = el('span', 'mic', KRCams.isMicOn(p.discord_id) ? '🎤' : '🔇');
            pill.append(avatar, el('span', 'name', p.username), mic);

            tile.append(cam, pill);
            return tile;
        }

        function renderCams(players) {
            latestPlayers = players;
            maybeRenderHostMenu();

            const sig = JSON.stringify(players.map(p => [
                p.discord_id, p.username, p.avatar_url, p.team_id, p.team_name, p.team_color, p.is_host,
                KRCams.showsVideo(p.discord_id), KRCams.isMicOn(p.discord_id),
                KRCams.trackFor(p.discord_id)?.id || '',
            ]));
            if (sig === lastCamSig) return;
            lastCamSig = sig;

            stripEl.innerHTML = '';
            players.forEach(p => stripEl.append(buildStripTile(p)));
        }

        camBtn.addEventListener('click', async () => {
            try {
                const on = await KRCams.toggle();
                camBtn.textContent = on ? 'Kamera aus' : 'Kamera an';
                camBtn.classList.toggle('active', on);
            } catch (e) {
                alert('Kamera konnte nicht gestartet werden: ' + e.message);
            }
        });

        function updateMicBtn() {
            const s = KRCams.micState();
            micBtn.textContent = !s.started ? 'Mikrofon an' : (s.enabled ? '🎤 Stumm schalten' : '🔇 Ton einschalten');
            micBtn.classList.toggle('active', s.started && !s.enabled);
        }

        micBtn.addEventListener('click', async () => {
            try {
                await KRCams.toggleMic();
                updateMicBtn();
            } catch (e) {
                alert('Mikrofon konnte nicht gestartet werden: ' + e.message);
            }
        });

        KRCams.init({
            code: roomCode,
            myId: myId,
            onUpdate: renderCams,
        });

        refresh();
        setInterval(refresh, 2000);
    </script>
</body>
</html>
<!-- Version 4.1 -->

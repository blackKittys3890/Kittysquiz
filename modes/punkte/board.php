<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
// Version 8.13
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/db.php';
require_once dirname(__DIR__, 2) . '/auth.php';
require_once __DIR__ . '/teams.php';

$user = $_SESSION['discord_user'] ?? null;
if (!$user) { header('Location: /index.php'); exit; }

$code = strtoupper($_GET['code'] ?? $_POST['code'] ?? '');
$stmt = db()->prepare('SELECT id, code, host_id, mode FROM kr_rooms WHERE code = ?');
$stmt->execute([$code]);
$room = $stmt->fetch();
if (!$room) die('Raum nicht gefunden. <a href="/lobby.php">Zurück</a>');
$roomId = (int)$room['id'];
$isHost = $room['host_id'] === $user['id'];
$currentMode = $room['mode'] ?? 'points';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['switch_mode'])) {
    $newMode = $_POST['switch_mode'];
    if ($isHost && in_array($newMode, ['points', 'hearts', 'eingestuhlt', 'dumm_drauf'], true) && mode_selectable($newMode)) {
        db()->prepare('UPDATE kr_rooms SET mode = ? WHERE code = ?')->execute([$newMode, $code]);
        if ($newMode === 'hearts') { header('Location: /modes/herzen/herzen.php?code=' . $code); exit; }
        elseif ($newMode === 'eingestuhlt') { header('Location: /modes/eingestuhlt/eingestuhlt.php?code=' . $code); exit; }
        elseif ($newMode === 'dumm_drauf') { header('Location: /modes/hau_drauf/dumm_drauf.php?code=' . $code); exit; }
        else { header('Location: /modes/punkte/board.php?code=' . $code); exit; }
    }
}

if ($currentMode === 'hearts') { header('Location: /modes/herzen/herzen.php?code=' . urlencode($code)); exit; }
elseif ($currentMode === 'eingestuhlt') { header('Location: /modes/eingestuhlt/eingestuhlt.php?code=' . urlencode($code)); exit; }
elseif ($currentMode === 'dumm_drauf') { header('Location: /modes/hau_drauf/dumm_drauf.php?code=' . urlencode($code)); exit; }

ensureTeams($roomId);

db()->prepare('
    INSERT INTO kr_room_players (room_id, discord_id, username, avatar, premium, decoration, team_id, last_seen)
    VALUES (?, ?, ?, ?, ?, ?, NULL, NOW())
    ON DUPLICATE KEY UPDATE username = VALUES(username), avatar = VALUES(avatar), premium = VALUES(premium), decoration = VALUES(decoration), last_seen = NOW()
')->execute([
    $roomId, $user['id'], $user['name'] ?? $user['username'], $user['avatar'],
    $user['premium'] ?? 0, $user['decoration'] ?? null
]);
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
        .btn.grey { background: #444; }
        .btn.camBtn { background: #3ddbc4; color: #1f1438; }
        .btn.camBtn.active { background: #ff3ea5; color: #fff; }
        .btn.hostBtn { background: #f5b400; color: #1f1438; }
        .btn.block { display: block; width: 100%; margin-top: 8px; box-sizing: border-box; }
        .btn.small { padding: 6px 10px; font-size: 13px; }
        .btn.buzz { background: #ff3ea5; font-size: 22px; padding: 18px 40px; min-width: 260px; }
        .btn.sel { outline: 3px solid #fff; }
        select.btn { background: #3b2a6b; }
        .buzzInfo { font-size: 22px; font-weight: bold; color: #f5d000; width: 100%; text-align: center; }

        .turnBar { display: none; margin-top: 14px; padding: 10px 16px; border-radius: 12px; font-weight: bold; color: #fff; text-align: center; text-shadow: 0 1px 3px rgba(0,0,0,.5); }
        .boardInfo { margin-top: 14px; font-size: 16px; opacity: .85; text-align: center; }
        .boardInfo b { color: #f5d000; }

        .boardWrap { width: 100%; margin-top: 10px; }
        .board { display: grid; grid-template-columns: repeat(6, 1fr); gap: 10px; }
        .head { background: linear-gradient(180deg, #ff3b5c, #b0123a); border-radius: 12px; padding: 14px 8px; text-align: center; font-weight: bold; min-height: 52px; display: flex; align-items: center; justify-content: center; }
        .head.clickable { cursor: pointer; }
        .head.clickable:hover { filter: brightness(1.15); }

        .pts { background: #2c1d52; border-radius: 10px; padding: 16px 8px; text-align: center; font-style: italic; font-weight: bold; color: #c9b8ff; min-height: 56px; display: flex; align-items: center; justify-content: center; cursor: pointer; }
        .pts.clickable:hover { filter: brightness(1.2); }
        .pts.open { outline: 3px solid #3ddbc4; }
        .pts.played { background: #120b22; color: #3ddbc4; font-style: normal; font-size: 22px; cursor: default; }
        .pts.error-missing { background: #7a1f2c !important; color: #fff; font-style: normal; font-size: 13px; }

        .camBar { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; margin-top: 22px; }
        .lkStatus { font-size: 14px; opacity: .8; }

        .arena { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 8px; margin-top: 16px; }
        .pcard { position: relative; aspect-ratio: 16 / 9; border-radius: 14px; overflow: hidden; border: 3px solid #666; background: #1a1030; cursor: pointer; }
        .pcam { position: absolute; inset: 0; display: flex; align-items: center; justify-content: center; background: radial-gradient(circle at top, #3b2a6b, #120b22); }
        .pcam video { width: 100%; height: 100%; object-fit: cover; }
        .pph { width: 42%; aspect-ratio: 1; border-radius: 50%; opacity: .9; }

        .pscore { position: absolute; top: 8px; left: 8px; z-index: 2; padding: 3px 10px; border-radius: 12px; font-weight: bold; font-size: 13px; color: #fff; text-shadow: 0 1px 2px rgba(0,0,0,.5); }
        .pscoreHost { background: #f5b400; color: #1f1438; text-shadow: none; }
        .pmic { position: absolute; top: 8px; right: 8px; z-index: 2; width: 28px; height: 28px; border-radius: 50%; background: rgba(0,0,0,.45); display: flex; align-items: center; justify-content: center; font-size: 15px; }
        .pstate { position: absolute; bottom: 14px; left: 8px; z-index: 2; width: 14px; height: 14px; border-radius: 50%; }
        .pstate.on { background: #38d26b; box-shadow: 0 0 6px #38d26b; }
        .pstate.off { background: #f5a623; color: #1f1438; font-size: 10px; line-height: 14px; text-align: center; font-weight: bold; }

        .pname { position: absolute; bottom: 8px; left: 50%; transform: translateX(-50%); z-index: 2; display: flex; align-items: center; gap: 6px; padding: 3px 12px 3px 3px; border-radius: 20px; color: #fff; font-weight: bold; font-size: 13px; max-width: 90%; box-sizing: border-box; }
        .pavwrap { position: relative; width: 22px; height: 22px; flex-shrink: 0; }
        .pav { width: 22px; height: 22px; border-radius: 50%; background: #1a1030; display: block; border: 1px solid rgba(255,255,255,.6); box-sizing: border-box; }
        .pdeco { position: absolute; left: -17%; top: -17%; width: 134%; height: 134%; pointer-events: none; }
        .pnametext { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; }

        .zoom { position: fixed; inset: 0; background: rgba(0,0,0,.85); display: none; flex-direction: column; align-items: center; justify-content: center; gap: 16px; padding: 20px; z-index: 50; box-sizing: border-box; }
        .zoomBox { width: min(92vw, 960px); }
        .zoomBox .pcard { width: 100%; cursor: default; }
        .zoomBox .pph { width: 30%; }
        .zoomBox .pname { font-size: 18px; }
        .zoomBox .pav { width: 36px; height: 36px; }
        .zoomBox .pavwrap { width: 36px; height: 36px; }

        .modal { position: fixed; inset: 0; background: rgba(0,0,0,.6); display: none; align-items: center; justify-content: center; padding: 20px; z-index: 40; }
        .modal .box { background: #2c1d52; border-radius: 16px; padding: 24px; width: min(480px, 100%); max-height: 85vh; overflow: auto; box-sizing: border-box; }
        .modal h3 { margin-top: 0; }
        .modal .section { margin-top: 20px; }
        .prow { display: flex; align-items: center; justify-content: space-between; gap: 10px; padding: 8px 0; border-bottom: 1px solid rgba(255,255,255,.1); }
        .swatch { width: 18px; height: 18px; border-radius: 50%; display: inline-block; margin-right: 8px; flex-shrink: 0; }
        .tsel { padding: 6px 8px; border-radius: 8px; border: none; background: #3b2a6b; color: #fff; }
        .addTeam { display: flex; gap: 8px; margin-top: 12px; flex-wrap: wrap; align-items: center; }
        .addTeam input[type=text] { flex: 1; min-width: 140px; padding: 10px; border: none; border-radius: 10px; }
        .addTeam input[type=color] { width: 52px; height: 42px; border: none; background: none; padding: 0; }
        .row { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 10px; align-items: center; }

        .question { position: fixed; inset: 0; background: rgba(10,5,25,.75); pointer-events: none; display: none; align-items: center; justify-content: center; padding: 30px; z-index: 30; }
        .qbox { pointer-events: auto; background: linear-gradient(160deg, #2c1d52, #1a1030); border: 3px solid #3ddbc4; border-radius: 20px; padding: 36px; width: min(900px, 100%); text-align: center; box-sizing: border-box; }
        .qCategory { font-size: 18px; opacity: .85; margin-bottom: 14px; }
        .qText { font-size: 32px; font-weight: bold; white-space: pre-wrap; line-height: 1.3; }

        .qImage { display: block; margin: 16px auto; max-width: 100%; max-height: 300px; border-radius: 12px; object-fit: contain; cursor: zoom-in; transition: transform 0.2s; }
        .qImage:hover { transform: scale(1.02); }

        .qPreview { margin-top: 16px; font-size: 14px; opacity: .7; }
        .qAnswer { margin-top: 24px; padding: 14px; border-radius: 12px; background: rgba(255,210,0,.15); border: 2px dashed #f5b400; font-size: 20px; white-space: pre-wrap; }
        .qWait { margin-top: 24px; opacity: .7; font-size: 18px; }
        .qButtons { display: flex; gap: 12px; flex-wrap: wrap; justify-content: center; margin-top: 28px; }
        .qButtons .btn { min-width: 170px; }
        .resolve { width: 100%; display: flex; flex-direction: column; gap: 8px; align-items: center; }
        .rlabel { font-weight: bold; opacity: .85; margin-top: 6px; }

        .image-zoom { position: fixed; inset: 0; background: rgba(0,0,0,.95); display: none; align-items: center; justify-content: center; z-index: 100; cursor: zoom-out; padding: 20px; box-sizing: border-box; }
        .image-zoom img { max-width: 100%; max-height: 100%; border-radius: 8px; object-fit: contain; box-shadow: 0 0 40px rgba(255,255,255,0.1); }

        @media (max-width: 700px) {
            body { padding: 14px; }
            h1 { font-size: 20px; }
            .topbar { gap: 10px; margin-top: 50px; }
            .boardWrap { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px -14px 0; padding: 0 14px 8px; }
            .board { grid-template-columns: repeat(6, minmax(120px, 1fr)); gap: 6px; min-width: 760px; }
            .head { font-size: 13px; padding: 10px 6px; min-height: 44px; }
            .pts { font-size: 16px; min-height: 44px; padding: 10px 4px; }
            .pts.played { font-size: 18px; }
            .camBar { gap: 8px; }
            .camBar .btn { flex: 1 1 auto; padding: 10px 12px; font-size: 14px; }
            .arena { grid-template-columns: repeat(2, 1fr); gap: 6px; }
            .pname { font-size: 11px; }
            .modal .box { padding: 16px; }
            .qbox { padding: 20px; }
            .qText { font-size: 22px; }
            .qAnswer { font-size: 16px; }
            .qButtons .btn { min-width: 0; flex: 1 1 45%; }
        }
        .pcard.speaking { box-shadow: 0 0 0 3px #38d26b, 0 0 18px #38d26b; border-color: #38d26b !important; }
        .pcard { transition: box-shadow .12s ease, border-color .12s ease; }
        .volRow { display: flex; gap: 10px; align-items: center; justify-content: center; margin-top: 10px; color: #fff; }
        .volRow input { width: min(360px, 80%); }
        .btn.active { outline: 3px solid #fff; }
    </style>
</head>
<body>
    <?php require dirname(__DIR__, 2) . '/modus_auswahl.php'; ?>

    <div class="topbar" style="margin-top: 50px;">
        <?php if ($isHost): ?>
            <button class="btn hostBtn" id="hostBtn" type="button">☰ Host-Menü</button>
            <button class="btn grey" id="undoBtn" type="button" style="display:none">↶ Rückgängig</button>
            <select id="roundSel" class="btn">
                <option value="1">Board 1</option>
                <option value="2">Board 2 (höhere Punkte)</option>
            </select>
        <?php endif; ?>
        <a href="/lobby.php">← Lobby</a>
    </div>

    <h1>Spielfeld – Raum <?= htmlspecialchars($code) ?></h1>

    <div class="turnBar" id="turnBar"></div>
    <div class="boardInfo" id="boardInfo"></div>

    <div class="boardWrap"><div class="board" id="board"></div></div>

    <div class="camBar">
        <button class="btn camBtn" id="camBtn" type="button">Kamera an</button>
        <button class="btn camBtn" id="micBtn" type="button">Mikrofon an</button>
        <button class="btn camBtn" id="fullBtn" type="button">Full Mute</button>
        <button class="btn camBtn" id="shareAudioBtn" type="button" style="display:none">🔊 Song für alle teilen</button>
        <select id="shareSrc" title="Audioquelle" style="display:none;max-width:260px;padding:6px;border-radius:8px;background:#3b2a6b;color:#fff;border:none;">
            <option value="">Tab-Audio (Browser-Fenster wählen)</option>
        </select>
        <span class="lkStatus" id="lkStatus"></span>
    </div>

    <div class="arena" id="arena"></div>

    <div class="zoom" id="zoom">
        <div class="zoomBox" id="zoomBox"></div>
        <button class="btn grey" id="zoomClose" type="button">✕ Schließen</button>
    </div>

    <div class="question" id="questionOverlay">
        <div class="qbox">
            <div class="qCategory" id="qCategory"></div>
            <div class="qText" id="qText"></div>
            <img id="qImage" class="qImage" style="display:none" alt="Frage Bild" onclick="openImageZoom(this.src)">
            <div class="qPreview" id="qPreview" style="display:none">Vorschau – nur du siehst die Frage, bis du sie anzeigst.</div>
            <div class="qAnswer" id="qAnswer" style="display:none"></div>
            <div class="qButtons" id="qButtons" style="display:none"></div>
            <div class="qWait" id="qWait">Warte auf den Host …</div>
        </div>
    </div>

    <div class="image-zoom" id="imageZoom" onclick="closeImageZoom()">
        <img id="imageZoomSrc" src="" alt="Großansicht">
    </div>

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
                <strong>Punkte korrigieren</strong>
                <div id="hostScores"></div>
                <div class="row"><button class="btn danger small" id="resetBtn" type="button">Punkte aller Teams auf 0</button></div>
                <div class="row"><button class="btn small" id="podiumBtn" type="button">🏆 Podium zeigen</button></div>
            </div>
            <button class="btn block grey" id="hostClose" type="button">Schließen</button>
        </div>
    </div>

    <div class="modal" id="picker">
        <div class="box">
            <h3 id="pickerTitle"></h3>
            <div id="pickerList"></div>
            <button class="btn block grey" id="pickerClose" type="button">Abbrechen</button>
        </div>
    </div>

    <div class="modal" id="keepDialog">
        <div class="box">
            <h3>Punkte behalten?</h3>
            <p>Die Kategorie wird getauscht. Sollen die bisher erspielten Punkte bleiben?</p>
            <button class="btn block" id="keepYes" type="button">Punkte behalten</button>
            <button class="btn block danger" id="keepNo" type="button">Punkte zurücksetzen</button>
            <button class="btn block grey" id="keepCancel" type="button">Abbrechen</button>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/livekit-client/dist/livekit-client.umd.js"></script>
    <script>
        const roomCode = <?= json_encode($code) ?>;
        const myId = <?= json_encode($user['id']) ?>;
        const myName = <?= json_encode($user['name'] ?? $user['username']) ?>;
        const isHost = <?= json_encode($isHost) ?>;

        const POINTS_BOARD_1 = [100, 200, 300, 500];
        const POINTS_BOARD_2 = [200, 400, 600, 1000];

        const LK = window.LivekitClient;
        const IS_MOBILE = /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent);

        const ERRORS = {
            busy: 'Es ist gerade schon eine Frage offen.',
            played: 'Dieses Feld wurde schon gespielt.',
            slot: 'Für diese Spalte ist noch keine Kategorie gesetzt.',
            category_used: 'Diese Kategorie liegt schon in der anderen Runde. Bitte eine andere wählen.',
            noquestion: 'FEHLER: In dieser Kategorie fehlt eine Frage für diesen Punktwert! Bitte in fragen.php anlegen.',
            noactive: 'Es ist gerade keine Frage offen.',
            input: 'Ungültige Eingabe.',
            host: 'Nur der Host darf das.',
            turn: 'Nur das Team, das gerade dran ist, darf die Frage wählen.',
            login: 'Bitte neu einloggen.',
            room: 'Raum nicht gefunden.',
            team_name: 'Teamname: 1 bis 40 Zeichen.',
            color: 'Ungültige Farbe.',
            team_max: 'Maximal 8 Teams sind möglich.',
            team_last: 'Mindestens ein Team muss bestehen bleiben.',
            no_joker: 'Dieses Team hat seinen Joker bereits verbraucht.',
        };

        const boardEl = document.getElementById('board');
        const boardInfo = document.getElementById('boardInfo');
        const turnBar = document.getElementById('turnBar');
        const picker = document.getElementById('picker');
        const pickerTitle = document.getElementById('pickerTitle');
        const pickerList = document.getElementById('pickerList');
        const keepDialog = document.getElementById('keepDialog');
        const overlay = document.getElementById('questionOverlay');
        const qButtons = document.getElementById('qButtons');
        const arena = document.getElementById('arena');
        const zoom = document.getElementById('zoom');
        const zoomBox = document.getElementById('zoomBox');
        const camBtn = document.getElementById('camBtn');
        const micBtn = document.getElementById('micBtn');
        const lkStatus = document.getElementById('lkStatus');
        const hostModal = document.getElementById('hostModal');
        const hostTeams = document.getElementById('hostTeams');
        const hostPlayers = document.getElementById('hostPlayers');
        const hostScores = document.getElementById('hostScores');
        const shareAudioBtn = document.getElementById('shareAudioBtn');
        const fullBtn = document.getElementById('fullBtn');

        if (isHost && shareAudioBtn) shareAudioBtn.style.display = '';
        async function fillShareSources() {
            const sel = document.getElementById('shareSrc');
            if (!sel || !navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) return;
            const cur = sel.value;
            const devs = (await navigator.mediaDevices.enumerateDevices()).filter(d => d.kind === 'audioinput');
            sel.innerHTML = '';
            sel.append(new Option('Tab-Audio (Browser-Fenster wählen)', ''));
            for (const d of devs) sel.append(new Option(d.label || 'Audiogerät', d.deviceId));
            sel.value = cur;
        }
        if (isHost && document.getElementById('shareSrc')) {
            document.getElementById('shareSrc').style.display = '';
            document.getElementById('shareSrc').addEventListener('focus', fillShareSources);
            fillShareSources();
        }

        let state = null;
        let players = [];
        let lastCamSig = '';
        let pending = null;
        let hostSig = '';
        let zoomId = null;

        let buzzKey = '';
        let buzzWinner = null;
        let resolveMain = null;
        let resolveSteal = 'none';
        let resolveStealTeam = null;

        function openImageZoom(src) {
            if (!src) return;
            document.getElementById('imageZoomSrc').src = src;
            document.getElementById('imageZoom').style.display = 'flex';
        }
        function closeImageZoom() {
            document.getElementById('imageZoom').style.display = 'none';
            document.getElementById('imageZoomSrc').src = '';
        }

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
        function choiceBtn(label, cls, selected, onClick) {
            return makeBtn(label, cls + (selected ? ' sel' : ''), onClick);
        }
        function rememberFlag(name, on) {
            try { if (on) sessionStorage.setItem(name, '1'); else sessionStorage.removeItem(name); } catch (e) {}
        }
        function wasFlagOn(name) { try { return sessionStorage.getItem(name) === '1'; } catch (e) { return false; } }

        // ---- API: Host-Aktionen senden zusätzlich ein "refresh"-Signal an alle ----
        async function api(action, data = {}) {
            const body = new URLSearchParams({ code: roomCode, action, ...data });
            try {
                const res = await fetch('/modes/punkte/board_api.php', { method: 'POST', body, cache: 'no-store' });
                if (res.ok) return { ok: true };
                const json = await res.json().catch(() => ({}));
                return { ok: false, error: ERRORS[json.error] || json.fatal || 'Fehler beim Speichern.', code: json.error, raw: json };
            } catch (e) {
                return { ok: false, error: 'Netzwerkfehler.' };
            }
        }

        // Broadcast an ALLE (LiveKit DataReceived)
        function broadcast(payload) {
            if (!room) return;
            try {
                room.localParticipant.publishData(
                    new TextEncoder().encode(JSON.stringify(payload)),
                    { reliable: true }
                ).catch(() => {});
            } catch (e) {}
        }

        // Triggert einen Refresh bei allen anderen Clients
        function broadcastRefresh() {
            broadcast({ t: 'refresh' });
        }

        async function refresh() {
            try {
                const res = await fetch('/modes/punkte/board_api.php?code=' + encodeURIComponent(roomCode), { cache: 'no-store' });
                if (res.ok) {
                    const text = await res.text();
                    try { state = JSON.parse(text); render(); }
                    catch (err) {
                        console.error("API JSON Fehler:", text);
                        if (!state) { state = { round: 1, teams: [], played: [], active: null, board: [], categories: [] }; render(); }
                    }
                }
            } catch (e) {}
        }
        async function refreshPlayers() {
            try {
                const res = await fetch('/api/players.php?code=' + encodeURIComponent(roomCode), { cache: 'no-store' });
                if (res.ok) { players = await res.json(); drawCams(); maybeRenderHostMenu(); }
            } catch (e) {}
            setTimeout(refreshPlayers, 2000);
        }

        function renderUndo() {
            const b = document.getElementById('undoBtn');
            if (b) b.style.display = state.canUndo && !state.active ? '' : 'none';
        }
        if (isHost) {
            document.getElementById('undoBtn').addEventListener('click', async () => {
                if (!confirm('Letzte Auflösung rückgängig machen? Punkte und Zug werden zurückgesetzt.')) return;
                const r = await api('undo');
                if (!r.ok) alert(r.error);
                refresh(); broadcastRefresh();
            });
        }

        function render() {
            if (!state) return;
            renderTurn(); renderBoardInfo(); renderUndo(); renderBoard(); renderQuestion(); maybeRenderHostMenu(); drawCams();
        }

        function renderTurn() {
            if (!state.teams) return;
            const t = state.teams.find(x => x.id === state.turnTeamId);
            if (!t) { turnBar.style.display = 'none'; return; }
            turnBar.style.display = 'block';
            turnBar.style.background = t.color;
            let text = `🎯 Dran: ${t.name}`;
            if (state.active && state.active.jokerActive) text += ` 🃏 (Joker aktiv: ${state.active.points} Punkte)`;
            else if (!isHost && state.canPick) text += ' – das seid ihr, wählt eine Frage';
            else if (isHost && state.canPick) text += ' (Wähle eine Frage)';
            turnBar.textContent = text;
        }
        function renderBoardInfo() {
            if (!state) return;
            boardInfo.innerHTML = '';
            boardInfo.append(el('b', '', `Board ${state.round || 1}`));
            if (isHost) {
                const roundSel = document.getElementById('roundSel');
                if (roundSel) roundSel.value = String(state.round);
            }
        }
        function renderBoard() {
            boardEl.innerHTML = '';
            if (!state || !state.round) { boardEl.innerHTML = '<p style="color:red">Lade Spieldaten...</p>'; return; }
            const boardData = state.board || [];
            const playedData = state.played || [];

            for (let slot = 0; slot < 6; slot++) {
                const entry = boardData.find(b => b.slot === slot) || null;
                const head = el('div', 'head' + (isHost ? ' clickable' : ''), entry ? entry.name : (isHost ? '+ Kategorie wählen' : '—'));
                if (isHost) head.addEventListener('click', () => openPicker(slot, entry));
                boardEl.append(head);
            }
            const currentPoints = state.round === 1 ? POINTS_BOARD_1 : POINTS_BOARD_2;
            const playedKeys = new Set(playedData.map(p => p.slot + ':' + p.points));
            for (const base of currentPoints) {
                for (let slot = 0; slot < 6; slot++) {
                    const entry = boardData.find(b => b.slot === slot) || null;
                    const done = playedKeys.has(slot + ':' + base);
                    const isOpen = !!state.active && state.active.slot === slot && state.active.base === base;
                    const canOpen = state.canPick && entry && !done && !state.active;
                    const canUnplay = isHost && done;
                    const cell = el('div', 'pts' + (done ? ' played' : '') + (isOpen ? ' open' : '') + (canOpen || canUnplay ? ' clickable' : ''), done ? '✓' : String(base));
                    if (canOpen) cell.addEventListener('click', () => openQuestion(slot, base, cell));
                    if (canUnplay) { cell.title = 'Klicken, um das Feld für eine Wiederholung freizugeben'; cell.addEventListener('click', () => unplayField(slot, base)); }
                    boardEl.append(cell);
                }
            }
        }

        function qKey() { const a = state && state.active; return a ? a.slot + ':' + a.points + ':' + a.category : ''; }

        function renderQuestion() {
            const a = state.active;
            const key = qKey();
            if (key !== buzzKey) { buzzKey = key; buzzWinner = null; resolveMain = null; resolveSteal = 'none'; resolveStealTeam = null; }

            if (!a || (!isHost && !a.revealed)) { overlay.style.display = 'none'; return; }

            overlay.style.display = 'flex';
            document.getElementById('qCategory').textContent = `${a.category} · ${a.points} Punkte`;
            document.getElementById('qText').textContent = a.question || '';

            const img = document.getElementById('qImage');
            const oldMedia = document.getElementById('qMedia'); if (oldMedia) oldMedia.remove();
            img.style.display = 'none'; img.src = '';
            document.getElementById('qSpotify')?.remove();
            const spotifyEmbed = (u) => {
                const m = /open\.spotify\.com\/(?:intl-[a-z]+\/)?(track|album|playlist)\/([A-Za-z0-9]+)/.exec(u || '');
                return m ? `https://open.spotify.com/embed/${m[1]}/${m[2]}?utm_source=generator` : null;
            };
            const embed = isHost ? spotifyEmbed(a.spotify_url) : null;
            if (embed) {
                const sp = document.createElement('iframe');
                sp.id = 'qSpotify'; sp.src = embed; sp.width = '100%'; sp.height = '152';
                sp.allow = 'autoplay; clipboard-write; encrypted-media; picture-in-picture'; sp.loading = 'eager';
                sp.style.cssText = 'border:0;border-radius:12px;margin-top:16px;max-width:520px;';
                img.after(sp);
            }

            const url = (a.image_url || '').trim();
            const ext = url.split('?')[0].split('.').pop().toLowerCase();
            if (url !== '') {
                if (['mp4','webm'].includes(ext)) {
                    const v = document.createElement('video');
                    v.id = 'qMedia'; v.src = url; v.controls = true; v.autoplay = true; v.playsInline = true;
                    v.style.cssText = 'max-height:40vh;max-width:100%;border-radius:12px;margin-top:16px;';
                    img.after(v);
                } else if (['mp3','wav','ogg','m4a'].includes(ext)) {
                    const au = document.createElement('audio');
                    au.id = 'qMedia'; au.src = url; au.controls = true; au.autoplay = true;
                    au.style.cssText = 'width:100%;margin-top:16px;';
                    img.after(au);
                } else {
                    img.onerror = function() { this.style.display = 'none'; };
                    img.src = url; img.style.display = 'block';
                }
            }

            const ans = document.getElementById('qAnswer');
            if (isHost && a.answer) { ans.style.display = ''; ans.textContent = 'Antwort: ' + a.answer; }
            else ans.style.display = 'none';

            document.getElementById('qPreview').style.display = (isHost && !a.revealed) ? '' : 'none';
            document.getElementById('qWait').style.display = 'none';

            qButtons.innerHTML = ''; qButtons.style.display = 'flex';
            if (isHost) {
                if (!a.revealed) qButtons.append(makeBtn('Für alle anzeigen', 'btn', revealQuestion));
                else {
                    if (!a.timer_started_at && !a.timerPaused) qButtons.append(makeBtn('⏱ 20s Timer starten', 'btn hostBtn', startTimer));
                    else if (a.timerPaused) qButtons.append(makeBtn('▶ Timer weiter', 'btn hostBtn', () => timerAction('timer_resume')));
                    else qButtons.append(makeBtn('⏸ Timer pausieren', 'btn grey', () => timerAction('timer_pause')));
                    qButtons.append(makeBtn(hostHearAll ? '🎧 Nur Dran-Team hören' : '🎧 Alle Teams hören', 'btn grey', () => { hostHearAll = !hostHearAll; applyAudio(); renderQuestion(); }));
                    const bz = makeBtn(a.buzzOpen ? '🔔 Buzzer läuft' : '🔔 Buzzer freigeben', 'btn hostBtn', openBuzzer);
                    bz.disabled = !!a.buzzOpen; qButtons.append(bz);
                    qButtons.append(makeBtn('🙈 Frage verbergen', 'btn grey', hideQuestion));
                    qButtons.append(buildResolvePanel(a));
                }
                qButtons.append(makeBtn('Abbrechen', 'btn grey', cancelQuestion));
            } else {
                if (buzzWinner) {
                    const text = buzzWinner.id === myId ? '🔔 Du hast zuerst gedrückt!' : `🔔 ${buzzWinner.name} (${buzzWinner.team}) hat zuerst gedrückt`;
                    qButtons.append(el('div', 'buzzInfo', text));
                } else if (canBuzz()) {
                    qButtons.append(makeBtn('🔔 BUZZER', 'btn buzz', sendBuzz));
                } else if (state.myTeamId && state.myTeamId === state.turnTeamId) {
                    qButtons.append(el('div', 'buzzInfo', 'Ihr seid dran – beantwortet die Frage laut.'));
                    if (!state.jokerActive && state.myJokerAvailable) qButtons.append(makeBtn('🃏 Joker spielen (Punkte verdoppeln)', 'btn hostBtn', playJoker));
                } else {
                    qButtons.append(el('div', 'buzzInfo', a.buzzOpen ? 'Warte auf die Auflösung …' : 'Warte, bis der Host den Buzzer freigibt …'));
                }
            }
        }

        function buildResolvePanel(a) {
            const wrap = el('div', 'resolve');
            const turn = state.teams.find(t => t.id === state.turnTeamId);
            const lose = Math.floor(a.points / 2);
            const half = Math.floor(a.points / 2);
            wrap.append(el('div', 'rlabel', `Team dran: ${turn ? turn.name : '–'}`));
            const r1 = el('div', 'row'); r1.style.justifyContent = 'center';
            r1.append(
                choiceBtn(`✓ richtig +${a.points}`, 'btn', resolveMain === 'right', () => { resolveMain = 'right'; renderQuestion(); }),
                choiceBtn(`✗ falsch −${lose}`, 'btn danger', resolveMain === 'wrong', () => { resolveMain = 'wrong'; renderQuestion(); }),
                choiceBtn('keine Antwort', 'btn grey', resolveMain === 'none', () => { resolveMain = 'none'; renderQuestion(); }),
            );
            wrap.append(r1);
            const others = state.teams.filter(t => t.id !== state.turnTeamId);
            const defaultSteal = buzzWinner && buzzWinner.team_id && buzzWinner.team_id !== state.turnTeamId ? buzzWinner.team_id : 0;
            const stealId = resolveStealTeam !== null ? resolveStealTeam : defaultSteal;
            if (others.length) {
                wrap.append(el('div', 'rlabel', 'Klauen (Buzzer):'));
                const r2 = el('div', 'row'); r2.style.justifyContent = 'center';
                const sel = document.createElement('select'); sel.className = 'tsel';
                sel.append(new Option('kein Team', '0'));
                for (const t of others) sel.append(new Option(t.name + (buzzWinner && buzzWinner.team_id === t.id ? ' 🔔' : ''), String(t.id)));
                sel.value = String(stealId);
                sel.addEventListener('change', () => { resolveStealTeam = Number(sel.value); renderQuestion(); });
                r2.append(sel,
                    choiceBtn(`✓ Klau +${half}`, 'btn', stealId && resolveSteal === 'right', () => { resolveSteal = 'right'; renderQuestion(); }),
                    choiceBtn('✗ kein Abzug', 'btn grey', stealId && resolveSteal === 'none', () => { resolveSteal = 'none'; renderQuestion(); }),
                );
                wrap.append(r2);
            }
            const skipRow = el('div', 'row'); skipRow.style.justifyContent = 'center';
            skipRow.append(makeBtn('Frage überspringen (keine Punkte)', 'btn grey', () => { resolveMain = 'none'; resolveSteal = 'none'; resolveStealTeam = 0; submitResolve(0); }));
            wrap.append(skipRow);
            const go = makeBtn('Auflösen', 'btn', () => submitResolve(stealId));
            go.disabled = resolveMain === null;
            const rGo = el('div', 'row'); rGo.style.justifyContent = 'center'; rGo.append(go);
            wrap.append(rGo);
            return wrap;
        }

        async function submitResolve(stealId) {
            const steal = stealId && stealId !== state.turnTeamId ? stealId : 0;
            const r = await api('resolve', { mode: resolveMain, steal_team_id: steal, steal_mode: steal ? resolveSteal : 'none' });
            if (!r.ok) alert(r.error);
            resolveMain = null; resolveSteal = 'none'; resolveStealTeam = null;
            broadcastRefresh();
            refresh();
        }

        async function playJoker() {
            if (!confirm('Joker spielen? Punkte für diese Frage verdoppeln sich.')) return;
            const r = await api('use_joker'); if (!r.ok) alert(r.error); broadcastRefresh(); refresh();
        }
        function canBuzz() {
            return !isHost && !!state.active && state.active.revealed && !!state.active.buzzOpen && !buzzWinner && !!state.myTeamId && state.myTeamId !== state.turnTeamId;
        }
        function sendBuzz() {
            if (!canBuzz() || !room) return;
            const myTeam = state.teams.find(t => t.id === state.myTeamId);
            const msg = { t: 'buzz', key: qKey(), id: myId, name: myName, team_id: state.myTeamId, team: myTeam ? myTeam.name : '' };
            room.localParticipant.publishData(new TextEncoder().encode(JSON.stringify(msg)), { reliable: true }).catch(() => {});
            onBuzz(msg);
        }
        function onBuzz(msg) {
            if (!state || msg.key !== qKey() || buzzWinner) return;
            if (msg.team_id === state.turnTeamId) return;
            buzzWinner = { id: msg.id, name: msg.name, team_id: msg.team_id, team: msg.team };
            renderQuestion();
        }

        // Datenempfang: Buzzer + Refresh-Signal
        function onData(payload) {
            try {
                const msg = JSON.parse(new TextDecoder().decode(payload));
                if (msg.t === 'buzz') onBuzz(msg);
                else if (msg.t === 'refresh') refresh();
            } catch (e) {}
        }

        document.addEventListener('keydown', (e) => {
            if (e.code !== 'Space' || e.repeat || isHost) return;
            const tag = (e.target.tagName || '').toUpperCase();
            if (['INPUT','SELECT','TEXTAREA','BUTTON'].includes(tag)) return;
            e.preventDefault(); sendBuzz();
        });

        async function openQuestion(slot, base, cellElement) {
            const r = await api('open_question', { slot, points: base });
            if (!r.ok) {
                if (r.code === 'noquestion' && cellElement) { cellElement.classList.add('error-missing'); cellElement.textContent = 'Keine Frage!'; }
                alert(r.error);
            }
            broadcastRefresh();
            refresh();
        }
        async function unplayField(slot, base) {
            if (!confirm(`Feld ${base} wieder freigeben? Die Frage kann dann erneut gespielt werden.`)) return;
            const r = await api('unplay', { slot, points: base }); if (!r.ok) alert(r.error); broadcastRefresh(); refresh();
        }
        async function revealQuestion() {
            const r = await api('reveal'); if (!r.ok) alert(r.error);
            broadcastRefresh();   // <-- WICHTIG: sagt allen anderen sofort "neu laden"
            refresh();
        }
        async function startTimer() { const r = await api('start_timer'); if (!r.ok) alert(r.error); broadcastRefresh(); refresh(); }
        async function cancelQuestion() { const r = await api('cancel_question'); if (!r.ok) alert(r.error); broadcastRefresh(); refresh(); }
        async function timerAction(action) { const r = await api(action); if (!r.ok) alert(r.error); broadcastRefresh(); refresh(); }
        async function hideQuestion() { const r = await api('hide'); if (!r.ok) alert(r.error); broadcastRefresh(); refresh(); }
        async function openBuzzer() { const r = await api('buzz_open'); if (!r.ok) alert(r.error); broadcastRefresh(); refresh(); }

        function openPicker(slot, entry) {
            pickerTitle.textContent = 'Kategorie für Spalte ' + (slot + 1) + ' · Board ' + state.round;
            pickerList.innerHTML = '';
            if (!state.categories || state.categories.length === 0) pickerList.innerHTML = '<p>Noch keine Kategorien vorhanden. Lege sie zuerst unter fragen.php an.</p>';
            for (const c of (state.categories || [])) {
                pickerList.append(makeBtn(c.name + (entry && entry.category_id === c.id ? ' (aktuell)' : ''), 'btn block', () => pick(slot, c.id, entry)));
            }
            picker.style.display = 'flex';
        }
        function closePicker() { picker.style.display = 'none'; }
        function pick(slot, catId, entry) {
            closePicker();
            if (entry && entry.category_id === catId) return;
            if (!entry) { setSlot(slot, catId, true); return; }
            pending = { slot, catId }; keepDialog.style.display = 'flex';
        }
        async function setSlot(slot, catId, keep) {
            const r = await api('set_slot', { slot, category_id: catId, keep_points: keep ? '1' : '0' });
            if (!r.ok) alert(r.error); broadcastRefresh(); refresh();
        }
        function finishPending(keep) { keepDialog.style.display = 'none'; if (pending) setSlot(pending.slot, pending.catId, keep); pending = null; }
        document.getElementById('pickerClose').addEventListener('click', closePicker);
        document.getElementById('keepYes').addEventListener('click', () => finishPending(true));
        document.getElementById('keepNo').addEventListener('click', () => finishPending(false));
        document.getElementById('keepCancel').addEventListener('click', () => { pending = null; keepDialog.style.display = 'none'; });

        function openHostMenu() { hostModal.style.display = 'flex'; hostSig = ''; maybeRenderHostMenu(); }
        function maybeRenderHostMenu() {
            if (!isHost || hostModal.style.display !== 'flex' || !state) return;
            const sig = JSON.stringify([state.teams, players.map(p => [p.discord_id, p.username, p.team_id])]);
            if (sig === hostSig) return;
            hostSig = sig; renderHostTeams(); renderHostPlayers(); renderHostScores();
        }
        function renderHostTeams() {
            hostTeams.innerHTML = '';
            for (const t of state.teams) {
                const row = el('div', 'prow');
                const left = el('span', ''); left.style.display = 'flex'; left.style.alignItems = 'center';
                const sw = el('span', 'swatch'); sw.style.background = t.color;
                left.append(sw, el('span', '', t.name));
                const del = makeBtn('Löschen', 'btn danger small', () => deleteTeam(t.id, t.name));
                del.disabled = state.teams.length <= 1;
                row.append(left, del); hostTeams.append(row);
            }
        }
        function renderHostPlayers() {
            hostPlayers.innerHTML = '';
            const others = players.filter(p => !p.is_host);
            if (others.length === 0) { hostPlayers.append(el('p', 'muted', 'Noch keine Mitspieler.')); return; }
            for (const p of others) {
                const row = el('div', 'prow');
                row.append(el('span', '', p.username));
                const sel = document.createElement('select'); sel.className = 'tsel';
                sel.append(new Option('Kein Team', '0'));
                for (const t of state.teams) sel.append(new Option(t.name, String(t.id)));
                sel.value = p.team_id ? String(p.team_id) : '0';
                sel.addEventListener('change', () => setTeam(p.discord_id, Number(sel.value)));
                row.append(sel); hostPlayers.append(row);
            }
        }
        function renderHostScores() {
            hostScores.innerHTML = '';
            const row = el('div', 'row'); row.style.alignItems = 'center';
            const sel = document.createElement('select'); sel.className = 'tsel';
            for (const t of state.teams) sel.append(new Option(`${t.name} (${t.points})`, String(t.id)));
            const amount = document.createElement('input');
            amount.type = 'number'; amount.min = '1'; amount.max = '2000'; amount.value = '100';
            amount.style.cssText = 'width:90px;padding:6px 8px;border-radius:8px;border:none;background:#3b2a6b;color:#fff;';
            const change = async (sign) => {
                const v = Math.max(1, Math.min(2000, parseInt(amount.value, 10) || 0));
                const r = await api('add_points', { team_id: sel.value, delta: sign * v });
                if (!r.ok) alert(r.error);
                hostSig = ''; broadcastRefresh(); refresh();
            };
            row.append(sel, amount, makeBtn('+ hinzufügen', 'btn small', () => change(1)), makeBtn('− abziehen', 'btn danger small', () => change(-1)));
            hostScores.append(row);
        }
        async function setTeam(discordId, teamId) {
            try {
                const res = await fetch('team.php', { method: 'POST', body: new URLSearchParams({ code: roomCode, discord_id: discordId, team_id: String(teamId) }) });
                if (!res.ok) { alert('Team konnte nicht geändert werden.'); return; }
            } catch (e) { alert('Netzwerkfehler.'); return; }
            const p = players.find(x => x.discord_id === discordId);
            if (p) { const t = state.teams.find(x => x.id === teamId); p.team_id = teamId || null; p.team_name = t ? t.name : null; p.team_color = t ? t.color : null; }
            lastCamSig = ''; drawCams(); hostSig = ''; maybeRenderHostMenu(); broadcastRefresh();
        }
        async function deleteTeam(teamId, name) {
            if (!confirm(`Team "${name}" löschen? Seine Mitglieder landen ohne Team.`)) return;
            const r = await api('delete_team', { team_id: teamId }); if (!r.ok) alert(r.error); hostSig = ''; broadcastRefresh(); refresh();
        }
        if (isHost) {
            document.getElementById('hostBtn').addEventListener('click', openHostMenu);
            document.getElementById('hostClose').addEventListener('click', () => { hostModal.style.display = 'none'; });
            document.getElementById('podiumBtn').addEventListener('click', () => {
                hostModal.style.display = 'none';
                document.dispatchEvent(new CustomEvent('kk:podium'));
            });
            document.getElementById('addTeamForm').addEventListener('submit', async (e) => {
                e.preventDefault();
                const name = document.getElementById('newTeamName').value.trim();
                const color = document.getElementById('newTeamColor').value;
                const r = await api('add_team', { name, color });
                if (!r.ok) alert(r.error); else document.getElementById('newTeamName').value = '';
                hostSig = ''; broadcastRefresh(); refresh();
            });
            document.getElementById('resetBtn').addEventListener('click', async () => {
                if (!confirm('Punkte aller Teams auf 0 setzen?')) return;
                await api('reset_scores'); hostSig = ''; broadcastRefresh(); refresh();
            });
            document.getElementById('roundSel').addEventListener('change', async (e) => {
                const r = await api('set_round', { round: e.target.value });
                if (!r.ok) alert(r.error); broadcastRefresh(); refresh();
            });
        }

        // ============================================================
        // SONG FÜR ALLE TEILEN (Host teilt Tab-/System-Audio via LiveKit)
        // ============================================================
        let shareAudioTrack = null;
        let shareAudioPub   = null;
        let shareDisplayStream = null;

        if (isHost && shareAudioBtn) {
            shareAudioBtn.addEventListener('click', async () => {
                if (!room) { alert('Noch nicht mit LiveKit verbunden.'); return; }

                if (shareAudioTrack) {
                    try { await room.localParticipant.unpublishTrack(shareAudioTrack); } catch (e) {}
                    try { shareAudioTrack.stop(); } catch (e) {}
                    if (shareDisplayStream) shareDisplayStream.getTracks().forEach(t => t.stop());
                    shareAudioTrack = null; shareAudioPub = null; shareDisplayStream = null;
                    shareAudioBtn.textContent = '🔊 Song für alle teilen';
                    shareAudioBtn.classList.remove('active');
                    return;
                }

                try {
                    const srcSel = document.getElementById('shareSrc');
                    const deviceId = srcSel ? srcSel.value : '';
                    let stream;
                    if (deviceId) {
                        // Ausgewähltes Audiogerät (z. B. virtueller Kabel-Ausgang) direkt übertragen
                        stream = await navigator.mediaDevices.getUserMedia({
                            audio: { deviceId: { exact: deviceId }, echoCancellation: false, noiseSuppression: false, autoGainControl: false },
                            video: false,
                        });
                    } else {
                        stream = await navigator.mediaDevices.getDisplayMedia({
                            video: true,
                            audio: { echoCancellation: false, noiseSuppression: false, autoGainControl: false },
                        });
                    }
                    const audioTrack = stream.getAudioTracks()[0];
                    if (!audioTrack) {
                        stream.getTracks().forEach(t => t.stop());
                        alert('Kein Audio im geteilten Stream. Bitte im Dialog "Audio teilen" aktivieren.');
                        return;
                    }
                    stream.getVideoTracks().forEach(t => t.stop());
                    shareDisplayStream = stream;
                    shareAudioTrack = audioTrack;
                    // Mikrofon aus, sonst nimmt es den Song von den Lautsprechern auf und die Runde hört ihn doppelt
                    if (room.localParticipant.isMicrophoneEnabled) {
                        await room.localParticipant.setMicrophoneEnabled(false).catch(() => {});
                        rememberFlag('kr_mic', false);
                        syncButtons();
                    }
                    shareAudioPub = await room.localParticipant.publishTrack(audioTrack, {
                        name: 'shared-audio', source: LK.Track.Source.Unknown,
                    });
                    shareAudioBtn.textContent = '⏹ Song-Teilen beenden';
                    shareAudioBtn.classList.add('active');

                    audioTrack.addEventListener('ended', async () => {
                        try { await room.localParticipant.unpublishTrack(audioTrack); } catch (e) {}
                        shareAudioTrack = null; shareAudioPub = null;
                        if (shareDisplayStream) shareDisplayStream.getTracks().forEach(t => t.stop());
                        shareDisplayStream = null;
                        shareAudioBtn.textContent = '🔊 Song für alle teilen';
                        shareAudioBtn.classList.remove('active');
                    });
                } catch (err) {
                    if (err.name !== 'NotAllowedError') alert('Teilen fehlgeschlagen: ' + err.message);
                }
            });
        }

        // ============================================================
        // LIVEKIT
        // ============================================================
        let room = null;
        function setStatus(text) { lkStatus.textContent = text; }
        function participantFor(id) {
            if (!room) return null;
            if (id === myId) return room.localParticipant;
            return room.remoteParticipants.get(id) || null;
        }
        function mediaFor(id) {
            const p = participantFor(id);
            if (!p) return { track: null, micOn: false };
            const pub = p.getTrackPublication(LK.Track.Source.Camera);
            const track = pub && pub.track && !pub.isMuted ? pub.track : null;
            return { track, micOn: !!p.isMicrophoneEnabled };
        }

        const videoCache = new Map();
        function dropVideo(id, art = null) {
            for (const key of [...videoCache.keys()]) {
                const [vid, vart] = key.split('|');
                if (vid !== id) continue;
                if (art && vart !== art) continue;
                const c = videoCache.get(key);
                c.track.detach(c.el);
                videoCache.delete(key);
            }
        }
        function videoFor(id, track, mirror, art) {
            const key = id + '|' + art;
            if (!track) { dropVideo(id, art); return null; }
            const c = videoCache.get(key);
            if (c && c.sid === track.sid) { c.el.style.transform = mirror ? 'scaleX(-1)' : ''; return c.el; }
            dropVideo(id, art);
            const v = track.attach();
            v.playsInline = true; v.muted = true;
            v.style.width = '100%'; v.style.height = '100%'; v.style.objectFit = 'cover';
            v.style.transform = mirror ? 'scaleX(-1)' : '';
            videoCache.set(key, { sid: track.sid, el: v, track });
            return v;
        }
        async function restoreMedia() {
            if (wasFlagOn('kr_cam')) await room.localParticipant.setCameraEnabled(true).catch(() => {});
            if (wasFlagOn('kr_mic')) await room.localParticipant.setMicrophoneEnabled(true).catch(() => {});
        }
        async function connectLiveKit() {
            if (!LK) { setStatus('LiveKit konnte nicht geladen werden.'); return; }
            if (room) { room.disconnect(); room = null; }
            setStatus('verbinde …');
            try {
                const res = await fetch('/api/livekit_token.php?code=' + encodeURIComponent(roomCode), { cache: 'no-store' });
                const data = await res.json();
                if (!res.ok || !data.token) throw new Error(data.error || 'kein Zugang');

                room = new LK.Room({
                    adaptiveStream: true, dynacast: true,
                    videoCaptureDefaults: {
                        resolution: IS_MOBILE ? { width: 320, height: 240, frameRate: 15 } : { width: 480, height: 360, frameRate: 20 },
                    },
                });
                const E = LK.RoomEvent;
                for (const ev of [E.ParticipantConnected, E.ParticipantDisconnected, E.TrackMuted, E.TrackUnmuted, E.LocalTrackPublished, E.LocalTrackUnpublished]) {
                    room.on(ev, () => drawCams());
                }
                room.on(E.TrackSubscribed, (track, pub, participant) => {
                    if (track.kind === 'audio') {
                        const a = track.attach(); a.style.display = 'none';
                        document.body.append(a);
                        audioEls.set(participant.identity, a);
                        applyAudio();
                    }
                    drawCams();
                });
                room.on(E.TrackUnsubscribed, (track, pub, participant) => {
                    if (track.kind === 'audio') audioEls.delete(participant.identity);
                    track.detach().forEach(a => a.remove());
                    drawCams();
                });
                room.on(E.DataReceived, onData);
                room.on(E.ActiveSpeakersChanged, (sp) => { speakingSet = new Set(sp.map(s => s.identity)); paintSpeaking(); });
                room.on(E.Reconnecting, () => setStatus('Verbindung wird wiederhergestellt …'));
                room.on(E.Reconnected, () => { setStatus(''); drawCams(); });
                room.on(E.Disconnected, () => setStatus('getrennt, bitte Seite neu laden'));

                await room.connect(data.url, data.token);
                setStatus('');
                await restoreMedia();
                drawCams();
            } catch (e) {
                setStatus('Verbindung fehlgeschlagen: ' + e.message);
                setTimeout(connectLiveKit, 6000);
            }
        }

        function syncButtons() {
            const lp = room ? room.localParticipant : null;
            const camOn = !!(lp && lp.isCameraEnabled);
            const micOn = !!(lp && lp.isMicrophoneEnabled);
            camBtn.textContent = camOn ? 'Kamera aus' : 'Kamera an';
            camBtn.classList.toggle('active', camOn);
            micBtn.textContent = micOn ? '🎤 Stumm schalten' : '🔇 Mikrofon an';
            micBtn.classList.toggle('active', micOn);
        }
        camBtn.addEventListener('click', async () => {
            if (!room) return;
            try {
                const on = room.localParticipant.isCameraEnabled;
                await room.localParticipant.setCameraEnabled(!on);
                rememberFlag('kr_cam', !on);
            } catch (e) { alert('Kamera konnte nicht gestartet werden: ' + e.message); }
            drawCams();
        });
        micBtn.addEventListener('click', async () => {
            if (!room) return;
            try {
                const on = room.localParticipant.isMicrophoneEnabled;
                await room.localParticipant.setMicrophoneEnabled(!on);
                rememberFlag('kr_mic', !on);
            } catch (e) { alert('Mikrofon konnte nicht gestartet werden: ' + e.message); }
            drawCams();
        });
        document.addEventListener('click', () => { if (room) room.startAudio().catch(() => {}); });

        function buildCard(p, zoomed = false) {
            const m = mediaFor(p.discord_id);
            const isMe = p.discord_id === myId;
            const team = p.team_id ? state.teams.find(t => t.id === p.team_id) : null;
            const color = p.is_host ? '#f5b400' : (team ? team.color : '#777');
            const card = el('div', 'pcard' + (speakingSet.has(p.discord_id) ? ' speaking' : ''));
            card.dataset.team = p.team_id || '';
            card.dataset.id = p.discord_id;
            card.style.borderColor = color;
            if (!zoomed) card.addEventListener('click', () => openZoom(p.discord_id));
            const cam = el('div', 'pcam');
            const video = videoFor(p.discord_id, m.track, isMe, zoomed ? 'gross' : 'kachel');
            if (video) cam.append(video);
            else {
                const img = el('img', 'pph'); img.src = p.avatar_url; img.alt = '';
                cam.append(img);
            }
            card.append(cam);
            if (team) { const score = el('div', 'pscore', String(team.points)); score.style.background = color; card.append(score); }
            else if (p.is_host) card.append(el('div', 'pscore pscoreHost', '👑 Host'));
            if (m.micOn) card.append(el('div', 'pmic', '🎤'));
            card.append(el('div', 'pstate ' + (video ? 'on' : 'off'), video ? '' : '✕'));
            const pill = el('div', 'pname'); pill.style.background = color;
            const avwrap = el('span', 'pavwrap');
            const av = el('img', 'pav'); av.src = p.avatar_url; av.alt = '';
            avwrap.append(av);
            if (p.decoration_url) { const d = el('img', 'pdeco'); d.src = p.decoration_url; d.alt = ''; avwrap.append(d); }
            pill.append(avwrap, el('span', 'pnametext', (p.premium ? '💎 ' : '') + p.username));
            card.append(pill);
            if (zoomed && !isMe) {
                const row = el('div', 'pvol');
                row.style.cssText = 'margin-top:10px;display:flex;align-items:center;gap:8px;justify-content:center;font-size:14px;';
                row.append(el('span', '', '🔈'));
                const slider = document.createElement('input');
                slider.type = 'range'; slider.min = '0'; slider.max = '1'; slider.step = '0.05';
                slider.value = String(prefVol[p.discord_id] ?? 1);
                slider.style.width = '60%';
                slider.addEventListener('click', (e) => e.stopPropagation());
                slider.addEventListener('input', () => {
                    prefVol[p.discord_id] = parseFloat(slider.value);
                    try { localStorage.setItem('kr_vol', JSON.stringify(prefVol)); } catch (e) {}
                    applyAudio();
                });
                row.append(slider);
                card.append(row);
            }
            return card;
        }

        let speakingSet = new Set();
        const audioEls = new Map();
        let fullMute = false;
        let hostHearAll = false;
        let prefVol = {};
        try { prefVol = JSON.parse(localStorage.getItem('kr_vol') || '{}'); } catch (e) { prefVol = {}; }

        function teamOf(id) {
            const p = players.find(x => x.discord_id === id);
            return p && p.team_id ? p.team_id : 0;
        }
        function canHear(id) {
            if (fullMute) return false;
            const a = state && state.active;
            if (!a) return true;
            const host = players.find(p => p.is_host);
            if (host && id === host.discord_id) return true;
            if (isHost) {
                if (hostHearAll) return true;
                const b = buzzWinner ? buzzWinner.team_id : 0;
                return teamOf(id) === state.turnTeamId || (!!b && teamOf(id) === b);
            }
            return !!state.myTeamId && teamOf(id) === state.myTeamId;
        }
        function applyAudio() {
            for (const [id, a] of audioEls) {
                const pref = prefVol[id] ?? 1;
                a.volume = canHear(id) ? pref : 0;
            }
            if (fullBtn) {
                fullBtn.textContent = fullMute ? '🔊 Ton an' : 'Full Mute';
                fullBtn.classList.toggle('active', fullMute);
            }
        }
        fullBtn.addEventListener('click', async () => {
            fullMute = !fullMute;
            if (fullMute && room && room.localParticipant.isMicrophoneEnabled) {
                await room.localParticipant.setMicrophoneEnabled(false).catch(() => {});
            }
            applyAudio(); drawCams();
        });

        function drawCams() {
            syncButtons();
            applyAudio();
            if (!state) return;
            const sig = JSON.stringify([
                state.teams.map(t => [t.id, t.name, t.color, t.points]),
                players.map(p => {
                    const m = mediaFor(p.discord_id);
                    return [p.discord_id, p.username, p.avatar_url, p.team_id, p.is_host, p.premium, p.decoration_url, m.track ? m.track.sid : '', m.micOn];
                }),
            ]);
            if (sig === lastCamSig) return;
            lastCamSig = sig;

            for (const key of [...videoCache.keys()]) {
                const id = key.split('|')[0];
                if (!mediaFor(id).track) dropVideo(id);
            }
            const teamIndex = (id) => { const i = state.teams.findIndex(t => t.id === id); return i < 0 ? 999 : i; };
            const order = [];
            const host = players.find(p => p.is_host);
            if (host) order.push(host);
            const rest = players.filter(p => !p.is_host).sort((a, b) => teamIndex(a.team_id) - teamIndex(b.team_id));
            order.push(...rest);

            arena.innerHTML = '';
            for (const p of order) arena.append(buildCard(p));
            drawZoom();
            paintSpeaking();
        }
        function paintSpeaking() {
            document.querySelectorAll('.pcard[data-id]').forEach(c => {
                c.classList.toggle('speaking', speakingSet.has(c.dataset.id));
            });
        }
        function openZoom(id) { zoomId = id; zoom.style.display = 'flex'; drawZoom(); }
        function closeZoom() {
            if (zoomId) dropVideo(zoomId, 'gross');
            zoomId = null; zoom.style.display = 'none'; zoomBox.innerHTML = '';
        }
        function drawZoom() {
            if (!zoomId) return;
            const p = players.find(x => x.discord_id === zoomId);
            if (!p) { closeZoom(); return; }
            zoomBox.innerHTML = '';
            zoomBox.append(buildCard(p, true));
        }
        document.getElementById('zoomClose').addEventListener('click', closeZoom);
        zoom.addEventListener('click', (e) => { if (e.target === zoom) closeZoom(); });

        // Start
        refresh();
        refreshPlayers();
        connectLiveKit();

        // ---- Polling-Fallback: alle 1,5 s aktualisieren ----
        // Nötig, falls ein Spieler kurz die LiveKit-Verbindung verliert.
        // Günstig, weil die API-Antwort klein ist.
        setInterval(refresh, 1500);
    </script>
    <script src="/js/effects.js"></script>
    <script>
    (function () {
        const CUR = 'points';
        const PAGES = { points: '/modes/punkte/board.php', hearts: '/modes/herzen/herzen.php', dumm_drauf: '/modes/hau_drauf/dumm_drauf.php', eingestuhlt: '/modes/eingestuhlt/eingestuhlt.php' };
        const code = new URLSearchParams(location.search).get('code') || '';
        async function checkMode() {
            try {
                const res = await fetch('/api/room_mode.php?code=' + encodeURIComponent(code), { cache: 'no-store' });
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
<script src="/js/bigtext.js"></script>
</body>
</html>
<!-- Version 8.13 -->
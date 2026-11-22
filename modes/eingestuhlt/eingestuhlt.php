<?php
// Version 1.16
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/db.php';
require_once dirname(__DIR__, 2) . '/auth.php';

$user = $_SESSION['discord_user'] ?? null;
if (!$user) { header('Location: /index.php'); exit; }

$code = strtoupper($_GET['code'] ?? $_POST['code'] ?? '');
$st = db()->prepare('SELECT id, code, host_id, mode FROM kr_rooms WHERE code = ?');
$st->execute([$code]);
$room = $st->fetch();
if (!$room) die('Raum nicht gefunden. <a href="/lobby.php">Zurück</a>');
$isHost = $room['host_id'] === $user['id'];
$currentMode = $room['mode'] ?? 'points';

// --- MODUS WECHSELN (Muss VOR der Weiterleitung stehen!) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['switch_mode'])) {
    $newMode = $_POST['switch_mode'];
    if ($isHost && in_array($newMode, ['points', 'hearts', 'eingestuhlt', 'dumm_drauf'], true) && mode_selectable($newMode)) {
        db()->prepare('UPDATE kr_rooms SET mode = ? WHERE code = ?')->execute([$newMode, $code]);
        if ($newMode === 'points') { header('Location: /modes/punkte/board.php?code=' . $code); exit; }
        elseif ($newMode === 'hearts') { header('Location: /modes/herzen/herzen.php?code=' . $code); exit; }
        elseif ($newMode === 'dumm_drauf') { header('Location: /modes/hau_drauf/dumm_drauf.php?code=' . $code); exit; }
        else { header('Location: /modes/eingestuhlt/eingestuhlt.php?code=' . $code); exit; }
    }
}

if ($currentMode !== 'eingestuhlt') {
    if ($currentMode === 'hearts') { header('Location: /modes/herzen/herzen.php?code=' . urlencode($code)); exit; }
    elseif ($currentMode === 'dumm_drauf') { header('Location: /modes/hau_drauf/dumm_drauf.php?code=' . urlencode($code)); exit; }
    else { header('Location: /modes/punkte/board.php?code=' . urlencode($code)); exit; }
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
    <title>Eingeschult – <?= htmlspecialchars($code) ?></title>
    <style>
        body { margin: 0; padding: 20px; font-family: system-ui, sans-serif; color: #f2f2ee; min-height: 100vh;
            background: radial-gradient(ellipse at top, #3a4f3b, #243326 70%); }
        a { color: #bff0c9; }
        .board { border: 10px solid #8b5a2b; border-radius: 12px; padding: 18px; background: #2f3f31;
            box-shadow: inset 0 0 40px rgba(0,0,0,.5); }
        .head { display: flex; justify-content: space-between; align-items: center; gap: 12px; flex-wrap: wrap; }
        h1 { font-family: 'Comic Sans MS', cursive, system-ui; font-size: 30px; margin: 0; }
        .chalk { font-family: 'Comic Sans MS', cursive, system-ui; }
        .muted { opacity: .65; font-size: 14px; }
        .timer { font-size: 34px; font-weight: 900; color: #ffe27a; font-variant-numeric: tabular-nums; }
        .qbox { margin: 16px 0; padding: 16px; border: 3px dashed #f2f2ee88; border-radius: 10px; font-size: 24px; text-align: center; white-space: pre-wrap; }
        .qans { color: #ffe27a; font-size: 18px; margin-top: 8px; }
        .btn { padding: 10px 16px; border: 2px solid #f2f2ee; border-radius: 10px; background: #3f5a42; color: #fff;
            font-weight: bold; cursor: pointer; font-size: 15px; margin: 4px; }
        .btn:disabled { opacity: .4; cursor: default; }
        .btn.red { background: #7a2b2b; } .btn.green { background: #2b7a4a; } .btn.gold { background: #8a6d12; }
        input[type=text] { padding: 10px; border-radius: 8px; border: 2px solid #f2f2ee; background: #26362a; color: #fff; font: inherit; width: min(480px, 90%); }
        .camBar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-top: 14px; }
        .btn.cam { background: #3ddbc4; color: #1f1438; border-color: #3ddbc4; }
        .btn.cam.active { background: #ff3ea5; color: #fff; border-color: #ff3ea5; }
        .lkStatus { font-size: 14px; opacity: .8; }
        .arena { display: grid; grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 8px; margin-top: 12px; }
        .pcard { position: relative; border: 2px solid #f2f2ee88; border-radius: 10px; overflow: hidden; background: #15201a; aspect-ratio: 16 / 9; }
        .pcard.speaking { box-shadow: 0 0 0 3px #38d26b, 0 0 14px #38d26b; border-color: #38d26b; }
        .pcam { width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; }
        .pcam video { width: 100%; height: 100%; object-fit: cover; }
        .pph { width: 42%; aspect-ratio: 1; border-radius: 50%; opacity: .9; }
        .pmic { position: absolute; top: 6px; left: 6px; background: rgba(0,0,0,.5); border-radius: 6px; padding: 2px 6px; font-size: 13px; }
        .pstate { position: absolute; top: 8px; right: 8px; width: 10px; height: 10px; border-radius: 50%; background: #f5a623; }
        .pstate.on { background: #38d26b; box-shadow: 0 0 6px #38d26b; }
        .pname { position: absolute; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,.55); padding: 4px 8px; font-size: 13px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .padBox { margin: 10px 0; }
        .pad { display: block; width: min(600px, 95%); height: auto; background: #fff; border-radius: 8px; border: 3px solid #f2f2ee;
            touch-action: none; cursor: crosshair; margin: 6px 0; }
        .swatch { width: 28px; height: 28px; border-radius: 50%; border: 2px solid #fff; margin: 4px; cursor: pointer; padding: 0; }
        .live-cv { display: block; width: 100%; background: #fff; border-radius: 6px; margin-top: 6px; }
        .ans-img { display: block; width: 100%; max-width: 260px; border-radius: 6px; background: #fff; margin-top: 6px; }
        .players { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 12px; margin-top: 14px; }
        .pl { border: 2px solid #f2f2ee88; border-radius: 12px; padding: 10px; background: #26362a; text-align: center; }
        .pl.me { border-color: #ffe27a; }
        .pl.out { opacity: .35; filter: grayscale(1); }
        .pl.ok { box-shadow: 0 0 0 3px #2b7a4a inset; }
        .pl.bad { box-shadow: 0 0 0 3px #7a2b2b inset; }
        .av { width: 64px; height: 64px; border-radius: 50%; border: 2px solid #f2f2ee; background: #15201a; }
        .lives { font-size: 20px; letter-spacing: 2px; margin: 4px 0; }
        .lives .on { color: #ff6b6b; } .lives .off { color: #555; }
        .ans { margin-top: 6px; font-size: 16px; min-height: 22px; color: #bff0c9; word-break: break-word; }
        .sm { font-size: 13px; opacity: .8; }
        .win { font-size: 40px; text-align: center; margin: 20px 0; color: #ffe27a; }
        .planes { position: fixed; inset: 0; pointer-events: none; overflow: hidden; z-index: 0; }
        .planes span { position: absolute; left: -60px; opacity: .18; animation-name: fly; animation-timing-function: linear; animation-iteration-count: infinite; }
        @keyframes fly { from { transform: translateX(0) rotate(-8deg); } to { transform: translateX(120vw) translateY(-40px) rotate(-8deg); } }
        .board { position: relative; z-index: 1; }
        .failOv { position: fixed; inset: 0; display: none; align-items: center; justify-content: center; flex-direction: column;
            background: rgba(20,10,10,.88); z-index: 50; font-family: 'Comic Sans MS', cursive, system-ui; text-align: center; }
        .failOv .big { font-size: 90px; font-weight: 900; color: #ff6b6b; }
        .failOv .sub { font-size: 24px; color: #f2f2ee; margin-top: 10px; }
    </style>
</head>
<body>
<?php require dirname(__DIR__, 2) . '/modus_auswahl.php'; ?>
<div class="planes" aria-hidden="true"></div>
<div class="failOv" id="fail"><div class="big">DURCHGEFALLEN</div><div class="sub" id="failSub">Schade. Setz dich hin.</div></div>
<div class="board" style="margin-top: 50px;">
    <div class="head">
        <h1>Eingeschult <span class="muted" style="font-size:16px">Raum <?= htmlspecialchars($code) ?></span></h1>
        <div id="status" class="chalk"></div>
        <div class="timer" id="timer"></div>
    </div>

    <div id="info" class="chalk muted" style="margin-top:8px"></div>
    <div id="teacher" class="chalk" style="margin-top:6px;min-height:24px;color:#ffe27a"></div>
    <div class="qbox chalk" id="qbox">Lade …</div>

    <div id="controls" class="chalk"></div>
    <div id="padBox" class="padBox chalk" style="display:none"></div>
    <div id="sayBox" class="chalk" style="margin-top:12px"></div>

    <div class="camBar">
        <button class="btn cam" id="camBtn" type="button">Kamera an</button>
        <button class="btn cam" id="micBtn" type="button">Mikrofon an</button>
        <span class="lkStatus" id="lkStatus"></span>
    </div>
    <div class="arena" id="arena"></div>

    <div class="players" id="players"></div>
    <div class="chalk" id="winner"></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/livekit-client/dist/livekit-client.umd.js"></script>
<script>
const code = <?= json_encode($code) ?>;
const isHost = <?= json_encode($isHost) ?>;
const myId = <?= json_encode($user['id']) ?>;
const $ = (id) => document.getElementById(id);
let S = null;
let marks = {};           
let markRound = null;

const PHASE = { lobby: 'Warten auf Start', ask: 'Frage läuft', reveal: 'Aufgedeckt', result: 'Ergebnis', over: 'Ende' };

async function api(action, data = {}) {
    const body = new URLSearchParams({ code, action, ...data });
    const res = await fetch('ei_api.php', { method: 'POST', body, cache: 'no-store' });
    if (!res.ok) {
        const j = await res.json().catch(() => ({}));
        alert(errorText(j.error));
    }
    load();
    return res.ok;
}

function errorText(e) {
    return ({
        running: 'Das Spiel läuft schon.', few: 'Mindestens 2 Spieler nötig.', noquestion: 'Keine passende Frage mehr übrig.',
        noactive: 'Gerade nicht möglich.', timeup: 'Die Zeit ist um.', out: 'Du bist raus.', nojoker: 'Kein Spicker-Joker mehr.',
        input: 'Ungültige Eingabe.', host: 'Nur der Host darf das.',
    })[e] || 'Fehler.';
}

function el(tag, cls, text) { const e = document.createElement(tag); if (cls) e.className = cls; if (text !== undefined) e.textContent = text; return e; }
function btn(label, cls, fn, disabled = false) { const b = el('button', 'btn ' + (cls || ''), label); b.type = 'button'; b.disabled = disabled; b.onclick = fn; return b; }

async function load() {
    try {
        const res = await fetch('ei_api.php?code=' + encodeURIComponent(code) + '&after=' + lastStroke, { cache: 'no-store' });
        if (res.ok) { S = await res.json(); draw(); }
    } catch (e) {}
    setTimeout(load, 1000);
}

function draw() {
    if (!S) return;

    $('status').textContent = PHASE[S.phase] || '';
    if (S.mode === 'rapid') {
        $('info').textContent = `Finale: Schnellfeuer ${S.round} von ${S.rapidTotal}`;
    } else if (S.class) {
        $('info').textContent = `Klasse ${S.class} · ${S.fach} · ${S.punkte} Punkte · Runde ${S.round}`;
    } else {
        $('info').textContent = '';
    }

    $('timer').textContent = S.phase === 'ask' && S.secondsLeft !== null ? S.secondsLeft + ' s' : '';

    const q = $('qbox');
    if (S.question) {
        q.innerHTML = '';
        q.append(el('div', '', S.question.text));
        if (S.question.answer) q.append(el('div', 'qans', 'Lösung: ' + S.question.answer));
    } else if (S.phase === 'lobby') {
        q.textContent = isHost ? 'Alle Spieler im Raum? Dann Spiel starten.' : 'Warte, bis der Host startet.';
    } else if (S.phase === 'over') {
        q.textContent = 'Spiel vorbei.';
    } else {
        q.textContent = '';
    }

    drawControls();
    updatePad();
    syncStrokes();
    drawSpy();
    drawPlayers();
    if (isHost && S.phase === 'ask' && S.secondsLeft === 0 && !autoRev) { autoRev = true; api('reveal'); }
    if (S.phase !== 'ask') autoRev = false;
    fx();

    $('winner').textContent = S.winner ? '🏆 ' + S.winner + ' gewinnt das Finale!' : '';
    $('winner').className = S.winner ? 'win' : 'chalk';
}

function drawControls() {
    const c = $('controls');
    c.innerHTML = '';
    if (!isHost) {
        const me = S.me;
        if (me && me.out) {
            c.append(el('div', 'sm', 'Du bist durchgefallen. Zuschauen ist jetzt angesagt.'));
        }
        return;
    }

    if (S.phase === 'lobby' || S.phase === 'over') {
        c.append(btn(S.phase === 'over' ? 'Neues Spiel' : 'Spiel starten', 'green', () => api('start')));
    }
    if (S.phase === 'ask') {
        c.append(btn('Aufdecken', 'gold', () => api('reveal')));
    }
    if (S.phase === 'reveal') {
        c.append(el('div', 'sm', 'Richtige Antworten anklicken, dann auswerten.'));
        const row = el('div');
        const list = S.players.filter(p => !p.out && p.answered);
        for (const p of list) {
            if (!(p.id in marks)) marks[p.id] = false;
        }
        const pick = el('div', 'row');
        for (const p of list) {
            const b = btn((marks[p.id] ? '✓ ' : '✗ ') + p.name, marks[p.id] ? 'green' : 'red', () => {
                marks[p.id] = !marks[p.id]; draw();
            });
            pick.append(b);
        }
        row.append(pick);
        c.append(row);
        c.append(btn('Auswerten', 'gold', () => {
            const ids = Object.keys(marks).filter(k => marks[k]).join(',');
            marks = {};
            api('mark', { correct: ids });
        }));
    }
    if (S.phase === 'result') {
        c.append(btn('Nächste Runde', 'green', () => api('next')));
    }
    if (S.phase === 'ask' || S.phase === 'reveal' || S.phase === 'result') {
        c.append(btn('Spiel abbrechen', 'red', () => { if (confirm('Spiel beenden?')) api('reset'); }));
    }
}

function drawSpy() {
    const box = $('sayBox');
    box.innerHTML = '';
    if (isHost || !S.me) return;

    if (S.phase === 'ask' && S.mySpyCanUse) {
        box.append(el('div', 'sm', `🕵 Spicker-Joker: ${S.me.joker} übrig (Sechsen: ${S.me.sixes} von 3)`));
        const row = el('div', 'row');
        for (const p of S.players) {
            if (p.isMe || p.out) continue;
            row.append(btn('Bei ' + p.name + ' schauen', 'gold', () => api('spy', { target: p.id }), p.spiedByMe));
        }
        box.append(row);
    }
    const spied = S.players.filter(p => p.spiedByMe);
    for (const p of spied) {
        const row = el('div', 'row');
        row.append(el('div', 'sm', `👀 Du schaust bei ${p.name} – zeichne es selbst ab`));
        box.append(row);
    }
}

function drawPlayers() {
    const box = $('players');
    box.innerHTML = '';
    for (const p of S.players) {
        const card = el('div', 'pl' + (p.isMe ? ' me' : '') + (p.out ? ' out' : '') +
            (p.correct === true ? ' ok' : p.correct === false ? ' bad' : ''));
        const img = el('img', 'av');
        img.src = p.avatar;
        img.alt = '';
        card.append(img);
        card.append(el('div', 'chalk', (p.premium ? '💎 ' : '') + p.name));

        const lives = el('div', 'lives');
        for (let i = 0; i < 3; i++) lives.append(el('span', i < p.lives ? 'on' : 'off', '♥'));
        card.append(lives);

        card.append(el('div', 'sm', `Sechsen: ${p.sixes} · Joker: ${p.joker}`));
        if (S.phase === 'over' || S.mode === 'rapid') card.append(el('div', 'sm', `Subs: ${p.points}`));

        if (S.phase === 'ask') {
            card.append(el('div', 'sm', p.answered ? 'hat geantwortet' : 'denkt nach …'));
        }
        if (S.phase === 'ask' && !p.isMe && (isHost || p.spiedByMe)) card.append(liveCanvas(p.id));
        else if (p.answer) card.append(answerNode(p.answer));

        if (isHost && S.phase === 'reveal' && p.answered && !p.out) {
            card.append(el('div', 'sm', marks[p.id] ? '✓ richtig markiert' : '✗ falsch markiert'));
        }
        box.append(card);
    }
}

// ---------- Zeichenfeld (Antwort wird gemalt) ----------
let pad = null, padCtx = null, padKey = null, padColor = '#111111', padDown = false, padLast = null, padBtn = null, padInfo = null;

function initPad() {
    pad = el('canvas', 'pad');
    pad.width = 800;
    pad.height = 500;
    padCtx = pad.getContext('2d');
    const pos = (e) => {
        const r = pad.getBoundingClientRect();
        return { x: (e.clientX - r.left) * pad.width / r.width, y: (e.clientY - r.top) * pad.height / r.height };
    };
    pad.addEventListener('pointerdown', (e) => { padDown = true; padLast = pos(e); pad.setPointerCapture(e.pointerId); });
    pad.addEventListener('pointermove', (e) => {
        if (!padDown) return;
        const p = pos(e);
        padCtx.strokeStyle = padColor;
        padCtx.lineWidth = 8;
        padCtx.lineCap = 'round';
        padCtx.beginPath();
        padCtx.moveTo(padLast.x, padLast.y);
        padCtx.lineTo(p.x, p.y);
        padCtx.stroke();
        queueSeg([r3(padLast.x / pad.width), r3(padLast.y / pad.height), r3(p.x / pad.width), r3(p.y / pad.height), padColor]);
        padLast = p;
    });
    const up = () => { padDown = false; };
    pad.addEventListener('pointerup', up);
    pad.addEventListener('pointercancel', up);

    const bar = el('div', 'row');
    for (const col of ['#111111', '#d62828', '#1d4ed8', '#15803d']) {
        const s = el('button', 'swatch');
        s.type = 'button';
        s.style.background = col;
        s.onclick = () => { padColor = col; };
        bar.append(s);
    }
    bar.append(btn('Löschen', 'red', () => { clearPad(); queueClear(); }));
    padBtn = btn('Abschicken', 'green', () => api('answer', { answer: pad.toDataURL('image/jpeg', 0.6) }));
    padInfo = el('div', 'sm', '');
    $('padBox').append(el('div', 'sm', 'Male deine Antwort:'), bar, pad, padBtn, padInfo);
    clearPad();
}

function clearPad() {
    padCtx.fillStyle = '#ffffff';
    padCtx.fillRect(0, 0, pad.width, pad.height);
}

function updatePad() {
    const box = $('padBox');
    const me = S.me;
    const show = !isHost && S.phase === 'ask' && me && !me.out;
    box.style.display = show ? 'block' : 'none';
    if (!show) return;
    const key = S.round + '|' + S.mode;
    if (padKey !== key) { padKey = key; clearPad(); }
    padBtn.textContent = me.answered ? 'Zeichnung ändern' : 'Abschicken';
    padInfo.textContent = me.answered ? 'Deine Zeichnung ist gespeichert.' : '';
}

// Antwort anzeigen: Zeichnung als Bild, sonst Text
function answerNode(a, cls = 'ans') {
    if (a && a.startsWith('data:image')) {
        const im = el('img', 'ans-img');
        im.src = a;
        im.alt = 'Zeichnung';
        return im;
    }
    return el('div', cls, a);
}

// ---------- Live-Striche ----------
let drawQueue = [], flushing = false, lastStroke = 0, viewKey = null, strokeRoundKey = null;
const liveCvs = {};
const r3 = (v) => Math.round(v * 1000) / 1000;

function queueSeg(seg) {
    const last = drawQueue[drawQueue.length - 1];
    if (Array.isArray(last)) last.push(seg); else drawQueue.push([seg]);
}
function queueClear() { drawQueue.push('clear'); }

async function flushDraw() {
    if (flushing || !drawQueue.length) return;
    if (!S || S.phase !== 'ask') { drawQueue = []; return; }
    flushing = true;
    try {
        while (drawQueue.length) {
            const op = drawQueue.shift();
            await fetch('ei_api.php', {
                method: 'POST',
                body: new URLSearchParams({ code, action: 'draw', data: op === 'clear' ? 'clear' : JSON.stringify(op) }),
                cache: 'no-store',
            });
        }
    } finally { flushing = false; }
}
setInterval(flushDraw, 200);

function liveCanvas(pid) {
    if (!liveCvs[pid]) {
        const cv = el('canvas', 'live-cv');
        cv.width = 400;
        cv.height = 250;
        const cx = cv.getContext('2d');
        cx.fillStyle = '#ffffff';
        cx.fillRect(0, 0, cv.width, cv.height);
        liveCvs[pid] = cv;
    }
    return liveCvs[pid];
}

function clearLive(pid) {
    const cv = liveCvs[pid];
    if (!cv) return;
    const cx = cv.getContext('2d');
    cx.fillStyle = '#ffffff';
    cx.fillRect(0, 0, cv.width, cv.height);
}

function applyStrokes(list) {
    for (const st of list) {
        if (st.d === myId) continue;   // eigene Striche sind schon lokal sichtbar
        if (st.s === null) { clearLive(st.d); continue; }
        const cv = liveCanvas(st.d);
        const cx = cv.getContext('2d');
        cx.lineWidth = 3;
        cx.lineCap = 'round';
        for (const [x0, y0, x1, y1, col] of st.s) {
            cx.strokeStyle = col;
            cx.beginPath();
            cx.moveTo(x0 * cv.width, y0 * cv.height);
            cx.lineTo(x1 * cv.width, y1 * cv.height);
            cx.stroke();
        }
    }
}

// Wer sieht wessen Striche: Host alle anderen, Spicker nur die Spieler, bei denen er schaut
function syncStrokes() {
    const viewers = S.players.filter(p => !p.isMe && (isHost || p.spiedByMe)).map(p => p.id).sort().join(',');
    const roundKey = S.round + '|' + S.mode + '|' + S.phase;
    if (viewers !== viewKey || roundKey !== strokeRoundKey) {
        // Sichtbarkeit oder Runde hat sich geändert: alles neu einlesen
        viewKey = viewers;
        strokeRoundKey = roundKey;
        lastStroke = 0;
        for (const pid in liveCvs) clearLive(pid);
        return;
    }
    const list = S.strokes || [];
    applyStrokes(list);
    if (list.length) lastStroke = list[list.length - 1].id;
}

// ---------- Kamera und Mikrofon (LiveKit, gleicher Raum wie das Brett) ----------
const LK = window.LivekitClient;
const IS_MOBILE = /Android|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent);
const arena = $('arena');
const camBtn = $('camBtn');
const micBtn = $('micBtn');
let room = null;
let roomPlayers = [];
let lastCamSig = '';
let speakingSet = new Set();
const audioEls = new Map();
const videoCache = new Map();

function setStatus(text) { $('lkStatus').textContent = text; }

function rememberFlag(name, on) {
    try { if (on) sessionStorage.setItem(name, '1'); else sessionStorage.removeItem(name); } catch (e) {}
}
function wasFlagOn(name) {
    try { return sessionStorage.getItem(name) === '1'; } catch (e) { return false; }
}

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

function dropVideo(id) {
    const c = videoCache.get(id);
    if (!c) return;
    c.track.detach(c.el);
    videoCache.delete(id);
}

function videoFor(id, track, mirror) {
    if (!track) { dropVideo(id); return null; }
    const c = videoCache.get(id);
    if (c && c.sid === track.sid) {
        c.el.style.transform = mirror ? 'scaleX(-1)' : '';
        return c.el;
    }
    dropVideo(id);
    const v = track.attach();
    v.playsInline = true;
    v.muted = true;
    v.style.transform = mirror ? 'scaleX(-1)' : '';
    videoCache.set(id, { sid: track.sid, el: v, track });
    return v;
}

function buildCam(p) {
    const m = mediaFor(p.discord_id);
    const isMe = p.discord_id === myId;
    const card = el('div', 'pcard' + (speakingSet.has(p.discord_id) ? ' speaking' : ''));
    const cam = el('div', 'pcam');
    const video = videoFor(p.discord_id, m.track, isMe);
    if (video) {
        cam.append(video);
    } else {
        const img = el('img', 'pph');
        img.src = p.avatar_url;
        img.alt = '';
        cam.append(img);
    }
    card.append(cam);
    if (m.micOn) card.append(el('div', 'pmic', '🎤'));
    card.append(el('div', 'pstate ' + (video ? 'on' : 'off')));
    card.append(el('div', 'pname', (p.is_host ? '👑 ' : '') + (p.premium ? '💎 ' : '') + p.username));
    return card;
}

function syncCamButtons() {
    const lp = room ? room.localParticipant : null;
    const camOn = !!(lp && lp.isCameraEnabled);
    const micOn = !!(lp && lp.isMicrophoneEnabled);
    camBtn.textContent = camOn ? 'Kamera aus' : 'Kamera an';
    camBtn.classList.toggle('active', camOn);
    micBtn.textContent = micOn ? '🎤 Stumm schalten' : 'Mikrofon an';
    micBtn.classList.toggle('active', micOn);
}

function drawCams() {
    syncCamButtons();
    const sig = JSON.stringify(roomPlayers.map(p => {
        const m = mediaFor(p.discord_id);
        return [p.discord_id, p.username, p.avatar_url, p.is_host, p.premium, m.track ? m.track.sid : '', m.micOn, speakingSet.has(p.discord_id)];
    }));
    if (sig === lastCamSig) return;
    lastCamSig = sig;
    for (const id of [...videoCache.keys()]) {
        if (!mediaFor(id).track) dropVideo(id);
    }
    arena.innerHTML = '';
    const order = [...roomPlayers.filter(p => p.is_host), ...roomPlayers.filter(p => !p.is_host)];
    for (const p of order) arena.append(buildCam(p));
}

async function refreshRoomPlayers() {
    try {
        const res = await fetch('/api/players.php?code=' + encodeURIComponent(code), { cache: 'no-store' });
        if (res.ok) roomPlayers = await res.json();
    } catch (e) {}
    drawCams();
    setTimeout(refreshRoomPlayers, 2000);
}

async function restoreMedia() {
    if (wasFlagOn('kr_cam')) await room.localParticipant.setCameraEnabled(true).catch(() => {});
    if (wasFlagOn('kr_mic')) await room.localParticipant.setMicrophoneEnabled(true).catch(() => {});
}

async function connectLiveKit() {
    if (!LK) { setStatus('LiveKit konnte nicht geladen werden. Internetverbindung prüfen.'); return; }
    if (room) { room.disconnect(); room = null; }
    setStatus('verbinde …');
    try {
        const res = await fetch('/api/livekit_token.php?code=' + encodeURIComponent(code), { cache: 'no-store' });
        const data = await res.json();
        if (!res.ok || !data.token) throw new Error(data.error || 'kein Zugang');

        room = new LK.Room({
            adaptiveStream: true,
            dynacast: true,
            videoCaptureDefaults: {
                resolution: IS_MOBILE ? { width: 320, height: 180, frameRate: 15 } : { width: 640, height: 360, frameRate: 20 },
            },
        });
        const E = LK.RoomEvent;
        for (const ev of [E.ParticipantConnected, E.ParticipantDisconnected, E.TrackMuted, E.TrackUnmuted, E.LocalTrackPublished, E.LocalTrackUnpublished]) {
            room.on(ev, () => drawCams());
        }
        room.on(E.TrackSubscribed, (track, pub, participant) => {
            if (track.kind === 'audio') {
                const a = track.attach();
                a.style.display = 'none';
                document.body.append(a);
                audioEls.set(participant.identity, a);
            }
            drawCams();
        });
        room.on(E.TrackUnsubscribed, (track, pub, participant) => {
            if (track.kind === 'audio') audioEls.delete(participant.identity);
            track.detach().forEach(a => a.remove());
            drawCams();
        });
        room.on(E.ActiveSpeakersChanged, (sp) => {
            speakingSet = new Set(sp.map(s => s.identity));
            drawCams();
        });
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

camBtn.addEventListener('click', async () => {
    if (!room) return;
    try {
        const on = room.localParticipant.isCameraEnabled;
        await room.localParticipant.setCameraEnabled(!on);
        rememberFlag('kr_cam', !on);
    } catch (e) {
        alert('Kamera konnte nicht gestartet werden: ' + e.message);
    }
    drawCams();
});

micBtn.addEventListener('click', async () => {
    if (!room) return;
    try {
        const on = room.localParticipant.isMicrophoneEnabled;
        await room.localParticipant.setMicrophoneEnabled(!on);
        rememberFlag('kr_mic', !on);
    } catch (e) {
        alert('Mikrofon konnte nicht gestartet werden: ' + e.message);
    }
    drawCams();
});

document.addEventListener('click', () => { if (room) room.startAudio().catch(() => {}); });

let AC = null;
function audio() {
    if (!AC) { const C = window.AudioContext || window.webkitAudioContext; if (!C) return null; AC = new C(); }
    if (AC.state === 'suspended') AC.resume().catch(() => {});
    return AC;
}
['click', 'keydown'].forEach(ev => document.addEventListener(ev, audio, { passive: true }));

function tone(freq, dur = 0.2, type = 'sine', delay = 0, vol = 0.2) {
    const c = audio(); if (!c) return;
    const t = c.currentTime + delay;
    const o = c.createOscillator(), g = c.createGain();
    o.type = type; o.frequency.setValueAtTime(freq, t);
    g.gain.setValueAtTime(vol, t); g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
    o.connect(g); g.connect(c.destination); o.start(t); o.stop(t + dur + 0.02);
}
const sfx = {
    bell:    () => { tone(988, 0.9, 'sine', 0, 0.25); tone(988, 0.9, 'sine', 0.9, 0.2); },
    fanfare: () => { [523, 659, 784, 1047].forEach((f, i) => tone(f, 0.25, 'triangle', i * 0.15, 0.22)); },
    wrong:   () => { tone(330, 0.25, 'sawtooth', 0, 0.2); tone(220, 0.4, 'sawtooth', 0.22, 0.2); },
    right:   () => { tone(660, 0.12, 'triangle'); tone(880, 0.2, 'triangle', 0.12); },
    fail:    () => { [392, 349, 330, 262].forEach((f, i) => tone(f, 0.45, 'triangle', i * 0.4, 0.22)); },
};

const LEHRER = [
    'Setzen!', 'Das war ja mal gar nichts.', 'Ich bin enttäuscht.', 'Handy weg, sonst gibt es Strafarbeit!',
    'Abschreiben zählt nicht als Wissen.', 'Wer hat denn da gespickt?', 'Das wird noch Thema bei den Eltern.',
    'Ruhe! Hier wird gelernt!', 'Gut. Das hätte ich nicht erwartet.',
];

let fx0 = { phase: null, cls: null, out: false, lives: null };
let autoRev = false;

function say(text) {
    const t = document.getElementById('teacher');
    if (t) t.textContent = '🧑‍🏫 Lehrer: „' + text + '“';
}

function failOverlay() {
    const f = document.getElementById('fail');
    f.style.display = 'flex';
    sfx.fail();
    setTimeout(() => { f.style.display = 'none'; }, 4500);
}

function fx() {
    if (!S) return;
    if (fx0.phase !== S.phase) {
        if (S.phase === 'ask') { sfx.bell(); say('Setzen! Die Zeit läuft.'); }
        if (S.phase === 'reveal') say(LEHRER[Math.floor(Math.random() * LEHRER.length)]);
        if (S.phase === 'result' && S.me && fx0.lives !== null && S.me.lives < fx0.lives) sfx.wrong();
        if (S.phase === 'result' && S.me && fx0.lives !== null && S.me.lives === fx0.lives && S.me.answered && !S.me.out) sfx.right();
        fx0.phase = S.phase;
    }
    if (S.class && fx0.cls !== null && S.class !== fx0.cls) { sfx.fanfare(); say('Klasse ' + S.class + '! Ein neues Schuljahr beginnt.'); }
    if (S.class) fx0.cls = S.class;
    if (S.me) {
        if (S.me.out && !fx0.out) failOverlay();
        fx0.out = S.me.out;
        fx0.lives = S.me.lives;
    }
}

(function planes() {
    const box = document.querySelector('.planes');
    for (let i = 0; i < 8; i++) {
        const s = document.createElement('span');
        s.textContent = '✈';
        s.style.top = (Math.random() * 90) + 'vh';
        s.style.fontSize = (18 + Math.random() * 26) + 'px';
        s.style.animationDuration = (18 + Math.random() * 16) + 's';
        s.style.animationDelay = (-Math.random() * 30) + 's';
        box.append(s);
    }
})();

initPad();
load();
refreshRoomPlayers();
connectLiveKit();
</script>
<script>
// Alle Spieler im Raum folgen dem Moduswechsel des Hosts
(function () {
    const CUR = 'eingestuhlt';
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
<script>(function(){try{var c=new URLSearchParams(location.search).get('code');if(c)localStorage.setItem('kr_last_room',JSON.stringify({code:c.toUpperCase(),t:Date.now()}));}catch(e){}})();</script>
<script src="/js/bigtext.js"></script>
</body>
</html>
<!-- Version 1.16 -->
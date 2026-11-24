<?php
// Version 1.0
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
// modes/quiz/quiz.php – Quiz-Duell (Kahoot-artig). NUR Lehrkräfte können Räume starten, Fragen anlegen und steuern.
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/db.php';

const QUIZ_GRACE_MS = 500;      // Kulanzzeit nach Ablauf der Zeit
const QUIZ_REVEAL_MS = 6000;    // Anzeige der Auflösung, bevor es weitergeht
const QUIZ_AWAY_S = 30;         // Spieler gilt als weg, wenn 30 s kein Kontakt

function qq(string $sql, array $p = []): PDOStatement
{
    $s = db()->prepare($sql);
    $s->execute($p);
    return $s;
}

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function now_ms(): int
{
    return (int)round(microtime(true) * 1000);
}

function json_out(array $data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function quiz_row(int $rid): array
{
    return qq('SELECT phase, qno, phase_ms FROM kr_quiz_state WHERE room_id = ?', [$rid])->fetch()
        ?: ['phase' => 'lobby', 'qno' => 0, 'phase_ms' => 0];
}

function quiz_count(int $rid): int
{
    return (int)qq('SELECT COUNT(*) FROM kr_quiz_items WHERE room_id = ?', [$rid])->fetchColumn();
}

function quiz_item(int $rid, int $qno): ?array
{
    $r = qq('SELECT qno, question, opts, correct, seconds FROM kr_quiz_items WHERE room_id = ? AND qno = ?', [$rid, $qno])->fetch();
    if (!$r) return null;
    return [
        'question' => (string)$r['question'],
        'opts' => json_decode((string)$r['opts'], true) ?: [],
        'correct' => (int)$r['correct'],
        'seconds' => (int)$r['seconds'],
    ];
}

function active_player_count(int $rid, string $hostId): int
{
    return (int)qq(
        'SELECT COUNT(*) FROM kr_room_players WHERE room_id = ? AND discord_id <> ? AND last_seen >= NOW() - INTERVAL ' . QUIZ_AWAY_S . ' SECOND',
        [$rid, $hostId]
    )->fetchColumn();
}

function answered_count(int $rid, int $qno): int
{
    return (int)qq('SELECT COUNT(*) FROM kr_quiz_answers WHERE room_id = ? AND qno = ?', [$rid, $qno])->fetchColumn();
}

function quiz_ranking(int $rid, string $hostId): array
{
    $rows = qq(
        'SELECT p.discord_id AS id, p.username AS name, COALESCE(SUM(a.points), 0) AS score
         FROM kr_room_players p
         LEFT JOIN kr_quiz_answers a ON a.room_id = p.room_id AND a.discord_id = p.discord_id
         WHERE p.room_id = ? AND p.discord_id <> ?
         GROUP BY p.discord_id, p.username
         ORDER BY score DESC, p.username ASC',
        [$rid, $hostId]
    )->fetchAll();
    return array_map(fn($r) => ['id' => (string)$r['id'], 'name' => (string)$r['name'], 'score' => (int)$r['score']], $rows);
}

// Start: Fragen der Lehrkraft in zufälliger Antwortreihenfolge als Momentaufnahme für diesen Raum speichern
function quiz_start(int $rid, string $me): array
{
    $bank = qq('SELECT question, correct, wrong1, wrong2, wrong3, seconds FROM kr_quiz_questions WHERE owner_key = ? ORDER BY id', [$me])->fetchAll();
    if (!$bank) return ['ok' => false, 'error' => 'Du hast noch keine Fragen. Lege sie zuerst unter „Fragen-Bank“ an.'];

    qq('DELETE FROM kr_quiz_answers WHERE room_id = ?', [$rid]);
    qq('DELETE FROM kr_quiz_items WHERE room_id = ?', [$rid]);

    foreach ($bank as $i => $b) {
        $opts = [(string)$b['correct'], (string)$b['wrong1'], (string)$b['wrong2'], (string)$b['wrong3']];
        $order = [0, 1, 2, 3];
        shuffle($order);
        $shuffled = array_map(fn($k) => $opts[$k], $order);
        $correct = (int)array_search(0, $order, true);
        qq('INSERT INTO kr_quiz_items (room_id, qno, question, opts, correct, seconds) VALUES (?, ?, ?, ?, ?, ?)', [
            $rid, $i, (string)$b['question'], json_encode($shuffled, JSON_UNESCAPED_UNICODE), $correct, (int)$b['seconds'],
        ]);
    }
    qq("UPDATE kr_quiz_state SET phase = 'question', qno = 0, phase_ms = ? WHERE room_id = ?", [now_ms(), $rid]);
    return ['ok' => true];
}

// Phasen weiterschalten: Frage → Auflösung → nächste Frage → Ergebnis
function quiz_advance(int $rid, string $hostId, bool $force): array
{
    $st = quiz_row($rid);
    $now = now_ms();
    $phaseMs = (int)$st['phase_ms'];
    $qno = (int)$st['qno'];

    if ($st['phase'] === 'question') {
        $item = quiz_item($rid, $qno);
        if (!$item) return ['ok' => false, 'error' => 'Frage fehlt.'];
        $over = ($phaseMs + $item['seconds'] * 1000 - $now) <= 0;
        $active = active_player_count($rid, $hostId);
        $all = $active > 0 && answered_count($rid, $qno) >= $active;
        if (!$force && !$over && !$all) return ['ok' => false, 'error' => 'Noch nicht alle geantwortet.'];
        qq("UPDATE kr_quiz_state SET phase = 'reveal', phase_ms = ? WHERE room_id = ?", [$now, $rid]);
        return ['ok' => true];
    }

    if ($st['phase'] === 'reveal') {
        if (!$force && ($now - $phaseMs) < QUIZ_REVEAL_MS) return ['ok' => false, 'error' => 'Auflösung läuft noch.'];
        if ($qno + 1 < quiz_count($rid)) {
            qq("UPDATE kr_quiz_state SET phase = 'question', qno = ?, phase_ms = ? WHERE room_id = ?", [$qno + 1, $now, $rid]);
        } else {
            qq("UPDATE kr_quiz_state SET phase = 'done', phase_ms = ? WHERE room_id = ?", [$now, $rid]);
        }
        return ['ok' => true];
    }

    return ['ok' => false, 'error' => 'Gerade nicht möglich.'];
}

// Antwort eines Spielers. Punkte: 1000 bei sofortiger Antwort, 500 bei Ablauf der Zeit.
function quiz_answer(int $rid, string $me, int $choice): array
{
    $st = quiz_row($rid);
    if ($st['phase'] !== 'question') return ['ok' => false, 'error' => 'Gerade keine offene Frage.'];
    if ($choice < 0 || $choice > 3) return ['ok' => false, 'error' => 'Ungültige Auswahl.'];

    $qno = (int)$st['qno'];
    $item = quiz_item($rid, $qno);
    if (!$item) return ['ok' => false, 'error' => 'Frage fehlt.'];

    $elapsed = max(0, now_ms() - (int)$st['phase_ms']);
    $limit = $item['seconds'] * 1000;
    if ($elapsed > $limit + QUIZ_GRACE_MS) return ['ok' => false, 'error' => 'Zu spät.'];

    $points = 0;
    if ($choice === $item['correct']) {
        $points = (int)round(1000 - 500 * min(1, $elapsed / $limit));
    }
    $ins = qq('INSERT IGNORE INTO kr_quiz_answers (room_id, discord_id, qno, choice, points, ms) VALUES (?, ?, ?, ?, ?, ?)',
        [$rid, $me, $qno, $choice, $points, $elapsed]);
    if ($ins->rowCount() === 0) return ['ok' => false, 'error' => 'Schon beantwortet.'];
    return ['ok' => true];
}

// Zustand für die Anzeige. Die richtige Antwort kommt erst in der Auflösung.
function quiz_payload(int $rid, string $me, bool $isHost, string $hostId): array
{
    $st = quiz_row($rid);
    $phase = (string)$st['phase'];
    $qno = (int)$st['qno'];

    $out = [
        'ok' => true,
        'phase' => $phase,
        'qno' => $qno,
        'total' => quiz_count($rid),
        'now' => now_ms(),
        'phase_ms' => (int)$st['phase_ms'],
        'is_host' => $isHost,
        'active' => active_player_count($rid, $hostId),
    ];

    $item = in_array($phase, ['question', 'reveal'], true) ? quiz_item($rid, $qno) : null;
    if ($item) {
        $q = ['text' => $item['question'], 'options' => $item['opts'], 'seconds' => $item['seconds']];
        if ($phase === 'reveal') $q['correct'] = $item['correct'];
        $out['question'] = $q;
        $out['answered'] = answered_count($rid, $qno);
        if ($phase === 'question') {
            $out['remaining_ms'] = $item['seconds'] * 1000 - (now_ms() - (int)$st['phase_ms']);
        }
    }

    $ranking = quiz_ranking($rid, $hostId);
    $myIdx = null;
    foreach ($ranking as $i => $r) {
        if ($r['id'] === $me) { $myIdx = $i; break; }
    }
    $out['my_total'] = $myIdx === null ? 0 : $ranking[$myIdx]['score'];
    $out['my_place'] = $myIdx === null ? null : $myIdx + 1;
    $out['top'] = array_map(fn($r) => ['name' => $r['name'], 'score' => $r['score']], array_slice($ranking, 0, 3));

    if ($isHost) {
        $out['ranking'] = array_map(fn($r) => ['name' => $r['name'], 'score' => $r['score']], $ranking);
        if ($item) {
            $dist = [0, 0, 0, 0];
            $rows = qq('SELECT choice, COUNT(*) AS c FROM kr_quiz_answers WHERE room_id = ? AND qno = ? GROUP BY choice', [$rid, $qno])->fetchAll();
            foreach ($rows as $d) {
                $dist[(int)$d['choice']] = (int)$d['c'];
            }
            $out['dist'] = $dist;
        }
    } else {
        qq('UPDATE kr_room_players SET last_seen = NOW() WHERE room_id = ? AND discord_id = ?', [$rid, $me]);
        if ($item && in_array($phase, ['question', 'reveal'], true)) {
            $mine = qq('SELECT choice, points FROM kr_quiz_answers WHERE room_id = ? AND discord_id = ? AND qno = ?', [$rid, $me, $qno])->fetch();
            $out['my'] = [
                'answered' => (bool)$mine,
                'points' => $mine ? (int)$mine['points'] : 0,
                'correct' => ($mine && $phase === 'reveal') ? ((int)$mine['choice'] === $item['correct']) : false,
            ];
        }
    }
    return $out;
}

// ---------- Session & Rolle ----------
$user = $_SESSION['discord_user'] ?? null;
if (!$user) {
    header('Location: /index.php');
    exit;
}
$me = (string)$user['id'];
$myName = (string)($user['name'] ?? $user['username'] ?? 'Gast');
$isGuest = !empty($user['guest']);
$isTeacher = !$isGuest && (int)qq('SELECT is_teacher FROM kr_profiles WHERE user_key = ?', [$me])->fetchColumn() === 1;

// ---------- Fragen-Bank (nur Lehrkräfte) ----------
if (isset($_GET['bank'])) {
    if (!$isTeacher) {
        http_response_code(403);
        exit('Die Fragen-Bank ist nur für Lehrkräfte.');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $act = $_POST['action'] ?? '';
        if ($act === 'add_q') {
            $question = trim((string)($_POST['question'] ?? ''));
            $correct = trim((string)($_POST['correct'] ?? ''));
            $w1 = trim((string)($_POST['w1'] ?? ''));
            $w2 = trim((string)($_POST['w2'] ?? ''));
            $w3 = trim((string)($_POST['w3'] ?? ''));
            $secs = max(5, min(60, (int)($_POST['seconds'] ?? 20)));
            if ($question !== '' && $correct !== '' && $w1 !== '' && $w2 !== '' && $w3 !== '') {
                qq('INSERT INTO kr_quiz_questions (owner_key, question, correct, wrong1, wrong2, wrong3, seconds) VALUES (?, ?, ?, ?, ?, ?, ?)', [
                    $me, mb_substr($question, 0, 500), mb_substr($correct, 0, 200), mb_substr($w1, 0, 200),
                    mb_substr($w2, 0, 200), mb_substr($w3, 0, 200), $secs,
                ]);
                $_SESSION['quiz_msg'] = 'Frage gespeichert.';
            } else {
                $_SESSION['quiz_msg'] = 'Bitte Frage und alle 4 Antworten ausfüllen.';
            }
        } elseif ($act === 'del_q') {
            qq('DELETE FROM kr_quiz_questions WHERE id = ? AND owner_key = ?', [(int)($_POST['id'] ?? 0), $me]);
            $_SESSION['quiz_msg'] = 'Frage gelöscht.';
        }
        header('Location: /modes/quiz/quiz.php?bank=1');
        exit;
    }
    $list = qq('SELECT id, question, correct, seconds FROM kr_quiz_questions WHERE owner_key = ? ORDER BY id', [$me])->fetchAll();
    $msg = (string)($_SESSION['quiz_msg'] ?? '');
    unset($_SESSION['quiz_msg']);
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Fragen-Bank · Quiz-Duell</title>
<style>
body { margin:0; font-family: system-ui, sans-serif; background:#1d1030; color:#fff; }
.wrap { max-width:720px; margin:0 auto; padding:16px; }
a { color:#ff9ad5; }
.card { background:rgba(255,255,255,.08); border-radius:16px; padding:18px; margin:14px 0; }
label { display:block; margin:10px 0; font-weight:600; }
input, textarea { width:100%; box-sizing:border-box; padding:10px; border-radius:10px; border:none; margin-top:4px; font-size:15px; }
textarea { min-height:70px; }
.btn { display:inline-block; background:#ff6fb5; color:#fff; border:none; border-radius:12px; padding:10px 16px; font-weight:700; cursor:pointer; text-decoration:none; }
.btn-ghost { background:rgba(255,255,255,.15); }
.notice { background:rgba(46,204,113,.2); padding:10px; border-radius:10px; }
.row { display:flex; justify-content:space-between; align-items:center; gap:10px; padding:8px 0; border-bottom:1px solid rgba(255,255,255,.1); }
.small { font-size:13px; opacity:.8; }
</style>
</head>
<body>
<div class="wrap">
    <p><a href="/lobby.php">← Lobby</a></p>
    <h1>Quiz-Duell · Fragen-Bank</h1>
    <p class="small">Deine Fragen. Nur du siehst sie. Für jede Frage gibt es eine richtige und drei falsche Antworten.</p>
    <?php if ($msg !== ''): ?><p class="notice"><?= h($msg) ?></p><?php endif; ?>

    <form method="post" class="card">
        <input type="hidden" name="action" value="add_q">
        <label>Frage<textarea name="question" maxlength="500" required></textarea></label>
        <label>Richtige Antwort<input name="correct" maxlength="200" required></label>
        <label>Falsche Antwort 1<input name="w1" maxlength="200" required></label>
        <label>Falsche Antwort 2<input name="w2" maxlength="200" required></label>
        <label>Falsche Antwort 3<input name="w3" maxlength="200" required></label>
        <label>Sekunden zum Antworten (5 bis 60)<input type="number" name="seconds" min="5" max="60" value="20"></label>
        <button class="btn" type="submit">Frage speichern</button>
    </form>

    <div class="card">
        <h2>Deine Fragen (<?= count($list) ?>)</h2>
        <?php if (!$list): ?><p class="small">Noch keine Fragen.</p><?php endif; ?>
        <?php foreach ($list as $row): ?>
            <div class="row">
                <div><?= h($row['question']) ?><div class="small">Richtig: <?= h($row['correct']) ?> · <?= (int)$row['seconds'] ?> s</div></div>
                <form method="post" style="margin:0">
                    <input type="hidden" name="action" value="del_q">
                    <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                    <button class="btn btn-ghost" type="submit">Löschen</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
</div>
</body>
</html>
    <?php
    exit;
}

// ---------- Quiz-Duell anlegen (nur Lehrkräfte) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    if (!$isTeacher) {
        http_response_code(403);
        exit('Nur Lehrkräfte können ein Quiz-Duell starten.');
    }
    do {
        $newCode = strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 6));
    } while (qq('SELECT 1 FROM kr_rooms WHERE code = ?', [$newCode])->fetchColumn());
    qq('INSERT INTO kr_rooms (code, host_id, mode) VALUES (?, ?, ?)', [$newCode, $me, 'quiz']);
    $newId = (int)db()->lastInsertId();
    qq("INSERT IGNORE INTO kr_quiz_state (room_id, phase, qno, phase_ms) VALUES (?, 'lobby', 0, 0)", [$newId]);
    header('Location: /modes/quiz/quiz.php?code=' . $newCode);
    exit;
}

$code = strtoupper(trim((string)($_GET['code'] ?? $_POST['code'] ?? '')));

// ---------- Startseite ohne Raum ----------
if ($code === '') {
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Quiz-Duell</title>
<style>
body { margin:0; font-family: system-ui, sans-serif; background:#1d1030; color:#fff; }
.wrap { max-width:520px; margin:0 auto; padding:16px; }
a { color:#ff9ad5; }
.card { background:rgba(255,255,255,.08); border-radius:16px; padding:18px; margin:14px 0; }
input { padding:10px; border-radius:10px; border:none; font-size:16px; text-transform:uppercase; }
.btn { display:inline-block; background:#ff6fb5; color:#fff; border:none; border-radius:12px; padding:10px 16px; font-weight:700; cursor:pointer; text-decoration:none; }
</style>
</head>
<body>
<div class="wrap">
    <p><a href="/lobby.php">← Lobby</a></p>
    <h1>Quiz-Duell</h1>
    <?php if ($isTeacher): ?>
        <div class="card">
            <form method="post" action="/modes/quiz/quiz.php">
                <input type="hidden" name="action" value="create">
                <button class="btn" type="submit">Neues Quiz-Duell starten</button>
            </form>
            <p><a href="/modes/quiz/quiz.php?bank=1">Fragen-Bank öffnen</a></p>
        </div>
    <?php endif; ?>
    <div class="card">
        <form method="get" action="/modes/quiz/quiz.php">
            <label>Raum-Code eingeben<br><input name="code" maxlength="10" required></label>
            <button class="btn" type="submit">Beitreten</button>
        </form>
    </div>
</div>
</body>
</html>
    <?php
    exit;
}

// ---------- Raum ----------
$room = qq('SELECT id, host_id FROM kr_rooms WHERE code = ?', [$code])->fetch();
if (!$room) {
    die('Raum nicht gefunden. <a href="/modes/quiz/quiz.php">Zurück</a>');
}
$rid = (int)$room['id'];
$hostId = (string)$room['host_id'];
$isHost = $isTeacher && $hostId === $me;

qq("INSERT IGNORE INTO kr_quiz_state (room_id, phase, qno, phase_ms) VALUES (?, 'lobby', 0, 0)", [$rid]);

// Spieler (auch Gäste) im Raum eintragen
if (!$isHost) {
    qq('INSERT INTO kr_room_players (room_id, discord_id, username, avatar, premium, decoration, team_id, last_seen)
        VALUES (?, ?, ?, ?, ?, ?, NULL, NOW())
        ON DUPLICATE KEY UPDATE username = VALUES(username), last_seen = NOW()', [
        $rid, $me, $myName, $user['avatar'] ?? null, (int)($user['premium'] ?? 0), $user['decoration'] ?? null,
    ]);
}

// ---------- Schritte (POST mit act) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act'])) {
    $act = (string)$_POST['act'];
    if (in_array($act, ['start', 'advance'], true) && !$isHost) {
        json_out(['ok' => false, 'error' => 'Nur die Lehrkraft steuert das Quiz.'], 403);
    }
    if ($act === 'start') {
        $res = quiz_start($rid, $me);
    } elseif ($act === 'advance') {
        $res = quiz_advance($rid, $me, !empty($_POST['force']));
    } elseif ($act === 'answer') {
        if ($isHost) json_out(['ok' => false, 'error' => 'Die Lehrkraft spielt nicht mit.'], 403);
        $res = quiz_answer($rid, $me, (int)($_POST['choice'] ?? -1));
    } else {
        json_out(['ok' => false, 'error' => 'Unbekannte Aktion.'], 400);
    }
    json_out($res, $res['ok'] ? 200 : 409);
}

// ---------- Zustand (JSON) ----------
if (isset($_GET['api']) && $_GET['api'] === 'state') {
    json_out(quiz_payload($rid, $me, $isHost, $hostId));
}

// ---------- Rangliste als CSV (nur Lehrkraft) ----------
if (isset($_GET['csv']) && $isHost) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="quiz-' . $code . '.csv"');
    echo "\xEF\xBB\xBF";
    $fp = fopen('php://output', 'w');
    fputcsv($fp, ['Platz', 'Name', 'Punkte'], ';');
    foreach (quiz_ranking($rid, $hostId) as $i => $r) {
        fputcsv($fp, [$i + 1, $r['name'], $r['score']], ';');
    }
    fclose($fp);
    exit;
}

$joinLink = 'https://kittysquiz.app/modes/quiz/quiz.php?code=' . $code;
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Quiz-Duell <?= h($code) ?></title>
<style>
body { margin:0; font-family: system-ui, sans-serif; background:#1d1030; color:#fff; }
.wrap { max-width:760px; margin:0 auto; padding:14px; }
a { color:#ff9ad5; }
.card { background:rgba(255,255,255,.08); border-radius:16px; padding:18px; margin:14px 0; text-align:center; }
.timer { font-size:40px; font-weight:800; color:#ffd166; }
.code { font-size:28px; letter-spacing:3px; color:#ffd166; }
.opts { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-top:16px; }
.opt { border:none; border-radius:14px; padding:20px 12px; font-size:18px; font-weight:700; color:#fff; cursor:pointer; text-align:left; }
.opt:disabled { opacity:.55; cursor:default; }
.opt.wrong { opacity:.35; }
.opt.right { outline:4px solid #fff; }
.btn { display:inline-block; background:#ff6fb5; color:#fff; border:none; border-radius:12px; padding:12px 18px; font-weight:700; cursor:pointer; text-decoration:none; margin:6px; font-size:16px; }
.podium { list-style:none; padding:0; font-size:20px; }
.podium li { padding:6px; }
table { width:100%; border-collapse:collapse; text-align:left; }
td, th { padding:6px; border-bottom:1px solid rgba(255,255,255,.1); }
.small { font-size:13px; opacity:.8; }
.result { font-size:22px; font-weight:800; }
#flash { color:#ffd166; min-height:20px; }
@media (max-width:600px) { .opts { grid-template-columns:1fr; } .opt { font-size:16px; } }
</style>
</head>
<body>
<div class="wrap">
    <p><a href="/lobby.php">← Lobby</a><?php if ($isHost): ?> · <a href="/modes/quiz/quiz.php?bank=1">Fragen-Bank</a><?php endif; ?></p>
    <div id="root"></div>
    <div id="flash"></div>
</div>
<script>
const CODE = <?= json_encode($code) ?>;
const JOIN_LINK = <?= json_encode($joinLink) ?>;
const IS_HOST = <?= $isHost ? 'true' : 'false' ?>;
const URL_ROOM = '/modes/quiz/quiz.php?code=' + encodeURIComponent(CODE);
const COLORS = ['#e74c3c', '#3498db', '#f1c40f', '#2ecc71'];
const LETTERS = ['A', 'B', 'C', 'D'];
const root = document.getElementById('root');
const flashEl = document.getElementById('flash');
let st = null, recvAt = 0, busy = false;

function esc(s) { const d = document.createElement('div'); d.textContent = String(s ?? ''); return d.innerHTML; }
function flash(t) { flashEl.textContent = t || ''; }
function remainingNow() { return Math.max(0, (st.remaining_ms || 0) - (Date.now() - recvAt)); }

async function post(params) {
    const r = await fetch(URL_ROOM, { method: 'POST', body: new URLSearchParams(params) });
    return r.json();
}

async function poll() {
    try {
        const r = await fetch(URL_ROOM + '&api=state', { cache: 'no-store' });
        const j = await r.json();
        if (j.ok) {
            st = j; recvAt = Date.now();
            render();
            if (IS_HOST) hostTick();
        }
    } catch (e) { /* Netz kurz weg, nächster Versuch folgt */ }
    setTimeout(poll, 1000);
}

async function advance(force) {
    if (busy) return;
    busy = true;
    try {
        const j = await post({ act: 'advance', force: force ? '1' : '' });
        if (!j.ok && force) flash(j.error || '');
    } finally { busy = false; }
}

// Die Lehrkraft-Seite schaltet automatisch weiter: nach Zeitablauf oder wenn alle geantwortet haben, und nach der Auflösung
function hostTick() {
    if (!st || busy) return;
    if (st.phase === 'question') {
        const left = remainingNow();
        if (left <= 0 || (st.active > 0 && st.answered >= st.active)) advance(false);
    } else if (st.phase === 'reveal') {
        const serverNow = st.now + (Date.now() - recvAt);
        if (serverNow - st.phase_ms >= 6000) advance(false);
    }
}

function list(arr, withScore) {
    return '<ul style="list-style:none;padding:0">' + arr.map(r =>
        `<li>${esc(r.name)}${withScore ? ' – ' + r.score : ''}</li>`).join('') + '</ul>';
}

function renderLobby() {
    let h = '<div class="card"><h2>Warten auf den Start</h2>';
    if (IS_HOST) {
        h += `<p>Raum-Code: <span class="code">${esc(CODE)}</span></p>
              <p class="small">Link für Schüler und Gäste:<br>${esc(JOIN_LINK)}</p>
              <button class="btn" data-act="start">Quiz starten</button>
              <p class="small">Teilnehmer: ${(st.ranking || []).length}</p>${list(st.ranking || [], false)}`;
    } else {
        h += '<p>Das Quiz startet gleich. Bleib auf dieser Seite.</p>';
    }
    return h + '</div>';
}

function renderQuestion() {
    const q = st.question;
    const left = Math.ceil(remainingNow() / 1000);
    let h = `<div class="card"><div class="timer">${left} s</div><h2>${esc(q.text)}</h2>`;
    if (IS_HOST) {
        h += `<p>${st.answered} von ${st.active} haben geantwortet</p>
              <div class="opts">${q.options.map((o, i) =>
                `<div class="opt" style="background:${COLORS[i]}">${LETTERS[i]}: ${esc(o)}</div>`).join('')}</div>
              <button class="btn" data-act="advance" data-force="1">Auflösen</button>`;
    } else {
        const answered = st.my && st.my.answered;
        h += `<div class="opts">${q.options.map((o, i) =>
                `<button class="opt" style="background:${COLORS[i]}" data-choice="${i}" ${(answered || left <= 0) ? 'disabled' : ''}>${LETTERS[i]}: ${esc(o)}</button>`).join('')}</div>`;
        if (answered) h += '<p>Antwort abgegeben. Die Auflösung folgt.</p>';
    }
    return h + '</div>';
}

function renderReveal() {
    const q = st.question;
    let h = `<div class="card"><h2>${esc(q.text)}</h2><div class="opts">${q.options.map((o, i) =>
        `<div class="opt ${i === q.correct ? 'right' : 'wrong'}" style="background:${COLORS[i]}">${LETTERS[i]}: ${esc(o)}</div>`).join('')}</div>`;
    if (IS_HOST) {
        const dist = (st.dist || [0, 0, 0, 0]).map((n, i) => `${LETTERS[i]}: ${n}`).join(' · ');
        const last = st.qno + 1 >= st.total;
        h += `<p class="small">Verteilung: ${dist}</p>
              <h3>Stand</h3>${list((st.ranking || []).slice(0, 5), true)}
              <button class="btn" data-act="advance" data-force="1">${last ? 'Ergebnis zeigen' : 'Nächste Frage'}</button>`;
    } else {
        const m = st.my;
        let res = 'Keine Antwort rechtzeitig.';
        if (m && m.answered) res = m.correct ? `Richtig! +${m.points} Punkte` : 'Leider falsch.';
        h += `<p class="result">${res}</p><p>Dein Stand: ${st.my_total} Punkte · Platz ${st.my_place || '–'}</p>`;
    }
    return h + '</div>';
}

function renderDone() {
    let h = '<div class="card"><h2>Ergebnis</h2>';
    if (IS_HOST) {
        h += '<table><tr><th>Platz</th><th>Name</th><th>Punkte</th></tr>' +
            (st.ranking || []).map((r, i) => `<tr><td>${i + 1}</td><td>${esc(r.name)}</td><td>${r.score}</td></tr>`).join('') +
            `</table>
             <p><a class="btn" href="${URL_ROOM}&csv=1">Rangliste als CSV laden</a>
             <button class="btn" data-act="start">Neues Quiz mit meinen Fragen</button></p>`;
    } else {
        const medals = ['🥇', '🥈', '🥉'];
        h += '<ol class="podium">' + (st.top || []).map((r, i) => `<li>${medals[i]} ${esc(r.name)} – ${r.score}</li>`).join('') + '</ol>';
        h += `<p>Dein Platz: ${st.my_place || '–'} mit ${st.my_total} Punkten</p>`;
    }
    return h + '</div>';
}

function render() {
    if (st.phase === 'lobby') root.innerHTML = renderLobby();
    else if (st.phase === 'question') root.innerHTML = renderQuestion();
    else if (st.phase === 'reveal') root.innerHTML = renderReveal();
    else root.innerHTML = renderDone();
}

root.addEventListener('click', async (e) => {
    const b = e.target.closest('button');
    if (!b || b.disabled) return;
    if (b.dataset.choice !== undefined) {
        flash('');
        const j = await post({ act: 'answer', choice: b.dataset.choice });
        if (!j.ok) flash(j.error || '');
        return;
    }
    if (b.dataset.act === 'start') {
        const j = await post({ act: 'start' });
        if (!j.ok) flash(j.error || ''); else flash('');
    }
    if (b.dataset.act === 'advance') {
        await advance(b.dataset.force === '1');
    }
});

poll();
</script>
</body>
</html>

<!-- Version 1.0 -->
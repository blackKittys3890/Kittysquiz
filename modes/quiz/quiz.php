<?php
// Version 1.6
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
    return qq('SELECT phase, qno, phase_ms, buzzer, buzz_by, buzz_name, buzz_qno FROM kr_quiz_state WHERE room_id = ?', [$rid])->fetch()
        ?: ['phase' => 'lobby', 'qno' => 0, 'phase_ms' => 0, 'buzzer' => 0, 'buzz_by' => null, 'buzz_name' => null, 'buzz_qno' => null];
}

// Buzzer: der erste Spieler pro Frage übernimmt die Antwort
function quiz_buzz(int $rid, string $me, string $name): array
{
    $st = quiz_row($rid);
    if ($st['phase'] !== 'question' || (int)$st['buzzer'] !== 1) return ['ok' => false, 'error' => 'Der Buzzer ist gerade nicht aktiv.'];
    $qno = (int)$st['qno'];
    $item = quiz_item($rid, $qno);
    if (!$item || (now_ms() - (int)$st['phase_ms']) > $item['seconds'] * 1000 + QUIZ_GRACE_MS) {
        return ['ok' => false, 'error' => 'Zu spät.'];
    }
    $upd = qq('UPDATE kr_quiz_state SET buzz_by = ?, buzz_name = ?, buzz_qno = ? WHERE room_id = ? AND (buzz_qno IS NULL OR buzz_qno <> ?)',
        [$me, mb_substr($name, 0, 20), $qno, $rid, $qno]);
    if ($upd->rowCount() === 0) return ['ok' => false, 'error' => 'Ein anderes Team war schneller.'];
    return ['ok' => true];
}

function quiz_count(int $rid): int
{
    return (int)qq('SELECT COUNT(*) FROM kr_quiz_items WHERE room_id = ?', [$rid])->fetchColumn();
}

function quiz_item(int $rid, int $qno): ?array
{
    $r = qq('SELECT qno, question, opts, correct, seconds, image_url FROM kr_quiz_items WHERE room_id = ? AND qno = ?', [$rid, $qno])->fetch();
    if (!$r) return null;
    return [
        'question' => (string)$r['question'],
        'image' => $r['image_url'] ? (string)$r['image_url'] : null,
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
function quiz_start(int $rid, string $me, bool $buzzer = false): array
{
    $bank = qq('SELECT question, correct, wrong1, wrong2, wrong3, seconds, image_url FROM kr_quiz_questions WHERE owner_key = ? ORDER BY id', [$me])->fetchAll();
    if (!$bank) return ['ok' => false, 'error' => 'Du hast noch keine Fragen. Lege sie zuerst unter „Fragen-Bank“ an.'];

    qq('DELETE FROM kr_quiz_answers WHERE room_id = ?', [$rid]);
    qq('DELETE FROM kr_quiz_items WHERE room_id = ?', [$rid]);
    qq('DELETE FROM kr_quiz_jokers WHERE room_id = ?', [$rid]);
    qq('DELETE FROM kr_quiz_emojis WHERE room_id = ?', [$rid]);

    foreach ($bank as $i => $b) {
        $opts = [(string)$b['correct'], (string)$b['wrong1'], (string)$b['wrong2'], (string)$b['wrong3']];
        $order = [0, 1, 2, 3];
        shuffle($order);
        $shuffled = array_map(fn($k) => $opts[$k], $order);
        $correct = (int)array_search(0, $order, true);
        qq('INSERT INTO kr_quiz_items (room_id, qno, question, opts, correct, seconds, image_url) VALUES (?, ?, ?, ?, ?, ?, ?)', [
            $rid, $i, (string)$b['question'], json_encode($shuffled, JSON_UNESCAPED_UNICODE), $correct, (int)$b['seconds'], $b['image_url'] ?: null,
        ]);
    }
    qq("UPDATE kr_quiz_state SET phase = 'question', qno = 0, phase_ms = ?, buzzer = ?, buzz_by = NULL, buzz_name = NULL, buzz_qno = NULL WHERE room_id = ?",
        [now_ms(), $buzzer ? 1 : 0, $rid]);
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
        if ((int)($st['buzzer'] ?? 0) === 1 && answered_count($rid, $qno) >= 1) $all = true; // Buzzer hat geantwortet
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

const QUIZ_EMOJIS = ['👍', '😂', '🔥', '👏', '😮'];

// 50:50-Joker: entfernt zwei falsche Antworten, einmal pro Spiel und Spieler
function quiz_joker(int $rid, string $me): array
{
    $st = quiz_row($rid);
    if ($st['phase'] !== 'question') return ['ok' => false, 'error' => 'Joker nur während einer offenen Frage.'];
    $qno = (int)$st['qno'];
    $item = quiz_item($rid, $qno);
    if (!$item) return ['ok' => false, 'error' => 'Frage fehlt.'];
    if (qq('SELECT 1 FROM kr_quiz_answers WHERE room_id = ? AND discord_id = ? AND qno = ?', [$rid, $me, $qno])->fetchColumn()) {
        return ['ok' => false, 'error' => 'Du hast schon geantwortet.'];
    }
    $wrong = array_values(array_diff([0, 1, 2, 3], [$item['correct']]));
    shuffle($wrong);
    $hide = array_slice($wrong, 0, 2);
    sort($hide);
    $ins = qq('INSERT IGNORE INTO kr_quiz_jokers (room_id, discord_id, qno, hidden) VALUES (?, ?, ?, ?)', [$rid, $me, $qno, implode(',', $hide)]);
    if ($ins->rowCount() === 0) return ['ok' => false, 'error' => 'Du hast deinen Joker schon benutzt.'];
    return ['ok' => true];
}

// Emoji-Reaktion, höchstens alle 2 Sekunden pro Spieler
function quiz_emoji(int $rid, string $me, string $name, string $emoji): array
{
    if (!in_array($emoji, QUIZ_EMOJIS, true)) return ['ok' => false, 'error' => 'Unbekanntes Emoji.'];
    $now = now_ms();
    $last = qq('SELECT MAX(created_ms) FROM kr_quiz_emojis WHERE room_id = ? AND discord_id = ?', [$rid, $me])->fetchColumn();
    if ($last && $now - (int)$last < 2000) return ['ok' => false, 'error' => 'Gleich nochmal.'];
    qq('DELETE FROM kr_quiz_emojis WHERE room_id = ? AND created_ms < ?', [$rid, $now - 60000]);
    qq('INSERT INTO kr_quiz_emojis (room_id, discord_id, name, emoji, created_ms) VALUES (?, ?, ?, ?, ?)', [$rid, $me, mb_substr($name, 0, 20), $emoji, $now]);
    return ['ok' => true];
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

    // Buzzer-Modus: nur der Spieler, der zuerst gebuzzt hat, darf antworten
    if ((int)$st['buzzer'] === 1 && ((int)($st['buzz_qno'] ?? -1) !== $qno || ($st['buzz_by'] ?? null) !== $me)) {
        return ['ok' => false, 'error' => 'Nur wer zuerst gebuzzt hat, darf antworten.'];
    }

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
function quiz_payload(int $rid, string $me, bool $isHost, string $hostId, bool $spectator = false): array
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
        $q = ['text' => $item['question'], 'options' => $item['opts'], 'seconds' => $item['seconds'], 'image' => $item['image']];
        if ($phase === 'reveal') $q['correct'] = $item['correct'];
        $out['question'] = $q;
        $out['answered'] = answered_count($rid, $qno);
        if ($phase === 'question') {
            $out['remaining_ms'] = $item['seconds'] * 1000 - (now_ms() - (int)$st['phase_ms']);
        }
    }

    $buzzMine = ($st['buzz_by'] ?? null) === $me && (int)($st['buzz_qno'] ?? -1) === $qno;
    $out['buzzer'] = (int)($st['buzzer'] ?? 0) === 1;
    $out['buzz'] = ($item && (int)($st['buzz_qno'] ?? -1) === $qno && !empty($st['buzz_name'])) ? ['name' => (string)$st['buzz_name']] : null;
    $out['can_answer'] = !$out['buzzer'] || $buzzMine;

    $ranking = quiz_ranking($rid, $hostId);
    $myIdx = null;
    foreach ($ranking as $i => $r) {
        if ($r['id'] === $me) { $myIdx = $i; break; }
    }
    $out['my_total'] = $myIdx === null ? 0 : $ranking[$myIdx]['score'];
    $out['my_place'] = $myIdx === null ? null : $myIdx + 1;
    $out['top'] = array_map(fn($r) => ['name' => $r['name'], 'score' => $r['score']], array_slice($ranking, 0, 3));

    if ($isHost || $spectator) {
        $out['emojis'] = qq('SELECT name, emoji FROM kr_quiz_emojis WHERE room_id = ? AND created_ms >= ? ORDER BY id DESC LIMIT 8', [$rid, now_ms() - 8000])->fetchAll();
        $out['ranking'] = array_map(fn($r) => ['name' => $r['name'], 'score' => $r['score']], $ranking);
        if ($item) {
            $dist = [0, 0, 0, 0];
            $rows = qq('SELECT choice, COUNT(*) AS c FROM kr_quiz_answers WHERE room_id = ? AND qno = ? GROUP BY choice', [$rid, $qno])->fetchAll();
            foreach ($rows as $d) {
                $dist[(int)$d['choice']] = (int)$d['c'];
            }
            $out['dist'] = $dist;
        }
    } elseif (!$spectator) {
        qq('UPDATE kr_room_players SET last_seen = NOW() WHERE room_id = ? AND discord_id = ?', [$rid, $me]);
        $jk = qq('SELECT qno, hidden FROM kr_quiz_jokers WHERE room_id = ? AND discord_id = ?', [$rid, $me])->fetch();
        $out['joker_used'] = (bool)$jk;
        $out['hidden'] = ($jk && $item && (int)$jk['qno'] === $qno) ? array_map('intval', explode(',', (string)$jk['hidden'])) : [];
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
    // Ohne Anmeldung: Schüler können über den Raum-Code als Gast beitreten (Name, kein Konto)
    $gcode = strtoupper(trim((string)($_GET['code'] ?? $_POST['code'] ?? '')));
    if (isset($_GET['api']) || isset($_POST['act'])) {
        json_out(['ok' => false, 'error' => 'Bitte die Seite neu laden und mit Namen beitreten.'], 401);
    }
    if ($gcode === '' || !qq('SELECT 1 FROM kr_rooms WHERE code = ?', [$gcode])->fetchColumn()) {
        header('Location: /index.php');
        exit;
    }
    $gerr = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['guest_name'])) {
        $gname = trim((string)$_POST['guest_name']);
        $glen = mb_strlen($gname);
        if ($glen >= 2 && $glen <= 20) {
            $_SESSION['discord_user'] = [
                'id' => 'guest_' . bin2hex(random_bytes(8)),
                'name' => $gname,
                'username' => $gname,
                'avatar' => null,
                'premium' => 0,
                'decoration' => null,
                'guest' => true,
            ];
            header('Location: /modes/quiz/quiz.php?code=' . urlencode($gcode));
            exit;
        }
        $gerr = 'Bitte 2 bis 20 Zeichen eingeben.';
    }
    ?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Quiz-Duell beitreten</title>
<style>
body { margin:0; font-family: system-ui, sans-serif; background:#1d1030; color:#fff; }
.wrap { max-width:420px; margin:0 auto; padding:24px 16px; text-align:center; }
.card { background:rgba(255,255,255,.08); border-radius:16px; padding:18px; }
input { padding:12px; border-radius:10px; border:none; font-size:16px; width:100%; box-sizing:border-box; margin:10px 0; }
.btn { background:#ff6fb5; color:#fff; border:none; border-radius:12px; padding:12px 18px; font-weight:700; font-size:16px; cursor:pointer; width:100%; }
.err { color:#ffd166; }
</style>
</head>
<body>
<div class="wrap">
    <h1>Quiz-Duell</h1>
    <div class="card">
        <p>Raum <strong><?= h($gcode) ?></strong> · Gib deinen Namen ein, um mitzuspielen. Du brauchst kein Konto.</p>
        <?php if ($gerr !== ''): ?><p class="err"><?= h($gerr) ?></p><?php endif; ?>
        <form method="post" action="/modes/quiz/quiz.php?code=<?= h(urlencode($gcode)) ?>">
            <input name="guest_name" maxlength="20" placeholder="Dein Name" required>
            <button class="btn" type="submit">Beitreten</button>
        </form>
    </div>
</div>
</body>
</html>
    <?php
    exit;
}
$me = (string)$user['id'];
$myName = (string)($user['name'] ?? $user['username'] ?? 'Gast');
$isGuest = !empty($user['guest']);
$isTeacher = !$isGuest && (int)qq('SELECT is_teacher FROM kr_profiles WHERE user_key = ?', [$me])->fetchColumn() === 1;

// Vorlagen für Lehrkräfte: [Frage, richtig, falsch 1, falsch 2, falsch 3]
const QUIZ_SETS = [
    'mathe' => ['label' => 'Mathe', 'items' => [
        ['Was ist 7 × 8?', '56', '54', '63', '48'],
        ['Was ist 144 ÷ 12?', '12', '11', '14', '16'],
        ['Wie viel sind 25 % von 200?', '50', '25', '40', '75'],
        ['Was ist die Quadratwurzel aus 81?', '9', '8', '7', '10'],
        ['Was ist 2³?', '8', '6', '9', '4'],
        ['Wie viele Grad hat ein voller Kreis?', '360', '180', '270', '90'],
        ['Was ergibt 15 + 27?', '42', '40', '32', '52'],
        ['Wie viele Ecken hat ein Würfel?', '8', '6', '12', '4'],
        ['Was ist 1000 − 386?', '614', '624', '586', '714'],
        ['Wie viele Seiten hat ein Dreieck?', '3', '4', '5', '2'],
    ]],
    'fussball' => ['label' => 'Fußball', 'items' => [
        ['Wie viele Spieler stehen pro Team auf dem Feld?', '11', '10', '12', '9'],
        ['Wie lange dauert ein reguläres Spiel ohne Nachspielzeit?', '90 Minuten', '60 Minuten', '45 Minuten', '120 Minuten'],
        ['Wie viele Halbzeiten hat ein Spiel?', '2', '1', '3', '4'],
        ['Welche Karte gibt es für eine Verwarnung?', 'Gelb', 'Rot', 'Grün', 'Blau'],
        ['Wie viele Punkte gibt ein Sieg in der Bundesliga?', '3', '1', '2', '4'],
        ['Wie viele Tore erzielt man mit einem Hattrick?', '3', '2', '4', '5'],
        ['Wie breit ist ein Standard-Fußballtor?', '7,32 m', '5 m', '6 m', '8 m'],
        ['Wie viele Minuten dauert eine reguläre Halbzeit?', '45', '30', '40', '60'],
        ['In welchem Land fand die WM 2014 statt?', 'Brasilien', 'Argentinien', 'Südafrika', 'Russland'],
        ['Welche Mannschaft gewann die WM 2014?', 'Deutschland', 'Argentinien', 'Brasilien', 'Niederlande'],
    ]],
];

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
            $img = trim((string)($_POST['image_url'] ?? ''));
            $imgOk = $img === '' || (strlen($img) <= 500 && preg_match('#^https://#i', $img) && filter_var($img, FILTER_VALIDATE_URL));
            if (!$imgOk) {
                $_SESSION['quiz_msg'] = 'Die Bild-Adresse muss mit https:// beginnen.';
            } elseif ($question !== '' && $correct !== '' && $w1 !== '' && $w2 !== '' && $w3 !== '') {
                qq('INSERT INTO kr_quiz_questions (owner_key, question, correct, wrong1, wrong2, wrong3, seconds, image_url) VALUES (?, ?, ?, ?, ?, ?, ?, ?)', [
                    $me, mb_substr($question, 0, 500), mb_substr($correct, 0, 200), mb_substr($w1, 0, 200),
                    mb_substr($w2, 0, 200), mb_substr($w3, 0, 200), $secs, $img !== '' ? $img : null,
                ]);
                $_SESSION['quiz_msg'] = 'Frage gespeichert.';
            } else {
                $_SESSION['quiz_msg'] = 'Bitte Frage und alle 4 Antworten ausfüllen.';
            }
        } elseif ($act === 'load_set') {
            $setKey = (string)($_POST['set'] ?? '');
            if (!isset(QUIZ_SETS[$setKey])) {
                $_SESSION['quiz_msg'] = 'Diese Vorlage gibt es nicht.';
            } else {
                foreach (QUIZ_SETS[$setKey]['items'] as $it) {
                    qq('INSERT INTO kr_quiz_questions (owner_key, question, correct, wrong1, wrong2, wrong3, seconds) VALUES (?, ?, ?, ?, ?, ?, 20)', [
                        $me, $it[0], $it[1], $it[2], $it[3], $it[4],
                    ]);
                }
                $_SESSION['quiz_msg'] = QUIZ_SETS[$setKey]['label'] . ': ' . count(QUIZ_SETS[$setKey]['items']) . ' Fragen hinzugefügt.';
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

    <div class="card">
        <h2>Vorlagen</h2>
        <p class="small">Fügt fertige Fragen zu deiner Bank hinzu (je 20 Sekunden).</p>
        <?php foreach (QUIZ_SETS as $key => $set): ?>
            <form method="post" style="display:inline">
                <input type="hidden" name="action" value="load_set">
                <input type="hidden" name="set" value="<?= h($key) ?>">
                <button class="btn" type="submit">+ <?= h($set['label']) ?>-Fragen</button>
            </form>
        <?php endforeach; ?>
    </div>

    <form method="post" class="card">
        <input type="hidden" name="action" value="add_q">
        <label>Frage<textarea name="question" maxlength="500" required></textarea></label>
        <label>Richtige Antwort<input name="correct" maxlength="200" required></label>
        <label>Falsche Antwort 1<input name="w1" maxlength="200" required></label>
        <label>Falsche Antwort 2<input name="w2" maxlength="200" required></label>
        <label>Falsche Antwort 3<input name="w3" maxlength="200" required></label>
        <label>Sekunden zum Antworten (5 bis 60)<input type="number" name="seconds" min="5" max="60" value="20"></label>
        <label>Bild-Adresse für die Bild-Enthüllung (optional, https://)<input name="image_url" maxlength="500" placeholder="https://…"></label>
        <p class="small">Das Bild wird während der Frage Stück für Stück sichtbar und ganz bei der Auflösung.</p>
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
// Zuschauer: sehen alles, werden aber nicht als Mitspieler geführt
$isSpectator = !$isHost && isset($_GET['zuschauer']);

qq("INSERT IGNORE INTO kr_quiz_state (room_id, phase, qno, phase_ms) VALUES (?, 'lobby', 0, 0)", [$rid]);

// Spieler (auch Gäste) im Raum eintragen
if (!$isHost && !$isSpectator) {
    qq('INSERT INTO kr_room_players (room_id, discord_id, username, avatar, premium, decoration, team_id, last_seen)
        VALUES (?, ?, ?, ?, ?, ?, NULL, NOW())
        ON DUPLICATE KEY UPDATE username = VALUES(username), last_seen = NOW()', [
        $rid, $me, $myName, $user['avatar'] ?? null, (int)($user['premium'] ?? 0), $user['decoration'] ?? null,
    ]);
}

// ---------- Schritte (POST mit act) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act'])) {
    $act = (string)$_POST['act'];
    if ($isSpectator) json_out(['ok' => false, 'error' => 'Zuschauer spielen nicht mit.'], 403);
    if (in_array($act, ['start', 'advance'], true) && !$isHost) {
        json_out(['ok' => false, 'error' => 'Nur die Lehrkraft steuert das Quiz.'], 403);
    }
    if ($act === 'start') {
        $res = quiz_start($rid, $me, !empty($_POST['buzzer']));
    } elseif ($act === 'advance') {
        $res = quiz_advance($rid, $me, !empty($_POST['force']));
    } elseif ($act === 'answer') {
        if ($isHost) json_out(['ok' => false, 'error' => 'Die Lehrkraft spielt nicht mit.'], 403);
        $res = quiz_answer($rid, $me, (int)($_POST['choice'] ?? -1));
    } elseif ($act === 'buzz') {
        if ($isHost) json_out(['ok' => false, 'error' => 'Die Lehrkraft spielt nicht mit.'], 403);
        $res = quiz_buzz($rid, $me, $myName);
    } elseif ($act === 'joker' || $act === 'emoji') {
        if ($isHost) json_out(['ok' => false, 'error' => 'Die Lehrkraft spielt nicht mit.'], 403);
        $res = $act === 'joker'
            ? quiz_joker($rid, $me)
            : quiz_emoji($rid, $me, $myName, (string)($_POST['emoji'] ?? ''));
    } else {
        json_out(['ok' => false, 'error' => 'Unbekannte Aktion.'], 400);
    }
    json_out($res, $res['ok'] ? 200 : 409);
}

// ---------- Zustand (JSON) ----------
if (isset($_GET['api']) && $_GET['api'] === 'state') {
    json_out(quiz_payload($rid, $me, $isHost, $hostId, $isSpectator));
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
const IS_SPEC = <?= $isSpectator ? 'true' : 'false' ?>;
const URL_ROOM = '/modes/quiz/quiz.php?code=' + encodeURIComponent(CODE);
const COLORS = ['#e74c3c', '#3498db', '#f1c40f', '#2ecc71'];
const LETTERS = ['A', 'B', 'C', 'D'];
const root = document.getElementById('root');
const flashEl = document.getElementById('flash');
let st = null, recvAt = 0, busy = false, buzzOn = false;

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
              <p><label><input type="checkbox" id="buzzOn" ${buzzOn ? 'checked' : ''}> Buzzer-Modus (nur ein Team antwortet)</label></p>
              <p class="small">Link für Schüler und Gäste:<br>${esc(JOIN_LINK)}</p>
              <p class="small">Zuschauer-Link (sieht alles, wird nicht gezählt):<br>${esc(JOIN_LINK + '&zuschauer=1')}</p>
              <button class="btn" data-act="start">Quiz starten</button>
              <p class="small">Teilnehmer: ${(st.ranking || []).length}</p>${list(st.ranking || [], false)}`;
    } else {
        h += '<p>Das Quiz startet gleich. Bleib auf dieser Seite.</p>';
    }
    return h + '</div>';
}

function emojiBar() {
    return '<div class="emoji-bar">' + ['👍', '😂', '🔥', '👏', '😮'].map(e =>
        `<button class="btn btn-ghost" data-emoji="${e}">${e}</button>`).join('') + '</div>';
}

// Bild-Enthüllung: Kacheln werden in einer festen, für alle gleichen Reihenfolge nach und nach entfernt
const REVEAL_COLS = 8, REVEAL_ROWS = 5;
function revealOrder(seed) {
    const n = REVEAL_COLS * REVEAL_ROWS;
    const idx = [...Array(n).keys()];
    const key = i => Math.imul(i + 1, 2654435761) ^ Math.imul(seed + 7, 40503) ^ (i * 97);
    return idx.sort((a, b) => (key(a) >>> 0) - (key(b) >>> 0));
}
function revealImageHtml(q, fraction) {
    const order = revealOrder(st.qno || 0);
    const hiddenCount = Math.round((1 - fraction) * order.length);
    const hiddenSet = new Set(order.slice(0, hiddenCount));
    let tiles = '';
    if (hiddenCount > 0) {
        for (let i = 0; i < REVEAL_COLS * REVEAL_ROWS; i++) {
            if (!hiddenSet.has(i)) continue;
            const c = i % REVEAL_COLS, r = Math.floor(i / REVEAL_COLS);
            tiles += `<div style="position:absolute;left:${c * 100 / REVEAL_COLS}%;top:${r * 100 / REVEAL_ROWS}%;width:${100 / REVEAL_COLS}%;height:${100 / REVEAL_ROWS}%;background:#1b1b2f"></div>`;
        }
    }
    return `<div style="position:relative;margin:12px 0;border-radius:12px;overflow:hidden;background:#111">
              <img src="${esc(q.image)}" alt="" style="display:block;width:100%;max-height:340px;object-fit:contain" referrerpolicy="no-referrer">
              ${tiles}
            </div>`;
}

function renderQuestion() {
    const q = st.question;
    const left = Math.ceil(remainingNow() / 1000);
    let h = `<div class="card"><div class="timer">${left} s</div><h2>${esc(q.text)}</h2>`;
    if (q.image) h += revealImageHtml(q, Math.max(0, Math.min(1, 1 - remainingNow() / (q.seconds * 1000))));
    if (IS_HOST) {
        h += `<p>${st.answered} von ${st.active} haben geantwortet</p>${st.buzz ? '<p>Gebuzzert: ' + esc(st.buzz.name) + '</p>' : ''}
              <div class="opts">${q.options.map((o, i) =>
                `<div class="opt" style="background:${COLORS[i]}">${LETTERS[i]}: ${esc(o)}</div>`).join('')}</div>
              <p class="small">Reaktionen: ${(st.emojis || []).map(e => esc(e.emoji) + ' ' + esc(e.name)).join('  ·  ') || '–'}</p>
              <button class="btn" data-act="advance" data-force="1">Auflösen</button>`;
    } else {
        const answered = st.my && st.my.answered;
        const hidden = st.hidden || [];
        h += `<div class="opts">${q.options.map((o, i) => hidden.includes(i)
                ? `<div class="opt" style="visibility:hidden"></div>`
                : `<button class="opt" style="background:${COLORS[i]}" data-choice="${i}" ${(answered || left <= 0 || !st.can_answer || IS_SPEC) ? 'disabled' : ''}>${LETTERS[i]}: ${esc(o)}</button>`).join('')}</div>`;
        if (answered) h += '<p>Antwort abgegeben. Die Auflösung folgt.</p>';
        if (st.buzzer && !st.can_answer && !IS_SPEC) {
            h += st.buzz ? `<p>${esc(st.buzz.name)} hat gebuzzt.</p>` : `<button class="btn ok" data-buzz="1">BUZZER</button>`;
        }
        if (!IS_SPEC && !answered && !st.joker_used && left > 0) h += `<button class="btn btn-ghost" data-joker="1">50:50-Joker</button>`;
        if (!IS_SPEC) h += emojiBar();
    }
    return h + '</div>';
}

function renderReveal() {
    const q = st.question;
    let h = `<div class="card"><h2>${esc(q.text)}</h2>${q.image ? revealImageHtml(q, 1) : ''}<div class="opts">${q.options.map((o, i) =>
        `<div class="opt ${i === q.correct ? 'right' : 'wrong'}" style="background:${COLORS[i]}">${LETTERS[i]}: ${esc(o)}</div>`).join('')}</div>`;
    if (IS_HOST) {
        const dist = (st.dist || [0, 0, 0, 0]).map((n, i) => `${LETTERS[i]}: ${n}`).join(' · ');
        const last = st.qno + 1 >= st.total;
        h += `<p class="small">Verteilung: ${dist}</p>
              <h3>Stand</h3>${list((st.ranking || []).slice(0, 5), true)}
              <button class="btn" data-act="advance" data-force="1">${last ? 'Ergebnis zeigen' : 'Nächste Frage'}</button>`;
    } else if (IS_SPEC) {
        h += '<p class="small">Du schaust zu.</p>' + list((st.ranking || []).slice(0, 5), true);
    } else {
        const m = st.my;
        let res = 'Keine Antwort rechtzeitig.';
        if (m && m.answered) res = m.correct ? `Richtig! +${m.points} Punkte` : 'Leider falsch.';
        h += `<p class="result">${res}</p><p>Dein Stand: ${st.my_total} Punkte · Platz ${st.my_place || '–'}</p>${emojiBar()}`;
    }
    return h + '</div>';
}

function renderDone() {
    let h = '<div class="card"><h2>Ergebnis</h2>';
    if (IS_HOST || IS_SPEC) {
        h += '<table><tr><th>Platz</th><th>Name</th><th>Punkte</th></tr>' +
            (st.ranking || []).map((r, i) => `<tr><td>${i + 1}</td><td>${esc(r.name)}</td><td>${r.score}</td></tr>`).join('') +
            `</table>
             <p>${IS_HOST ? `<a class="btn" href="${URL_ROOM}&csv=1">Rangliste als CSV laden</a>` : ''}
             ${IS_HOST ? '<button class="btn" data-act="start">Neues Quiz mit meinen Fragen</button>' : ''}</p>`;
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

root.addEventListener('change', (e) => {
    if (e.target && e.target.id === 'buzzOn') buzzOn = e.target.checked;
});

root.addEventListener('click', async (e) => {
    const b = e.target.closest('button');
    if (!b || b.disabled) return;
    if (b.dataset.buzz !== undefined) {
        const j = await post({ act: 'buzz' });
        flash(j.ok ? '' : (j.error || ''));
        return;
    }
    if (b.dataset.joker !== undefined) {
        const j = await post({ act: 'joker' });
        flash(j.ok ? 'Joker eingesetzt.' : (j.error || ''));
        return;
    }
    if (b.dataset.emoji !== undefined) {
        const j = await post({ act: 'emoji', emoji: b.dataset.emoji });
        flash(j.ok ? '' : (j.error || ''));
        return;
    }
    if (b.dataset.choice !== undefined) {
        flash('');
        const j = await post({ act: 'answer', choice: b.dataset.choice });
        if (!j.ok) flash(j.error || '');
        return;
    }
    if (b.dataset.act === 'start') {
        const j = await post({ act: 'start', buzzer: buzzOn ? '1' : '' });
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

<!-- Version 1.6 -->
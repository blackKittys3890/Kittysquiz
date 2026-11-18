<?php
// Version 1.7
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

const EI_FAECHER      = ['Mathe', 'Sachkunde', 'Deutsch', 'Kunst'];
const EI_ROUNDS_CLASS = 12;     // 4 Fächer x 3 Schwierigkeiten
const EI_MAX_CLASS    = 13;
const EI_RAPID_Q      = 20;
const EI_RAPID_BASE   = 9;
const EI_RAPID_MULT   = 5;
const EI_TIME         = 60;
const EI_SIX_LIMIT    = 3;     // ab 3 Sechsen kein Joker mehr

$me = $_SESSION['discord_user']['id'] ?? null;
if (!$me) { http_response_code(401); echo json_encode(['error' => 'login']); exit; }

$code = strtoupper($_REQUEST['code'] ?? '');
$st = db()->prepare('SELECT id, host_id FROM kr_rooms WHERE code = ?');
$st->execute([$code]);
$room = $st->fetch();
if (!$room) { http_response_code(404); echo json_encode(['error' => 'room']); exit; }
$rid    = (int)$room['id'];
$isHost = $room['host_id'] === $me;

function q(string $sql, array $p = []): PDOStatement { $s = db()->prepare($sql); $s->execute($p); return $s; }
function fail(int $c, string $e): void { http_response_code($c); echo json_encode(['error' => $e]); exit; }
function game(int $rid): array { return q('SELECT * FROM kr_ei_game WHERE room_id = ?', [$rid])->fetch() ?: []; }
function aliveIds(int $rid): array { return q('SELECT discord_id FROM kr_ei_players WHERE room_id = ? AND `out` = 0 ORDER BY discord_id', [$rid])->fetchAll(PDO::FETCH_COLUMN); }
function norm(string $s): string { return mb_strtolower(trim(preg_replace('/\s+/', ' ', $s))); }

// Klasse, Fach und Punkte aus der Rundennummer (nur Klassenmodus)
function classInfo(int $round): array {
    $cls = intdiv($round - 1, EI_ROUNDS_CLASS) + 1;
    $r   = ($round - 1) % EI_ROUNDS_CLASS;
    return ['class' => $cls, 'fach' => EI_FAECHER[$r % 4], 'punkte' => [100, 200, 300][intdiv($r, 4)]];
}

// Zufällige, noch nicht gestellte Frage für die Runde
function pickQuestion(int $rid, array $g): ?int {
    if ($g['mode'] === 'rapid') {
        $s = q('SELECT q.id FROM kr_questions q JOIN kr_categories c ON c.id = q.category_id
                WHERE c.name LIKE ? AND q.id NOT IN (SELECT question_id FROM kr_ei_used WHERE room_id = ?)
                ORDER BY RAND() LIMIT 1', ['Kl. %', $rid]);
    } else {
        $i = classInfo((int)$g['round_no']);
        $s = q('SELECT q.id FROM kr_questions q JOIN kr_categories c ON c.id = q.category_id
                WHERE c.name = ? AND q.points = ? AND q.id NOT IN (SELECT question_id FROM kr_ei_used WHERE room_id = ?)
                ORDER BY RAND() LIMIT 1', ["Kl. {$i['class']} · {$i['fach']}", $i['punkte'], $rid]);
    }
    $id = $s->fetchColumn();
    return $id ? (int)$id : null;
}

function startRound(int $rid, int $round, string $mode): void {
    $g = ['mode' => $mode, 'round_no' => $round];
    $qid = pickQuestion($rid, $g);
    if (!$qid) fail(400, 'noquestion');
    q('DELETE FROM kr_ei_strokes WHERE room_id = ?', [$rid]);
    q('INSERT INTO kr_ei_used (room_id, question_id) VALUES (?, ?)', [$rid, $qid]);
    q('UPDATE kr_ei_game SET phase = \'ask\', mode = ?, round_no = ?, question_id = ?, deadline = NOW() + INTERVAL ' . ($mode === 'rapid' ? 10 : EI_TIME) . ' SECOND WHERE room_id = ?',
      [$mode, $round, $qid, $rid]);
}

// Heartbeat: eigenen Eintrag als aktiv markieren (Eingestuhlt-Seite ruft diese API regelmäßig ab)
q('UPDATE kr_room_players SET last_seen = NOW() WHERE room_id = ? AND discord_id = ?', [$rid, $me]);

// ---------- Aktionen ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $g = game($rid);
    $phase = $g['phase'] ?? 'lobby';

    if (in_array($action, ['start', 'reveal', 'mark', 'next', 'reset'], true) && !$isHost) fail(403, 'host');

    if ($action === 'start') {
        if (!in_array($phase, ['lobby', 'over'], true)) fail(409, 'running');
        q('DELETE FROM kr_ei_players WHERE room_id = ?', [$rid]);
        q('DELETE FROM kr_ei_answers WHERE room_id = ?', [$rid]);
        q('DELETE FROM kr_ei_spies WHERE room_id = ?', [$rid]);
        q('DELETE FROM kr_ei_used WHERE room_id = ?', [$rid]);
        q('INSERT INTO kr_ei_players (room_id, discord_id) SELECT room_id, discord_id FROM kr_room_players WHERE room_id = ? AND discord_id <> ? AND last_seen > NOW() - INTERVAL 10 SECOND', [$rid, $room['host_id']]);
        if (count(aliveIds($rid)) < 2) fail(400, 'few');
        q('REPLACE INTO kr_ei_game (room_id, phase, mode, round_no) VALUES (?, \'lobby\', \'class\', 1)', [$rid]);
        startRound($rid, 1, 'class');
    }

    elseif ($action === 'answer') {
        if ($phase !== 'ask') fail(409, 'noactive');
        $ok = q('SELECT 1 FROM kr_ei_game WHERE room_id = ? AND deadline > NOW()', [$rid])->fetch();
        if (!$ok) fail(409, 'timeup');
        if (!in_array($me, aliveIds($rid), true)) fail(403, 'out');
        // Antwort ist eine Zeichnung (JPEG/PNG als Data-URL)
        $text = $_POST['answer'] ?? '';
        if (!preg_match('#^data:image/(jpeg|png);base64,#', $text) || strlen($text) > 400000) fail(400, 'input');
        q('INSERT INTO kr_ei_answers (room_id, round_no, discord_id, answer) VALUES (?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE answer = VALUES(answer)', [$rid, $g['round_no'], $me, $text]);
    }

    elseif ($action === 'spy') {
        $target = $_POST['target'] ?? '';
        if ($phase !== 'ask') fail(409, 'noactive');
        $pl = q('SELECT joker, sixes FROM kr_ei_players WHERE room_id = ? AND discord_id = ? AND `out` = 0', [$rid, $me])->fetch();
        if (!$pl) fail(403, 'out');
        if ((int)$pl['joker'] < 1 || (int)$pl['sixes'] >= EI_SIX_LIMIT) fail(409, 'nojoker');
        if ($target === $me || !in_array($target, aliveIds($rid), true)) fail(400, 'input');
        $ins = q('INSERT IGNORE INTO kr_ei_spies (room_id, round_no, spy_id, target_id) VALUES (?, ?, ?, ?)',
                 [$rid, $g['round_no'], $me, $target]);
        if ($ins->rowCount() > 0) {
            q('UPDATE kr_ei_players SET joker = joker - 1 WHERE room_id = ? AND discord_id = ?', [$rid, $me]);
        }
    }

    elseif ($action === 'draw') {
        // Live-Striche: Liste von Segmenten [x0,y0,x1,y1,farbe] (Koordinaten 0..1) oder 'clear'
        if ($phase !== 'ask') fail(409, 'noactive');
        if (!in_array($me, aliveIds($rid), true)) fail(403, 'out');
        $data = $_POST['data'] ?? '';
        if ($data === 'clear') {
            $data = 'null';
        } else {
            $segs = json_decode($data, true);
            if (!is_array($segs) || count($segs) > 500 || strlen($data) > 40000) fail(400, 'input');
            $data = json_encode($segs);
        }
        q('INSERT INTO kr_ei_strokes (room_id, round_no, discord_id, data) VALUES (?, ?, ?, ?)', [$rid, $g['round_no'], $me, $data]);
    }

    elseif ($action === 'reveal') {
        if ($phase !== 'ask') fail(409, 'noactive');
        q('UPDATE kr_ei_game SET phase = \'reveal\' WHERE room_id = ?', [$rid]);
    }

    elseif ($action === 'mark') {
        // Host markiert richtige Antworten. Danach werden Leben, Sechsen und Punkte verrechnet.
        if ($phase !== 'reveal') fail(409, 'noactive');
        $correct = array_filter(array_map('trim', explode(',', $_POST['correct'] ?? '')));
        $alive = aliveIds($rid);
        $rapid = $g['mode'] === 'rapid';
        $pdo = db();
        $pdo->beginTransaction();

        foreach ($alive as $pid) {
            $ca = q('SELECT answer FROM kr_ei_answers WHERE room_id = ? AND round_no = ? AND discord_id = ?', [$rid, $g['round_no'], $pid])->fetchColumn();
            if ($ca !== false) {
                q('UPDATE kr_ei_answers SET correct = ? WHERE room_id = ? AND round_no = ? AND discord_id = ?',
                  [in_array($pid, $correct, true) ? 1 : 0, $rid, $g['round_no'], $pid]);
            }
        }
        foreach ($alive as $pid) {
            $a = q('SELECT answer FROM kr_ei_answers WHERE room_id = ? AND round_no = ? AND discord_id = ?',
                   [$rid, $g['round_no'], $pid])->fetchColumn();
            $isRight = in_array($pid, $correct, true) && $a !== false;
            if ($a !== false) {
                q('UPDATE kr_ei_answers SET correct = ? WHERE room_id = ? AND round_no = ? AND discord_id = ?',
                  [$isRight ? 1 : 0, $rid, $g['round_no'], $pid]);
            }
            if ($rapid) {
                if ($isRight) q('UPDATE kr_ei_players SET points = points + ? WHERE room_id = ? AND discord_id = ?',
                                [EI_RAPID_BASE * EI_RAPID_MULT, $rid, $pid]);
                continue;
            }
            if ($isRight) continue;
            // Falsch: 1 Leben und 1 Sechs
            q('UPDATE kr_ei_players SET lives = GREATEST(lives - 1, 0), sixes = sixes + 1 WHERE room_id = ? AND discord_id = ?', [$rid, $pid]);
        }
        if (!$rapid) {
            q('UPDATE kr_ei_players SET `out` = 1 WHERE room_id = ? AND lives = 0 AND `out` = 0', [$rid]);
        }
        q('UPDATE kr_ei_game SET phase = \'result\' WHERE room_id = ?', [$rid]);
        $pdo->commit();
    }

    elseif ($action === 'next') {
        if ($phase !== 'result') fail(409, 'noactive');
        $alive = count(aliveIds($rid));
        if ($g['mode'] === 'rapid') {
            $n = (int)$g['round_no'] - 1000;
            if ($n >= EI_RAPID_Q || $alive === 0) {
                q('UPDATE kr_ei_game SET phase = \'over\' WHERE room_id = ?', [$rid]);
            } else {
                startRound($rid, 1000 + $n + 1, 'rapid');
            }
        } else {
            $round = (int)$g['round_no'];
            if ($alive === 0) {
                q('UPDATE kr_ei_game SET phase = \'over\' WHERE room_id = ?', [$rid]);
            } elseif ($alive === 1 || $round >= EI_MAX_CLASS * EI_ROUNDS_CLASS) {
                // Finale: der letzte Spieler bekommt die Schnellfeuer-Runde (Rundennummern ab 1001)
                startRound($rid, 1001, 'rapid');
            } else {
                startRound($rid, $round + 1, 'class');
            }
        }
    }

    elseif ($action === 'reset') {
        q('UPDATE kr_ei_game SET phase = \'over\' WHERE room_id = ?', [$rid]);
    }

    else {
        fail(400, 'action');
    }

    echo json_encode(['ok' => true]);
    exit;
}

// ---------- Status ----------
$g = game($rid);
$phase = $g['phase'] ?? 'lobby';
$round = (int)($g['round_no'] ?? 1);
$mode  = $g['mode'] ?? 'class';

$secondsLeft = null;
if ($phase === 'ask' && !empty($g['deadline'])) {
    $s = q('SELECT GREATEST(TIMESTAMPDIFF(SECOND, NOW(), deadline), 0) FROM kr_ei_game WHERE room_id = ?', [$rid]);
    $secondsLeft = (int)$s->fetchColumn();
}

$question = null;
if (!empty($g['question_id'])) {
    $qq = q('SELECT question, answer FROM kr_questions WHERE id = ?', [$g['question_id']])->fetch();
    if ($qq) {
        $question = ['text' => $qq['question'], 'answer' => ($isHost || in_array($phase, ['reveal', 'result'], true)) ? $qq['answer'] : null];
    }
}

$info = $mode === 'class' ? classInfo($round) : null;

if ($phase === 'lobby') {
    // Lobby: nur Spieler, die gerade aktiv im Raum sind (Host ausgenommen)
    $rows = q('SELECT r.discord_id, 3 AS lives, 0 AS sixes, 1 AS joker, 0 AS `out`, 0 AS points,
                      r.username, r.avatar, r.decoration, r.premium
               FROM kr_room_players r
               WHERE r.room_id = ? AND r.discord_id <> ? AND r.last_seen > NOW() - INTERVAL 10 SECOND
               ORDER BY r.joined_at, r.discord_id', [$rid, $room['host_id']])->fetchAll();
} else {
    $rows = q('SELECT p.discord_id, p.lives, p.sixes, p.joker, p.out, p.points, r.username, r.avatar, r.decoration, r.premium
               FROM kr_ei_players p
               JOIN kr_room_players r ON r.room_id = p.room_id AND r.discord_id = p.discord_id
               WHERE p.room_id = ? ORDER BY r.joined_at, p.discord_id', [$rid])->fetchAll();
}

$answers = [];
foreach (q('SELECT discord_id, answer, correct FROM kr_ei_answers WHERE room_id = ? AND round_no = ?', [$rid, $round])->fetchAll() as $a) {
    $answers[$a['discord_id']] = $a;
}

$mySpied = [];
foreach (q('SELECT target_id FROM kr_ei_spies WHERE room_id = ? AND round_no = ? AND spy_id = ?', [$rid, $round, $me])->fetchAll(PDO::FETCH_COLUMN) as $t) {
    $mySpied[] = $t;
}

$suspect = [];
foreach (q('SELECT s.spy_id, a.answer AS spied, b.answer AS own FROM kr_ei_spies s
            JOIN kr_ei_answers a ON a.room_id = s.room_id AND a.round_no = s.round_no AND a.discord_id = s.target_id
            JOIN kr_ei_answers b ON b.room_id = s.room_id AND b.round_no = s.round_no AND b.discord_id = s.spy_id
            WHERE s.room_id = ? AND s.round_no = ?', [$rid, $round])->fetchAll() as $x) {
    if (norm($x['spied']) === norm($x['own'])) $suspect[$x['spy_id']] = true;
}

$penalty = [];
foreach (q('SELECT s.spy_id FROM kr_ei_spies s
            JOIN kr_ei_answers a ON a.room_id = s.room_id AND a.round_no = s.round_no AND a.discord_id = s.target_id
            JOIN kr_ei_answers b ON b.room_id = s.room_id AND b.round_no = s.round_no AND b.discord_id = s.spy_id
            WHERE s.room_id = ? AND s.round_no = ? AND a.correct = 0 AND b.correct = 0', [$rid, $round])->fetchAll(PDO::FETCH_COLUMN) as $sid) {
    $penalty[$sid] = true;
}

// Live-Striche: Host sieht alle, Spicker nur die Spieler, bei denen er schaut
$strokeRows = [];
$after = (int)($_GET['after'] ?? 0);
if ($isHost || $mySpied) {
    $sql = 'SELECT id, discord_id, data FROM kr_ei_strokes WHERE room_id = ? AND round_no = ? AND id > ?';
    $params = [$rid, $round, $after];
    if (!$isHost) {
        $sql .= ' AND discord_id IN (' . implode(',', array_fill(0, count($mySpied), '?')) . ')';
        $params = array_merge($params, $mySpied);
    }
    $sql .= ' ORDER BY id LIMIT 2000';
    foreach (q($sql, $params)->fetchAll() as $r) {
        $strokeRows[] = ['id' => (int)$r['id'], 'd' => $r['discord_id'], 's' => json_decode($r['data'], true)];
    }
}

$players = [];
foreach ($rows as $p) {
    $pid = $p['discord_id'];
    $ans = $answers[$pid] ?? null;
    $showAns = $isHost || in_array($phase, ['reveal', 'result'], true) || in_array($pid, $mySpied, true);
    $players[] = [
        'id'        => $pid,
        'name'      => $p['username'],
        'avatar'    => getAvatarUrl(['id' => $pid, 'avatar' => $p['avatar']]),
        'decoration'=> $p['decoration'] ?: null,
        'premium'   => (int)$p['premium'],
        'lives'     => (int)$p['lives'],
        'sixes'     => (int)$p['sixes'],
        'joker'     => (int)$p['joker'],
        'out'       => (bool)$p['out'],
        'points'    => (int)$p['points'],
        'answered'  => $ans !== null,
        'answer'    => $showAns && $ans ? $ans['answer'] : null,
        'correct'   => $ans && $ans['correct'] !== null ? (bool)$ans['correct'] : null,
        'isMe'      => $pid === $me,
        'spiedByMe' => in_array($pid, $mySpied, true),
        'suspect'   => !empty($suspect[$pid]),
        'spyPenalty'=> !empty($penalty[$pid]),
    ];
}

$my = null;
foreach ($players as $p) if ($p['isMe']) $my = $p;

echo json_encode([
    'isHost'      => $isHost,
    'phase'       => $phase,
    'mode'        => $mode,
    'round'       => $mode === 'rapid' ? $round - 1000 : $round,
    'class'       => $info['class'] ?? null,
    'fach'        => $info['fach'] ?? null,
    'punkte'      => $info['punkte'] ?? null,
    'rapidTotal'  => EI_RAPID_Q,
    'secondsLeft' => $secondsLeft,
    'question'    => $question,
    'players'     => $players,
    'strokes'     => $strokeRows,
    'me'          => $my,
    'mySpyCanUse' => $my ? (!$my['out'] && $my['joker'] > 0 && $my['sixes'] < EI_SIX_LIMIT && $phase === 'ask') : false,
    'winner'      => ($phase === 'over' && count(array_filter($players, fn($p) => !$p['out'])) === 1)
                        ? current(array_filter($players, fn($p) => !$p['out']))['name'] : null,
]);
// Version 1.7
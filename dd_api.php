<?php
// Version 1.1
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

const DD_POINTS = [100, 60, 30, 10];   // Punkte je nach Anzahl gezeigter Tipps (0, 1, 2, 3)

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
function game(int $rid): array {
    q('INSERT IGNORE INTO kr_dd_game (room_id) VALUES (?)', [$rid]);
    return q('SELECT * FROM kr_dd_game WHERE room_id = ?', [$rid])->fetch();
}
// Nächsten noch nicht gespielten Begriff ziehen und als Runde setzen
function nextTerm(int $rid): bool {
    $id = q('SELECT id FROM kr_dd_terms WHERE id NOT IN (SELECT term_id FROM kr_dd_used WHERE room_id = ?) ORDER BY RAND() LIMIT 1', [$rid])->fetchColumn();
    if (!$id) return false;
    q('INSERT INTO kr_dd_used (room_id, term_id) VALUES (?, ?)', [$rid, $id]);
    q('UPDATE kr_dd_game SET term_id = ?, tip_level = 0, phase = \'play\' WHERE room_id = ?', [$id, $rid]);
    return true;
}

// ---------- Aktionen (nur Host) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isHost) fail(403, 'host');
    $action = $_POST['action'] ?? '';
    $g = game($rid);
    $phase = $g['phase'];

    if ($action === 'start') {
        if (!in_array($phase, ['lobby', 'over'], true)) fail(409, 'running');
        $total = max(3, min(15, (int)($_POST['total'] ?? 10)));
        q('DELETE FROM kr_dd_used WHERE room_id = ?', [$rid]);
        q('UPDATE kr_dd_game SET total = ?, done = 0, score = 0, last_result = NULL, term_id = NULL WHERE room_id = ?', [$total, $rid]);
        if (!nextTerm($rid)) fail(400, 'noterms');
    }
    elseif ($action === 'tip') {
        if ($phase !== 'play') fail(409, 'noactive');
        if ((int)$g['tip_level'] >= 3) fail(409, 'maxtip');
        q('UPDATE kr_dd_game SET tip_level = tip_level + 1 WHERE room_id = ?', [$rid]);
    }
    elseif ($action === 'solved') {
        if ($phase !== 'play') fail(409, 'noactive');
        $value = DD_POINTS[(int)$g['tip_level']];
        q('UPDATE kr_dd_game SET score = score + ?, done = done + 1, last_result = \'solved\', phase = \'reveal\' WHERE room_id = ?', [$value, $rid]);
    }
    elseif ($action === 'skip') {
        if ($phase !== 'play') fail(409, 'noactive');
        q('UPDATE kr_dd_game SET done = done + 1, last_result = \'skipped\', phase = \'reveal\' WHERE room_id = ?', [$rid]);
    }
    elseif ($action === 'next') {
        if ($phase !== 'reveal') fail(409, 'noactive');
        if ((int)$g['done'] >= (int)$g['total'] || !nextTerm($rid)) {
            q('UPDATE kr_dd_game SET phase = \'over\' WHERE room_id = ?', [$rid]);
        }
    }
    elseif ($action === 'reset') {
        q('UPDATE kr_dd_game SET phase = \'over\' WHERE room_id = ?', [$rid]);
    }
    else {
        fail(400, 'action');
    }
    echo json_encode(['ok' => true]);
    exit;
}

// ---------- Status ----------
$g = game($rid);
$phase = $g['phase'];
$tipLevel = (int)$g['tip_level'];

$term = null;
if (!empty($g['term_id']) && in_array($phase, ['play', 'reveal'], true)) {
    $t = q('SELECT * FROM kr_dd_terms WHERE id = ?', [$g['term_id']])->fetch();
    if ($t) {
        $tips = array_slice([$t['tip1'], $t['tip2'], $t['tip3']], 0, $tipLevel);
        $term = [
            'word'     => $t['word'],
            'tips'     => $tips,
            'solution' => $phase === 'reveal' ? $t['solution'] : null,
            'pointe'   => $phase === 'reveal' ? $t['pointe'] : null,
        ];
    }
}

// Nur Spieler, die in den letzten 10 Sekunden aktiv waren
$players = array_map(fn($p) => [
    'name'   => $p['username'],
    'avatar' => getAvatarUrl(['id' => $p['discord_id'], 'avatar' => $p['avatar']]),
    'isMe'   => $p['discord_id'] === $me,
    'cam'    => (bool)$p['cam_on'],
    'mic'    => (bool)$p['mic_on'],
], q('SELECT discord_id, username, avatar, cam_on, mic_on
      FROM kr_room_players
      WHERE room_id = ?
        AND discord_id <> ?
        AND last_seen > NOW() - INTERVAL 10 SECOND
      ORDER BY joined_at, discord_id',
     [$rid, $room['host_id']])->fetchAll());

echo json_encode([
    'isHost'     => $isHost,
    'phase'      => $phase,
    'total'      => (int)$g['total'],
    'done'       => (int)$g['done'],
    'score'      => (int)$g['score'],
    'tipLevel'   => $tipLevel,
    'nextValue'  => DD_POINTS[min($tipLevel, 3)],
    'lastResult' => $g['last_result'],
    'term'       => $term,
    'players'    => $players,
]);
// Version 1.1
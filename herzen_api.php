<?php
// Version 1.1
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

$me = $_SESSION['discord_user']['id'] ?? null;
if (!$me) {
    http_response_code(401);
    echo json_encode(['error' => 'login']);
    exit;
}

function q(string $sql, array $p = []): PDOStatement
{
    $s = db()->prepare($sql);
    $s->execute($p);
    return $s;
}

function fail(int $status, string $error): void
{
    http_response_code($status);
    echo json_encode(['error' => $error]);
    exit;
}

// Alle Spieler des Spiels (ohne Host), in Beitrittsreihenfolge
function players(int $roomId): array
{
    return q('
        SELECT h.discord_id, h.hearts, h.joker_used, h.eliminated,
               p.username, p.avatar, p.decoration, p.premium
        FROM kr_hearts_players h
        JOIN kr_room_players p ON p.room_id = h.room_id AND p.discord_id = h.discord_id
        WHERE h.room_id = ?
        ORDER BY p.joined_at, p.discord_id
    ', [$roomId])->fetchAll();
}

function alivePlayers(int $roomId): array
{
    return array_values(array_filter(players($roomId), fn($p) => (int)$p['eliminated'] === 0));
}

function game(int $roomId): array
{
    return q('SELECT * FROM kr_hearts_game WHERE room_id = ?', [$roomId])->fetch();
}

// Eine Frage aus der Kategorie, die in diesem Spiel noch nicht gestellt wurde
function pickQuestion(int $roomId, int $catId): ?int
{
    $r = q('
        SELECT id FROM kr_questions
        WHERE category_id = ?
          AND id NOT IN (SELECT question_id FROM kr_hearts_answers WHERE room_id = ?)
        ORDER BY RAND() LIMIT 1
    ', [$catId, $roomId])->fetch();
    return $r ? (int)$r['id'] : null;
}

// Frage an einen Spieler stellen und den 15-Sekunden-Timer starten
function ask(int $roomId, int $catId, string $playerId): void
{
    $qid = pickQuestion($roomId, $catId);
    if (!$qid) {
        fail(400, 'noquestion');
    }
    q('
        UPDATE kr_hearts_game
        SET phase = \'asking\', question_id = ?, current_player = ?,
            question_no = question_no + 1, via_joker = 0,
            deadline = NOW() + INTERVAL 15 SECOND
        WHERE room_id = ?
    ', [$qid, $playerId, $roomId]);
}

// Läuft die Zeit ab, gilt die Antwort als falsch
function timeoutCheck(int $roomId): void
{
    $upd = q('
        UPDATE kr_hearts_game SET phase = \'answered\'
        WHERE room_id = ? AND phase = \'asking\' AND deadline < NOW()
    ', [$roomId]);
    if ($upd->rowCount() > 0) {
        $g = game($roomId);
        q('
            INSERT INTO kr_hearts_answers (room_id, discord_id, question_id, correct, via_joker, round_no)
            VALUES (?, ?, ?, 0, ?, ?)
        ', [$roomId, $g['current_player'], $g['question_id'], $g['via_joker'], $g['round_no']]);
    }
}

// Spiel zurücksetzen: Spieler, Antworten und Stimmen löschen
function resetGame(int $roomId): void
{
    q('DELETE FROM kr_hearts_players WHERE room_id = ?', [$roomId]);
    q('DELETE FROM kr_hearts_answers WHERE room_id = ?', [$roomId]);
    q('DELETE FROM kr_hearts_votes WHERE room_id = ?', [$roomId]);
    q('
        UPDATE kr_hearts_game
        SET phase = \'idle\', question_id = NULL, current_player = NULL, deadline = NULL,
            question_no = 0, round_no = 1, via_joker = 0
        WHERE room_id = ?
    ', [$roomId]);
}

// Fragerunde vorbei? Jeder lebende Spieler bekommt 2 Fragen
function roundDone(int $roomId, array $g): bool
{
    return (int)$g['question_no'] >= 2 * count(alivePlayers($roomId));
}

// ---------- Raum und Rechte ----------

$code = strtoupper($_REQUEST['code'] ?? '');
$room = q('SELECT id, host_id, mode FROM kr_rooms WHERE code = ?', [$code])->fetch();
if (!$room) {
    fail(404, 'room');
}

$roomId = (int)$room['id'];
$hostId = $room['host_id'];
$isHost = $hostId === $me;

q('INSERT IGNORE INTO kr_hearts_game (room_id) VALUES (?)', [$roomId]);
q('UPDATE kr_room_players SET last_seen = NOW() WHERE room_id = ? AND discord_id = ?', [$roomId, $me]);
timeoutCheck($roomId);

// ---------- Aktionen ----------

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $g = game($roomId);

    if ($action === 'set_mode') {
        if (!$isHost) fail(403, 'host');
        $mode = ($_POST['mode'] ?? '') === 'hearts' ? 'hearts' : 'points';
        $keep = ($_POST['keep'] ?? '1') === '1';
        if (!$keep) {
            q('UPDATE kr_teams SET points = 0 WHERE room_id = ?', [$roomId]);
        }
        q('UPDATE kr_rooms SET mode = ? WHERE id = ?', [$mode, $roomId]);
    }

    elseif ($action === 'start') {
        if (!$isHost) fail(403, 'host');
        if (!in_array($g['phase'], ['idle', 'finished'], true)) fail(409, 'running');

        $catId = (int)($_POST['category_id'] ?? 0);
        if (!q('SELECT id FROM kr_categories WHERE id = ?', [$catId])->fetch()) fail(400, 'input');

        resetGame($roomId);
        q('
            INSERT INTO kr_hearts_players (room_id, discord_id)
            SELECT room_id, discord_id FROM kr_room_players
            WHERE room_id = ? AND discord_id <> ?
        ', [$roomId, $hostId]);

        $alive = alivePlayers($roomId);
        if (count($alive) < 2) fail(400, 'few');

        q('UPDATE kr_hearts_game SET category_id = ? WHERE room_id = ?', [$catId, $roomId]);
        ask($roomId, $catId, $alive[0]['discord_id']);
    }

    elseif ($action === 'mark') {
        if (!$isHost) fail(403, 'host');
        if ($g['phase'] !== 'asking') fail(409, 'noactive');
        $correct = ($_POST['correct'] ?? '0') === '1' ? 1 : 0;
        q('
            INSERT INTO kr_hearts_answers (room_id, discord_id, question_id, correct, via_joker, round_no)
            VALUES (?, ?, ?, ?, ?, ?)
        ', [$roomId, $g['current_player'], $g['question_id'], $correct, $g['via_joker'], $g['round_no']]);
        q('UPDATE kr_hearts_game SET phase = \'answered\' WHERE room_id = ?', [$roomId]);
    }

    elseif ($action === 'joker') {
        // Der aktuelle Spieler gibt die Frage weiter, auch wenn er sie selbst per Joker bekommen hat
        if ($g['phase'] !== 'asking') fail(409, 'noactive');
        if ($g['current_player'] !== $me) fail(403, 'notyours');

        $mine = null;
        foreach (players($roomId) as $p) {
            if ($p['discord_id'] === $me) $mine = $p;
        }
        if (!$mine) fail(409, 'nojoker');
        if ((int)$mine['joker_used'] === 1) fail(409, 'nojoker');
        if ((int)$mine['eliminated'] === 1) fail(409, 'out');

        $target = $_POST['target'] ?? '';
        $aliveIds = array_column(alivePlayers($roomId), 'discord_id');
        if ($target === $me || !in_array($target, $aliveIds, true)) fail(400, 'input');

        q('UPDATE kr_hearts_players SET joker_used = 1 WHERE room_id = ? AND discord_id = ?', [$roomId, $me]);
        q('
            UPDATE kr_hearts_game
            SET current_player = ?, via_joker = 1, deadline = NOW() + INTERVAL 15 SECOND
            WHERE room_id = ?
        ', [$target, $roomId]);
    }

    elseif ($action === 'next') {
        if (!$isHost) fail(403, 'host');
        if ($g['phase'] !== 'answered') fail(409, 'noactive');

        if (roundDone($roomId, $g)) {
            // Erst die falschen Antworten zeigen, dann abstimmen lassen
            q('UPDATE kr_hearts_game SET phase = \'review\' WHERE room_id = ?', [$roomId]);
        } else {
            $alive = alivePlayers($roomId);
            $ids = array_column($alive, 'discord_id');
            $idx = array_search($g['current_player'], $ids, true);
            $next = $alive[((($idx === false) ? 0 : $idx) + 1) % count($alive)]['discord_id'];
            ask($roomId, (int)$g['category_id'], $next);
        }
    }

    elseif ($action === 'start_vote') {
        if (!$isHost) fail(403, 'host');
        if ($g['phase'] !== 'review') fail(409, 'noactive');
        q('UPDATE kr_hearts_game SET phase = \'voting\' WHERE room_id = ?', [$roomId]);
    }

    elseif ($action === 'vote') {
        if ($g['phase'] !== 'voting') fail(409, 'noactive');
        $aliveIds = array_column(alivePlayers($roomId), 'discord_id');
        $target = $_POST['target'] ?? '';
        if (!in_array($me, $aliveIds, true)) fail(403, 'out');
        if ($target === $me || !in_array($target, $aliveIds, true)) fail(400, 'input');

        q('
            REPLACE INTO kr_hearts_votes (room_id, round_no, voter_id, target_id)
            VALUES (?, ?, ?, ?)
        ', [$roomId, $g['round_no'], $me, $target]);
    }

    elseif ($action === 'tally') {
        if (!$isHost) fail(403, 'host');
        if ($g['phase'] !== 'voting') fail(409, 'noactive');

        $rows = q('
            SELECT target_id, COUNT(*) AS c FROM kr_hearts_votes
            WHERE room_id = ? AND round_no = ?
            GROUP BY target_id
        ', [$roomId, $g['round_no']])->fetchAll();

        if ($rows) {
            $max = max(array_map(fn($r) => (int)$r['c'], $rows));
            foreach ($rows as $r) {
                if ((int)$r['c'] !== $max) continue;
                q('UPDATE kr_hearts_players SET hearts = GREATEST(hearts - 1, 0) WHERE room_id = ? AND discord_id = ?',
                    [$roomId, $r['target_id']]);
                q('UPDATE kr_hearts_players SET eliminated = 1 WHERE room_id = ? AND discord_id = ? AND hearts = 0',
                    [$roomId, $r['target_id']]);
            }
        }
        q('UPDATE kr_hearts_game SET phase = \'result\' WHERE room_id = ?', [$roomId]);
    }

    elseif ($action === 'nextround') {
        if (!$isHost) fail(403, 'host');
        if ($g['phase'] !== 'result') fail(409, 'noactive');

        $alive = alivePlayers($roomId);
        if (count($alive) <= 1) {
            q('UPDATE kr_hearts_game SET phase = \'finished\' WHERE room_id = ?', [$roomId]);
        } else {
            q('UPDATE kr_hearts_game SET round_no = round_no + 1, question_no = 0 WHERE room_id = ?', [$roomId]);
            ask($roomId, (int)$g['category_id'], $alive[0]['discord_id']);
        }
    }

    elseif ($action === 'reset') {
        if (!$isHost) fail(403, 'host');
        resetGame($roomId);
    }

    else {
        fail(400, 'action');
    }

    echo json_encode(['ok' => true]);
    exit;
}

// ---------- Status für alle ----------

$g = game($roomId);

$secondsLeft = null;
if ($g['phase'] === 'asking') {
    $secondsLeft = (int)q('
        SELECT GREATEST(TIMESTAMPDIFF(SECOND, NOW(), deadline), 0)
        FROM kr_hearts_game WHERE room_id = ?
    ', [$roomId])->fetchColumn();
}

$names = [];
$aliveIds = [];
$out = [];
foreach (players($roomId) as $p) {
    $names[$p['discord_id']] = $p['username'];
    if ((int)$p['eliminated'] === 0) $aliveIds[] = $p['discord_id'];
    $out[] = [
        'discord_id'     => $p['discord_id'],
        'name'           => $p['username'],
        'avatar_url'     => getAvatarUrl(['id' => $p['discord_id'], 'avatar' => $p['avatar']]),
        'decoration_url' => $p['decoration'] ?: null,
        'premium'        => (int)$p['premium'],
        'hearts'         => (int)$p['hearts'],
        'joker_used'     => (bool)$p['joker_used'],
        'eliminated'     => (bool)$p['eliminated'],
        'is_current'     => $p['discord_id'] === $g['current_player'] && in_array($g['phase'], ['asking', 'answered'], true),
        'is_me'          => $p['discord_id'] === $me,
    ];
}

$question = null;
if ($g['question_id']) {
    $qq = q('SELECT question, answer FROM kr_questions WHERE id = ?', [$g['question_id']])->fetch();
    if ($qq) {
        $question = ['text' => $qq['question'], 'answer' => $isHost ? $qq['answer'] : null];
    }
}

$catName = null;
if ($g['category_id']) {
    $catName = q('SELECT name FROM kr_categories WHERE id = ?', [$g['category_id']])->fetchColumn() ?: null;
}

// Falsche Antworten der aktuellen Runde, pro Spieler gruppiert
$wrongOut = [];
if (in_array($g['phase'], ['review', 'voting', 'result'], true)) {
    $rows = q('
        SELECT a.discord_id, qq.question, qq.answer
        FROM kr_hearts_answers a
        JOIN kr_questions qq ON qq.id = a.question_id
        WHERE a.room_id = ? AND a.round_no = ? AND a.correct = 0
        ORDER BY a.id
    ', [$roomId, $g['round_no']])->fetchAll();

    $grouped = [];
    foreach ($rows as $r) {
        $grouped[$r['discord_id']][] = ['q' => $r['question'], 'a' => $r['answer']];
    }
    foreach ($grouped as $id => $items) {
        $wrongOut[] = ['discord_id' => (string)$id, 'name' => $names[$id] ?? (string)$id, 'items' => $items];
    }
}

// Stimmen der aktuellen Runde
$myVote = null;
$voteCount = 0;
$tally = [];
if (in_array($g['phase'], ['voting', 'result'], true)) {
    $vs = q('SELECT voter_id, target_id FROM kr_hearts_votes WHERE room_id = ? AND round_no = ?',
        [$roomId, $g['round_no']])->fetchAll();
    $voteCount = count($vs);
    $counts = [];
    foreach ($vs as $v) {
        if ($v['voter_id'] === $me) $myVote = $v['target_id'];
        $counts[$v['target_id']] = ($counts[$v['target_id']] ?? 0) + 1;
    }
    arsort($counts);
    foreach ($counts as $id => $c) {
        $tally[] = ['discord_id' => (string)$id, 'name' => $names[$id] ?? (string)$id, 'votes' => $c];
    }
}

$winner = null;
if ($g['phase'] === 'finished' && count($aliveIds) === 1) {
    $winner = $names[$aliveIds[0]] ?? null;
}

// Joker: nur für den aktuellen Spieler, solange er ihn noch hat
$jokerAvailable = false;
foreach ($out as $p) {
    if ($p['is_me'] && $g['phase'] === 'asking' && $g['current_player'] === $me
        && !$p['joker_used'] && !$p['eliminated']) {
        $jokerAvailable = true;
    }
}

echo json_encode([
    'mode'           => $room['mode'],
    'isHost'         => $isHost,
    'phase'          => $g['phase'],
    'round'          => (int)$g['round_no'],
    'category'       => $catName,
    'categories'     => q('SELECT id, name FROM kr_categories ORDER BY name')->fetchAll(),
    'secondsLeft'    => $secondsLeft,
    'viaJoker'       => (bool)$g['via_joker'],
    'question'       => $question,
    'players'        => $out,
    'aliveCount'     => count($aliveIds),
    'jokerAvailable' => $jokerAvailable,
    'wrongs'         => $wrongOut,
    'vote'           => ['mine' => $myVote, 'count' => $voteCount],
    'tally'          => $tally,
    'winner'         => $winner,
]);
// Version 1.1
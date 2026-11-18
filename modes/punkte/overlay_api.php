<?php
// Version 1.1
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/db.php';

header('Content-Type: application/json; charset=utf-8');

$code = strtoupper($_GET['code'] ?? '');
$stmt = db()->prepare('SELECT id, turn_team_id, board_round FROM kr_rooms WHERE code = ?');
$stmt->execute([$code]);
$room = $stmt->fetch();
if (!$room) {
    http_response_code(404);
    echo json_encode(['error' => 'room']);
    exit;
}

$roomId = (int)$room['id'];
$round  = (int)$room['board_round'] === 2 ? 2 : 1;

$stmt = db()->prepare('SELECT id, name, color, points FROM kr_teams WHERE room_id = ? ORDER BY sort, id');
$stmt->execute([$roomId]);
$teams = array_map(fn($r) => [
    'id'     => (int)$r['id'],
    'name'   => $r['name'],
    'color'  => $r['color'],
    'points' => (int)$r['points'],
], $stmt->fetchAll());

$ids  = array_column($teams, 'id');
$turn = (int)($room['turn_team_id'] ?? 0);
if (!in_array($turn, $ids, true)) {
    $turn = $ids[0] ?? 0;
}

// HIER DIE ERWEITERUNG UM image_url
$stmt = db()->prepare('
    SELECT a.slot, a.points, a.round, a.revealed, q.image_url 
    FROM kr_active a 
    JOIN kr_questions q ON q.id = a.question_id 
    WHERE a.room_id = ?
');
$stmt->execute([$roomId]);
$a = $stmt->fetch();
$active = null;
if ($a) {
    $active = [
        'slot'     => (int)$a['slot'],
        'points'   => (int)$a['points'],
        'revealed' => (bool)$a['revealed'],
        'image_url'=> $a['image_url']
    ];
}

$stmt2 = db()->prepare('SELECT COUNT(*) FROM kr_played WHERE room_id = ? AND round = ?');
$stmt2->execute([$roomId, $round]);
$finished = ((int)$stmt2->fetchColumn()) >= 42;
$winner = null;
if ($finished && $teams) {
    $top = $teams;
    usort($top, fn($a, $b) => $b['points'] <=> $a['points']);
    $winner = $top[0]['name'];
}
echo json_encode([
    'finished' => $finished,
    'winner'   => $winner,
    'round'      => $round,
    'teams'      => $teams,
    'turnTeamId' => $turn,
    'active'     => $active,
]);
<?php
// Version 1.0
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/db.php';

header('Content-Type: application/json; charset=utf-8');

// Öffentlich lesbar mit dem Raumcode. Keine Antworten, keine Stimmen, keine Kamerabilder.
$code = strtoupper($_GET['code'] ?? '');
$stmt = db()->prepare('SELECT id, host_id, mode FROM kr_rooms WHERE code = ?');
$stmt->execute([$code]);
$room = $stmt->fetch();
if (!$room) {
    http_response_code(404);
    echo json_encode(['error' => 'room']);
    exit;
}
$roomId = (int)$room['id'];

$stmt = db()->prepare('SELECT * FROM kr_hearts_game WHERE room_id = ?');
$stmt->execute([$roomId]);
$g = $stmt->fetch() ?: [];
$phase   = $g['phase'] ?? 'idle';
$round   = (int)($g['round_no'] ?? 1);
$current = $g['current_player'] ?? null;

$secondsLeft = null;
if ($phase === 'asking' && !empty($g['deadline'])) {
    $s = db()->prepare('SELECT GREATEST(TIMESTAMPDIFF(SECOND, NOW(), deadline), 0) FROM kr_hearts_game WHERE room_id = ?');
    $s->execute([$roomId]);
    $secondsLeft = (int)$s->fetchColumn();
}

$stmt = db()->prepare('
    SELECT h.discord_id, h.hearts, h.joker_used, h.eliminated,
           p.username, p.avatar, p.decoration, p.premium
    FROM kr_hearts_players h
    JOIN kr_room_players p ON p.room_id = h.room_id AND p.discord_id = h.discord_id
    WHERE h.room_id = ?
    ORDER BY p.joined_at, p.discord_id
');
$stmt->execute([$roomId]);
$players = array_map(fn($p) => [
    'name'       => $p['username'],
    'avatar'     => getAvatarUrl(['id' => $p['discord_id'], 'avatar' => $p['avatar']]),
    'decoration' => $p['decoration'] ?: null,
    'premium'    => (int)$p['premium'],
    'hearts'     => (int)$p['hearts'],
    'joker'      => !(int)$p['joker_used'],
    'out'        => (bool)$p['eliminated'],
    'current'    => $p['discord_id'] === $current && in_array($phase, ['asking', 'answered'], true),
], $stmt->fetchAll());

$question = null;
if (!empty($g['question_id']) && $phase === 'asking') {
    $q = db()->prepare('SELECT question FROM kr_questions WHERE id = ?');
    $q->execute([$g['question_id']]);
    $question = $q->fetchColumn() ?: null;
}

$winner = null;
if ($phase === 'finished') {
    $alive = array_values(array_filter($players, fn($p) => !$p['out']));
    if (count($alive) === 1) {
        $winner = $alive[0]['name'];
    }
}

echo json_encode([
    'phase'       => $phase,
    'round'       => $round,
    'secondsLeft' => $secondsLeft,
    'question'    => $question,
    'viaJoker'    => (bool)($g['via_joker'] ?? 0),
    'players'     => $players,
    'winner'      => $winner,
]);
// Version 1.0

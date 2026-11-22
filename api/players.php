<?php
// Version 2.4
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/auth.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['discord_user'])) {
    http_response_code(401);
    echo '[]';
    exit;
}

$code = strtoupper($_GET['code'] ?? '');
$me   = $_SESSION['discord_user']['id'];

$stmt = db()->prepare('SELECT id, host_id FROM kr_rooms WHERE code = ?');
$stmt->execute([$code]);
$room = $stmt->fetch();

if (!$room) {
    http_response_code(404);
    echo '[]';
    exit;
}

// Heartbeat + Kamera/Mikro-Status in einem Aufruf
$cam = isset($_GET['cam']) ? (int)($_GET['cam'] === '1') : null;
$mic = isset($_GET['mic']) ? (int)($_GET['mic'] === '1') : null;

if ($cam !== null && $mic !== null) {
    db()->prepare('UPDATE kr_room_players SET last_seen = NOW(), cam_on = ?, mic_on = ? WHERE room_id = ? AND discord_id = ?')
        ->execute([$cam, $mic, $room['id'], $me]);
} else {
    db()->prepare('UPDATE kr_room_players SET last_seen = NOW() WHERE room_id = ? AND discord_id = ?')
        ->execute([$room['id'], $me]);
}

// Nur Spieler anzeigen, die in den letzten 10 Sekunden aktiv waren
$stmt = db()->prepare('
    SELECT p.discord_id, p.username, p.avatar, p.team_id, p.cam_on, p.mic_on,
           p.premium, p.decoration,
           t.name AS team_name, t.color AS team_color
    FROM kr_room_players p
    LEFT JOIN kr_teams t ON t.id = p.team_id
    WHERE p.room_id = ? AND p.last_seen > NOW() - INTERVAL 10 SECOND
    ORDER BY p.joined_at
');
$stmt->execute([$room['id']]);

$players = array_map(fn($p) => [
    'discord_id'     => $p['discord_id'],
    'username'       => $p['username'],
    'avatar_url'     => kr_avatar($p['discord_id'], $p['avatar']),
    'team_id'        => $p['team_id'] !== null ? (int)$p['team_id'] : null,
    'team_name'      => $p['team_name'],
    'team_color'     => $p['team_color'],
    'is_host'        => $p['discord_id'] === $room['host_id'],
    'cam_on'         => (bool)$p['cam_on'],
    'mic_on'         => (bool)$p['mic_on'],
    'premium'        => (int)$p['premium'],
    'decoration_url' => $p['decoration'] ?: null,
], $stmt->fetchAll());

echo json_encode($players);
// Version 2.4
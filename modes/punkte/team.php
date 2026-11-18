<?php
// Version 3.0
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/db.php';
require_once __DIR__ . '/teams.php';

header('Content-Type: application/json; charset=utf-8');

$me = $_SESSION['discord_user']['id'] ?? null;
if (!$me) {
    http_response_code(401);
    echo '{"ok":false,"error":"login"}';
    exit;
}

$code     = strtoupper($_POST['code'] ?? '');
$targetId = $_POST['discord_id'] ?? '';
$teamId   = (int)($_POST['team_id'] ?? -1); // 0 = kein Team

if ($targetId === '' || $teamId < 0) {
    http_response_code(400);
    echo '{"ok":false,"error":"input"}';
    exit;
}

$stmt = db()->prepare('SELECT id, host_id FROM kr_rooms WHERE code = ?');
$stmt->execute([$code]);
$room = $stmt->fetch();
if (!$room) {
    http_response_code(404);
    echo '{"ok":false,"error":"room"}';
    exit;
}

// Nur der Host ordnet zu, und der Host selbst hat kein Team
if ($room['host_id'] !== $me) {
    http_response_code(403);
    echo '{"ok":false,"error":"host"}';
    exit;
}
if ($targetId === $room['host_id']) {
    http_response_code(400);
    echo '{"ok":false,"error":"host_no_team"}';
    exit;
}
if ($teamId !== 0 && !in_array($teamId, teamIds((int)$room['id']), true)) {
    http_response_code(400);
    echo '{"ok":false,"error":"input"}';
    exit;
}

db()->prepare('UPDATE kr_room_players SET team_id = ? WHERE room_id = ? AND discord_id = ?')
    ->execute([$teamId ?: null, $room['id'], $targetId]);

echo '{"ok":true}';
// Version 3.0

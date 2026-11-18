<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

$me = $_SESSION['discord_user']['id'] ?? null;
if (!$me) { http_response_code(401); echo json_encode(['error' => 'login']); exit; }

$code = strtoupper($_POST['code'] ?? '');
$cam  = ($_POST['cam'] ?? '0') === '1' ? 1 : 0;
$mic  = ($_POST['mic'] ?? '0') === '1' ? 1 : 0;

$st = db()->prepare('SELECT id FROM kr_rooms WHERE code = ?');
$st->execute([$code]);
$rid = $st->fetchColumn();
if (!$rid) { http_response_code(404); echo json_encode(['error' => 'room']); exit; }

$up = db()->prepare('UPDATE kr_room_players SET cam_on = ?, mic_on = ? WHERE room_id = ? AND discord_id = ?');
$up->execute([$cam, $mic, (int)$rid, $me]);

echo json_encode(['ok' => true]);
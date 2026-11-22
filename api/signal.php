<?php
// Version 1.0
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';

header('Content-Type: application/json; charset=utf-8');

$me = $_SESSION['discord_user']['id'] ?? null;
if (!$me) {
    http_response_code(401);
    echo '[]';
    exit;
}

$code = strtoupper($_REQUEST['code'] ?? '');
$stmt = db()->prepare('SELECT id FROM kr_rooms WHERE code = ?');
$stmt->execute([$code]);
$room = $stmt->fetch();
if (!$room) {
    http_response_code(404);
    echo '[]';
    exit;
}
$roomId = $room['id'];

// Alte Signale aufräumen
db()->exec('DELETE FROM kr_signals WHERE created_at < NOW() - INTERVAL 1 MINUTE');

// Nachricht speichern
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $to      = $_POST['to'] ?? '';
    $payload = $_POST['payload'] ?? '';
    if ($to !== '' && $payload !== '' && strlen($payload) <= 200000) {
        db()->prepare('INSERT INTO kr_signals (room_id, from_id, to_id, payload) VALUES (?, ?, ?, ?)')
            ->execute([$roomId, $me, $to, $payload]);
    }
    echo '{"ok":true}';
    exit;
}

// Eigene Nachrichten holen und danach löschen
$stmt = db()->prepare('SELECT id, from_id, payload FROM kr_signals WHERE room_id = ? AND to_id = ? ORDER BY id');
$stmt->execute([$roomId, $me]);
$rows = $stmt->fetchAll();

if ($rows) {
    $ids = implode(',', array_map('intval', array_column($rows, 'id')));
    db()->exec("DELETE FROM kr_signals WHERE id IN ($ids)");
}

echo json_encode(array_map(fn($r) => [
    'from_id' => $r['from_id'],
    'payload' => $r['payload'],
], $rows));
// Version 1.0
<?php
// Version 1.0
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['discord_user'])) {
    http_response_code(401);
    echo json_encode(['error' => 'login']);
    exit;
}

$code = strtoupper($_GET['code'] ?? '');
$st = db()->prepare('SELECT mode FROM kr_rooms WHERE code = ?');
$st->execute([$code]);
$row = $st->fetch();
if (!$row) {
    http_response_code(404);
    echo json_encode(['error' => 'room']);
    exit;
}

echo json_encode(['mode' => $row['mode'] ?? 'points']);
// Version 1.0
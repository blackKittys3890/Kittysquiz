<?php

ini_set('display_errors', '1');
error_reporting(E_ALL);
// Version 1.0
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

$user = $_SESSION['discord_user'] ?? null;
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'login']);
    exit;
}

$code = strtoupper($_GET['code'] ?? '');
$stmt = db()->prepare('SELECT id, host_id FROM kr_rooms WHERE code = ?');
$stmt->execute([$code]);
$room = $stmt->fetch();
if (!$room) {
    http_response_code(404);
    echo json_encode(['error' => 'room']);
    exit;
}

// Der Host ist Konferenzleiter (kann andere stummschalten und entfernen)
$isHost = $room['host_id'] === $user['id'];

$token = livekit_token(
    (string)$user['id'],
    (string)($user['name'] ?? $user['username'] ?? 'Spieler'),
    'kr-' . $code,
    $isHost
);

echo json_encode(['token' => $token, 'url' => LIVEKIT_URL]);
// Version 1.0

/**
 * Erzeugt einen signierten LiveKit-Zugangsschlüssel (JWT, HS256). Ohne Bibliothek.
 */
function livekit_token(string $identity, string $name, string $room, bool $admin): string
{
    $b64 = fn(string $s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');

    $now = time();
    $header = ['alg' => 'HS256', 'typ' => 'JWT'];
    $payload = [
        'iss'  => LIVEKIT_API_KEY,
        'sub'  => $identity,
        'name' => $name,
        'nbf'  => $now - 10,
        'exp'  => $now + 6 * 3600,
        'video' => [
            'room'           => $room,
            'roomJoin'       => true,
            'canPublish'     => true,
            'canSubscribe'   => true,
            'canPublishData' => true,
            'roomAdmin'      => $admin,
        ],
    ];

    $segments = $b64(json_encode($header)) . '.' . $b64(json_encode($payload));
    $sig = hash_hmac('sha256', $segments, LIVEKIT_API_SECRET, true);
    return $segments . '.' . $b64($sig);
}

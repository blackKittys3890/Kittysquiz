<?php
// Version 1.1
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';

if (!isset($_SESSION['discord_user'])) {
    exit;
}

$code = strtoupper($_POST['code'] ?? '');

// Nicht löschen, sondern nur als "nicht mehr aktiv" markieren, damit das Team erhalten bleibt
db()->prepare('
    UPDATE kr_room_players p
    JOIN kr_rooms r ON r.id = p.room_id
    SET p.last_seen = \'2000-01-01 00:00:00\'
    WHERE r.code = ? AND p.discord_id = ?
')->execute([$code, $_SESSION['discord_user']['id']]);
// Version 1.1
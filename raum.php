<?php
// Version 4.0
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$user = $_SESSION['discord_user'] ?? null;
if (!$user) {
    header('Location: index.php');
    exit;
}

$code = strtoupper($_GET['code'] ?? '');
$stmt = db()->prepare('SELECT id FROM kr_rooms WHERE code = ?');
$stmt->execute([$code]);
if (!$stmt->fetch()) {
    die('Raum nicht gefunden. <a href="lobby.php">Zurück</a>');
}

// Direkt aufs Spielfeld, dort wird der Spieler eingetragen
header('Location: board.php?code=' . urlencode($code));
exit;
// Version 4.0

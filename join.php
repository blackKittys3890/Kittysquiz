<?php
// Version 1.1
// Link aus der Einladung: leitet nach dem Login in den richtigen Modus.
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/auth.php';
remember_restore();

$code = strtoupper(trim($_GET['code'] ?? ''));
$user = $_SESSION['discord_user'] ?? null;

if (!$user) {
    // Nicht angemeldet: zuerst anmelden, danach wieder hierher
    $_SESSION['after_login'] = '/invite/join.php?code=' . urlencode($code);
    header('Location: /index.php');
    exit;
}

$st = db()->prepare('SELECT mode FROM kr_rooms WHERE code = ?');
$st->execute([$code]);
$mode = $st->fetchColumn();
if ($mode === false) {
    echo 'Dieser Raum existiert nicht mehr. <a href="/lobby.php">Zur Lobby</a>';
    exit;
}

$pages = [
    'points' => '/modes/punkte/board.php',
    'hearts' => '/modes/herzen/herzen.php',
    'eingestuhlt' => '/modes/eingestuhlt/eingestuhlt.php',
    'dumm_drauf' => '/modes/hau_drauf/dumm_drauf.php',
];
header('Location: ' . ($pages[$mode] ?? $pages['points']) . '?code=' . urlencode($code));
exit;
// Version 1.1
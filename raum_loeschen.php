<?php
// Version 1.0
// Löscht einen eigenen Raum samt aller Spieldaten. Nur der Host darf das.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$user = $_SESSION['discord_user'] ?? null;
if (!$user) { header('Location: /index.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /lobby.php'); exit; }

$code = strtoupper(trim($_POST['code'] ?? ''));
$me = (string)$user['id'];

$st = db()->prepare('SELECT id FROM kr_rooms WHERE code = ? AND host_id = ?');
$st->execute([$code, $me]);
$roomId = $st->fetchColumn();
if (!$roomId) {
    header('Location: /lobby.php?msg=' . urlencode('Dieser Raum gehört dir nicht oder existiert nicht mehr.'));
    exit;
}

// Alle Spieldaten des Raums entfernen (Tabellen, die es bei dir nicht gibt, werden übersprungen)
$tables = [
    'kr_teams', 'kr_room_players', 'kr_board', 'kr_played', 'kr_active',
    'kr_dd_game', 'kr_dd_used',
    'kr_ei_answers', 'kr_ei_game', 'kr_ei_players', 'kr_ei_spies', 'kr_ei_strokes', 'kr_ei_used',
    'kr_hearts_answers', 'kr_hearts_game', 'kr_hearts_players', 'kr_hearts_votes',
];
foreach ($tables as $table) {
    try {
        db()->prepare("DELETE FROM {$table} WHERE room_id = ?")->execute([(int)$roomId]);
    } catch (PDOException $e) {
        // Tabelle fehlt: überspringen
    }
}
db()->prepare('DELETE FROM kr_rooms WHERE id = ?')->execute([(int)$roomId]);

header('Location: /lobby.php?msg=' . urlencode('Raum ' . $code . ' wurde gelöscht.'));
exit;
// Version 1.0
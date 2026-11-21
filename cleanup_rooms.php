<?php
// Version 1.1
// Löscht Räume und alle zugehörigen Spieldaten, die älter als CLEANUP_DAYS Tage sind.
// Läuft per Cronjob über den Browser-Aufruf mit Schlüssel (siehe Anleitung).

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

const CLEANUP_DAYS = 30;

// Tabellen mit room_id: werden zuerst geleert, danach die Räume selbst.
// Fehlt eine Tabelle, wird sie übersprungen.
const ROOM_TABLES = [
    'kr_teams', 'kr_room_players', 'kr_board', 'kr_played', 'kr_active',
    'kr_dd_game', 'kr_dd_used',
    'kr_ei_answers', 'kr_ei_game', 'kr_ei_players', 'kr_ei_spies', 'kr_ei_strokes', 'kr_ei_used',
    'kr_hearts_answers', 'kr_hearts_game', 'kr_hearts_players', 'kr_hearts_votes',
];

// Nur über Cronjob / Kommandozeile oder mit gültigem Schlüssel aufrufbar
if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    if (!defined('CLEANUP_KEY') || !hash_equals(CLEANUP_KEY, $_GET['key'] ?? '')) {
        http_response_code(403);
        exit("forbidden\n");
    }
}

$pdo = db();

// Einladungen und Erinnerungen nach 30 Tagen löschen (vor dem Raum-Teil, damit es immer läuft)
$inv = $pdo->prepare('DELETE FROM kr_invites WHERE created_at < NOW() - INTERVAL 30 DAY');
$inv->execute();
echo "kr_invites: " . $inv->rowCount() . " alte Einladungen gelöscht\n";
$rem = $pdo->prepare('DELETE FROM kr_reminders WHERE remind_at < NOW() - INTERVAL 30 DAY');
$rem->execute();
echo "kr_reminders: " . $rem->rowCount() . " alte Erinnerungen gelöscht\n";

$stmt = $pdo->prepare('SELECT id FROM kr_rooms WHERE created_at < NOW() - INTERVAL ' . (int)CLEANUP_DAYS . ' DAY');
$stmt->execute();
$ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (!$ids) {
    echo "Keine alten Räume gefunden.\n";
    exit;
}

$placeholders = implode(',', array_fill(0, count($ids), '?'));

foreach (ROOM_TABLES as $table) {
    try {
        $del = $pdo->prepare("DELETE FROM {$table} WHERE room_id IN ({$placeholders})");
        $del->execute($ids);
        echo "{$table}: " . $del->rowCount() . " Zeilen gelöscht\n";
    } catch (PDOException $e) {
        echo "{$table}: übersprungen (" . $e->getCode() . ")\n";
    }
}

$del = $pdo->prepare("DELETE FROM kr_rooms WHERE id IN ({$placeholders})");
$del->execute($ids);
echo "kr_rooms: " . $del->rowCount() . " Räume gelöscht\n";
// Version 1.1
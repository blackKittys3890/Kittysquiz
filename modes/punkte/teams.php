<?php
// Version 1.0
require_once __DIR__ . '/db.php';

// Legt zwei Standardteams an, falls der Raum noch keine hat
function ensureTeams(int $roomId): void
{
    $stmt = db()->prepare('SELECT COUNT(*) FROM kr_teams WHERE room_id = ?');
    $stmt->execute([$roomId]);
    if ((int)$stmt->fetchColumn() === 0) {
        db()->prepare("
            INSERT INTO kr_teams (room_id, name, color, points, sort)
            VALUES (?, 'Rot', '#ff3b5c', 0, 1), (?, 'Blau', '#3b82ff', 0, 2)
        ")->execute([$roomId, $roomId]);
    }
}

// Alle Team-IDs eines Raums, zur Prüfung von Eingaben
function teamIds(int $roomId): array
{
    $stmt = db()->prepare('SELECT id FROM kr_teams WHERE room_id = ?');
    $stmt->execute([$roomId]);
    return array_map('intval', array_column($stmt->fetchAll(), 'id'));
}
// Version 1.0

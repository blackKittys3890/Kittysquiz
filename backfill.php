<?php
// Version 1.0
// Einmalig: ergänzt die Spieler in alten Board-Einträgen (vor dem Update), soweit der Raum noch existiert.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if (!auth_is_admin()) { http_response_code(403); exit('Nur für Admins.'); }

$rows = db()->query('SELECT id, room_id, results FROM kr_history')->fetchAll();
$updated = 0; $skipped = 0; $nothing = 0;
foreach ($rows as $r) {
    $results = json_decode($r['results'], true) ?: [];
    if (!$results) { $nothing++; continue; }
    if (isset($results[0]['members'])) { $skipped++; continue; }   // schon mit Spielern gespeichert

    $changed = false;
    foreach ($results as &$t) {
        $st = db()->prepare('SELECT id FROM kr_teams WHERE room_id = ? AND name = ? LIMIT 1');
        $st->execute([(int)$r['room_id'], $t['name']]);
        $teamId = $st->fetchColumn();
        $t['members'] = [];
        if ($teamId) {
            $pl = db()->prepare('SELECT discord_id, username FROM kr_room_players WHERE room_id = ? AND team_id = ?');
            $pl->execute([(int)$r['room_id'], (int)$teamId]);
            foreach ($pl->fetchAll() as $p) {
                $t['members'][] = ['id' => (string)$p['discord_id'], 'name' => (string)$p['username']];
            }
            $changed = true;
        }
    }
    unset($t);
    if ($changed) {
        db()->prepare('UPDATE kr_history SET results = ? WHERE id = ?')
            ->execute([json_encode($results, JSON_UNESCAPED_UNICODE), $r['id']]);
        $updated++;
    } else {
        $nothing++;
    }
}

header('Content-Type: text/plain; charset=utf-8');
echo "Ergänzt: $updated\nSchon vollständig: $skipped\nNicht ergänzbar (Raum/Teams gelöscht): $nothing\n";
// Version 1.0
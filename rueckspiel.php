<?php
// Version 1.0
// Rückspiel: Board und Punkte zurücksetzen, gleiche Kategorien und Fragen, Spieler werden neu auf die Teams verteilt.
ini_set('display_errors', '0');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$user = $_SESSION['discord_user'] ?? null;
if (!$user) { header('Location: /index.php'); exit; }
$me = (string)$user['id'];

$code = strtoupper(trim((string)($_REQUEST['code'] ?? '')));
$room = db()->prepare('SELECT id, host_id FROM kr_rooms WHERE code = ?');
$room->execute([$code]);
$room = $room->fetch();
if (!$room) exit('Raum nicht gefunden.');
if ((string)$room['host_id'] !== $me) { http_response_code(403); exit('Nur die Host-Lehrkraft kann ein Rückspiel starten.'); }
$rid = (int)$room['id'];
$hostId = (string)$room['host_id'];

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Board leeren: gespielte Felder und offene Frage weg, Runde 1, Punkte auf 0
    db()->prepare('DELETE FROM kr_played WHERE room_id = ?')->execute([$rid]);
    db()->prepare('DELETE FROM kr_active WHERE room_id = ?')->execute([$rid]);
    db()->prepare('UPDATE kr_teams SET points = 0 WHERE room_id = ?')->execute([$rid]);
    db()->prepare('UPDATE kr_rooms SET board_round = 1, turn_team_id = NULL WHERE id = ?')->execute([$rid]);

    // Teams neu mischen
    $teamIds = db()->prepare('SELECT id FROM kr_teams WHERE room_id = ? ORDER BY sort, id');
    $teamIds->execute([$rid]);
    $teamIds = array_map('intval', $teamIds->fetchAll(PDO::FETCH_COLUMN));
    if ($teamIds) {
        $players = db()->prepare('SELECT discord_id FROM kr_room_players WHERE room_id = ? AND discord_id <> ?');
        $players->execute([$rid, $hostId]);
        $players = $players->fetchAll(PDO::FETCH_COLUMN);
        shuffle($players);
        $upd = db()->prepare('UPDATE kr_room_players SET team_id = ? WHERE room_id = ? AND discord_id = ?');
        foreach ($players as $i => $pid) {
            $upd->execute([$teamIds[$i % count($teamIds)], $rid, $pid]);
        }
        $msg = 'Rückspiel gestartet: Board und Punkte zurückgesetzt, ' . count($players) . ' Spieler neu verteilt.';
    } else {
        $msg = 'Board zurückgesetzt. Es gibt noch keine Teams.';
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Rückspiel – KittysRätsel</title>
    <link rel="stylesheet" href="/css/base.css?v=1.6">
</head>
<body>
<div class="page">
    <section class="card">
        <h2>Rückspiel</h2>
        <p>Raum <strong><?= htmlspecialchars($code) ?></strong></p>
        <?php if ($msg !== ''): ?><p><?= htmlspecialchars($msg) ?></p><?php endif; ?>
        <p class="muted">Das Board und die Punkte werden zurückgesetzt. Die Kategorien und Fragen bleiben gleich. Die Spieler werden neu auf die Teams verteilt. Die Historie bleibt erhalten.</p>
        <form method="post" onsubmit="return confirm('Das Board und alle Punkte dieses Raums werden zurückgesetzt. Fortfahren?');">
            <input type="hidden" name="code" value="<?= htmlspecialchars($code) ?>">
            <p><button class="btn" type="submit">Rückspiel starten</button></p>
        </form>
        <p><a href="/index.php">Zurück</a></p>
    </section>
</div>
</body>
</html>
<!-- Version 1.0 -->

<?php
// Version 1.0
// Zufalls-Teams: Die Host-Lehrkraft verteilt alle Spieler eines Raums zufällig auf die Teams.
ini_set('display_errors', '0');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

const RT_COLORS = ['#3b82ff', '#ef4444', '#22c55e', '#f59e0b', '#a855f7', '#14b8a6', '#ec4899', '#84cc16'];

$user = $_SESSION['discord_user'] ?? null;
if (!$user) { header('Location: /index.php'); exit; }
$me = (string)$user['id'];

$code = strtoupper(trim((string)($_REQUEST['code'] ?? '')));
$room = db()->prepare('SELECT id, host_id FROM kr_rooms WHERE code = ?');
$room->execute([$code]);
$room = $room->fetch();
if (!$room) exit('Raum nicht gefunden.');
if ((string)$room['host_id'] !== $me) { http_response_code(403); exit('Nur die Host-Lehrkraft kann Zufalls-Teams bilden.'); }
$rid = (int)$room['id'];
$hostId = (string)$room['host_id'];

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $want = max(2, min(8, (int)($_POST['teams'] ?? 2)));

    // Fehlende Teams anlegen
    $have = (int)db()->query('SELECT COUNT(*) FROM kr_teams WHERE room_id = ' . $rid)->fetchColumn();
    for ($i = $have; $i < $want; $i++) {
        db()->prepare('INSERT INTO kr_teams (room_id, name, color, sort) VALUES (?, ?, ?, ?)')
            ->execute([$rid, 'Team ' . ($i + 1), RT_COLORS[$i % count(RT_COLORS)], $i + 1]);
    }
    $teamIds = db()->prepare('SELECT id FROM kr_teams WHERE room_id = ? ORDER BY sort, id LIMIT ' . $want);
    $teamIds->execute([$rid]);
    $teamIds = array_map('intval', $teamIds->fetchAll(PDO::FETCH_COLUMN));

    // Alle Spieler (inkl. Gäste) zufällig verteilen, Host zählt nicht mit
    $players = db()->prepare('SELECT discord_id FROM kr_room_players WHERE room_id = ? AND discord_id <> ?');
    $players->execute([$rid, $hostId]);
    $players = $players->fetchAll(PDO::FETCH_COLUMN);
    shuffle($players);
    $upd = db()->prepare('UPDATE kr_room_players SET team_id = ? WHERE room_id = ? AND discord_id = ?');
    foreach ($players as $i => $pid) {
        $upd->execute([$teamIds[$i % count($teamIds)], $rid, $pid]);
    }
    $msg = count($players) . ' Spieler auf ' . count($teamIds) . ' Teams verteilt.';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Zufalls-Teams – KittysRätsel</title>
    <link rel="stylesheet" href="/css/base.css?v=1.6">
</head>
<body>
<div class="page">
    <section class="card">
        <h2>Zufalls-Teams</h2>
        <p>Raum <strong><?= htmlspecialchars($code) ?></strong></p>
        <?php if ($msg !== ''): ?><p><?= htmlspecialchars($msg) ?></p><?php endif; ?>
        <form method="post">
            <input type="hidden" name="code" value="<?= htmlspecialchars($code) ?>">
            <label>Anzahl Teams (2 bis 8)<br><input type="number" name="teams" min="2" max="8" value="2"></label>
            <p><button class="btn" type="submit">Neu mischen</button></p>
        </form>
        <p class="muted">Alle Spieler werden zufällig verteilt. Die Punkte bleiben unverändert.</p>
        <p><a href="/index.php">Zurück</a></p>
    </section>
</div>
</body>
</html>
<!-- Version 1.0 -->

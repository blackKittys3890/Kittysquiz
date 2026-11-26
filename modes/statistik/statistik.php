<?php
// Version 1.0
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
// modes/statistik/statistik.php – Lehrer-Statistik: Auswertung über alle Ergebnisse der eigenen Räume. Nur Lehrkräfte.
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/db.php';

function st_h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

$user = $_SESSION['discord_user'] ?? null;
if (!$user || !empty($user['guest'])) {
    header('Location: /index.php');
    exit;
}
$me = (string)$user['id'];
$stT = db()->prepare('SELECT is_teacher FROM kr_profiles WHERE user_key = ?');
$stT->execute([$me]);
if ((int)$stT->fetchColumn() !== 1) {
    http_response_code(403);
    exit('Die Statistik ist nur für Lehrkräfte.');
}

// Zeitraum aus dem Formular (Standard: alle)
$days = in_array((int)($_GET['tage'] ?? 0), [0, 30, 90, 365], true) ? (int)($_GET['tage'] ?? 0) : 0;
$sql = '
    SELECT h.id, h.room_id, h.round_played, h.results, h.created_at, r.code
    FROM kr_history h
    JOIN kr_rooms r ON r.id = h.room_id
    WHERE r.host_id = ?' . ($days > 0 ? ' AND h.created_at >= (NOW() - INTERVAL ' . $days . ' DAY)' : '') . '
    ORDER BY h.id DESC
    LIMIT 1000
';
$stH = db()->prepare($sql);
$stH->execute([$me]);
$rows = $stH->fetchAll();

$runs = count($rows);
$rooms = [];
$teamCount = 0;
$pointsSum = 0;
$players = []; // id => ['name', 'games', 'points', 'teams', 'wins']

foreach ($rows as $r) {
    $rooms[(string)$r['code']] = true;
    $teams = json_decode((string)$r['results'], true) ?: [];
    usort($teams, fn($a, $b) => ($b['points'] ?? 0) <=> ($a['points'] ?? 0));
    foreach ($teams as $i => $t) {
        $pts = (int)($t['points'] ?? 0);
        $teamCount++;
        $pointsSum += $pts;
        foreach (($t['members'] ?? []) as $m) {
            $id = (string)($m['id'] ?? '');
            if ($id === '' || str_starts_with($id, 'guest_')) continue;
            if (!isset($players[$id])) {
                $players[$id] = ['name' => (string)($m['name'] ?? $id), 'games' => 0, 'points' => 0, 'wins' => 0];
            }
            $players[$id]['games']++;
            $players[$id]['points'] += $pts;
            if ($i === 0 && $pts > 0) $players[$id]['wins']++;
        }
    }
}

$avgTeam = $teamCount ? round($pointsSum / $teamCount, 1) : 0;
$byPoints = $players;
uasort($byPoints, fn($a, $b) => $b['points'] <=> $a['points']);
$byPoints = array_slice($byPoints, 0, 10, true);
$byGames = $players;
uasort($byGames, fn($a, $b) => $b['games'] <=> $a['games']);
$byGames = array_slice($byGames, 0, 10, true);
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Lehrer-Statistik</title>
<style>
body { margin:0; font-family: system-ui, sans-serif; background:#1d1030; color:#fff; }
.wrap { max-width:820px; margin:0 auto; padding:14px; }
a { color:#ff9ad5; }
.card { background:rgba(255,255,255,.08); border-radius:16px; padding:16px; margin:12px 0; }
.grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:10px; }
.stat { background:rgba(255,255,255,.06); border-radius:12px; padding:12px; text-align:center; }
.stat b { display:block; font-size:26px; color:#ffd166; }
table { width:100%; border-collapse:collapse; text-align:left; }
td, th { padding:6px; border-bottom:1px solid rgba(255,255,255,.1); }
.small { font-size:13px; opacity:.8; }
.btn { display:inline-block; background:#ff6fb5; color:#fff; border-radius:12px; padding:6px 12px; font-weight:700; text-decoration:none; margin-right:6px; }
.btn.off { background:rgba(255,255,255,.15); }
</style>
</head>
<body>
<div class="wrap">
    <p><a href="/lobby.php">← Lobby</a></p>
    <h1>Lehrer-Statistik</h1>
    <p class="small">Auswertung aller gespeicherten Ergebnisse deiner Räume. Gäste werden nicht gezählt.</p>

    <p>
        <?php foreach ([0 => 'Alle', 30 => '30 Tage', 90 => '90 Tage', 365 => '1 Jahr'] as $d => $label): ?>
            <a class="btn <?= $d === $days ? '' : 'off' ?>" href="?tage=<?= $d ?>"><?= st_h($label) ?></a>
        <?php endforeach; ?>
    </p>

    <?php if (!$runs): ?>
        <div class="card">Für diesen Zeitraum gibt es noch keine gespeicherten Ergebnisse.</div>
    <?php else: ?>
        <div class="card grid">
            <div class="stat"><b><?= $runs ?></b>Runden</div>
            <div class="stat"><b><?= count($rooms) ?></b>Räume</div>
            <div class="stat"><b><?= count($players) ?></b>Schüler/Spieler</div>
            <div class="stat"><b><?= $avgTeam ?></b>Ø Punkte pro Team</div>
        </div>

        <div class="card">
            <h2>Meiste Punkte</h2>
            <table>
                <tr><th>#</th><th>Name</th><th>Punkte</th><th>Runden</th><th>Siege</th></tr>
                <?php $n = 1; foreach ($byPoints as $p): ?>
                    <tr><td><?= $n++ ?></td><td><?= st_h($p['name']) ?></td><td><?= (int)$p['points'] ?></td><td><?= (int)$p['games'] ?></td><td><?= (int)$p['wins'] ?></td></tr>
                <?php endforeach; ?>
            </table>
        </div>

        <div class="card">
            <h2>Meiste Teilnahmen</h2>
            <table>
                <tr><th>#</th><th>Name</th><th>Runden</th><th>Siege</th></tr>
                <?php $n = 1; foreach ($byGames as $p): ?>
                    <tr><td><?= $n++ ?></td><td><?= st_h($p['name']) ?></td><td><?= (int)$p['games'] ?></td><td><?= (int)$p['wins'] ?></td></tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
<!-- Version 1.0 -->
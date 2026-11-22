<?php
// Version 1.1
// Allgemeines Scoreboard: Punkte aller gespielten Boards, zusammengefasst nach Teamname.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$user = $_SESSION['discord_user'] ?? null;
if (!$user) { header('Location: /index.php'); exit; }

$rows = db()->query('SELECT id, room_id, round_played, results FROM kr_history ORDER BY id DESC LIMIT 3000')->fetchAll();

$teams = [];
$recent = [];
foreach ($rows as $r) {
    $results = json_decode($r['results'], true) ?: [];
    foreach ($results as $i => $t) {
        $key = mb_strtolower(trim((string)$t['name']));
        if ($key === '') continue;
        if (!isset($teams[$key])) {
            $teams[$key] = ['name' => $t['name'], 'points' => 0, 'boards' => 0, 'wins' => 0, 'best' => 0];
        }
        $teams[$key]['points'] += (int)$t['points'];
        $teams[$key]['boards']++;
        $teams[$key]['best'] = max($teams[$key]['best'], (int)$t['points']);
        if ($i === 0 && (int)$t['points'] > 0) $teams[$key]['wins']++;
    }
    if (count($recent) < 15) $recent[] = $r + ['results' => $results];
}
uasort($teams, fn($a, $b) => $b['points'] <=> $a['points'] ?: $b['wins'] <=> $a['wins']);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Scoreboard – <?= htmlspecialchars(defined('APP_NAME') ? APP_NAME : 'KittysRätsel') ?></title>
    <link rel="stylesheet" href="/css/base.css?v=1.4">
    <link rel="stylesheet" href="/css/lobby.css?v=1.1">
</head>
<body>
<div class="page">
    <header class="topbar">
        <div class="brand">🏆 Scoreboard</div>
        <a class="btn btn-ghost btn-small" href="/lobby.php">Zur Lobby</a>
    </header>

    <section class="card" style="margin-bottom:18px;">
        <h2>Gesamtwertung</h2>
        <p class="muted">Alle gespielten Boards, zusammengefasst nach Teamname. Groß- und Kleinschreibung zählen nicht.</p>
        <?php if (!$teams): ?>
            <p class="muted">Noch keine abgeschlossenen Boards.</p>
        <?php else: ?>
            <div style="overflow-x:auto;">
            <table style="width:100%;border-collapse:collapse;">
                <tr style="text-align:left;">
                    <th style="padding:6px;">#</th><th style="padding:6px;">Team</th>
                    <th style="padding:6px;text-align:right;">Punkte</th><th style="padding:6px;text-align:right;">Boards</th>
                    <th style="padding:6px;text-align:right;">Siege</th><th style="padding:6px;text-align:right;">Bestes Board</th>
                </tr>
                <?php $pos = 0; foreach ($teams as $t): $pos++; ?>
                    <tr style="border-top:1px solid var(--line);<?= $pos === 1 ? 'color:var(--yellow);font-weight:700;' : '' ?>">
                        <td style="padding:6px;"><?= $pos ?>.</td>
                        <td style="padding:6px;"><?= htmlspecialchars($t['name']) ?></td>
                        <td style="padding:6px;text-align:right;"><?= (int)$t['points'] ?></td>
                        <td style="padding:6px;text-align:right;"><?= (int)$t['boards'] ?></td>
                        <td style="padding:6px;text-align:right;"><?= (int)$t['wins'] ?></td>
                        <td style="padding:6px;text-align:right;"><?= (int)$t['best'] ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
            </div>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>Letzte Boards</h2>
        <?php if (!$recent): ?>
            <p class="muted">Noch keine Boards.</p>
        <?php endif; ?>
        <?php foreach ($recent as $r): ?>
            <div class="room" style="margin-bottom:8px;display:block;">
                <div class="date">Board <?= (int)$r['round_played'] ?> · Eintrag #<?= (int)$r['id'] ?></div>
                <div style="margin-top:4px;">
                    <?php foreach ($r['results'] as $i => $t): ?>
                        <span style="margin-right:12px;<?= $i === 0 ? 'color:var(--yellow);font-weight:700;' : '' ?>"><?= htmlspecialchars($t['name']) ?>: <?= (int)$t['points'] ?></span>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </section>
</div>
</body>
</html>
<!-- Version 1.1 -->
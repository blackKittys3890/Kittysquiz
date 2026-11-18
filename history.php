<?php
// Version 1.0
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$user = $_SESSION['discord_user'] ?? null;
if (!$user) {
    header('Location: index.php');
    exit;
}

// Nur Spiele aus Räumen, die man selbst als Host geleitet hat
$stmt = db()->prepare('
    SELECT h.*, r.code
    FROM kr_history h
    JOIN kr_rooms r ON r.id = h.room_id
    WHERE r.host_id = ?
    ORDER BY h.id DESC
    LIMIT 200
');
$stmt->execute([$user['id']]);
$rows = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Historie – <?= APP_NAME ?></title>
    <style>
        body { font-family: system-ui, sans-serif; background: #1f1438; color: #f5f0ff; margin: 0; padding: 24px; }
        a { color: #7fe7d9; }
        h1 { margin: 10px 0 16px; font-size: 22px; }
        .game { background: rgba(255,255,255,.06); border-radius: 14px; padding: 16px 18px; margin-bottom: 14px; }
        .gh { display: flex; justify-content: space-between; flex-wrap: wrap; gap: 8px; margin-bottom: 8px; font-weight: bold; }
        .muted { opacity: .65; font-size: 14px; font-weight: normal; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 6px 8px; border-bottom: 1px solid rgba(255,255,255,.08); }
        td.r { text-align: right; font-weight: bold; }
        .first td { color: #f5b400; }
    </style>
</head>
<body>
    <p><a href="lobby.php">← Lobby</a></p>
    <h1>Historie deiner Spiele</h1>

    <?php if (!$rows): ?>
        <p class="muted">Noch keine gespeicherten Spiele.</p>
    <?php endif; ?>

    <?php foreach ($rows as $h):
        $results = json_decode($h['results'] ?? '[]', true) ?: [];
        $when = $h['created_at'] ?? null; ?>
        <div class="game">
            <div class="gh">
                <span>Raum <?= htmlspecialchars($h['code']) ?> · Board <?= (int)$h['round_played'] ?></span>
                <span class="muted"><?= $when ? htmlspecialchars(date('d.m.Y H:i', strtotime($when))) : 'Spiel #' . (int)$h['id'] ?></span>
            </div>
            <table>
                <?php foreach ($results as $i => $t): ?>
                    <tr class="<?= $i === 0 ? 'first' : '' ?>">
                        <td><?= $i + 1 ?>.</td>
                        <td><?= htmlspecialchars($t['name']) ?></td>
                        <td class="r"><?= (int)$t['points'] ?> Punkte</td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endforeach; ?>
    <footer style="text-align:center;margin:30px 0 10px;font-size:14px;opacity:.8;"><a href="/impressum.php" style="color:#9ff0ff;">Impressum</a> · <a href="/datenschutz.php" style="color:#9ff0ff;">Datenschutz</a></footer>
</body>
</html>
<!-- Version 1.0 -->

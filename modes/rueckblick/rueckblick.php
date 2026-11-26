<?php
// Version 1.0
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
// modes/rueckblick/rueckblick.php – Lehrer-Rückblick: vollständige Ergebnisse der eigenen Räume, CSV-Download. Nur Lehrkräfte.
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/db.php';

function rb_h($s): string
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
    exit('Der Rückblick ist nur für Lehrkräfte.');
}

// Nur Ergebnisse aus Räumen, die diese Lehrkraft gehostet hat
$stH = db()->prepare('
    SELECT h.id, h.room_id, h.round_played, h.results, h.created_at, r.code
    FROM kr_history h
    JOIN kr_rooms r ON r.id = h.room_id
    WHERE r.host_id = ?
    ORDER BY h.id DESC
    LIMIT 100
');
$stH->execute([$me]);
$rows = $stH->fetchAll();

// CSV-Download eines einzelnen Eintrags
if (isset($_GET['csv'])) {
    $id = (int)$_GET['csv'];
    foreach ($rows as $r) {
        if ((int)$r['id'] !== $id) continue;
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="rueckblick-' . $r['code'] . '-' . (int)$r['id'] . '.csv"');
        echo "\xEF\xBB\xBF";
        $fp = fopen('php://output', 'w');
        fputcsv($fp, ['Platz', 'Team', 'Punkte', 'Mitglieder'], ';');
        $teams = json_decode((string)$r['results'], true) ?: [];
        usort($teams, fn($a, $b) => ($b['points'] ?? 0) <=> ($a['points'] ?? 0));
        foreach ($teams as $i => $t) {
            $members = implode(', ', array_map(fn($m) => (string)($m['name'] ?? ''), $t['members'] ?? []));
            fputcsv($fp, [$i + 1, $t['name'] ?? '', (int)($t['points'] ?? 0), $members], ';');
        }
        fclose($fp);
        exit;
    }
    http_response_code(404);
    exit('Eintrag nicht gefunden.');
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Lehrer-Rückblick</title>
<style>
body { margin:0; font-family: system-ui, sans-serif; background:#1d1030; color:#fff; }
.wrap { max-width:820px; margin:0 auto; padding:14px; }
a { color:#ff9ad5; }
.card { background:rgba(255,255,255,.08); border-radius:16px; padding:16px; margin:12px 0; }
table { width:100%; border-collapse:collapse; text-align:left; }
td, th { padding:6px; border-bottom:1px solid rgba(255,255,255,.1); }
.small { font-size:13px; opacity:.8; }
.btn { display:inline-block; background:#ff6fb5; color:#fff; border-radius:12px; padding:8px 14px; font-weight:700; text-decoration:none; }
</style>
</head>
<body>
<div class="wrap">
    <p><a href="/lobby.php">← Lobby</a></p>
    <h1>Lehrer-Rückblick</h1>
    <p class="small">Vollständige Ergebnisse deiner Räume. Die Schüler sehen nur die Top-3-Ansicht.</p>

    <?php if (!$rows): ?>
        <div class="card">Noch keine gespeicherten Ergebnisse.</div>
    <?php endif; ?>

    <?php foreach ($rows as $r):
        $teams = json_decode((string)$r['results'], true) ?: [];
        usort($teams, fn($a, $b) => ($b['points'] ?? 0) <=> ($a['points'] ?? 0));
    ?>
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
                <div>
                    <strong>Raum <?= rb_h($r['code']) ?></strong>
                    <span class="small">· Runde <?= (int)$r['round_played'] ?> · <?= rb_h($r['created_at']) ?></span>
                </div>
                <a class="btn" href="/modes/rueckblick/rueckblick.php?csv=<?= (int)$r['id'] ?>">CSV laden</a>
            </div>
            <table>
                <tr><th>Platz</th><th>Team</th><th>Punkte</th><th>Mitglieder</th></tr>
                <?php foreach ($teams as $i => $t): ?>
                    <tr>
                        <td><?= $i + 1 ?></td>
                        <td><?= rb_h($t['name'] ?? '') ?></td>
                        <td><?= (int)($t['points'] ?? 0) ?></td>
                        <td class="small"><?= rb_h(implode(', ', array_map(fn($m) => (string)($m['name'] ?? ''), $t['members'] ?? []))) ?></td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endforeach; ?>
</div>
</body>
</html>
<!-- Version 1.0 -->
<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
// Version 1.0
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$user = $_SESSION['discord_user'] ?? null;
if (!$user) {
    header('Location: index.php');
    exit;
}
$me = $user['id'];

const EI_POINTS = [100, 200, 300];
const EI_CLASSES = 13;

function ei_flash(string $t): void { $_SESSION['ei_flash'] = $t; }

// ---------- Aktionen ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $klasse = (int)($_POST['klasse'] ?? 0);
        $fach   = trim($_POST['fach'] ?? '');
        $punkte = (int)($_POST['punkte'] ?? 0);
        $frage  = trim($_POST['frage'] ?? '');
        $antw   = trim($_POST['antwort'] ?? '');

        if ($klasse < 1 || $klasse > EI_CLASSES) {
            ei_flash('Klasse: 1 bis 13.');
        } elseif ($fach === '' || mb_strlen($fach) > 40) {
            ei_flash('Fach: 1 bis 40 Zeichen.');
        } elseif (!in_array($punkte, EI_POINTS, true)) {
            ei_flash('Schwierigkeit: 100, 200 oder 300.');
        } elseif ($frage === '' || $antw === '') {
            ei_flash('Frage und Antwort ausfüllen.');
        } else {
            $name = "Kl. $klasse · $fach";
            $stmt = db()->prepare('SELECT id FROM kr_categories WHERE owner_id = ? AND name = ?');
            $stmt->execute([$me, $name]);
            $catId = $stmt->fetchColumn();
            if (!$catId) {
                db()->prepare('INSERT INTO kr_categories (owner_id, name) VALUES (?, ?)')->execute([$me, $name]);
                $catId = db()->lastInsertId();
            }
            db()->prepare('INSERT INTO kr_questions (category_id, points, question, answer) VALUES (?, ?, ?, ?)')
                ->execute([$catId, $punkte, $frage, $antw]);
            ei_flash('Frage gespeichert.');
        }
        header('Location: eingestuhlt_fragen.php?klasse=' . $klasse . '&fach=' . urlencode($fach));
        exit;
    }

    if ($action === 'delete') {
        db()->prepare('
            DELETE q FROM kr_questions q
            JOIN kr_categories c ON c.id = q.category_id
            WHERE q.id = ? AND c.owner_id = ?
        ')->execute([(int)($_POST['id'] ?? 0), $me]);
        ei_flash('Frage gelöscht.');
        header('Location: eingestuhlt_fragen.php?klasse=' . (int)($_POST['klasse'] ?? 0));
        exit;
    }
}

// ---------- Ansicht ----------
$msg = $_SESSION['ei_flash'] ?? '';
unset($_SESSION['ei_flash']);

$klasse = (int)($_GET['klasse'] ?? 1);
if ($klasse < 1 || $klasse > EI_CLASSES) $klasse = 1;
$fachVorgabe = trim($_GET['fach'] ?? '');

// Bekannte Fächer dieser Klasse für die Auswahl
$stmt = db()->prepare('SELECT name FROM kr_categories WHERE owner_id = ? AND name LIKE ? ORDER BY name');
$stmt->execute([$me, "Kl. $klasse · %"]);
$faecher = array_map(fn($n) => substr($n, strlen("Kl. $klasse · ")), $stmt->fetchAll(PDO::FETCH_COLUMN));

// Alle Fragen dieser Klasse, nach Fach und Punkten sortiert
$stmt = db()->prepare('
    SELECT q.id, q.points, q.question, q.answer, c.name
    FROM kr_questions q
    JOIN kr_categories c ON c.id = q.category_id
    WHERE c.owner_id = ? AND c.name LIKE ?
    ORDER BY c.name, q.points, q.id
');
$stmt->execute([$me, "Kl. $klasse · %"]);
$fragen = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Eingestuhlt – Fragen</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #1f1438; color: #f5f0ff; margin: 0; padding: 24px; }
        a { color: #7fe7d9; }
        h1 { margin: 10px 0 16px; font-size: 22px; }
        .panel { background: rgba(255,255,255,.06); border-radius: 14px; padding: 18px; margin-bottom: 20px; }
        label { display: block; margin-top: 12px; font-size: 14px; }
        input, select, textarea { width: 100%; box-sizing: border-box; padding: 10px; margin-top: 6px; border: none; border-radius: 8px; font: inherit; background: #3b2a6b; color: #fff; }
        textarea { min-height: 70px; }
        .row { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        button { margin-top: 14px; padding: 10px 16px; border: none; border-radius: 8px; background: #3ddbc4; color: #1f1438; font-weight: bold; cursor: pointer; }
        button.danger { background: #ff3b5c; color: #fff; padding: 6px 10px; font-size: 13px; margin: 0; }
        .msg { color: #7fe7d9; font-weight: bold; }
        .nav a { margin-right: 10px; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 8px; border-bottom: 1px solid rgba(255,255,255,.1); text-align: left; vertical-align: top; }
        .fach { font-weight: bold; color: #f5b400; }
        .muted { opacity: .6; font-size: 14px; }
        @media (max-width: 700px) { .row { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
    <p><a href="lobby.php">← Lobby</a></p>
    <h1>Eingestuhlt – Fragen eintragen</h1>

    <div class="nav panel">
        Klasse:
        <?php for ($k = 1; $k <= EI_CLASSES; $k++): ?>
            <a href="?klasse=<?= $k ?>" style="<?= $k === $klasse ? 'font-weight:bold;color:#f5b400' : '' ?>"><?= $k ?></a>
        <?php endfor; ?>
    </div>

    <?php if ($msg): ?><p class="msg"><?= htmlspecialchars($msg) ?></p><?php endif; ?>

    <div class="panel">
        <h2>Frage für Klasse <?= $klasse ?> hinzufügen</h2>
        <form method="post">
            <input type="hidden" name="action" value="add">
            <input type="hidden" name="klasse" value="<?= $klasse ?>">

            <label>Fach
                <input type="text" name="fach" list="faecherListe" maxlength="40" required
                       value="<?= htmlspecialchars($fachVorgabe) ?>" placeholder="z. B. Mathe">
                <datalist id="faecherListe">
                    <?php foreach ($faecher as $f): ?>
                        <option value="<?= htmlspecialchars($f) ?>">
                    <?php endforeach; ?>
                </datalist>
            </label>

            <div class="row">
                <label>Schwierigkeit
                    <select name="punkte" required>
                        <option value="100">leicht (100)</option>
                        <option value="200">mittel (200)</option>
                        <option value="300">schwer (300)</option>
                    </select>
                </label>
            </div>

            <label>Frage
                <textarea name="frage" required></textarea>
            </label>
            <label>Antwort
                <input type="text" name="antwort" required>
            </label>
            <button type="submit">Speichern</button>
        </form>
    </div>

    <div class="panel">
        <h2>Schon eingetragen (Klasse <?= $klasse ?>)</h2>
        <?php if (!$fragen): ?>
            <p class="muted">Noch keine Fragen für diese Klasse.</p>
        <?php else: ?>
            <table>
                <tr><th>Fach</th><th>Punkte</th><th>Frage</th><th>Antwort</th><th></th></tr>
                <?php foreach ($fragen as $f): ?>
                    <tr>
                        <td class="fach"><?= htmlspecialchars(substr($f['name'], strlen("Kl. $klasse · "))) ?></td>
                        <td><?= (int)$f['points'] ?></td>
                        <td><?= htmlspecialchars($f['question']) ?></td>
                        <td><?= htmlspecialchars($f['answer']) ?></td>
                        <td>
                            <form method="post" onsubmit="return confirm('Frage löschen?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="klasse" value="<?= $klasse ?>">
                                <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                                <button class="danger" type="submit">Löschen</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        <?php endif; ?>
    </div>
</body>
</html>
<!-- Version 1.0 -->

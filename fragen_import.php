<?php
// Version 1.1
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/fragen_tools.php';

$user = $_SESSION['discord_user'] ?? null;
if (!$user) { header('Location: index.php'); exit; }
$me = $user['id'];

// Vorlage herunterladen
if (isset($_GET['vorlage'])) {
    $variant = ($_GET['vorlage'] === 'klasse') ? 'klasse' : 'kategorie';
    $tmp = sys_get_temp_dir() . '/fragen_vorlage_' . bin2hex(random_bytes(4)) . '.xlsx';
    ft_write_template($tmp, $variant);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . ($variant === 'klasse' ? 'eingestuhlt_vorlage.xlsx' : 'fragen_vorlage.xlsx') . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    exit;
}

$result = null;
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['datei'])) {
    $f = $_FILES['datei'];
    $ext = strtolower(pathinfo($f['name'] ?? '', PATHINFO_EXTENSION));
    if ($f['error'] !== UPLOAD_ERR_OK) {
        $error = 'Der Upload hat nicht geklappt.';
    } elseif (!in_array($ext, ['xlsx', 'csv'], true)) {
        $error = 'Bitte eine Excel-Datei (.xlsx) oder eine CSV-Datei hochladen.';
    } elseif ($f['size'] > 2 * 1024 * 1024) {
        $error = 'Die Datei ist größer als 2 MB.';
    } else {
        try {
            $rows = $ext === 'xlsx' ? ft_read_xlsx($f['tmp_name']) : ft_read_csv($f['tmp_name']);
            $records = ft_to_records($rows);
            if (!$records) {
                $error = 'Die Datei enthält keine Zeilen unter der Kopfzeile.';
            } else {
                $result = ft_save(db(), $me, $records);
            }
        } catch (Throwable $e) {
            $error = 'Die Datei konnte nicht gelesen werden: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fragen importieren – <?= APP_NAME ?></title>
    <style>
        body { font-family: system-ui, sans-serif; background: #1f1438; color: #f5f0ff; margin: 0; padding: 24px; }
        a { color: #7fe7d9; }
        .panel { background: rgba(255,255,255,.06); border-radius: 14px; padding: 18px; margin-bottom: 20px; max-width: 760px; }
        button, .btn { margin-top: 12px; padding: 10px 16px; border: none; border-radius: 8px; background: #3ddbc4; color: #1f1438;
            font-weight: bold; cursor: pointer; text-decoration: none; display: inline-block; }
        .ok { color: #7fe7d9; font-weight: bold; }
        .err { color: #ff8a8a; }
        li { margin: 4px 0; }
        code { background: rgba(0,0,0,.3); padding: 2px 6px; border-radius: 6px; }
    </style>
</head>
<body>
    <p><a href="lobby.php">← Lobby</a> · <a href="fragen_ki.php">KI-Fragen erstellen</a> · <a href="fragen.php">Fragen verwalten</a></p>
    <h1>Fragen per Datei hochladen</h1>

    <div class="panel">
        <p>Lade eine Excel-Datei (.xlsx) oder eine CSV-Datei hoch. Die erste Zeile ist die Kopfzeile. Es gibt zwei Formate:</p>
        <p><b>Kategorie-Format:</b> <code>Kategorie</code> · <code>Punkte</code> · <code>Frage</code> · <code>Antwort</code></p>
        <p><b>Klassen-Format (Eingestuhlt):</b> <code>Klasse</code> · <code>Fach</code> · <code>Punkte</code> · <code>Frage</code> · <code>Antwort</code>. Daraus wird die Kategorie „Kl. X · Fach“.</p>
        <p class="muted">Hat eine Spalte nur den Namen „Wert“ oder „Stufe“, erkennt das System Klasse (Zahlen 1 bis 13) und Punkte (größere Zahlen) an den Werten.</p>
        <ul>
            <li>Eine Zeile pro Frage. Gleiche Kategorie = gleiche Kategorie-Spalte.</li>
            <li>Punkte: Zahl zwischen 1 und 1000.</li>
            <li>Fragen, die es in dieser Kategorie schon mit demselben Punktwert und Text gibt, werden übersprungen.</li>
        </ul>
        <a class="btn" href="?vorlage=kategorie">📄 Vorlage Kategorie-Format</a>
        <a class="btn" href="?vorlage=klasse">📄 Vorlage Klassen-Format</a>
    </div>

    <div class="panel">
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="datei" accept=".xlsx,.csv" required>
            <br><button type="submit">Hochladen und speichern</button>
        </form>

        <?php if ($error): ?><p class="err"><?= htmlspecialchars($error) ?></p><?php endif; ?>

        <?php if ($result): ?>
            <p class="ok"><?= (int)$result['added'] ?> Fragen neu gespeichert, <?= (int)$result['skipped'] ?> schon vorhanden.</p>
            <?php if ($result['errors']): ?>
                <p class="err">Diese Zeilen wurden nicht übernommen:</p>
                <ul class="err"><?php foreach ($result['errors'] as $e): ?><li><?= htmlspecialchars($e) ?></li><?php endforeach; ?></ul>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
<!-- Version 1.0 -->

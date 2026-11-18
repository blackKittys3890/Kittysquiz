<?php
// Version 1.0
// Importiert eine Fragen-CSV (Trennzeichen ;) in die Kategorien und Fragen.
// Aufruf: import_eingestuhlt.php?datei=eingestuhlt_fragen_klasse1.csv  (nur eingeloggt)
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/db.php';

$user = $_SESSION['discord_user'] ?? null;
if (!$user) {
    die('Bitte zuerst einloggen.');
}
$owner = $user['id'];

$file = basename($_GET['datei'] ?? 'eingestuhlt_fragen_klasse1.csv');
$path = __DIR__ . '/' . $file;
if (!is_file($path)) {
    die('Datei nicht gefunden: ' . htmlspecialchars($file));
}

$fh = fopen($path, 'r');
fgetcsv($fh, 0, ';'); // Kopfzeile überspringen
$added = 0;
$skipped = 0;
$cats = [];

$findCat = db()->prepare('SELECT id FROM kr_categories WHERE owner_id = ? AND name = ?');
$newCat  = db()->prepare('INSERT INTO kr_categories (owner_id, name) VALUES (?, ?)');
$findQ   = db()->prepare('SELECT id FROM kr_questions WHERE category_id = ? AND points = ? AND question = ?');
$newQ    = db()->prepare('INSERT INTO kr_questions (category_id, points, question, answer) VALUES (?, ?, ?, ?)');

while (($row = fgetcsv($fh, 0, ';')) !== false) {
    if (count($row) < 5) continue;
    [$klasse, $fach, $punkte, $frage, $antwort] = array_map('trim', $row);
    $name = "Kl. $klasse · $fach";

    if (!isset($cats[$name])) {
        $findCat->execute([$owner, $name]);
        $id = $findCat->fetchColumn();
        if (!$id) {
            $newCat->execute([$owner, $name]);
            $id = db()->lastInsertId();
        }
        $cats[$name] = (int)$id;
    }
    $catId = $cats[$name];

    $findQ->execute([$catId, (int)$punkte, $frage]);
    if ($findQ->fetchColumn()) {
        $skipped++;
        continue;
    }
    $newQ->execute([$catId, (int)$punkte, $frage, $antwort]);
    $added++;
}
fclose($fh);

echo "Fertig: $added Fragen angelegt, $skipped schon vorhanden. Kategorien: " . implode(', ', array_keys($cats));
// Version 1.0

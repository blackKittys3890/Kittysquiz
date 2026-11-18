<?php
// Version 1.1
// Gemeinsame Funktionen: Excel (.xlsx) und CSV einlesen, Fragen speichern, Vorlage erzeugen.
// Keine externen Bibliotheken nötig (nur die PHP-Erweiterung ZipArchive).

const FT_HEADERS = ['Kategorie', 'Punkte', 'Frage', 'Antwort'];

// Excel-Datei (.xlsx) lesen: liefert Zeilen als Listen. Erste Tabelle wird gelesen.
function ft_read_xlsx(string $path): array
{
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Excel-Datei lässt sich nicht öffnen.');
    }

    // Gemeinsame Texte (shared strings), falls vorhanden
    $strings = [];
    if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $sd = new DOMDocument();
        $sd->loadXML($xml);
        foreach ($sd->getElementsByTagName('si') as $si) {
            $txt = '';
            foreach ($si->getElementsByTagName('t') as $t) {
                $txt .= $t->textContent;
            }
            $strings[] = $txt;
        }
    }

    $sheetXml = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheetXml === false) {
        throw new RuntimeException('Keine Tabelle gefunden (xl/worksheets/sheet1.xml fehlt).');
    }

    $doc = new DOMDocument();
    $doc->loadXML($sheetXml);
    $rows = [];
    foreach ($doc->getElementsByTagName('row') as $rowNode) {
        $r = [];
        foreach ($rowNode->getElementsByTagName('c') as $c) {
            // Spaltenbuchstabe aus z. B. "C7" -> Index 2
            preg_match('/^([A-Z]+)/', $c->getAttribute('r'), $m);
            $col = 0;
            foreach (str_split($m[1] ?? 'A') as $ch) {
                $col = $col * 26 + (ord($ch) - 64);
            }
            $idx = $col - 1;

            $type = $c->getAttribute('t');
            $val = '';
            if ($type === 'inlineStr') {
                foreach ($c->getElementsByTagName('t') as $t) $val .= $t->textContent;
            } elseif ($type === 's') {
                $v = $c->getElementsByTagName('v')->item(0);
                $val = $v ? ($strings[(int)$v->textContent] ?? '') : '';
            } else {
                $v = $c->getElementsByTagName('v')->item(0);
                $val = $v ? $v->textContent : '';
            }
            $r[$idx] = $val;
        }
        if ($r) {
            $max = max(array_keys($r));
            $line = [];
            for ($i = 0; $i <= $max; $i++) $line[$i] = $r[$i] ?? '';
            $rows[] = $line;
        }
    }
    return $rows;
}

// CSV lesen, Trennzeichen ; oder , wird automatisch erkannt
function ft_read_csv(string $path): array
{
    $rows = [];
    $fh = fopen($path, 'r');
    $first = fgets($fh);
    rewind($fh);
    $delim = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
    while (($row = fgetcsv($fh, 0, $delim)) !== false) {
        if ($row === [null] || $row === []) continue;
        // UTF-8-BOM am Anfang entfernen
        if (isset($row[0])) $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]);
        $rows[] = $row;
    }
    fclose($fh);
    return $rows;
}

// Spalten-Synonyme: welche Kopfzeile zu welchem Feld gehört
const FT_SYN = [
    'klasse'    => ['klasse', 'class', 'jahrgang'],
    'fach'      => ['fach', 'subject'],
    'kategorie' => ['kategorie', 'category', 'thema'],
    'punkte'    => ['punkte', 'punkt', 'points', 'punktzahl'],
    'frage'     => ['frage', 'question'],
    'antwort'   => ['antwort', 'answer', 'lösung', 'loesung'],
    'mehrdeutig' => ['wert', 'stufe', 'level', 'spalte', 'schwierigkeit'],
];

// Kopfzeile erkennen und Zeilen in Datensätze umwandeln.
// Steht in einer Spalte "Wert", "Stufe" o. ä., wird anhand der Zahlen entschieden:
// Werte von 1 bis 13 sind eine Klasse, größere Werte sind Punkte.
function ft_to_records(array $rows): array
{
    if (!$rows) return [];
    $head = array_map(fn($h) => mb_strtolower(trim((string)$h)), array_shift($rows));

    $idx = [];
    foreach (FT_SYN as $key => $names) {
        foreach ($names as $n) {
            $pos = array_search($n, $head, true);
            if ($pos !== false) { $idx[$key] = $pos; break; }
        }
    }

    // Mehrdeutige Spalte auflösen
    $ambiguous = $idx['mehrdeutig'] ?? null;
    if ($ambiguous !== null && !isset($idx['punkte']) && !isset($idx['klasse'])) {
        $max = 0;
        foreach ($rows as $r) {
            $v = (int)preg_replace('/\D/', '', (string)($r[$ambiguous] ?? ''));
            $max = max($max, $v);
        }
        $idx[$max <= 13 ? 'klasse' : 'punkte'] = $ambiguous;
    }

    $out = [];
    foreach ($rows as $n => $r) {
        $rec = [];
        foreach (['klasse', 'fach', 'kategorie', 'punkte', 'frage', 'antwort'] as $key) {
            $rec[$key] = isset($idx[$key]) ? trim((string)($r[$idx[$key]] ?? '')) : '';
        }
        $rec['_zeile'] = $n + 2;
        $out[] = $rec;
    }
    return $out;
}

// Fragen speichern (Kategorie wird angelegt, falls sie fehlt). Doppelte werden übersprungen.
function ft_save(PDO $pdo, string $owner, array $records): array
{
    $res = ['added' => 0, 'skipped' => 0, 'errors' => []];
    $findCat = $pdo->prepare('SELECT id FROM kr_categories WHERE owner_id = ? AND name = ?');
    $newCat  = $pdo->prepare('INSERT INTO kr_categories (owner_id, name) VALUES (?, ?)');
    $findQ   = $pdo->prepare('SELECT id FROM kr_questions WHERE category_id = ? AND points = ? AND question = ?');
    $newQ    = $pdo->prepare('INSERT INTO kr_questions (category_id, points, question, answer) VALUES (?, ?, ?, ?)');
    $cache = [];

    foreach ($records as $r) {
        $zeile = $r['_zeile'] ?? '?';
        $klasse = (int)($r['klasse'] ?? 0);
        $fach   = trim($r['fach'] ?? '');
        if ($klasse > 0) {
            // Klassenformat (Eingestuhlt): Kategorie = "Kl. X · Fach" (Fach aus Fach- oder Kategorie-Spalte)
            $fachName = $fach !== '' ? $fach : trim($r['kategorie'] ?? '');
            $cat = $fachName === '' ? '' : mb_substr("Kl. $klasse · $fachName", 0, 100);
            if ($klasse < 1 || $klasse > 13) {
                $res['errors'][] = "Zeile $zeile: Klasse muss zwischen 1 und 13 liegen.";
                continue;
            }
        } else {
            $cat = mb_substr($r['kategorie'] ?? '', 0, 100);
        }
        $points = (int)($r['punkte'] ?? 0);
        $frage  = $r['frage'] ?? '';
        $antw   = $r['antwort'] ?? '';

        if ($cat === '' || $frage === '' || $antw === '') {
            if ($cat === '' && $frage === '' && $antw === '') continue; // leere Zeile
            $res['errors'][] = "Zeile $zeile: Kategorie, Frage und Antwort müssen ausgefüllt sein.";
            continue;
        }
        if ($points < 1 || $points > 1000) {
            $res['errors'][] = "Zeile $zeile: Punkte müssen zwischen 1 und 1000 liegen.";
            continue;
        }

        if (!isset($cache[$cat])) {
            $findCat->execute([$owner, $cat]);
            $id = $findCat->fetchColumn();
            if (!$id) {
                $newCat->execute([$owner, $cat]);
                $id = $pdo->lastInsertId();
            }
            $cache[$cat] = (int)$id;
        }
        $catId = $cache[$cat];

        $findQ->execute([$catId, $points, $frage]);
        if ($findQ->fetchColumn()) {
            $res['skipped']++;
            continue;
        }
        $newQ->execute([$catId, $points, $frage, $antw]);
        $res['added']++;
    }
    return $res;
}

// Vorlage als .xlsx erzeugen (ohne Bibliothek)
function ft_write_template(string $path, string $variant = 'kategorie'): void
{
    if ($variant === 'klasse') {
        $header = ['Klasse', 'Fach', 'Punkte', 'Frage', 'Antwort'];
        $example = [
            [1, 'Mathe', 100, 'Was ist 3 + 4?', '7'],
            [1, 'Mathe', 200, 'Was ist 12 - 5?', '7'],
            [1, 'Sachkunde', 100, 'Welche Farbe hat der Himmel am Tag?', 'Blau'],
        ];
    } else {
        $header = FT_HEADERS;
        $example = [
            ['Mathe', 100, 'Was ist 3 + 4?', '7'],
            ['Mathe', 200, 'Was ist 12 - 5?', '7'],
            ['Geografie', 100, 'Welche Hauptstadt hat Frankreich?', 'Paris'],
            ['Geografie', 200, 'Wie heißt der längste Fluss Deutschlands?', 'Rhein'],
        ];
    }
    $esc = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $cols = ['A', 'B', 'C', 'D', 'E'];

    $rowsXml = '';
    $all = array_merge([$header], $example);
    foreach ($all as $i => $cells) {
        $n = $i + 1;
        $rowsXml .= "<row r=\"$n\">";
        foreach ($cells as $j => $v) {
            $ref = $cols[$j] . $n;
            if (is_int($v)) {
                $rowsXml .= "<c r=\"$ref\"><v>$v</v></c>";
            } else {
                $rowsXml .= "<c r=\"$ref\" t=\"inlineStr\"><is><t>" . $esc($v) . "</t></is></c>";
            }
        }
        $rowsXml .= '</row>';
    }

    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Vorlage kann nicht erstellt werden.');
    }
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="Fragen" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '</Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>' . $rowsXml . '</sheetData></worksheet>');
    $zip->close();
}
// Version 1.1

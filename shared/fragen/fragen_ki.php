<?php
// Version 1.1
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/api_keys_lib.php';
require_once dirname(__DIR__, 2) . '/db.php';
require_once __DIR__ . '/fragen_tools.php';

$user = $_SESSION['discord_user'] ?? null;
if (!$user) { header('Location: /index.php'); exit; }
$me = $user['id'];

$msg = '';
$draft = $_SESSION['ki_draft'] ?? null;   // { kategorie, fragen: [ {punkte, frage, antwort} ] }

// Anfrage an die KI (xAI / Grok). Gibt eine Liste von Fragen zurück oder wirft eine Exception.
function ki_generate(string $kategorie, int $anzahl, array $punkte, string $stil, string $apiKey): array
{
    if ($apiKey === '') {
        throw new RuntimeException('Kein xAI-Schlüssel hinterlegt. Trage deinen Key unter Profil → API-Schlüssel ein.');
    }
    $pkt = implode(', ', $punkte);
    $system = 'Du schreibst Quizfragen auf Deutsch. Antworte ausschließlich mit gültigem JSON, ohne Erklärtext, ohne Markdown. '
        . 'Format: [{"punkte": 100, "frage": "...", "antwort": "..."}, ...]. Antworten kurz und eindeutig.';
    $user = "Kategorie: $kategorie\nAnzahl Fragen: $anzahl\nPunktwerte (verteilen): $pkt\n"
        . ($stil !== '' ? "Stil/Hinweise: $stil\n" : '')
        . 'Die Fragen müssen faktisch korrekt sein. Keine Fragen mit mehreren richtigen Antworten.';

    $body = json_encode([
        'model' => defined('XAI_MODEL') ? XAI_MODEL : 'grok-4.7',
        'messages' => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ],
        'temperature' => 0.7,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://api.x.ai/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60,
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $code >= 400) {
        throw new RuntimeException('Die KI-Anfrage ist fehlgeschlagen (HTTP ' . $code . ').');
    }
    $resp = json_decode($raw, true);
    $text = $resp['choices'][0]['message']['content'] ?? '';

    // JSON aus der Antwort herausziehen
    $start = strpos($text, '[');
    $end = strrpos($text, ']');
    if ($start === false || $end === false) {
        throw new RuntimeException('Die KI hat kein verwertbares JSON geliefert.');
    }
    $list = json_decode(substr($text, $start, $end - $start + 1), true);
    if (!is_array($list)) {
        throw new RuntimeException('Das JSON der KI ist ungültig.');
    }

    $out = [];
    foreach ($list as $item) {
        if (!isset($item['frage'], $item['antwort'])) continue;
        $out[] = [
            'punkte' => (int)($item['punkte'] ?? 100),
            'frage'  => trim((string)$item['frage']),
            'antwort' => trim((string)$item['antwort']),
        ];
    }
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'generate') {
        $kat = trim($_POST['kategorie'] ?? '');
        $anzahl = max(1, min(20, (int)($_POST['anzahl'] ?? 5)));
        $punkte = array_values(array_filter(array_map('intval', $_POST['punkte'] ?? []), fn($p) => $p >= 1 && $p <= 1000));
        $stil = trim($_POST['stil'] ?? '');
        if ($kat === '' || mb_strlen($kat) > 100) {
            $msg = 'Bitte eine Kategorie angeben (1 bis 100 Zeichen).';
        } elseif (!$punkte) {
            $msg = 'Bitte mindestens einen Punktwert wählen.';
        } else {
            try {
                $fragen = ki_generate($kat, $anzahl, $punkte, $stil, (string)ak_get($me, 'xai'));
                $_SESSION['ki_draft'] = ['kategorie' => $kat, 'fragen' => $fragen];
                $draft = $_SESSION['ki_draft'];
                $msg = count($fragen) ? '' : 'Die KI hat keine brauchbaren Fragen geliefert. Bitte nochmal versuchen.';
            } catch (Throwable $e) {
                $msg = $e->getMessage();
            }
        }
    }

    if ($action === 'save' && $draft) {
        $auswahl = array_map('intval', $_POST['ja'] ?? []);
        $records = [];
        foreach ($draft['fragen'] as $i => $f) {
            if (!in_array($i, $auswahl, true)) continue;
            $records[] = [
                'kategorie' => $draft['kategorie'],
                'punkte' => $f['punkte'],
                'frage' => $f['frage'],
                'antwort' => $f['antwort'],
                '_zeile' => $i + 1,
            ];
        }
        $res = ft_save(db(), $me, $records);
        $msg = $res['added'] . ' Fragen gespeichert, ' . $res['skipped'] . ' schon vorhanden.'
            . ($res['errors'] ? ' Fehler: ' . implode(' ', $res['errors']) : '');
        unset($_SESSION['ki_draft']);
        $draft = null;
    }

    if ($action === 'verwerfen') {
        unset($_SESSION['ki_draft']);
        $draft = null;
    }
}
$hasOwnKey = false;
try { $hasOwnKey = ak_get($me, 'xai') !== null; } catch (Throwable $e) { $hasOwnKey = false; }
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>KI-Fragen – <?= APP_NAME ?></title>
    <style>
        body { font-family: system-ui, sans-serif; background: #1f1438; color: #f5f0ff; margin: 0; padding: 24px; }
        a { color: #7fe7d9; }
        .panel { background: rgba(255,255,255,.06); border-radius: 14px; padding: 18px; margin-bottom: 20px; max-width: 860px; }
        label { display: block; margin-top: 12px; font-size: 14px; }
        input[type=text], input[type=number], textarea { width: 100%; box-sizing: border-box; padding: 10px; margin-top: 6px; border: none; border-radius: 8px; background: #3b2a6b; color: #fff; font: inherit; }
        textarea { min-height: 60px; }
        button { margin-top: 14px; padding: 10px 16px; border: none; border-radius: 8px; background: #3ddbc4; color: #1f1438; font-weight: bold; cursor: pointer; }
        button.red { background: #ff3b5c; color: #fff; }
        table { width: 100%; border-collapse: collapse; }
        td, th { padding: 8px; border-bottom: 1px solid rgba(255,255,255,.1); text-align: left; vertical-align: top; }
        .msg { color: #7fe7d9; font-weight: bold; }
        .err { color: #ff8a8a; }
        .muted { opacity: .65; font-size: 14px; }
    </style>
</head>
<body>
    <p><a href="/lobby.php">← Lobby</a> · <a href="fragen_import.php">Datei hochladen</a> · <a href="fragen.php">Fragen verwalten</a></p>
    <h1>Fragen mit KI erstellen (Grok)</h1>
    <?php if (!$hasOwnKey): ?>
        <p class="err">Du hast noch keinen xAI-Key hinterlegt. <a href="/profile/api_keys.php">Jetzt unter Profil → API-Schlüssel eintragen</a>.</p>
    <?php endif; ?>

    <?php if ($msg): ?><p class="msg"><?= htmlspecialchars($msg) ?></p><?php endif; ?>

    <div class="panel">
        <form method="post">
            <input type="hidden" name="action" value="generate">
            <label>Kategorie (Name)
                <input type="text" name="kategorie" maxlength="100" required placeholder="z. B. Geografie, Harry Potter, Tiere">
            </label>
            <label>Anzahl Fragen (1 bis 20)
                <input type="number" name="anzahl" min="1" max="20" value="5">
            </label>
            <label>Punktwerte, die verteilt werden sollen</label>
            <div>
                <?php foreach ([100, 200, 300, 400, 500, 600, 1000] as $p): ?>
                    <label style="display:inline-block;margin-right:14px">
                        <input type="checkbox" name="punkte[]" value="<?= $p ?>" <?= in_array($p, [100, 200, 300]) ? 'checked' : '' ?>> <?= $p ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <label>Stil oder Hinweise (optional)
                <textarea name="stil" placeholder="z. B. eher leicht, für 10-jährige, mit Schwerpunkt Deutschland"></textarea>
            </label>
            <button type="submit">Fragen generieren</button>
        </form>
        <p class="muted">Die KI erstellt einen Entwurf. Du entscheidest vorher, welche Fragen gespeichert werden.</p>
    </div>

    <?php if ($draft && $draft['fragen']): ?>
        <div class="panel">
            <h2>Entwurf für „<?= htmlspecialchars($draft['kategorie']) ?>“</h2>
            <form method="post">
                <input type="hidden" name="action" value="save">
                <table>
                    <tr><th></th><th>Punkte</th><th>Frage</th><th>Antwort</th></tr>
                    <?php foreach ($draft['fragen'] as $i => $f): ?>
                        <tr>
                            <td><input type="checkbox" name="ja[]" value="<?= $i ?>" checked></td>
                            <td><?= (int)$f['punkte'] ?></td>
                            <td><?= htmlspecialchars($f['frage']) ?></td>
                            <td><?= htmlspecialchars($f['antwort']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                <button type="submit">Ausgewählte speichern</button>
            </form>
            <form method="post">
                <input type="hidden" name="action" value="verwerfen">
                <button class="red" type="submit">Entwurf verwerfen</button>
            </form>
        </div>
    <?php endif; ?>
</body>
</html>
<!-- Version 1.1 -->
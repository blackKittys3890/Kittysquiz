<?php
// Version 1.0
ini_set('display_errors', '1');
// Profil → API-Schlüssel: eigene Keys der KI-Anbieter verschlüsselt hinterlegen. Nur für angemeldete Konten (keine Gäste).
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/api_keys_lib.php';

$user = $_SESSION['discord_user'] ?? null;
if (!$user || !empty($user['guest'])) { header('Location: /index.php'); exit; }
$me = (string)$user['id'];

if (empty($_SESSION['ak_csrf'])) $_SESSION['ak_csrf'] = bin2hex(random_bytes(16));

$flash = $_SESSION['ak_flash'] ?? '';
$flashErr = $_SESSION['ak_flash_err'] ?? false;
unset($_SESSION['ak_flash'], $_SESSION['ak_flash_err']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfOk = hash_equals($_SESSION['ak_csrf'], (string)($_POST['csrf'] ?? ''));
    $provider = (string)($_POST['provider'] ?? '');
    $action = (string)($_POST['action'] ?? '');
    try {
        if (!$csrfOk) throw new RuntimeException('Sitzung abgelaufen. Bitte Seite neu laden.');
        if (!isset(AK_PROVIDERS[$provider])) throw new RuntimeException('Unbekannter Anbieter.');
        if ($action === 'save') {
            ak_save($me, $provider, (string)($_POST['api_key'] ?? ''));
            $_SESSION['ak_flash'] = AK_PROVIDERS[$provider]['label'] . ': Schlüssel gespeichert.';
        } elseif ($action === 'delete') {
            ak_delete($me, $provider);
            $_SESSION['ak_flash'] = AK_PROVIDERS[$provider]['label'] . ': Schlüssel gelöscht.';
        } else {
            throw new RuntimeException('Unbekannte Aktion.');
        }
    } catch (Throwable $e) {
        $_SESSION['ak_flash'] = $e->getMessage();
        $_SESSION['ak_flash_err'] = true;
    }
    header('Location: /profile/api_keys.php');
    exit;
}

$overview = ak_overview($me);
$secretOk = defined('API_KEY_SECRET') && strlen((string)API_KEY_SECRET) >= 32;
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>API-Schlüssel – <?= htmlspecialchars(defined('APP_NAME') ? APP_NAME : 'KittysRätsel') ?></title>
<style>
body { margin:0; font-family: system-ui, sans-serif; background:#1d1030; color:#fff; }
.wrap { max-width:760px; margin:0 auto; padding:14px; }
a { color:#ff9ad5; }
.card { background:rgba(255,255,255,.08); border-radius:16px; padding:16px; margin:12px 0; }
.muted { opacity:.75; font-size:14px; }
.ok { color:#7fe7d9; font-weight:700; }
.err { color:#ff8a8a; font-weight:700; }
input[type=password] { width:100%; box-sizing:border-box; padding:10px; margin-top:6px; border:none; border-radius:10px; background:#3b2a6b; color:#fff; font:inherit; }
.btn { display:inline-block; background:#ff6fb5; color:#fff; border:none; border-radius:12px; padding:9px 14px; font-weight:700; text-decoration:none; cursor:pointer; font:inherit; font-weight:700; }
.btn.ghost { background:rgba(255,255,255,.15); }
.btn.red { background:#ff3b5c; }
details { margin-top:10px; }
summary { cursor:pointer; color:#ffd166; }
ol li { margin:4px 0; }
.badge { display:inline-block; background:rgba(127,231,217,.2); color:#7fe7d9; border-radius:8px; padding:2px 8px; font-size:13px; }
</style>
</head>
<body>
<div class="wrap">
    <p><a href="/lobby.php">← Lobby</a> · <a href="/profile/index.php">Mein Profil</a></p>
    <h1>API-Schlüssel</h1>
    <p class="muted">Hinterlege hier deine eigenen Schlüssel der KI-Anbieter. Sie werden verschlüsselt gespeichert und nur auf dem Server für deine Anfragen genutzt. Die Abrechnung läuft über dein Konto beim jeweiligen Anbieter. Der vollständige Schlüssel wird danach nicht mehr angezeigt.</p>

    <?php if ($flash): ?>
        <div class="card <?= $flashErr ? 'err' : 'ok' ?>"><?= htmlspecialchars($flash) ?></div>
    <?php endif; ?>
    <?php if (!$secretOk): ?>
        <div class="card err">Die Verschlüsselung ist noch nicht eingerichtet. In config.php fehlt API_KEY_SECRET (mindestens 32 Zeichen).</div>
    <?php endif; ?>

    <?php foreach (AK_PROVIDERS as $pid => $p): $has = $overview[$pid] ?? null; ?>
        <div class="card">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;flex-wrap:wrap">
                <strong style="font-size:18px"><?= htmlspecialchars($p['label']) ?></strong>
                <?php if ($has): ?>
                    <span class="badge">hinterlegt · •••• <?= htmlspecialchars($has['hint']) ?></span>
                <?php else: ?>
                    <span class="muted">nicht hinterlegt</span>
                <?php endif; ?>
            </div>
            <p class="muted" style="margin:6px 0 0">
                <?= $p['used_by'] ? 'Wird genutzt für: ' . htmlspecialchars($p['used_by']) . '.' : 'Wird aktuell noch von keiner Funktion genutzt. Du kannst den Schlüssel schon jetzt hinterlegen.' ?>
            </p>

            <form method="post" autocomplete="off" style="margin-top:12px">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['ak_csrf']) ?>">
                <input type="hidden" name="provider" value="<?= htmlspecialchars($pid) ?>">
                <input type="hidden" name="action" value="save">
                <label class="muted">API-Schlüssel<input type="password" name="api_key" minlength="16" maxlength="300" required autocomplete="new-password" placeholder="<?= $has ? 'Neuen Schlüssel eintragen zum Ersetzen' : 'Schlüssel einfügen' ?>" <?= $secretOk ? '' : 'disabled' ?>></label>
                <button class="btn" type="submit" style="margin-top:10px" <?= $secretOk ? '' : 'disabled' ?>>Speichern</button>
            </form>

            <?php if ($has): ?>
                <form method="post" onsubmit="return confirm('Schlüssel wirklich löschen?');" style="margin-top:8px">
                    <input type="hidden" name="csrf" value="<?= htmlspecialchars($_SESSION['ak_csrf']) ?>">
                    <input type="hidden" name="provider" value="<?= htmlspecialchars($pid) ?>">
                    <input type="hidden" name="action" value="delete">
                    <button class="btn red" type="submit">Schlüssel löschen</button>
                </form>
            <?php endif; ?>

            <details>
                <summary>So bekommst du den Schlüssel</summary>
                <ol>
                    <?php foreach ($p['steps'] as $s): ?><li><?= htmlspecialchars($s) ?></li><?php endforeach; ?>
                </ol>
                <p><a href="<?= htmlspecialchars($p['url']) ?>" target="_blank" rel="noopener">Zur Seite des Anbieters</a></p>
            </details>
        </div>
    <?php endforeach; ?>

    <div class="card muted">
        Tipp: Lege beim Anbieter ein Ausgabelimit fest. So ist der Verbrauch auch bei einem Fehler begrenzt. Den Schlüssel teilst du nie mit anderen Personen. Wenn er verloren oder bekannt geworden ist, lösche ihn hier und erstelle beim Anbieter einen neuen.
    </div>
</div>
</body>
</html>
<!-- Version 1.0 -->
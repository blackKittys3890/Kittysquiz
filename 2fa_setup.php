<?php
// Version 1.0
// 2FA einrichten oder deaktivieren (nur für lokale Konten). Der Schlüssel wird als Text angezeigt, ohne QR-Code.
ini_set('display_errors', '0');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/totp.php';

$user = $_SESSION['discord_user'] ?? null;
if (!$user || !str_starts_with((string)$user['id'], 'local_')) {
    header('Location: lobby.php');
    exit;
}
$uid = (int)substr((string)$user['id'], 6);

$st = db()->prepare('SELECT username, totp_secret FROM kr_users WHERE id = ?');
$st->execute([$uid]);
$row = $st->fetch();
$enabled = $row && !empty($row['totp_secret']);
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['act'] ?? '');
    $code = (string)($_POST['code'] ?? '');

    if ($act === 'start' && !$enabled) {
        $_SESSION['totp_new'] = totp_new_secret();
    } elseif ($act === 'confirm' && !$enabled) {
        $secret = (string)($_SESSION['totp_new'] ?? '');
        if ($secret !== '' && totp_verify($secret, $code)) {
            $codes = backup_make();
            db()->prepare('UPDATE kr_users SET totp_secret = ?, totp_backup = ? WHERE id = ?')
                ->execute([$secret, backup_hashes($codes), $uid]);
            unset($_SESSION['totp_new']);
            $_SESSION['totp_codes'] = $codes;
            header('Location: 2fa_setup.php');
            exit;
        }
        $err = 'Der Code passt nicht. Prüfe die Uhrzeit auf deinem Handy und versuche es erneut.';
    } elseif ($act === 'disable' && $enabled) {
        if (totp_verify((string)$row['totp_secret'], $code)) {
            db()->prepare('UPDATE kr_users SET totp_secret = NULL, totp_backup = NULL WHERE id = ?')->execute([$uid]);
            $msg = '2FA wurde ausgeschaltet.';
            $enabled = false;
        } else {
            $err = 'Der Code passt nicht.';
        }
    }
}

$codes = $_SESSION['totp_codes'] ?? null;
unset($_SESSION['totp_codes']);
$newSecret = (!$enabled) ? ($_SESSION['totp_new'] ?? null) : null;
$name = (string)($row['username'] ?? 'Konto');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>2FA – KittysRätsel</title>
    <link rel="stylesheet" href="/css/base.css?v=1.6">
</head>
<body>
<div class="page">
    <section class="card">
        <h2>Zwei-Faktor-Anmeldung (2FA)</h2>
        <p class="muted">Freiwillig. Du brauchst dafür eine Authenticator-App, zum Beispiel Google Authenticator oder Microsoft Authenticator.</p>
        <?php if ($msg !== ''): ?><p><?= htmlspecialchars($msg) ?></p><?php endif; ?>
        <?php if ($err !== ''): ?><p style="color:#c0392b"><?= htmlspecialchars($err) ?></p><?php endif; ?>

        <?php if ($codes): ?>
            <h3>Deine Backup-Codes</h3>
            <p>Bewahre sie sicher auf. Jeder Code funktioniert nur einmal. Sie werden nur jetzt angezeigt.</p>
            <ul style="font-family:monospace;font-size:18px;">
                <?php foreach ($codes as $c): ?><li><?= htmlspecialchars($c) ?></li><?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($enabled): ?>
            <p><strong>2FA ist aktiv.</strong></p>
            <form method="post">
                <input type="hidden" name="act" value="disable">
                <label>Aktueller Code aus der App<br><input name="code" maxlength="12" required></label>
                <p><button class="btn" type="submit">2FA ausschalten</button></p>
            </form>
        <?php elseif ($newSecret): ?>
            <ol>
                <li>Öffne deine Authenticator-App und wähle „Schlüssel manuell eingeben“.</li>
                <li>Konto: <strong><?= htmlspecialchars($name) ?></strong>, Schlüssel:</li>
            </ol>
            <p style="font-family:monospace;font-size:18px;word-break:break-all;"><?= htmlspecialchars(trim(chunk_split($newSecret, 4, ' '))) ?></p>
            <p class="muted" style="font-size:13px;">Zeitbasiert, 30 Sekunden, 6 Ziffern.</p>
            <form method="post">
                <input type="hidden" name="act" value="confirm">
                <label>Code aus der App<br><input name="code" inputmode="numeric" maxlength="8" required></label>
                <p><button class="btn" type="submit">Bestätigen und einschalten</button></p>
            </form>
        <?php else: ?>
            <form method="post">
                <input type="hidden" name="act" value="start">
                <p><button class="btn" type="submit">2FA einrichten</button></p>
            </form>
        <?php endif; ?>
        <p><a href="lobby.php">Zurück zur Lobby</a></p>
    </section>
</div>
</body>
</html>
<!-- Version 1.0 -->
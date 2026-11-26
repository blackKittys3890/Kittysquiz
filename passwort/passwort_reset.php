<?php
// Version 1.0
// Neues Passwort setzen über den Link aus der Mail (1 Stunde gültig, einmal verwendbar).
ini_set('display_errors', '0');
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';

$raw = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$row = null;
if ($raw !== '') {
    $st = db()->prepare('SELECT id, user_key FROM kr_tokens WHERE token_hash = ? AND type = ? AND expires_at > NOW()');
    $st->execute([hash('sha256', $raw), 'pwreset']);
    $row = $st->fetch() ?: null;
}

$err = '';
$done = false;
if ($row && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $pw = (string)($_POST['password'] ?? '');
    $pw2 = (string)($_POST['password2'] ?? '');
    if (strlen($pw) < 8) {
        $err = 'Das Passwort muss mindestens 8 Zeichen haben.';
    } elseif ($pw !== $pw2) {
        $err = 'Die beiden Passwörter sind nicht gleich.';
    } else {
        $userId = (int)substr((string)$row['user_key'], 6);
        db()->prepare('UPDATE kr_users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($pw, PASSWORD_DEFAULT), $userId]);
        db()->prepare('DELETE FROM kr_tokens WHERE user_key = ? AND type = ?')->execute([$row['user_key'], 'pwreset']);
        $done = true;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Neues Passwort – KittysRätsel</title>
    <link rel="stylesheet" href="/css/base.css?v=1.6">
</head>
<body>
<div class="page">
    <section class="card">
        <h2>Neues Passwort</h2>
        <?php if ($done): ?>
            <p>Dein Passwort wurde geändert. Du kannst dich jetzt anmelden.</p>
            <p><a href="/index.php">Zur Anmeldung</a></p>
        <?php elseif (!$row): ?>
            <p>Der Link ist ungültig oder abgelaufen. Fordere einen neuen an.</p>
            <p><a href="/passwort/passwort_vergessen.php">Neuen Link anfordern</a></p>
        <?php else: ?>
            <?php if ($err !== ''): ?><p style="color:#c0392b"><?= htmlspecialchars($err) ?></p><?php endif; ?>
            <form method="post">
                <input type="hidden" name="token" value="<?= htmlspecialchars($raw) ?>">
                <label>Neues Passwort (mind. 8 Zeichen)<br><input type="password" name="password" minlength="8" required></label>
                <label>Passwort wiederholen<br><input type="password" name="password2" minlength="8" required></label>
                <p><button class="btn" type="submit">Speichern</button></p>
            </form>
        <?php endif; ?>
    </section>
</div>
</body>
</html>
<!-- Version 1.0 -->
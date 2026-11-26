<?php
// Version 1.0
// Zweiter Schritt der Anmeldung für lokale Konten mit 2FA: Code aus der Authenticator-App oder ein Backup-Code.
ini_set('display_errors', '0');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/totp.php';

$pending = $_SESSION['pending_2fa'] ?? null;
if (!$pending) {
    header('Location: index.php');
    exit;
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $pending['tries'] = (int)($pending['tries'] ?? 0) + 1;
    $_SESSION['pending_2fa'] = $pending;
    if ($pending['tries'] > 5) {
        unset($_SESSION['pending_2fa']);
        $_SESSION['login_error'] = 'Zu viele Fehlversuche. Bitte melde dich neu an.';
        header('Location: index.php');
        exit;
    }

    $code = trim((string)($_POST['code'] ?? ''));
    $st = db()->prepare('SELECT id, username, avatar, totp_secret, totp_backup FROM kr_users WHERE id = ?');
    $st->execute([(int)$pending['id']]);
    $row = $st->fetch();

    $ok = false;
    if ($row && !empty($row['totp_secret']) && totp_verify((string)$row['totp_secret'], $code)) {
        $ok = true;
    } elseif ($row && !empty($row['totp_backup'])) {
        $left = backup_use((string)$row['totp_backup'], $code);
        if ($left !== null) {
            db()->prepare('UPDATE kr_users SET totp_backup = ? WHERE id = ?')->execute([$left, (int)$row['id']]);
            $ok = true;
        }
    }

    if ($ok) {
        unset($_SESSION['pending_2fa']);
        session_regenerate_id(true);
        $user = [
            'id' => 'local_' . $row['id'],
            'name' => $row['username'],
            'username' => $row['username'],
            'avatar' => $row['avatar'],
            'premium' => 0,
            'decoration' => null,
        ];
        $_SESSION['discord_user'] = $user;
        if (!empty($pending['remember'])) {
            remember_set($user);
        }
        $target = $_SESSION['after_login'] ?? 'lobby.php';
        unset($_SESSION['after_login']);
        header('Location: ' . $target);
        exit;
    }
    $err = 'Der Code ist nicht gültig.';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bestätigungscode – KittysRätsel</title>
    <link rel="stylesheet" href="/css/base.css?v=1.6">
</head>
<body>
<div class="page">
    <section class="card">
        <h2>Bestätigungscode</h2>
        <p>Gib den Code aus deiner Authenticator-App ein. Du kannst auch einen Backup-Code verwenden.</p>
        <?php if ($err !== ''): ?><p style="color:#c0392b"><?= htmlspecialchars($err) ?></p><?php endif; ?>
        <form method="post" autocomplete="off">
            <input name="code" inputmode="text" maxlength="12" required autofocus>
            <p><button class="btn" type="submit">Bestätigen</button></p>
        </form>
    </section>
</div>
</body>
</html>
<!-- Version 1.0 -->
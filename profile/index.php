<?php
// Version 1.0
// Profil: Konto-Übersicht, E-Mail ändern (mit Bestätigung), Passwort ändern (nur lokal), Konto löschen.
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/invite/mail_helper.php';

$user = $_SESSION['discord_user'] ?? null;
if (!$user) { header('Location: /index.php'); exit; }

$key = (string)$user['id'];
$isLocal = str_starts_with($key, 'local_');
$provider = $isLocal ? 'Lokales Konto' : (str_starts_with($key, 'google_') ? 'Google' : 'Discord');
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'change_password') {
        if (!$isLocal) {
            $err = 'Das Passwort gehört zu deinem Discord- bzw. Google-Konto und wird dort geändert.';
        } else {
            $current = $_POST['current'] ?? '';
            $new = $_POST['new'] ?? '';
            $new2 = $_POST['new2'] ?? '';
            $id = (int)substr($key, 6);
            $st = db()->prepare('SELECT password_hash FROM kr_users WHERE id = ?');
            $st->execute([$id]);
            $hash = $st->fetchColumn();
            if (!$hash || !password_verify($current, $hash)) $err = 'Das aktuelle Passwort stimmt nicht.';
            elseif (mb_strlen($new) < 8) $err = 'Das neue Passwort braucht mindestens 8 Zeichen.';
            elseif ($new !== $new2) $err = 'Die beiden neuen Passwörter stimmen nicht überein.';
            else {
                db()->prepare('UPDATE kr_users SET password_hash = ? WHERE id = ?')
                    ->execute([password_hash($new, PASSWORD_DEFAULT), $id]);
                $msg = 'Passwort wurde geändert.';
            }
        }
    }

    elseif ($action === 'change_email') {
        $new = strtolower(trim($_POST['email'] ?? ''));
        if (!filter_var($new, FILTER_VALIDATE_EMAIL) || mb_strlen($new) > 190) {
            $err = 'Bitte eine gültige E-Mail-Adresse eingeben.';
        } else {
            $st = db()->prepare('SELECT 1 FROM kr_emails WHERE email = ? AND user_key <> ?');
            $st->execute([$new, $key]);
            if ($st->fetchColumn()) {
                $err = 'Diese E-Mail-Adresse wird schon verwendet.';
            } else {
                $raw = bin2hex(random_bytes(32));
                db()->prepare('INSERT INTO kr_tokens (type, user_key, token_hash, payload, expires_at) VALUES (?, ?, ?, ?, NOW() + INTERVAL 1 DAY)')
                    ->execute(['email', $key, hash('sha256', $raw), json_encode(['email' => $new])]);
                $url = 'https://kittysquiz.app/profile/confirm_email.php?token=' . $raw;
                $text = "Hallo,\n\nbitte bestätige die neue E-Mail-Adresse für dein KittysRätsel-Konto:\n\n$url\n\n"
                      . "Der Link ist 24 Stunden gültig. Wenn du das nicht warst, ignoriere diese Mail.\n\nKittysRätsel\nhttps://kittysquiz.app\n";
                if (send_app_mail($new, 'Bitte neue E-Mail bestätigen', $text)) {
                    $msg = 'Wir haben einen Bestätigungslink an die neue Adresse geschickt. Die Adresse wird erst nach dem Klick geändert.';
                } else {
                    $err = 'Die Bestätigungsmail konnte nicht verschickt werden. Bitte später erneut versuchen.';
                }
            }
        }
    }

    elseif ($action === 'delete_account') {
        if (trim($_POST['confirm'] ?? '') !== 'LÖSCHEN') {
            $err = 'Bitte gib zur Bestätigung genau LÖSCHEN ein.';
        } else {
            $p = db();
            $p->prepare('DELETE FROM kr_room_players WHERE discord_id = ?')->execute([$key]);
            $p->prepare('DELETE FROM kr_rooms WHERE host_id = ?')->execute([$key]);
            $p->prepare('DELETE FROM kr_remember WHERE user_key = ?')->execute([$key]);
            $p->prepare('DELETE FROM kr_emails WHERE user_key = ?')->execute([$key]);
            $p->prepare('DELETE FROM kr_admins WHERE user_key = ?')->execute([$key]);
            $p->prepare('DELETE FROM kr_tokens WHERE user_key = ?')->execute([$key]);
            if ($isLocal) {
                $p->prepare('DELETE FROM kr_users WHERE id = ?')->execute([(int)substr($key, 6)]);
            }
            remember_clear();
            $_SESSION = [];
            session_destroy();
            header('Location: /index.php');
            exit;
        }
    }
}

$email = auth_email_for($key);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mein Profil – <?= htmlspecialchars(defined('APP_NAME') ? APP_NAME : 'KittysRätsel') ?></title>
    <link rel="stylesheet" href="/css/base.css?v=1.2">
    <link rel="stylesheet" href="/css/lobby.css?v=1.1">
</head>
<body>
<div class="page">
    <header class="topbar">
        <div class="brand">Mein Profil</div>
        <a class="btn btn-ghost btn-small" href="/lobby.php">Zur Lobby</a>
    </header>

    <?php if ($msg): ?><div class="card msg" style="margin-bottom:18px;"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="card error" style="margin-bottom:18px;"><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <div class="stack">
        <section class="card">
            <h2>Konto</h2>
            <p><strong>Name:</strong> <?= htmlspecialchars($user['name'] ?? $user['username'] ?? '') ?></p>
            <p><strong>Anmeldung:</strong> <?= htmlspecialchars($provider) ?></p>
            <p><strong>E-Mail:</strong> <?= htmlspecialchars($email ?? 'nicht hinterlegt') ?></p>
            <p class="muted">Name und Profilbild bei Discord und Google kommen vom Anbieter.</p>
        </section>

        <section class="card">
            <h2>E-Mail ändern</h2>
            <p class="muted">Du bekommst einen Bestätigungslink an die neue Adresse. Erst nach dem Klick wird sie geändert.</p>
            <form method="post" class="invite-form">
                <input type="hidden" name="action" value="change_email">
                <input type="email" name="email" required maxlength="190" placeholder="Neue E-Mail-Adresse">
                <button type="submit" class="btn btn-block">Bestätigungslink schicken</button>
            </form>
        </section>

        <?php if ($isLocal): ?>
        <section class="card">
            <h2>Passwort ändern</h2>
            <form method="post" class="invite-form">
                <input type="hidden" name="action" value="change_password">
                <input type="password" name="current" required placeholder="Aktuelles Passwort" autocomplete="current-password">
                <input type="password" name="new" required minlength="8" placeholder="Neues Passwort (mind. 8 Zeichen)" autocomplete="new-password">
                <input type="password" name="new2" required minlength="8" placeholder="Neues Passwort wiederholen" autocomplete="new-password">
                <button type="submit" class="btn btn-block">Passwort ändern</button>
            </form>
        </section>
        <?php endif; ?>

        <section class="card">
            <h2>Konto löschen</h2>
            <p class="muted">Dabei werden dein Konto, deine E-Mail, deine Räume und deine Teilnahmen dauerhaft entfernt. Das lässt sich nicht rückgängig machen.</p>
            <form method="post" class="invite-form" onsubmit="return confirm('Konto wirklich löschen?');">
                <input type="hidden" name="action" value="delete_account">
                <input type="text" name="confirm" required placeholder="Zur Bestätigung LÖSCHEN eingeben">
                <button type="submit" class="btn btn-pink btn-block">Konto endgültig löschen</button>
            </form>
        </section>
    </div>
</div>
<script src="/js/bigtext.js"></script>
</body>
</html>
<!-- Version 1.0 -->
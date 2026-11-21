<?php
// Version 1.0
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$user = $_SESSION['discord_user'] ?? null;
if (!$user) { header('Location: index.php'); exit; }
if (auth_has_email()) { header('Location: lobby.php'); exit; }

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim($_POST['email'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 190) {
        $error = 'Bitte eine gültige E-Mail-Adresse eingeben.';
    } else {
        $st = db()->prepare('SELECT 1 FROM kr_emails WHERE email = ?');
        $st->execute([$email]);
        if ($st->fetchColumn()) {
            $error = 'Diese E-Mail-Adresse wird schon verwendet.';
        } else {
            db()->prepare('INSERT INTO kr_emails (user_key, email) VALUES (?, ?)')
                ->execute([(string)$user['id'], $email]);
            header('Location: lobby.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>E-Mail angeben – KittysRätsel</title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
            font-family: system-ui, sans-serif; color: #fff; padding: 20px; box-sizing: border-box;
            background: linear-gradient(160deg, #1d1b3a, #2c1046 60%, #14233b); }
        .card { width: min(420px, 100%); background: rgba(255,255,255,.08); border-radius: 20px; padding: 28px; }
        h1 { margin-top: 0; font-size: 24px; }
        input { width: 100%; padding: 12px; border-radius: 10px; border: none; font: inherit; box-sizing: border-box; margin-top: 6px; }
        button { width: 100%; padding: 12px; margin-top: 14px; border: none; border-radius: 10px; background: #ff4fa3; color: #fff; font-weight: bold; font-size: 16px; cursor: pointer; }
        .error { color: #ffb3c4; font-weight: bold; }
        .muted { opacity: .8; font-size: 14px; line-height: 1.5; }
        a { color: #9ff0ff; }
    </style>
</head>
<body>
    <div class="card">
        <h1>Wie erreichen wir dich?</h1>
        <p class="muted">Bitte gib deine E-Mail-Adresse an. Damit kannst du dein Passwort zurücksetzen und wichtige Hinweise bekommen. Die Angabe ist Pflicht.</p>
        <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
        <form method="post">
            <label>E-Mail-Adresse
                <input type="email" name="email" required maxlength="190" autocomplete="email">
            </label>
            <button type="submit">Speichern</button>
        </form>
        <p class="muted"><a href="/datenschutz.php">Datenschutzerklärung</a></p>
    </div>
</body>
</html>
<!-- Version 1.0 -->
<?php
// Version 1.4
require_once __DIR__ . '/config.php';

$user = $_SESSION['discord_user'] ?? null;

// Eingeloggte Nutzer direkt zur Lobby schicken
if ($user) {
    header('Location: lobby.php');
    exit;
}

// Discord-Login-Link mit CSRF-Schutz (state)
$state = bin2hex(random_bytes(16));
$_SESSION['oauth_state'] = $state;

$loginUrl = 'https://discord.com/oauth2/authorize?' . http_build_query([
    'client_id'     => DISCORD_CLIENT_ID,
    'redirect_uri'  => DISCORD_REDIRECT_URI,
    'response_type' => 'code',
    'scope'         => 'identify',
    'state'         => $state,
]);

// Google-Login-Link mit CSRF-Schutz (state)
$googleState = bin2hex(random_bytes(16));
$_SESSION['google_state'] = $googleState;

$googleUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'client_id'     => GOOGLE_CLIENT_ID,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'response_type' => 'code',
    'scope'         => 'openid profile',
    'state'         => $googleState,
    'prompt'        => 'select_account',
]);

$error = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= APP_NAME ?></title>
    <style>
        body {
            font-family: system-ui, sans-serif;
            background: linear-gradient(135deg, #2b1d4a, #4b2c6f);
            color: #f5f0ff;
            min-height: 100vh;
            margin: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .card {
            background: rgba(255, 255, 255, 0.08);
            border-radius: 20px;
            padding: 36px;
            text-align: center;
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.4);
            width: 330px;
            max-width: 90%;
        }
        h1 { margin-top: 0; }
        input {
            width: 100%;
            box-sizing: border-box;
            padding: 11px;
            margin-top: 8px;
            border: none;
            border-radius: 10px;
        }
        .btn {
            display: block;
            width: 100%;
            box-sizing: border-box;
            margin-top: 12px;
            padding: 12px;
            border: none;
            border-radius: 10px;
            background: #5865f2;
            color: white;
            text-decoration: none;
            font-weight: bold;
            cursor: pointer;
            font-size: 15px;
        }
        .btn:hover { filter: brightness(1.1); }
        .btn.local { background: #ff3ea5; }
        .btn.google { background: #ffffff; color: #1f1438; }
        .divider { margin: 22px 0 6px; opacity: .6; font-size: 14px; }
        .error { color: #ff8a8a; }
        a { color: #7fe7d9; }
    </style>
</head>
<body>
    <div class="card">
        <h1>🐱 <?= APP_NAME ?></h1>

        <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

        <form method="post" action="local_login.php">
            <input name="username" placeholder="Benutzername" required>
            <input name="password" type="password" placeholder="Passwort" required>
            <button class="btn local" type="submit">Anmelden</button>
        </form>
        <p><a href="local_register.php">Neues Konto erstellen</a></p>

        <div class="divider">— oder —</div>

        <a class="btn google" href="<?= htmlspecialchars($googleUrl) ?>">Mit Google anmelden</a>
        <a class="btn" href="<?= htmlspecialchars($loginUrl) ?>">Mit Discord anmelden</a>
    </div>
</body>
</html>
<!-- Version 1.4 -->

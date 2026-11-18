<?php
// Version 1.6
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
            box-sizing: border-box;
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
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
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
        .btn svg { width: 20px; height: 20px; flex-shrink: 0; }
        .btn.local { background: #ff3ea5; }
        .btn.google { background: #ffffff; color: #1f1438; }
        .btn.discord { background: #5865f2; color: #ffffff; }
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

        <a class="btn google" href="<?= htmlspecialchars($googleUrl) ?>">
            <svg viewBox="0 0 48 48" aria-hidden="true">
                <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
                <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
                <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
                <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
            </svg>
            Mit Google anmelden
        </a>

        <a class="btn discord" href="<?= htmlspecialchars($loginUrl) ?>">
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M20.317 4.3698a19.7913 19.7913 0 00-4.8851-1.5152.0741.0741 0 00-.0785.0371c-.211.3753-.4447.8648-.6083 1.2495-1.8447-.2762-3.68-.2762-5.4868 0-.1636-.3933-.4058-.8742-.6177-1.2495a.077.077 0 00-.0785-.037 19.7363 19.7363 0 00-4.8852 1.515.0699.0699 0 00-.0321.0277C.5334 9.0458-.319 13.5799.0992 18.0578a.0824.0824 0 00.0312.0561c2.0528 1.5076 4.0413 2.4228 5.9929 3.0294a.0777.0777 0 00.0842-.0276c.4616-.6304.8731-1.2952 1.226-1.9942a.076.076 0 00-.0416-.1057c-.6528-.2476-1.2743-.5495-1.8722-.8923a.077.077 0 01-.0076-.1277c.1258-.0943.2517-.1923.3718-.2914a.0743.0743 0 01.0776-.0105c3.9278 1.7933 8.18 1.7933 12.0614 0a.0739.0739 0 01.0785.0095c.1202.099.246.1981.3728.2924a.077.077 0 01-.0066.1276 12.2986 12.2986 0 01-1.873.8914.0766.0766 0 00-.0407.1067c.3604.698.7719 1.3628 1.225 1.9932a.076.076 0 00.0842.0286c1.961-.6067 3.9495-1.5219 6.0023-3.0294a.077.077 0 00.0313-.0552c.5004-5.177-.8382-9.6739-3.5485-13.6604a.061.061 0 00-.0312-.0286zM8.02 15.3312c-1.1825 0-2.1569-1.0857-2.1569-2.419 0-1.3332.9555-2.4189 2.157-2.4189 1.2108 0 2.1757 1.0952 2.1568 2.419 0 1.3332-.9555 2.4189-2.1569 2.4189zm7.9748 0c-1.1825 0-2.1569-1.0857-2.1569-2.419 0-1.3332.9554-2.4189 2.1569-2.4189 1.2108 0 2.1757 1.0952 2.1568 2.419 0 1.3332-.946 2.4189-2.1568 2.4189Z"/>
            </svg>
            Mit Discord anmelden
        </a>
    </div>
<footer style="text-align:center;margin:30px 0 10px;font-size:14px;opacity:.8;"><a href="/impressum.php" style="color:#9ff0ff;">Impressum</a> · <a href="/datenschutz.php" style="color:#9ff0ff;">Datenschutz</a></footer>
</body>
</html>
<!-- Version 1.6 -->
<?php
// Version 1.21
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/auth.php';
remember_restore();

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
    'scope'         => 'identify email',
    'state'         => $state,
]);

// Google-Login-Link mit CSRF-Schutz (state)
$googleState = bin2hex(random_bytes(16));
$_SESSION['google_state'] = $googleState;

$googleUrl = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'client_id'     => GOOGLE_CLIENT_ID,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'response_type' => 'code',
    'scope'         => 'openid profile email',
    'state'         => $googleState,
    'prompt'        => 'select_account',
]);

// Vector-Login: Client-ID und Widget-Adresse aus config.php.
// Solange nichts eingetragen ist, wird der Block nicht angezeigt.
$vectorClientId = defined('VECTOR_CLIENT_ID') ? (string)VECTOR_CLIENT_ID : '';
$vectorWidgetUrl = defined('VECTOR_WIDGET_URL') ? (string)VECTOR_WIDGET_URL : '';
$showVector = $vectorClientId !== '' && $vectorWidgetUrl !== '';

$error = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= APP_NAME ?></title>
    <link rel="stylesheet" href="/css/base.css?v=1.4">
    <link rel="stylesheet" href="/css/index.css?v=1.2">
</head>
<body>
    <div class="card login-card">
        <?php if (file_exists(__DIR__ . '/img/logo.png')): ?><img src="/img/logo.png" alt="" class="logo-img"><?php endif; ?>
        <div class="logo"><?= APP_NAME ?></div>
        <div class="tagline">Quiz & Rätsel mit Freunden</div>

        <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

        <form method="post" action="local_login.php" class="login-form">
            <input name="login" placeholder="Benutzername oder E-Mail" required>
            <input name="password" type="password" placeholder="Passwort" required>
            <label class="remember"><input type="checkbox" name="remember" value="1" checked> Angemeldet bleiben (30 Tage)</label>
            <button class="btn btn-pink btn-block" type="submit">Anmelden</button>
        </form>
        <a class="register" href="local_register.php">Neues Konto erstellen</a>

        <div class="divider">oder</div>

        <a class="btn btn-google btn-block" href="<?= htmlspecialchars($googleUrl) ?>">
            <svg viewBox="0 0 48 48" aria-hidden="true">
                <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
                <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
                <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.78l7.97-6.19z"/>
                <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
            </svg>
            Mit Google anmelden
        </a>

        <a class="btn btn-discord btn-block" href="<?= htmlspecialchars($loginUrl) ?>">
            <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                <path d="M20.317 4.3698a19.7913 19.7913 0 00-4.8851-1.5152.0741.0741 0 00-.0785.0371c-.211.3753-.4447.8648-.6083 1.2495-1.8447-.2762-3.68-.2762-5.4868 0-.1636-.3933-.4058-.8742-.6177-1.2495a.077.077 0 00-.0785-.037 19.7363 19.7363 0 00-4.8852 1.515.0699.0699 0 00-.0321.0277C.5334 9.0458-.319 13.5799.0992 18.0578a.0824.0824 0 00.0312.0561c2.0528 1.5076 4.0413 2.4228 5.9929 3.0294a.0777.0777 0 00.0842-.0276c.4616-.6304.8731-1.2952 1.226-1.9942a.076.076 0 00-.0416-.1057c-.6528-.2476-1.2743-.5495-1.8722-.8923a.077.077 0 01-.0076-.1277c.1258-.0943.2517-.1923.3718-.2914a.0743.0743 0 01.0776-.0105c3.9278 1.7933 8.18 1.7933 12.0614 0a.0739.0739 0 01.0785.0095c.1202.099.246.1981.3728.2924a.077.077 0 01-.0066.1276 12.2986 12.2986 0 01-1.873.8914.0766.0766 0 00-.0407.1067c.3604.698.7719 1.3628 1.225 1.9932a.076.076 0 00.0842.0286c1.961-.6067 3.9495-1.5219 6.0023-3.0294a.077.077 0 00.0313-.0552c.5004-5.177-.8382-9.6739-3.5485-13.6604a.061.061 0 00-.0312-.0286zM8.02 15.3312c-1.1825 0-2.1569-1.0857-2.1569-2.419 0-1.3332.9555-2.4189 2.157-2.4189 1.2108 0 2.1757 1.0952 2.1568 2.419 0 1.3332-.9555 2.4189-2.1569 2.4189zm7.9748 0c-1.1825 0-2.1569-1.0857-2.1569-2.419 0-1.3332.9554-2.4189 2.1569-2.4189 1.2108 0 2.1757 1.0952 2.1568 2.419 0 1.3332-.946 2.4189-2.1568 2.4189Z"/>
            </svg>
            Mit Discord anmelden
        </a>

        <div class="divider">oder mit dem Handy</div>
        <?php if ($showVector): ?>
        <div data-vector-login data-client-id="<?= htmlspecialchars($vectorClientId) ?>" style="display:flex;justify-content:center;"></div>
        <script src="<?= htmlspecialchars($vectorWidgetUrl) ?>"></script>
        <?php else: ?>
        <button class="btn btn-block" type="button" disabled style="opacity:.6;cursor:not-allowed;">Mit Vector anmelden (kommt noch)</button>
        <?php endif; ?>
    </div>
<footer class="site-footer"><a href="/impressum.php">Impressum</a> · <a href="/datenschutz.php">Datenschutz</a> · <a href="https://whydonate.com/fundraising/kittysquiz" target="_blank" rel="noopener">❤️ Unterstützen</a></footer>
<script src="/js/cookie-hinweis.js"></script>
<script>
// Merk-Wunsch vor dem Anbieter-Login merken (wird im Callback gelesen)
document.querySelectorAll('.btn-google, .btn-discord').forEach(function (a) {
    a.addEventListener('click', function () {
        var box = document.querySelector('input[name="remember"]');
        var on = box && box.checked ? '1' : '0';
        document.cookie = 'kr_remember_pref=' + on + '; path=/; max-age=600; SameSite=Lax; Secure';
    });
});
</script>
<script src="/js/bigtext.js"></script>
</body>
</html>
<!-- Version 1.21 -->
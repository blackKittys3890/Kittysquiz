<?php
// Version 1.0
// Startet die Verknüpfung eines weiteren Kontos (Discord oder Google) aus dem Profil heraus.
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/auth.php';

if (empty($_SESSION['discord_user'])) { header('Location: /index.php'); exit; }

$provider = $_GET['provider'] ?? '';
if ($provider === 'discord') {
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;
    $_SESSION['link_mode'] = 'discord';
    header('Location: https://discord.com/oauth2/authorize?' . http_build_query([
        'client_id' => DISCORD_CLIENT_ID,
        'redirect_uri' => DISCORD_REDIRECT_URI,
        'response_type' => 'code',
        'scope' => 'identify email',
        'state' => $state,
    ]));
    exit;
}
if ($provider === 'google') {
    $state = bin2hex(random_bytes(16));
    $_SESSION['google_state'] = $state;
    $_SESSION['link_mode'] = 'google';
    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => GOOGLE_CLIENT_ID,
        'redirect_uri' => GOOGLE_REDIRECT_URI,
        'response_type' => 'code',
        'scope' => 'openid profile email',
        'state' => $state,
        'prompt' => 'select_account',
    ]));
    exit;
}
header('Location: /profile/index.php');
exit;
// Version 1.0
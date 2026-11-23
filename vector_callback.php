<?php
// Version 1.0
// Rückkehr vom Vector-Login: anmelden oder (aus dem Profil heraus) ein Konto verknüpfen.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$clientId    = defined('VECTOR_CLIENT_ID') ? (string)VECTOR_CLIENT_ID : 'kittysquiz';
$apiBase     = 'https://europe-west3-jarvis-konto.cloudfunctions.net/api';
$redirectUri = 'https://kittysquiz.app/vector_callback.php';

$code = (string)($_GET['code'] ?? '');
if ($code === '') {
    http_response_code(400);
    exit('Kein Code erhalten. Die Anmeldung wurde evtl. abgebrochen.');
}

// 1. Code gegen Access Token tauschen
$ch = curl_init($apiBase . '/token');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode([
        'grant_type'   => 'authorization_code',
        'code'         => $code,
        'client_id'    => $clientId,
        'redirect_uri' => $redirectUri,
    ]),
    CURLOPT_TIMEOUT        => 15,
]);
$token = json_decode((string)curl_exec($ch), true);
curl_close($ch);

if (empty($token['access_token'])) {
    http_response_code(401);
    exit('Login fehlgeschlagen.');
}

// 2. Profildaten abrufen
$ch = curl_init($apiBase . '/userinfo');
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token['access_token']],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 15,
]);
$info = json_decode((string)curl_exec($ch), true);
curl_close($ch);

if (empty($info['sub'])) {
    http_response_code(401);
    exit('Benutzerdaten konnten nicht geladen werden.');
}

// 3. Gleiches Format wie bei Google und Discord
$name = mb_substr((string)($info['name'] ?? ($info['email'] ?? 'Spieler')), 0, 60);
$loginKey = 'vector_' . (string)$info['sub'];
$vectorAvatarUrl = !empty($info['picture']) ? (string)$info['picture'] : null;

// Verknüpfen statt anmelden (aus dem Profil heraus gestartet)
if (($_SESSION['link_mode'] ?? '') === 'vector' && !empty($_SESSION['discord_user'])) {
    unset($_SESSION['link_mode']);
    $_SESSION['profile_msg'] = auth_link_login($loginKey, $vectorAvatarUrl);
    header('Location: /profile/index.php');
    exit;
}
unset($_SESSION['link_mode']);

session_regenerate_id(true);
$main = canonical_key($loginKey);
auth_save_provider_avatar($loginKey, $vectorAvatarUrl);
$_SESSION['discord_user'] = auth_apply_profile([
    'id'         => $main,
    'name'       => $name,
    'username'   => $name,
    'avatar'     => $vectorAvatarUrl,
    'premium'    => 0,
    'decoration' => null,
]);

// E-Mail von Vector übernehmen (nur wenn bestätigt und frei)
auth_store_provider_email($main, (string)($info['email'] ?? ''), !empty($info['email_verified']));

// Angemeldet bleiben, wenn im Formular gewählt
if (remember_wanted()) {
    remember_set($_SESSION['discord_user']);
}

// Zurück zum Ziel (z. B. Einladungslink), sonst Lobby
$target = $_SESSION['after_login'] ?? 'lobby.php';
unset($_SESSION['after_login']);
header('Location: ' . $target);
exit;
<?php
// Version 1.3
require_once __DIR__ . '/config.php';

// 1. State prüfen (Schutz gegen CSRF)
if (!isset($_GET['state'], $_SESSION['oauth_state']) ||
    !hash_equals($_SESSION['oauth_state'], $_GET['state'])) {
    die('Ungültiger State. Bitte erneut anmelden.');
}
unset($_SESSION['oauth_state']);

if (!isset($_GET['code'])) {
    die('Kein Code erhalten. Die Anmeldung wurde evtl. abgebrochen.');
}

// 2. Code gegen Access Token tauschen
$ch = curl_init('https://discord.com/api/oauth2/token');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'client_id'     => DISCORD_CLIENT_ID,
        'client_secret' => DISCORD_CLIENT_SECRET,
        'grant_type'    => 'authorization_code',
        'code'          => $_GET['code'],
        'redirect_uri'  => DISCORD_REDIRECT_URI,
    ]),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
    CURLOPT_RETURNTRANSFER => true,
]);
$tokenData = json_decode(curl_exec($ch), true);
curl_close($ch);

if (empty($tokenData['access_token'])) {
    die('Token konnte nicht abgerufen werden.');
}

// 3. Benutzerdaten abrufen
$ch = curl_init('https://discord.com/api/users/@me');
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $tokenData['access_token']],
    CURLOPT_RETURNTRANSFER => true,
]);
$discordUser = json_decode(curl_exec($ch), true);
curl_close($ch);

if (empty($discordUser['id'])) {
    die('Benutzerdaten konnten nicht geladen werden.');
}

// 4. Session setzen (Session-ID nach Login erneuern)
session_regenerate_id(true);
$_SESSION['discord_user'] = [
    'id'       => $discordUser['id'],
    'name'     => $discordUser['global_name'] ?? $discordUser['username'],
    'username' => $discordUser['username'],
    'avatar'   => $discordUser['avatar'] ?? null,
];

// 5. Nach dem Login direkt zur Lobby
header('Location: lobby.php');
exit;
// Version 1.3
<?php
// Version 1.6
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

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

// 3. Benutzerdaten abrufen (inkl. Nitro-Status und Avatar-Deko)
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

// Anzeigename: der Discord-Anzeigename, sonst der Benutzername
$displayName = mb_substr($discordUser['global_name'] ?? $discordUser['username'], 0, 60);

// Nitro: 0 = kein Nitro, 1/2/3 = Nitro-Varianten
$premium = (int)($discordUser['premium_type'] ?? 0);

// Avatar-Deko als fertige Bild-URL
$decorationAsset = $discordUser['avatar_decoration_data']['asset'] ?? null;
$decoration = $decorationAsset
    ? 'https://cdn.discordapp.com/avatar-decoration-presets/' . $decorationAsset . '.png'
    : null;

// 4. Session setzen (Session-ID nach Login erneuern)
session_regenerate_id(true);
$_SESSION['discord_user'] = [
    'id'         => $discordUser['id'],
    'name'       => $displayName,
    'username'   => $displayName,
    'avatar'     => $discordUser['avatar'] ?? null,
    'premium'    => $premium,
    'decoration' => $decoration,
];

// E-Mail von Discord übernehmen (nur wenn bestätigt und frei)
$key = (string)$discordUser['id'];
auth_store_provider_email($key, (string)($discordUser['email'] ?? ''), !empty($discordUser['verified']));

// Angemeldet bleiben, wenn im Formular gewählt
if (remember_wanted()) {
    remember_set($_SESSION['discord_user']);
}

// 5. Zurück zum Ziel (z. B. Einladungslink), sonst Lobby. Fehlende E-Mail fragt die Lobby ab.
$target = $_SESSION['after_login'] ?? 'lobby.php';
unset($_SESSION['after_login']);
header('Location: ' . $target);
exit;
// Version 1.6
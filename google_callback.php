<?php
// Version 1.1
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// 1. State prüfen (Schutz gegen CSRF)
if (!isset($_GET['state'], $_SESSION['google_state']) ||
    !hash_equals($_SESSION['google_state'], $_GET['state'])) {
    die('Ungültiger State. Bitte erneut anmelden.');
}
unset($_SESSION['google_state']);

if (!isset($_GET['code'])) {
    die('Kein Code erhalten. Die Anmeldung wurde evtl. abgebrochen.');
}

// 2. Code gegen Access Token tauschen
$ch = curl_init('https://oauth2.googleapis.com/token');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query([
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'code'          => $_GET['code'],
        'grant_type'    => 'authorization_code',
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
    ]),
    CURLOPT_RETURNTRANSFER => true,
]);
$tokenData = json_decode(curl_exec($ch), true);
curl_close($ch);

if (empty($tokenData['access_token'])) {
    die('Token konnte nicht abgerufen werden.');
}

// 3. Profildaten abrufen (Name, Profilbild)
$ch = curl_init('https://openidconnect.googleapis.com/v1/userinfo');
curl_setopt_array($ch, [
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $tokenData['access_token']],
    CURLOPT_RETURNTRANSFER => true,
]);
$info = json_decode(curl_exec($ch), true);
curl_close($ch);

if (empty($info['sub'])) {
    die('Benutzerdaten konnten nicht geladen werden.');
}

// 4. Session setzen. Gleiches Format wie bei Discord und lokalen Konten.
$name = mb_substr($info['name'] ?? ($info['email'] ?? 'Spieler'), 0, 60);

session_regenerate_id(true);
$_SESSION['discord_user'] = [
    'id'         => 'google_' . $info['sub'],
    'name'       => $name,
    'username'   => $name,
    'avatar'     => $info['picture'] ?? null,
    'premium'    => 0,
    'decoration' => null,
];

// E-Mail von Google übernehmen (nur wenn bestätigt und frei)
auth_store_provider_email('google_' . $info['sub'], (string)($info['email'] ?? ''), !empty($info['email_verified']));

// Angemeldet bleiben, wenn im Formular gewählt
if (remember_wanted()) {
    remember_set($_SESSION['discord_user']);
}

// Zurück zum Ziel (z. B. Einladungslink), sonst Lobby
$target = $_SESSION['after_login'] ?? 'lobby.php';
unset($_SESSION['after_login']);
header('Location: ' . $target);
exit;
// Version 1.1
<?php
// Version 1.2
// Rückkehr vom Vector-Login: der Anbieter liefert einen Firebase-ID-Token, der hier geprüft wird.
// Anmelden oder (aus dem Profil heraus) ein Konto verknüpfen.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

// Öffentlicher Web-API-Schlüssel des Vector-Projekts. Er ist nicht geheim, sollte aber in der
// Google-Cloud-Konsole auf die Domain kittysquiz.app eingeschränkt sein.
$apiKey = defined('VECTOR_API_KEY') ? (string)VECTOR_API_KEY : '';

$idToken = (string)($_GET['id_token'] ?? '');
if ($idToken === '' || $apiKey === '') {
    http_response_code(400);
    exit('Kein id_token erhalten oder Vector ist nicht eingerichtet.');
}

// 1. Token serverseitig prüfen. Nur so ist sicher, dass der Token echt und gültig ist.
$ch = curl_init('https://identitytoolkit.googleapis.com/v1/accounts:lookup?key=' . urlencode($apiKey));
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS     => json_encode(['idToken' => $idToken]),
    CURLOPT_TIMEOUT        => 15,
]);
$response = curl_exec($ch);
$status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($status !== 200) {
    http_response_code(401);
    exit('Anmeldung konnte nicht geprüft werden.');
}

$vUser = json_decode((string)$response, true)['users'][0] ?? null;
if (!$vUser || empty($vUser['localId'])) {
    http_response_code(401);
    exit('Ungültiger Token.');
}

// 2. Gleiches Format wie bei Google und Discord
$loginKey        = 'vector_' . (string)$vUser['localId'];
$email           = !empty($vUser['email']) ? (string)$vUser['email'] : '';
$name            = mb_substr((string)($vUser['displayName'] ?? ($email !== '' ? $email : 'Spieler')), 0, 60);
$vectorAvatarUrl = !empty($vUser['photoUrl']) ? (string)$vUser['photoUrl'] : null;

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

// E-Mail von Vector übernehmen, auch ohne Bestätigung (ausdrücklich so gewünscht).
// Die Adresse wird nur gespeichert, wenn sie noch frei ist.
auth_store_provider_email($main, $email, true);

// Angemeldet bleiben, wenn im Formular gewählt
if (remember_wanted()) {
    remember_set($_SESSION['discord_user']);
}

// Zurück zum Ziel (z. B. Einladungslink), sonst Lobby
$target = $_SESSION['after_login'] ?? 'lobby.php';
unset($_SESSION['after_login']);
header('Location: ' . $target);
exit;
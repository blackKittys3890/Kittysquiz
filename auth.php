<?php
// Version 1.2
// Gemeinsame Anmelde-Hilfen: „Angemeldet bleiben“ und E-Mail-Pflicht.

const REMEMBER_DAYS = 30;
const REMEMBER_COOKIE = 'kr_remember';

function auth_email_for(string $key): ?string
{
    $st = db()->prepare('SELECT email FROM kr_emails WHERE user_key = ?');
    $st->execute([$key]);
    $e = $st->fetchColumn();
    return $e === false ? null : $e;
}

function auth_has_email(): bool
{
    $u = $_SESSION['discord_user'] ?? null;
    return $u && auth_email_for((string)$u['id']) !== null;
}

// Nicht angemeldet: nichts tun. Angemeldet ohne E-Mail: zur Eingabe schicken.
function require_email(): void
{
    if (empty($_SESSION['discord_user'])) return;
    if (basename($_SERVER['SCRIPT_NAME']) === 'email_setzen.php') return;
    if (!auth_has_email()) {
        header('Location: /email_setzen.php');
        exit;
    }
}

// Merkt die Anmeldung für 30 Tage (nur Hash des Schlüssels in der Datenbank)
function remember_set(array $user): void
{
    $raw = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO kr_remember (user_key, token_hash, payload, expires_at) VALUES (?, ?, ?, NOW() + INTERVAL ' . (int)REMEMBER_DAYS . ' DAY)')
        ->execute([(string)$user['id'], hash('sha256', $raw), json_encode($user, JSON_UNESCAPED_UNICODE)]);
    setcookie(REMEMBER_COOKIE, $raw, [
        'expires' => time() + REMEMBER_DAYS * 86400,
        'path' => '/',
        'secure' => true,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

// Stellt die Sitzung aus dem Merk-Cookie wieder her
function remember_restore(): void
{
    if (!empty($_SESSION['discord_user']) || empty($_COOKIE[REMEMBER_COOKIE])) return;
    $st = db()->prepare('SELECT payload FROM kr_remember WHERE token_hash = ? AND expires_at > NOW()');
    $st->execute([hash('sha256', $_COOKIE[REMEMBER_COOKIE])]);
    $payload = $st->fetchColumn();
    if (!$payload) return;
    $user = json_decode($payload, true);
    if (!is_array($user)) return;
    session_regenerate_id(true);
    $_SESSION['discord_user'] = $user;
}

// Beim Abmelden: Merk-Eintrag und Cookie löschen
function remember_clear(): void
{
    if (!empty($_COOKIE[REMEMBER_COOKIE])) {
        db()->prepare('DELETE FROM kr_remember WHERE token_hash = ?')
            ->execute([hash('sha256', $_COOKIE[REMEMBER_COOKIE])]);
    }
    setcookie(REMEMBER_COOKIE, '', ['expires' => 1, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax']);
}

// Admin: Eintrag in kr_admins
function auth_is_admin(): bool
{
    $u = $_SESSION['discord_user'] ?? null;
    if (!$u) return false;
    $st = db()->prepare('SELECT 1 FROM kr_admins WHERE user_key = ?');
    $st->execute([(string)$u['id']]);
    return (bool)$st->fetchColumn();
}

// Modus aktiv? Fehlt der Eintrag, gilt er als aktiv.
function mode_enabled(string $key): bool
{
    $st = db()->prepare('SELECT enabled FROM kr_modes WHERE mode_key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? true : (bool)$v;
}

// Darf dieser Nutzer den Modus wählen? Admins dürfen alles.
function mode_selectable(string $key): bool
{
    return mode_enabled($key) || auth_is_admin();
}

// E-Mail vom Anbieter (Discord/Google) übernehmen, aber nur wenn sie bestätigt, frei und noch nicht hinterlegt ist
function auth_store_provider_email(string $key, string $email, bool $verified): void
{
    if (!$verified || !filter_var($email, FILTER_VALIDATE_EMAIL)) return;
    $email = strtolower($email);
    if (auth_email_for($key) !== null) return;
    $st = db()->prepare('SELECT 1 FROM kr_emails WHERE email = ?');
    $st->execute([$email]);
    if ($st->fetchColumn()) return;
    db()->prepare('INSERT INTO kr_emails (user_key, email) VALUES (?, ?)')->execute([$key, $email]);
}

// Merk-Wunsch aus dem Anmeldeformular (per Cookie, vor dem Anbieter-Login gesetzt)
function remember_wanted(): bool
{
    $on = ($_COOKIE['kr_remember_pref'] ?? '') === '1';
    setcookie('kr_remember_pref', '', ['expires' => 1, 'path' => '/', 'secure' => true, 'samesite' => 'Lax']);
    return $on;
}
// Version 1.2
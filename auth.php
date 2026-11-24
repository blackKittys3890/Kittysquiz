<?php
// Version 1.6
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
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
    $user['id'] = canonical_key((string)$user['id']);
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

// Profilbild-Adresse: fertige Adressen/Pfade direkt, sonst Discord-Hash über die Standard-Funktion
function kr_avatar(string $id, ?string $avatar): string
{
    if ($avatar && (str_starts_with($avatar, '/') || str_starts_with($avatar, 'http'))) {
        return $avatar;
    }
    return getAvatarUrl(['id' => $id, 'avatar' => $avatar]);
}

// Name und Profilbild aus kr_profiles über die Sitzung legen (nach jeder Anmeldung)
function auth_apply_profile(array $user): array
{
    $st = db()->prepare('SELECT display_name, avatar FROM kr_profiles WHERE user_key = ?');
    $st->execute([(string)$user['id']]);
    $row = $st->fetch();
    if (!$row) return $user;

    $user['name'] = $row['display_name'];
    if (!empty($row['avatar'])) {
        $user['avatar'] = $row['avatar'];
    } elseif (!empty($user['avatar']) && !str_starts_with($user['avatar'], '/') && !str_starts_with($user['avatar'], 'http')) {
        // Alte lokale Bilder liegen als Dateiname im Upload-Ordner
        $user['avatar'] = '/uploads/' . $user['avatar'];
    }
    return $user;
}

// Alle Standardbilder aus img/avatars (preset-*.png/.jpg/.jpeg/.webp), als Web-Pfade
function avatar_presets(): array
{
    $files = glob(__DIR__ . '/img/avatars/preset-*.{png,jpg,jpeg,webp}', GLOB_BRACE) ?: [];
    sort($files, SORT_NATURAL);
    return array_map(fn($f) => '/img/avatars/' . basename($f), $files);
}

// ---------- Verknüpfung mehrerer Anmeldungen zu einem Profil ----------

// Anmelde-Schlüssel -> Hauptkonto (ist keine Verknüpfung da, bleibt es der Schlüssel selbst)
function canonical_key(string $loginKey): string
{
    $st = db()->prepare('SELECT profile_key FROM kr_links WHERE login_key = ?');
    $st->execute([$loginKey]);
    return (string)($st->fetchColumn() ?: $loginKey);
}

// Alle Anmeldungen, die zu diesem Profil gehören (inkl. Hauptkonto)
function linked_logins(string $profileKey): array
{
    $st = db()->prepare('SELECT login_key FROM kr_links WHERE profile_key = ? ORDER BY created_at');
    $st->execute([$profileKey]);
    return array_merge([$profileKey], $st->fetchAll(PDO::FETCH_COLUMN));
}

// Bild-Adresse einer verbundenen Anmeldung (nur wenn sie zum Profil gehört)
function linked_avatar_url(string $profileKey, string $loginKey): ?string
{
    if (!in_array($loginKey, linked_logins($profileKey), true)) return null;
    $st = db()->prepare('SELECT url FROM kr_provider_avatars WHERE login_key = ?');
    $st->execute([$loginKey]);
    $u = $st->fetchColumn();
    return $u ?: null;
}

// Bild einer Anmeldung speichern (bei jeder Anmeldung aktualisieren)
function auth_save_provider_avatar(string $loginKey, ?string $url): void
{
    if (!$url) return;
    db()->prepare('INSERT INTO kr_provider_avatars (login_key, url) VALUES (?, ?) ON DUPLICATE KEY UPDATE url = VALUES(url)')
        ->execute([$loginKey, $url]);
}

// Anmeldung an das aktuell angemeldete Profil hängen. Gibt eine Meldung zurück.
function auth_link_login(string $loginKey, ?string $avatarUrl): string
{
    $cur = (string)($_SESSION['discord_user']['id'] ?? '');
    if ($cur === '') return 'Bitte zuerst anmelden.';
    // Schon verbunden (oder das Hauptkonto selbst): nur das Bild aktualisieren
    if ($loginKey === $cur || in_array($loginKey, linked_logins($cur), true)) {
        auth_save_provider_avatar($loginKey, $avatarUrl);
        return 'Bild aktualisiert.';
    }
    if (canonical_key($loginKey) !== $loginKey) return 'Dieses Konto gehört schon zu einem anderen Profil.';
    $st = db()->prepare('SELECT 1 FROM kr_links WHERE login_key = ?');
    $st->execute([$loginKey]);
    if ($st->fetchColumn()) return 'Dieses Konto gehört schon zu einem anderen Profil.';
    db()->prepare('INSERT INTO kr_links (login_key, profile_key) VALUES (?, ?)')->execute([$loginKey, $cur]);
    auth_save_provider_avatar($loginKey, $avatarUrl);
    return 'Konto verbunden.';
}

// Badges eines Profils (Liste wie ['discord', 'google'])
function user_badges(string $key): array
{
    $st = db()->prepare('SELECT badge, is_teacher FROM kr_profiles WHERE user_key = ?');
    $st->execute([$key]);
    $row = $st->fetch() ?: [];
    $b = (string)($row['badge'] ?? '');
    $list = array_values(array_intersect(array_filter(explode(',', $b)), ['discord', 'google', 'vector']));
    // Lehrer-Badge: nur vom Admin vergeben, nicht selbst wählbar
    if (!empty($row['is_teacher'])) array_unshift($list, 'teacher');
    return $list;
}

// Badge-Bilder als HTML (neben dem Namen)
function badge_imgs(array $badges): string
{
    $html = '';
    foreach ($badges as $b) {
        $html .= '<img class="badge-icon" src="/img/badges/' . htmlspecialchars($b) . '-badge.png" alt="' . htmlspecialchars(ucfirst($b)) . '" title="' . htmlspecialchars(ucfirst($b)) . '">';
    }
    return $html;
}
// Version 1.10
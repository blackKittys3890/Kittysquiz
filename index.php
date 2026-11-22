<?php
// Version 1.5
// Profil: Konto-Übersicht, E-Mail ändern (mit Bestätigung), Passwort ändern (nur lokal), Konto löschen.
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/auth.php';
require_once dirname(__DIR__) . '/invite/mail_helper.php';

$user = $_SESSION['discord_user'] ?? null;
if (!$user) { header('Location: /index.php'); exit; }

$key = (string)$user['id'];
$isLocal = str_starts_with($key, 'local_');
$provider = $isLocal ? 'Lokales Konto' : (str_starts_with($key, 'google_') ? 'Google' : 'Discord');
$msg = '';
$err = '';

// Verlauf aller Profilbilder: jedes je genutzte Bild wird gemerkt
function avatar_history_add(string $key, ?string $value): void
{
    if (!$value) return;
    db()->prepare('INSERT IGNORE INTO kr_avatar_history (user_key, avatar_hash, avatar) VALUES (?, ?, ?)')
        ->execute([$key, sha1($value), $value]);
}

// Alte Bilder nachtragen: aktuelles Profilbild, lokales Bild, Bilder der verbundenen Anmeldungen
function avatar_history_sync(string $key, array $links): void
{
    $cur = db()->prepare('SELECT avatar FROM kr_profiles WHERE user_key = ?');
    $cur->execute([$key]);
    avatar_history_add($key, $cur->fetchColumn() ?: null);

    $loc = db()->prepare('SELECT avatar FROM kr_users WHERE id = ?');
    if (str_starts_with($key, 'local_')) {
        $loc->execute([(int)substr($key, 6)]);
        $f = $loc->fetchColumn();
        if ($f) avatar_history_add($key, str_starts_with($f, '/') || str_starts_with($f, 'http') ? $f : '/uploads/' . $f);
    }
    foreach ($links as $lk) {
        $p = db()->prepare('SELECT url FROM kr_provider_avatars WHERE login_key = ?');
        $p->execute([$lk]);
        avatar_history_add($key, $p->fetchColumn() ?: null);
    }
}

// Profil-Zeile holen, für Discord/Google beim ersten Aufruf anlegen
function ensure_profile(string $key, string $name, bool $isLocal): array
{
    $st = db()->prepare('SELECT display_name, handle FROM kr_profiles WHERE user_key = ?');
    $st->execute([$key]);
    if ($r = $st->fetch()) return $r;

    if ($isLocal) {
        $u = db()->prepare('SELECT username FROM kr_users WHERE id = ?');
        $u->execute([(int)substr($key, 6)]);
        $handle = (string)$u->fetchColumn();
    } else {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: '';
        $base = substr(strtolower(preg_replace('/[^a-z0-9_.]/i', '', $ascii)), 0, 14) ?: 'spieler';
        $handle = $base;
        for ($i = 0; $i < 50; $i++) {
            $c = db()->prepare('SELECT 1 FROM kr_profiles WHERE handle = ?');
            $c->execute([$handle]);
            if (!$c->fetchColumn()) break;
            $handle = $base . random_int(10, 99999);
        }
    }
    db()->prepare('INSERT INTO kr_profiles (user_key, display_name, handle) VALUES (?, ?, ?)')
        ->execute([$key, $name, $handle]);
    return ['display_name' => $name, 'handle' => $handle];
}

$links = linked_logins($key);
avatar_history_sync($key, $links);
if (!empty($_SESSION['profile_msg'])) { $msg = $_SESSION['profile_msg']; unset($_SESSION['profile_msg']); }
$profile = ensure_profile($key, (string)($user['name'] ?? $user['username'] ?? 'Spieler'), $isLocal);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'change_password') {
        if (!$isLocal) {
            $err = 'Das Passwort gehört zu deinem Discord- bzw. Google-Konto und wird dort geändert.';
        } else {
            $current = $_POST['current'] ?? '';
            $new = $_POST['new'] ?? '';
            $new2 = $_POST['new2'] ?? '';
            $id = (int)substr($key, 6);
            $st = db()->prepare('SELECT password_hash FROM kr_users WHERE id = ?');
            $st->execute([$id]);
            $hash = $st->fetchColumn();
            if (!$hash || !password_verify($current, $hash)) $err = 'Das aktuelle Passwort stimmt nicht.';
            elseif (mb_strlen($new) < 8) $err = 'Das neue Passwort braucht mindestens 8 Zeichen.';
            elseif ($new !== $new2) $err = 'Die beiden neuen Passwörter stimmen nicht überein.';
            else {
                db()->prepare('UPDATE kr_users SET password_hash = ? WHERE id = ?')
                    ->execute([password_hash($new, PASSWORD_DEFAULT), $id]);
                $msg = 'Passwort wurde geändert.';
            }
        }
    }

    elseif ($action === 'change_names') {
        $display = trim($_POST['display_name'] ?? '');
        $handle = strtolower(trim($_POST['handle'] ?? ''));
        if (mb_strlen($display) < 1 || mb_strlen($display) > 32) {
            $err = 'Der Anzeigename braucht 1 bis 32 Zeichen.';
        } elseif (!preg_match('/^[a-z0-9_.]{3,20}$/', $handle)) {
            $err = 'Der Benutzername braucht 3 bis 20 Zeichen: Buchstaben (a–z), Zahlen, Punkt oder Unterstrich.';
        } else {
            $c = db()->prepare('SELECT 1 FROM kr_profiles WHERE handle = ? AND user_key <> ?');
            $c->execute([$handle, $key]);
            $taken = (bool)$c->fetchColumn();
            if ($isLocal) {
                $c2 = db()->prepare('SELECT 1 FROM kr_users WHERE username = ? AND id <> ?');
                $c2->execute([$handle, (int)substr($key, 6)]);
                $taken = $taken || (bool)$c2->fetchColumn();
            }
            if ($taken) {
                $err = 'Dieser Benutzername ist schon vergeben.';
            } else {
                $p = db();
                $p->prepare('UPDATE kr_profiles SET display_name = ?, handle = ? WHERE user_key = ?')->execute([$display, $handle, $key]);
                if ($isLocal) {
                    $p->prepare('UPDATE kr_users SET username = ? WHERE id = ?')->execute([$handle, (int)substr($key, 6)]);
                }
                // Anzeige überall aktualisieren: Sitzung, laufende Räume, Merk-Einträge
                $_SESSION['discord_user']['name'] = $display;
                $_SESSION['discord_user']['username'] = $handle;
                $p->prepare('UPDATE kr_room_players SET username = ? WHERE discord_id = ?')->execute([$display, $key]);
                $rows = $p->prepare('SELECT id, payload FROM kr_remember WHERE user_key = ?');
                $rows->execute([$key]);
                foreach ($rows->fetchAll() as $r) {
                    $data = json_decode($r['payload'], true) ?: [];
                    $data['name'] = $display;
                    $data['username'] = $handle;
                    $p->prepare('UPDATE kr_remember SET payload = ? WHERE id = ?')
                        ->execute([json_encode($data, JSON_UNESCAPED_UNICODE), $r['id']]);
                }
                $profile = ['display_name' => $display, 'handle' => $handle];
                $msg = 'Name und Benutzername wurden gespeichert.';
            }
        }
    }

    elseif ($action === 'set_avatar') {
        $choice = $_POST['avatar_choice'] ?? '';
        $value = null;
        if (str_starts_with($choice, 'hist:')) {
            $h = db()->prepare('SELECT avatar FROM kr_avatar_history WHERE id = ? AND user_key = ?');
            $h->execute([(int)substr($choice, 5), $key]);
            $value = $h->fetchColumn() ?: null;
        } elseif (str_starts_with($choice, 'preset:') && in_array('/img/avatars/' . basename(substr($choice, 7)), avatar_presets(), true)) {
            $value = '/img/avatars/' . basename(substr($choice, 7));
        } elseif ($choice === 'upload' && !empty($_FILES['avatar_file']['name'])) {
            $f = $_FILES['avatar_file'];
            $info = $f['error'] === UPLOAD_ERR_OK ? @getimagesize($f['tmp_name']) : false;
            $ext = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/gif' => 'gif', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? null;
            if (!$info || !$ext || $f['size'] > 2 * 1024 * 1024) {
                $err = 'Profilbild: nur PNG, JPG, GIF oder WebP bis 2 MB.';
            } else {
                $dir = dirname(__DIR__) . '/uploads/avatars';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $name = bin2hex(random_bytes(12)) . '.' . $ext;
                if (move_uploaded_file($f['tmp_name'], $dir . '/' . $name)) {
                    $value = '/uploads/avatars/' . $name;
                } else {
                    $err = 'Das Bild konnte nicht gespeichert werden.';
                }
            }
        } else {
            $err = 'Bitte wähle ein Profilbild aus.';
        }

        if ($value !== null && !$err) {
            db()->prepare('UPDATE kr_profiles SET avatar = ? WHERE user_key = ?')->execute([$value, $key]);
            avatar_history_add($key, $value);
            $_SESSION['discord_user']['avatar'] = $value;
            $user = $_SESSION['discord_user'];
            $p = db();
            $p->prepare('UPDATE kr_room_players SET avatar = ? WHERE discord_id = ?')->execute([$value, $key]);
            $rows = $p->prepare('SELECT id, payload FROM kr_remember WHERE user_key = ?');
            $rows->execute([$key]);
            foreach ($rows->fetchAll() as $r) {
                $data = json_decode($r['payload'], true) ?: [];
                $data['avatar'] = $value;
                $p->prepare('UPDATE kr_remember SET payload = ? WHERE id = ?')
                    ->execute([json_encode($data, JSON_UNESCAPED_UNICODE), $r['id']]);
            }
            $msg = 'Profilbild wurde gespeichert.';
        }
    }

    elseif ($action === 'unlink') {
        $login = $_POST['login_key'] ?? '';
        if (!in_array($login, linked_logins($key), true) || $login === $key) {
            $err = 'Dieses Konto kann hier nicht getrennt werden.';
        } else {
            db()->prepare('DELETE FROM kr_links WHERE login_key = ? AND profile_key = ?')->execute([$login, $key]);
            $msg = 'Konto getrennt. Es ist jetzt wieder ein eigenes Konto.';
        }
    }

    elseif ($action === 'change_email') {
        $new = strtolower(trim($_POST['email'] ?? ''));
        if (!filter_var($new, FILTER_VALIDATE_EMAIL) || mb_strlen($new) > 190) {
            $err = 'Bitte eine gültige E-Mail-Adresse eingeben.';
        } else {
            $st = db()->prepare('SELECT 1 FROM kr_emails WHERE email = ? AND user_key <> ?');
            $st->execute([$new, $key]);
            if ($st->fetchColumn()) {
                $err = 'Diese E-Mail-Adresse wird schon verwendet.';
            } else {
                $raw = bin2hex(random_bytes(32));
                db()->prepare('INSERT INTO kr_tokens (type, user_key, token_hash, payload, expires_at) VALUES (?, ?, ?, ?, NOW() + INTERVAL 1 DAY)')
                    ->execute(['email', $key, hash('sha256', $raw), json_encode(['email' => $new])]);
                $url = 'https://kittysquiz.app/profile/confirm_email.php?token=' . $raw;
                $text = "Hallo,\n\nbitte bestätige die neue E-Mail-Adresse für dein KittysRätsel-Konto:\n\n$url\n\n"
                      . "Der Link ist 24 Stunden gültig. Wenn du das nicht warst, ignoriere diese Mail.\n\nKittysRätsel\nhttps://kittysquiz.app\n";
                if (send_app_mail($new, 'Bitte neue E-Mail bestätigen', $text)) {
                    $msg = 'Wir haben einen Bestätigungslink an die neue Adresse geschickt. Die Adresse wird erst nach dem Klick geändert.';
                } else {
                    $err = 'Die Bestätigungsmail konnte nicht verschickt werden. Bitte später erneut versuchen.';
                }
            }
        }
    }

    elseif ($action === 'delete_account') {
        if (trim($_POST['confirm'] ?? '') !== 'LÖSCHEN') {
            $err = 'Bitte gib zur Bestätigung genau LÖSCHEN ein.';
        } else {
            $p = db();
            $p->prepare('DELETE FROM kr_room_players WHERE discord_id = ?')->execute([$key]);
            $p->prepare('DELETE FROM kr_rooms WHERE host_id = ?')->execute([$key]);
            $p->prepare('DELETE FROM kr_remember WHERE user_key = ?')->execute([$key]);
            $p->prepare('DELETE FROM kr_emails WHERE user_key = ?')->execute([$key]);
            $p->prepare('DELETE FROM kr_admins WHERE user_key = ?')->execute([$key]);
            $p->prepare('DELETE FROM kr_tokens WHERE user_key = ?')->execute([$key]);
            if ($isLocal) {
                $p->prepare('DELETE FROM kr_users WHERE id = ?')->execute([(int)substr($key, 6)]);
            }
            remember_clear();
            $_SESSION = [];
            session_destroy();
            header('Location: /index.php');
            exit;
        }
    }
}

$email = auth_email_for($key);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Mein Profil – <?= htmlspecialchars(defined('APP_NAME') ? APP_NAME : 'KittysRätsel') ?></title>
    <link rel="stylesheet" href="/css/base.css?v=1.4">
    <link rel="stylesheet" href="/css/lobby.css?v=1.1">
</head>
<body>
<div class="page">
    <header class="topbar">
        <div class="brand">Mein Profil</div>
        <a class="btn btn-ghost btn-small" href="/lobby.php">Zur Lobby</a>
    </header>

    <?php if ($msg): ?><div class="card msg" style="margin-bottom:18px;"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="card error" style="margin-bottom:18px;"><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <div class="stack">
        <section class="card">
            <h2>Konto</h2>
            <p><strong>Anzeigename:</strong> <?= htmlspecialchars($profile['display_name']) ?></p>
            <p><strong>Benutzername:</strong> @<?= htmlspecialchars($profile['handle']) ?></p>
            <p><strong>Anmeldung:</strong> <?= htmlspecialchars($provider) ?></p>
            <p><strong>E-Mail:</strong> <?= htmlspecialchars($email ?? 'nicht hinterlegt') ?></p>
            <p class="muted">Name und Profilbild bei Discord und Google kommen vom Anbieter.</p>
        </section>

        <section class="card">
            <h2>Verbundene Konten</h2>
            <p class="muted">Mehrere Anmeldungen teilen sich ein Profil. Mit jedem verbundenen Konto kannst du dich anmelden und dasselbe Profil nutzen.</p>
            <?php foreach (linked_logins($key) as $lk):
                $lp = str_starts_with($lk, 'google_') ? 'Google' : (str_starts_with($lk, 'local_') ? 'Lokales Konto' : 'Discord');
            ?>
                <div class="room" style="margin-bottom:8px;">
                    <div><strong><?= htmlspecialchars($lp) ?></strong><?= $lk === $key ? ' <span class="muted">(Hauptkonto)</span>' : '' ?></div>
                    <?php if ($lk !== $key): ?>
                        <form method="post" class="inline" onsubmit="return confirm('Dieses Konto wirklich trennen?');">
                            <input type="hidden" name="action" value="unlink">
                            <input type="hidden" name="login_key" value="<?= htmlspecialchars($lk) ?>">
                            <button class="btn btn-ghost btn-small" type="submit">Trennen</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
            <div class="links" style="margin-top:10px;">
                <?php
                $hasDiscord = false; $hasGoogle = false;
                foreach ($links as $lk) { if (str_starts_with($lk, 'google_')) $hasGoogle = true; elseif (!str_starts_with($lk, 'local_')) $hasDiscord = true; }
                ?>
                <?php if (!$hasDiscord): ?><a class="btn btn-small" href="/profile/link.php?provider=discord">Discord verbinden</a><?php endif; ?>
                <?php if (!$hasGoogle): ?><a class="btn btn-small" href="/profile/link.php?provider=google">Google verbinden</a><?php endif; ?>
            </div>
        </section>

        <section class="card">
            <h2>Anzeigename &amp; Benutzername</h2>
            <p class="muted">Der Anzeigename ist frei wählbar und darf doppelt vorkommen. Der Benutzername ist einmalig und dient zur Identifikation.<?= $isLocal ? ' Mit dem Benutzernamen meldest du dich auch an.' : '' ?></p>
            <form method="post" class="invite-form">
                <input type="hidden" name="action" value="change_names">
                <input type="text" name="display_name" required maxlength="32" value="<?= htmlspecialchars($profile['display_name']) ?>" placeholder="Anzeigename">
                <input type="text" name="handle" required minlength="3" maxlength="20" pattern="[a-zA-Z0-9_.]{3,20}" value="<?= htmlspecialchars($profile['handle']) ?>" placeholder="Benutzername">
                <button type="submit" class="btn btn-block">Speichern</button>
            </form>
        </section>

        <section class="card">
            <h2>Profilbild</h2>
            <p><img src="<?= htmlspecialchars(kr_avatar($key, $user['avatar'] ?? null)) ?>" alt="" style="width:96px;height:96px;border-radius:50%;object-fit:cover;border:3px solid var(--mint);"></p>
            <form method="post" enctype="multipart/form-data" class="invite-form">
                <input type="hidden" name="action" value="set_avatar">

                <?php
                $hist = db()->prepare('SELECT id, avatar FROM kr_avatar_history WHERE user_key = ? ORDER BY created_at DESC, id DESC');
                $hist->execute([$key]);
                $history = $hist->fetchAll();
                ?>
                <?php if ($history): ?>
                    <p class="muted" style="margin:8px 0 4px;">Deine bisherigen Bilder:</p>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;">
                        <?php foreach ($history as $h): ?>
                            <label style="cursor:pointer;">
                                <input type="radio" name="avatar_choice" value="hist:<?= (int)$h['id'] ?>" style="display:none;">
                                <img src="<?= htmlspecialchars($h['avatar']) ?>" alt="" style="width:56px;height:56px;border-radius:50%;object-fit:cover;border:2px solid transparent;">
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <p class="muted" style="margin:8px 0 4px;">Standardbilder:</p>
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <?php foreach (avatar_presets() as $n => $pathPreset): ?>
                        <label style="cursor:pointer;">
                            <input type="radio" name="avatar_choice" value="preset:<?= htmlspecialchars(basename($pathPreset)) ?>" style="display:none;">
                            <img src="<?= htmlspecialchars($pathPreset) ?>" alt="Standardbild <?= $n + 1 ?>" style="width:56px;height:56px;border-radius:50%;border:2px solid transparent;">
                        </label>
                    <?php endforeach; ?>
                </div>

                <p class="muted" style="margin:8px 0 4px;">Oder eigenes Bild:</p>
                <label class="remember"><input type="radio" name="avatar_choice" value="upload"> Eigenes Bild hochladen</label>
                <input type="file" name="avatar_file" accept="image/png,image/jpeg,image/gif,image/webp">

                <button type="submit" class="btn btn-block">Profilbild speichern</button>
            </form>
        </section>

        <section class="card">
            <h2>E-Mail ändern</h2>
            <p class="muted">Du bekommst einen Bestätigungslink an die neue Adresse. Erst nach dem Klick wird sie geändert.</p>
            <form method="post" class="invite-form">
                <input type="hidden" name="action" value="change_email">
                <input type="email" name="email" required maxlength="190" placeholder="Neue E-Mail-Adresse">
                <button type="submit" class="btn btn-block">Bestätigungslink schicken</button>
            </form>
        </section>

        <?php if ($isLocal): ?>
        <section class="card">
            <h2>Passwort ändern</h2>
            <form method="post" class="invite-form">
                <input type="hidden" name="action" value="change_password">
                <input type="password" name="current" required placeholder="Aktuelles Passwort" autocomplete="current-password">
                <input type="password" name="new" required minlength="8" placeholder="Neues Passwort (mind. 8 Zeichen)" autocomplete="new-password">
                <input type="password" name="new2" required minlength="8" placeholder="Neues Passwort wiederholen" autocomplete="new-password">
                <button type="submit" class="btn btn-block">Passwort ändern</button>
            </form>
        </section>
        <?php endif; ?>

        <section class="card">
            <h2>Konto löschen</h2>
            <p class="muted">Dabei werden dein Konto, deine E-Mail, deine Räume und deine Teilnahmen dauerhaft entfernt. Das lässt sich nicht rückgängig machen.</p>
            <form method="post" class="invite-form" onsubmit="return confirm('Konto wirklich löschen?');">
                <input type="hidden" name="action" value="delete_account">
                <input type="text" name="confirm" required placeholder="Zur Bestätigung LÖSCHEN eingeben">
                <button type="submit" class="btn btn-pink btn-block">Konto endgültig löschen</button>
            </form>
        </section>
    </div>
</div>
<script src="/js/bigtext.js"></script>
</body>
</html>
<!-- Version 1.5 -->
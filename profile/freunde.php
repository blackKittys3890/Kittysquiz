<?php
// Version 1.3
// Freundesystem: Freunde per Benutzername hinzufügen, Anfragen annehmen oder ablehnen, Freunde entfernen.
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/auth.php';

$user = $_SESSION['discord_user'] ?? null;
if (!$user) { header('Location: /index.php'); exit; }
$me = (string)$user['id'];
$msg = '';
$err = '';

// Paar immer in gleicher Reihenfolge speichern
function friend_pair(string $a, string $b): array
{
    return strcmp($a, $b) < 0 ? [$a, $b] : [$b, $a];
}

function friend_row(string $a, string $b): ?array
{
    [$x, $y] = friend_pair($a, $b);
    $st = db()->prepare('SELECT * FROM kr_friends WHERE user_a = ? AND user_b = ?');
    $st->execute([$x, $y]);
    return $st->fetch() ?: null;
}

// E-Mail an die Empfängerin / den Empfänger, nur wenn sie/er dem ausdrücklich zugestimmt hat
function friend_mail_notify(string $toKey, string $fromName): void
{
    $st = db()->prepare('SELECT notify_friend_mail FROM kr_profiles WHERE user_key = ?');
    $st->execute([$toKey]);
    if ((int)$st->fetchColumn() !== 1) return;

    $to = function_exists('auth_email_for') ? auth_email_for($toKey) : null;
    if (!$to || !filter_var($to, FILTER_VALIDATE_EMAIL)) return;

    $app = defined('APP_NAME') ? APP_NAME : 'KittysRätsel';
    $name = trim((string)preg_replace('/[\r\n]+/', ' ', $fromName));
    $subject = '=?UTF-8?B?' . base64_encode($app . ': Neue Freundschaftsanfrage') . '?=';
    $body = "Hallo,\n\n" . $name . " hat dir eine Freundschaftsanfrage geschickt.\n\n"
        . "Du kannst sie hier annehmen oder ablehnen:\nhttps://kittysquiz.app/profile/freunde.php\n\n"
        . "Diese Benachrichtigungen kannst du auf der Freunde-Seite jederzeit abschalten.\n";
    $headers = "From: " . $app . " <noreply@kittysquiz.app>\r\n"
        . "Content-Type: text/plain; charset=UTF-8\r\n"
        . "Content-Transfer-Encoding: 8bit";
    @mail($to, $subject, $body, $headers);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $handle = strtolower(trim($_POST['handle'] ?? ''));
        $st = db()->prepare('SELECT user_key FROM kr_profiles WHERE handle = ?');
        $st->execute([$handle]);
        $other = $st->fetchColumn();
        if (!$other) {
            $err = 'Kein Konto mit diesem Benutzernamen.';
        } elseif ($other === $me) {
            $err = 'Du kannst dich nicht selbst hinzufügen.';
        } else {
            $row = friend_row($me, (string)$other);
            if ($row && $row['status'] === 'accepted') {
                $err = 'Ihr seid schon befreundet.';
            } elseif ($row && $row['requester'] !== $me) {
                // Die andere Person hat schon angefragt: direkt annehmen
                db()->prepare('UPDATE kr_friends SET status = ? WHERE id = ?')->execute(['accepted', $row['id']]);
                $msg = 'Ihr seid jetzt befreundet.';
            } elseif ($row) {
                $err = 'Die Anfrage wurde schon gesendet.';
            } else {
                [$x, $y] = friend_pair($me, (string)$other);
                db()->prepare('INSERT INTO kr_friends (user_a, user_b, requester, status) VALUES (?, ?, ?, ?)')
                    ->execute([$x, $y, $me, 'pending']);
                $msg = 'Freundschaftsanfrage gesendet.';
                $st = db()->prepare('SELECT display_name FROM kr_profiles WHERE user_key = ?');
                $st->execute([$me]);
                friend_mail_notify((string)$other, (string)($st->fetchColumn() ?: 'Ein Spieler'));
            }
        }
    }

    elseif ($action === 'mail_toggle') {
        // Zustimmung zur E-Mail bei Freundschaftsanfragen, nur auf ausdrücklichen Klick
        $on = isset($_POST['mail']) ? 1 : 0;
        db()->prepare('UPDATE kr_profiles SET notify_friend_mail = ? WHERE user_key = ?')->execute([$on, $me]);
        $msg = $on ? 'E-Mail-Benachrichtigung ist an.' : 'E-Mail-Benachrichtigung ist aus.';
    }

    elseif ($action === 'accept' || $action === 'decline' || $action === 'remove') {
        $other = (string)($_POST['other'] ?? '');
        $row = $other ? friend_row($me, $other) : null;
        if (!$row) {
            $err = 'Diese Verbindung gibt es nicht mehr.';
        } elseif ($action === 'accept') {
            if ($row['requester'] === $me || $row['status'] !== 'pending') {
                $err = 'Diese Anfrage kannst du nicht annehmen.';
            } else {
                db()->prepare('UPDATE kr_friends SET status = ? WHERE id = ?')->execute(['accepted', $row['id']]);
                $msg = 'Freundschaft angenommen.';
            }
        } else {
            // Ablehnen, zurückziehen oder entfernen
            db()->prepare('DELETE FROM kr_friends WHERE id = ?')->execute([$row['id']]);
            $msg = $action === 'remove' ? 'Freund entfernt.' : 'Anfrage entfernt.';
        }
    }
}

// Alle Verbindungen laden
$st = db()->prepare('SELECT user_a, user_b, requester, status FROM kr_friends WHERE user_a = ? OR user_b = ? ORDER BY id DESC');
$st->execute([$me, $me]);
$friends = []; $incoming = []; $outgoing = [];
foreach ($st->fetchAll() as $r) {
    $other = $r['user_a'] === $me ? $r['user_b'] : $r['user_a'];
    $p = db()->prepare('SELECT display_name, handle, avatar FROM kr_profiles WHERE user_key = ?');
    $p->execute([$other]);
    $info = $p->fetch() ?: ['display_name' => 'Spieler', 'handle' => '', 'avatar' => null];
    $entry = ['key' => $other, 'name' => $info['display_name'], 'handle' => $info['handle'], 'avatar' => $info['avatar'],
              'badges' => user_badges($other)];
    if ($r['status'] === 'accepted') $friends[] = $entry;
    elseif ($r['requester'] === $me) $outgoing[] = $entry;
    else $incoming[] = $entry;
}

function friend_card(array $f, string $buttons): string
{
    $img = $f['avatar']
        ? '<img src="' . htmlspecialchars($f['avatar']) . '" alt="" style="width:40px;height:40px;border-radius:50%;object-fit:cover;">'
        : '<span style="display:inline-block;width:40px;height:40px;border-radius:50%;background:var(--card-strong);text-align:center;line-height:40px;font-weight:700;">' . htmlspecialchars(mb_strtoupper(mb_substr($f['name'], 0, 1))) . '</span>';
    return '<div class="room" style="margin-bottom:8px;"><div style="display:flex;gap:10px;align-items:center;">' . $img
        . '<div><strong>' . htmlspecialchars($f['name']) . '</strong> ' . badge_imgs($f['badges'])
        . '<div class="muted">@' . htmlspecialchars($f['handle']) . '</div></div></div><div>' . $buttons . '</div></div>';
}

// Einstellung E-Mail-Benachrichtigung laden
$st = db()->prepare('SELECT notify_friend_mail FROM kr_profiles WHERE user_key = ?');
$st->execute([$me]);
$mailOn = (int)$st->fetchColumn() === 1;
$hasMail = function_exists('auth_email_for') && (bool)auth_email_for($me);

// Spielersuche (mindestens 3 Zeichen, eigenes Konto ausgeschlossen)
$q = trim((string)($_GET['q'] ?? ''));
$searched = false;
$results = [];
if ($q !== '') {
    $searched = mb_strlen($q) >= 3;
    if ($searched) {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $st = db()->prepare('SELECT user_key, display_name, handle, avatar FROM kr_profiles
                             WHERE (handle LIKE ? OR display_name LIKE ?) AND user_key <> ?
                             ORDER BY handle LIMIT 10');
        $st->execute([$like, $like, $me]);
        foreach ($st->fetchAll() as $p) {
            $results[] = ['key' => (string)$p['user_key'], 'name' => $p['display_name'] ?: 'Spieler',
                          'handle' => (string)$p['handle'], 'avatar' => $p['avatar'],
                          'badges' => user_badges((string)$p['user_key'])];
        }
    }
}
foreach ($results as &$r) {
    $row = friend_row($me, $r['key']);
    if ($row && $row['status'] === 'accepted') {
        $r['buttons'] = '<span class="muted">Schon befreundet</span>';
    } elseif ($row && $row['requester'] === $me) {
        $r['buttons'] = '<span class="muted">Anfrage gesendet</span>';
    } elseif ($row) {
        $r['buttons'] = '<form method="post" class="inline"><input type="hidden" name="action" value="accept"><input type="hidden" name="other" value="' . htmlspecialchars($r['key']) . '"><button class="btn btn-small" type="submit">Annehmen</button></form>';
    } elseif ($r['handle'] !== '') {
        $r['buttons'] = '<form method="post" class="inline"><input type="hidden" name="action" value="add"><input type="hidden" name="handle" value="' . htmlspecialchars($r['handle']) . '"><button class="btn btn-pink btn-small" type="submit">Anfrage senden</button></form>';
    } else {
        $r['buttons'] = '<span class="muted">Kein @-Name</span>';
    }
}
unset($r);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Freunde – <?= htmlspecialchars(defined('APP_NAME') ? APP_NAME : 'KittysRätsel') ?></title>
    <link rel="stylesheet" href="/css/base.css?v=1.6">
    <link rel="stylesheet" href="/css/lobby.css?v=1.1">
</head>
<body>
<div class="page">
    <header class="topbar">
        <div class="brand">Freunde</div>
        <a class="btn btn-ghost btn-small" href="/lobby.php">Zur Lobby</a>
    </header>

    <?php if ($msg): ?><div class="card msg" style="margin-bottom:18px;"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="card error" style="margin-bottom:18px;"><?= htmlspecialchars($err) ?></div><?php endif; ?>

    <div class="stack">
        <section class="card">
            <h2>Freunde finden</h2>
            <p class="muted">Suche nach @-Name oder Anzeigename (mindestens 3 Zeichen) und sende direkt eine Anfrage.</p>
            <form method="post" style="margin-bottom:14px;">
                <input type="hidden" name="action" value="mail_toggle">
                <label><input type="checkbox" name="mail" value="1" <?= $mailOn ? 'checked' : '' ?>> Mir per E-Mail Bescheid geben, wenn mir jemand eine Freundschaftsanfrage schickt</label>
                <button type="submit" class="btn btn-ghost btn-small">Speichern</button>
                <?php if (!$hasMail): ?><p class="muted" style="margin-top:6px;">Dafür brauchst du eine E-Mail-Adresse im Profil.</p><?php endif; ?>
            </form>
            <form method="get" class="join-row">
                <input type="text" name="q" value="<?= htmlspecialchars($q) ?>" minlength="3" maxlength="40" placeholder="Name oder @benutzername">
                <button type="submit" class="btn btn-small">Suchen</button>
            </form>
            <?php if ($searched && !$results): ?><p class="muted" style="margin-top:12px;">Keine Treffer.</p><?php endif; ?>
            <?php if ($q !== '' && !$searched): ?><p class="muted" style="margin-top:12px;">Bitte mindestens 3 Zeichen eingeben.</p><?php endif; ?>
            <?php foreach ($results as $r): ?>
                <div style="margin-top:12px;"><?= friend_card($r, $r['buttons']) ?></div>
            <?php endforeach; ?>
        </section>

        <?php if ($incoming): ?>
        <section class="card">
            <h2>Anfragen</h2>
            <?php foreach ($incoming as $f): ?>
                <?= friend_card($f, '<form method="post" class="inline"><input type="hidden" name="action" value="accept"><input type="hidden" name="other" value="' . htmlspecialchars($f['key']) . '"><button class="btn btn-small" type="submit">Annehmen</button></form> <form method="post" class="inline"><input type="hidden" name="action" value="decline"><input type="hidden" name="other" value="' . htmlspecialchars($f['key']) . '"><button class="btn btn-ghost btn-small" type="submit">Ablehnen</button></form>') ?>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>

        <section class="card">
            <h2>Deine Freunde</h2>
            <?php if (!$friends): ?><p class="muted">Noch keine Freunde.</p><?php endif; ?>
            <?php foreach ($friends as $f): ?>
                <?= friend_card($f, '<form method="post" class="inline" onsubmit="return confirm(\'Freund wirklich entfernen?\');"><input type="hidden" name="action" value="remove"><input type="hidden" name="other" value="' . htmlspecialchars($f['key']) . '"><button class="btn btn-ghost btn-small" type="submit">Entfernen</button></form>') ?>
            <?php endforeach; ?>
        </section>

        <?php if ($outgoing): ?>
        <section class="card">
            <h2>Gesendete Anfragen</h2>
            <?php foreach ($outgoing as $f): ?>
                <?= friend_card($f, '<form method="post" class="inline"><input type="hidden" name="action" value="decline"><input type="hidden" name="other" value="' . htmlspecialchars($f['key']) . '"><button class="btn btn-ghost btn-small" type="submit">Zurückziehen</button></form>') ?>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
<!-- Version 1.3 -->
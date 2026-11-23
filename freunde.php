<?php
// Version 1.0
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
            }
        }
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
            <h2>Freund hinzufügen</h2>
            <p class="muted">Gib den Benutzernamen (@-Name) der Person ein.</p>
            <form method="post" class="join-row">
                <input type="hidden" name="action" value="add">
                <input type="text" name="handle" required minlength="3" maxlength="20" placeholder="@benutzername">
                <button type="submit" class="btn btn-pink">Anfrage senden</button>
            </form>
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
<!-- Version 1.0 -->
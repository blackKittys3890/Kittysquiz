<?php
// Version 1.1
ini_set('display_errors', '1');
// Admin: Updates schreiben, bearbeiten, löschen und optional in den Discord-Kanal senden.
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/auth.php';

function ua_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$user = $_SESSION['discord_user'] ?? null;
if (!$user) { header('Location: /index.php'); exit; }
if (!auth_is_admin()) { http_response_code(403); exit('Nur für Admins.'); }

if (empty($_SESSION['ua_csrf'])) $_SESSION['ua_csrf'] = bin2hex(random_bytes(16));

// Nachricht an den Discord-Kanal (Webhook). Discord-Markdown bleibt erhalten, Erwähnungen werden nicht ausgelöst.
function updates_discord_send(int $id, string $title, string $body): void
{
    if (!defined('DISCORD_UPDATES_WEBHOOK') || DISCORD_UPDATES_WEBHOOK === '') {
        throw new RuntimeException('DISCORD_UPDATES_WEBHOOK fehlt in config.php.');
    }
    $url = 'https://kittysquiz.app/updates/updates.php#u' . $id;
    $desc = $body;
    if (mb_strlen($desc) > 3800) $desc = mb_substr($desc, 0, 3800) . '…';
    $desc .= "\n\n[Weiterlesen]($url)";
    $payload = [
        'username' => 'KittysRätsel Updates',
        'allowed_mentions' => ['parse' => []],
        'embeds' => [[
            'title' => mb_substr($title, 0, 250),
            'description' => $desc,
            'url' => $url,
            'color' => 0xFF6FB5,
            'timestamp' => gmdate('c'),
        ]],
    ];
    $ch = curl_init(DISCORD_UPDATES_WEBHOOK);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
    ]);
    $raw = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException('Discord nicht erreichbar: ' . $err);
    if ($code >= 300) throw new RuntimeException('Discord hat abgelehnt (HTTP ' . $code . '): ' . mb_substr((string)$raw, 0, 200));
    db()->prepare('UPDATE kr_updates SET discord_sent_at = NOW() WHERE id = ?')->execute([$id]);
}

$flash = $_SESSION['ua_flash'] ?? '';
$flashErr = $_SESSION['ua_flash_err'] ?? false;
unset($_SESSION['ua_flash'], $_SESSION['ua_flash_err']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfOk = hash_equals($_SESSION['ua_csrf'], (string)($_POST['csrf'] ?? ''));
    $action = (string)($_POST['action'] ?? '');
    $id = (int)($_POST['id'] ?? 0);
    try {
        if (!$csrfOk) throw new RuntimeException('Sitzung abgelaufen. Bitte Seite neu laden.');
        if ($action === 'save') {
            $title = trim((string)($_POST['title'] ?? ''));
            $body = trim((string)($_POST['body'] ?? ''));
            if ($title === '' || mb_strlen($title) > 200 || $body === '') {
                throw new RuntimeException('Bitte Titel (höchstens 200 Zeichen) und Text ausfüllen.');
            }
            if ($id > 0) {
                db()->prepare('UPDATE kr_updates SET title = ?, body = ? WHERE id = ?')->execute([$title, $body, $id]);
                $newId = $id;
                $msg = 'Update gespeichert.';
            } else {
                db()->prepare('INSERT INTO kr_updates (title, body) VALUES (?, ?)')->execute([$title, $body]);
                $newId = (int)db()->lastInsertId();
                $msg = 'Update veröffentlicht.';
            }
            if (!empty($_POST['to_discord'])) {
                updates_discord_send($newId, $title, $body);
                $msg .= ' Auf Discord gesendet.';
            }
            $_SESSION['ua_flash'] = $msg;
        } elseif ($action === 'discord') {
            $st = db()->prepare('SELECT title, body FROM kr_updates WHERE id = ?');
            $st->execute([$id]);
            $row = $st->fetch();
            if (!$row) throw new RuntimeException('Update nicht gefunden.');
            updates_discord_send($id, (string)$row['title'], (string)$row['body']);
            $_SESSION['ua_flash'] = 'Auf Discord gesendet.';
        } elseif ($action === 'delete') {
            db()->prepare('DELETE FROM kr_updates WHERE id = ?')->execute([$id]);
            $_SESSION['ua_flash'] = 'Update gelöscht.';
        } else {
            throw new RuntimeException('Unbekannte Aktion.');
        }
    } catch (Throwable $e) {
        $_SESSION['ua_flash'] = $e->getMessage();
        $_SESSION['ua_flash_err'] = true;
    }
    header('Location: /updates/updates_admin.php');
    exit;
}

$edit = null;
if (!empty($_GET['edit'])) {
    $st = db()->prepare('SELECT id, title, body FROM kr_updates WHERE id = ?');
    $st->execute([(int)$_GET['edit']]);
    $edit = $st->fetch() ?: null;
}
$list = db()->query('SELECT id, title, created_at, discord_sent_at FROM kr_updates ORDER BY created_at DESC, id DESC')->fetchAll();
$webhookOk = defined('DISCORD_UPDATES_WEBHOOK') && DISCORD_UPDATES_WEBHOOK !== '';
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Updates verwalten</title>
<style>
body { margin:0; font-family: system-ui, sans-serif; background:#1d1030; color:#fff; }
.wrap { max-width:820px; margin:0 auto; padding:14px; }
a { color:#ff9ad5; }
.card { background:rgba(255,255,255,.08); border-radius:16px; padding:16px; margin:12px 0; }
input[type=text], textarea { width:100%; box-sizing:border-box; padding:10px; margin-top:6px; border:none; border-radius:10px; background:#3b2a6b; color:#fff; font:inherit; }
textarea { min-height:180px; }
.btn { display:inline-block; background:#ff6fb5; color:#fff; border:none; border-radius:12px; padding:8px 14px; font-weight:700; text-decoration:none; cursor:pointer; font:inherit; font-weight:700; }
.btn.ghost { background:rgba(255,255,255,.15); }
.btn.red { background:#ff3b5c; }
.ok { color:#7fe7d9; font-weight:700; }
.err { color:#ff8a8a; font-weight:700; }
.muted { opacity:.7; font-size:14px; }
table { width:100%; border-collapse:collapse; }
td, th { padding:6px; border-bottom:1px solid rgba(255,255,255,.1); text-align:left; vertical-align:top; }
</style>
</head>
<body>
<div class="wrap">
    <p><a href="/lobby.php">← Lobby</a> · <a href="/updates/updates.php">Zur Updates-Seite</a></p>
    <h1>Updates verwalten</h1>

    <?php if ($flash): ?><div class="card <?= $flashErr ? 'err' : 'ok' ?>"><?= ua_h($flash) ?></div><?php endif; ?>
    <?php if (!$webhookOk): ?><div class="card err">DISCORD_UPDATES_WEBHOOK fehlt in config.php. Ohne den Eintrag geht nur die Veröffentlichung auf der Seite.</div><?php endif; ?>

    <div class="card">
        <h2><?= $edit ? 'Update bearbeiten' : 'Neues Update' ?></h2>
        <form method="post" action="/updates/updates_admin.php">
            <input type="hidden" name="csrf" value="<?= ua_h($_SESSION['ua_csrf']) ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
            <label class="muted">Titel<input type="text" name="title" maxlength="200" required value="<?= ua_h($edit['title'] ?? '') ?>"></label>
            <label class="muted" style="display:block;margin-top:10px">Text<textarea name="body" required><?= ua_h($edit['body'] ?? '') ?></textarea></label>
            <label style="display:flex;align-items:center;gap:8px;margin-top:10px">
                <input type="checkbox" name="to_discord" value="1" <?= $webhookOk ? 'checked' : 'disabled' ?>> Zusätzlich in den Discord-Kanal senden
            </label>
            <button class="btn" type="submit" style="margin-top:12px">Speichern</button>
            <?php if ($edit): ?> <a class="btn ghost" href="/updates/updates_admin.php">Abbrechen</a><?php endif; ?>
        </form>
    </div>

    <div class="card">
        <h2>Alle Updates</h2>
        <table>
            <tr><th>Titel</th><th>Datum</th><th>Discord</th><th></th></tr>
            <?php foreach ($list as $r): ?>
                <tr>
                    <td><?= ua_h($r['title']) ?></td>
                    <td class="muted"><?= ua_h(date('d.m.Y H:i', strtotime((string)$r['created_at']))) ?></td>
                    <td class="muted"><?= $r['discord_sent_at'] ? 'gesendet' : '–' ?></td>
                    <td>
                        <a class="btn ghost" href="?edit=<?= (int)$r['id'] ?>">Bearbeiten</a>
                        <?php if (!$r['discord_sent_at'] && $webhookOk): ?>
                            <form method="post" style="display:inline">
                                <input type="hidden" name="csrf" value="<?= ua_h($_SESSION['ua_csrf']) ?>">
                                <input type="hidden" name="action" value="discord">
                                <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                                <button class="btn" type="submit">An Discord</button>
                            </form>
                        <?php endif; ?>
                        <form method="post" style="display:inline" onsubmit="return confirm('Update löschen?');">
                            <input type="hidden" name="csrf" value="<?= ua_h($_SESSION['ua_csrf']) ?>">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                            <button class="btn red" type="submit">Löschen</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
</div>
</body>
</html>
<!-- Version 1.1 -->
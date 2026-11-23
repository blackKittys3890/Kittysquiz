<?php
// Version 3.15
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
remember_restore();
require_email();

$user = $_SESSION['discord_user'] ?? null;
if (!$user) {
    header('Location: index.php');
    exit;
}
$me = $user['id'];

// Raum erstellen
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create') {
    $code = strtoupper(substr(str_shuffle('ABCDEFGHJKLMNPQRSTUVWXYZ23456789'), 0, 6));
    db()->prepare('INSERT INTO kr_rooms (code, host_id) VALUES (?, ?)')->execute([$code, $me]);
    header('Location: /modes/punkte/board.php?code=' . $code);
    exit;
}

// Räume laden, bei denen der Host dabei ist
$stmt = db()->prepare('SELECT code, created_at FROM kr_rooms WHERE host_id = ? ORDER BY created_at DESC');
$stmt->execute([$me]);
$myRooms = $stmt->fetchAll();

// Benachrichtigungen (Glocke): offene Freundschaftsanfragen, weitere Arten können hier ergänzt werden
$notes = [];
try {
    $st = db()->prepare('SELECT k.requester, p.display_name, p.handle FROM kr_friends k
                         LEFT JOIN kr_profiles p ON p.user_key = k.requester
                         WHERE (k.user_a = ? OR k.user_b = ?) AND k.status = ? AND k.requester <> ?
                         ORDER BY k.id DESC LIMIT 20');
    $st->execute([$me, $me, 'pending', $me]);
    foreach ($st->fetchAll() as $n) {
        $notes[] = 'Freundschaftsanfrage von ' . ($n['display_name'] ?: 'Spieler')
            . ($n['handle'] ? ' (@' . $n['handle'] . ')' : '');
    }
} catch (Throwable $e) {
    $notes = []; // Tabelle kr_friends fehlt noch, Lobby bleibt trotzdem benutzbar
}
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lobby – <?= APP_NAME ?></title>
    <link rel="stylesheet" href="/css/base.css?v=1.4">
    <link rel="stylesheet" href="/css/lobby.css?v=1.1">
    <style>
        .bell { position: relative; display: inline-block; margin-right: 8px; }
        .bell summary { list-style: none; cursor: pointer; }
        .bell summary::-webkit-details-marker { display: none; }
        .bell-btn { position: relative; display: inline-flex; align-items: center; justify-content: center; width: 38px; height: 38px; border-radius: 50%; background: var(--card); color: inherit; }
        .bell-count { position: absolute; top: -4px; right: -4px; min-width: 18px; height: 18px; padding: 0 5px; border-radius: 9px; background: var(--pink); color: #fff; font-size: 12px; font-weight: 700; line-height: 18px; text-align: center; }
        .bell-menu { position: absolute; right: 0; top: 46px; z-index: 50; min-width: 260px; max-width: 85vw; padding: 10px; border-radius: 14px; background: var(--card-strong, #fff); box-shadow: 0 8px 24px rgba(0, 0, 0, .25); display: flex; flex-direction: column; gap: 4px; }
        .bell-menu a { padding: 8px; border-radius: 8px; color: inherit; text-decoration: none; }
        .bell-menu a:hover { background: rgba(0, 0, 0, .06); }
    </style>
</head>
<body>
<div class="page">

    <header class="topbar">
        <div class="brand"><?php if (file_exists(__DIR__ . '/img/logo.png')): ?><img src="/img/logo.png" alt="" class="logo-img logo-img-small"><?php endif; ?><span><?= APP_NAME ?></span></div>
        <div class="who">
            <details class="bell">
                <summary class="bell-btn" aria-label="Benachrichtigungen">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
                    <?php if ($notes): ?><span class="bell-count"><?= count($notes) ?></span><?php endif; ?>
                </summary>
                <div class="bell-menu">
                    <?php if (!$notes): ?><p class="muted">Keine neuen Benachrichtigungen.</p><?php endif; ?>
                    <?php foreach ($notes as $t): ?>
                        <a href="/profile/freunde.php"><?= htmlspecialchars($t) ?></a>
                    <?php endforeach; ?>
                </div>
            </details>
            <span class="name"><?= htmlspecialchars($user['name'] ?? $user['username'] ?? '') ?></span>
            <a class="btn btn-ghost btn-small" href="logout.php">Abmelden</a>
        </div>
    </header>

    <?php if (!empty($_GET['msg'])): ?><div class="card msg" style="margin-bottom:18px;"><?= htmlspecialchars($_GET['msg']) ?></div><?php endif; ?>

    <div id="rejoin" class="banner">
        <span id="rejoinText" class="banner-text"></span>
        <button id="rejoinBtn" class="btn btn-pink btn-small" type="button">Wieder beitreten</button>
        <button id="rejoinX" class="btn btn-ghost btn-small" type="button" title="Ausblenden">×</button>
    </div>

    <div class="grid">

        <div class="stack">
            <section class="card">
                <h2>Neuer Raum</h2>
                <p class="muted">Erstelle einen Raum und lade Mitspieler mit dem Code ein.</p>
                <form method="post">
                    <input type="hidden" name="action" value="create">
                    <button type="submit" class="btn btn-block">Neuen Raum gründen</button>
                </form>
            </section>

            <section class="card">
                <h2>Raum beitreten</h2>
                <form method="get" action="/modes/punkte/board.php" class="join-row">
                    <input type="text" name="code" placeholder="Code, z. B. AB12CD" maxlength="8" required>
                    <button type="submit" class="btn btn-pink">Beitreten</button>
                </form>
            </section>
        </div>

        <section class="card">
            <h2>Deine Räume (als Host)</h2>
            <?php if (!$myRooms): ?>
                <p class="muted">Du hast noch keine Räume erstellt.</p>
            <?php else: ?>
                <div class="room-list">
                    <?php foreach ($myRooms as $r): ?>
                        <div class="room">
                            <div>
                                <div class="code"><?= htmlspecialchars($r['code']) ?></div>
                                <div class="date">Erstellt am <?= date('d.m.Y H:i', strtotime($r['created_at'])) ?></div>
                            </div>
                            <a href="/modes/punkte/board.php?code=<?= htmlspecialchars($r['code']) ?>" class="btn btn-small">Öffnen</a>
                            <form method="post" action="/raum_loeschen.php" class="inline" onsubmit="return confirm('Raum wirklich löschen? Alle Spieldaten gehen verloren.');">
                                <input type="hidden" name="code" value="<?= htmlspecialchars($r['code']) ?>">
                                <button type="submit" class="btn btn-ghost btn-small">Löschen</button>
                            </form>
                        </div>
                        <details class="invite-box">
                            <summary>Einladen oder erinnern</summary>
                            <form method="post" action="/invite/invite.php" class="invite-form">
                                <input type="hidden" name="code" value="<?= htmlspecialchars($r['code']) ?>">
                                <textarea name="emails" rows="2" placeholder="E-Mail-Adressen, mit Komma oder Leerzeichen getrennt" required></textarea>
                                <select name="mode">
                                    <option value="invite">Jetzt einladen</option>
                                    <option value="remind">Erinnerung zum Termin</option>
                                </select>
                                <input type="datetime-local" name="remind_at" aria-label="Termin für die Erinnerung">
                                <button type="submit" class="btn btn-small">Senden</button>
                                <span class="muted">Max. 20 Mails pro Tag.</span>
                            </form>
                        </details>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

    </div>

    <div class="links" style="margin-top:22px;">
        <a href="/ideen.php">💡 Ideen</a>
        <a href="/shared/fragen/fragen.php">Kategorien & Fragen</a>
        <a href="verfuegbarkeit.php">Verfügbarkeit planen</a>
        <a href="/scoreboard.php">Scoreboard</a>
        <a href="/profile/freunde.php">Freunde</a>
        <a href="/profile/index.php">Mein Profil</a>
        <?php if (auth_is_admin()): ?>
            <a href="/admin/admin_modes.php">Modi verwalten</a>
            <a href="/admin/admin_ideen.php">Ideen verwalten</a>
        <?php endif; ?>
    </div>
</div>

<footer class="site-footer"><a href="/impressum.php">Impressum</a> · <a href="/datenschutz.php">Datenschutz</a> · <a href="https://whydonate.com/fundraising/kittysquiz" target="_blank" rel="noopener">❤️ Unterstützen</a></footer>

<script>
(function () {
    const PAGES = {
        points: '/modes/punkte/board.php',
        hearts: '/modes/herzen/herzen.php',
        eingestuhlt: '/modes/eingestuhlt/eingestuhlt.php',
        dumm_drauf: '/modes/hau_drauf/dumm_drauf.php'
    };
    const box = document.getElementById('rejoin');
    let data = null;
    try { data = JSON.parse(localStorage.getItem('kr_last_room') || 'null'); } catch (e) {}
    if (!data || !data.code || Date.now() - data.t > 24 * 3600 * 1000) return;

    const clear = () => { localStorage.removeItem('kr_last_room'); box.style.display = 'none'; };
    document.getElementById('rejoinText').textContent = 'Du warst zuletzt in Raum ' + data.code + '.';
    document.getElementById('rejoinX').onclick = clear;
    box.style.display = 'flex';

    document.getElementById('rejoinBtn').onclick = async () => {
        try {
            const res = await fetch('/api/room_mode.php?code=' + encodeURIComponent(data.code), { cache: 'no-store' });
            if (!res.ok) { clear(); alert('Dieser Raum existiert nicht mehr.'); return; }
            const j = await res.json();
            location.href = (PAGES[j.mode] || PAGES.points) + '?code=' + encodeURIComponent(data.code);
        } catch (e) {
            alert('Verbindungsfehler, bitte nochmal versuchen.');
        }
    };
})();
</script>
<script src="/js/cookie-hinweis.js"></script>
<script src="/js/bigtext.js"></script>
<?php require_once __DIR__ . '/profile_bubble.php'; ?>
</body>
</html>
<!-- Version 3.15 -->
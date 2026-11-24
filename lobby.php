<?php
// Version 3.20
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
        $notes[] = ['text' => 'Freundschaftsanfrage von ' . ($n['display_name'] ?: 'Spieler')
            . ($n['handle'] ? ' (@' . $n['handle'] . ')' : ''), 'href' => '/profile/freunde.php'];
    }

    // Raum-Einladungen von Freunden
    $st = db()->prepare('SELECT i.room_code, r.mode, p.display_name FROM kr_room_invites i
                         JOIN kr_rooms r ON r.code = i.room_code
                         LEFT JOIN kr_profiles p ON p.user_key = i.from_key
                         WHERE i.to_key = ? ORDER BY i.id DESC LIMIT 10');
    $st->execute([$me]);
    $pages = ['points' => '/modes/punkte/board.php', 'hearts' => '/modes/herzen/herzen.php',
              'eingestuhlt' => '/modes/eingestuhlt/eingestuhlt.php', 'dumm_drauf' => '/modes/hau_drauf/dumm_drauf.php'];
    foreach ($st->fetchAll() as $inv) {
        $page = $pages[(string)($inv['mode'] ?? 'points')] ?? '/modes/punkte/board.php';
        $notes[] = ['text' => ($inv['display_name'] ?: 'Ein Freund') . ' lädt dich in Raum ' . $inv['room_code'] . ' ein',
                    'href' => $page . '?code=' . urlencode($inv['room_code'])];
    }
} catch (Throwable $e) {
    $notes = []; // Tabellen fehlen noch, Lobby bleibt trotzdem benutzbar
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
        .top-icon { display: inline-flex; align-items: center; justify-content: center; width: 38px; height: 38px; border-radius: 50%; background: var(--card); color: inherit; text-decoration: none; font-size: 18px; margin-right: 8px; transition: transform .15s ease; }
        .top-icon { position: relative; transition: transform .2s ease, box-shadow .2s ease; }
        .top-icon span { display: inline-block; }
        .top-icon:hover, .top-icon:focus-visible { transform: translateY(-3px) scale(1.12); box-shadow: 0 0 0 3px rgba(61, 220, 151, .45), 0 8px 20px rgba(0, 0, 0, .3); }
        .top-icon:hover span, .top-icon:focus-visible span { animation: wiggle .5s ease; }
        .top-icon::after { content: attr(data-label); position: absolute; top: 46px; left: 50%; transform: translateX(-50%) translateY(-6px) scale(.85); opacity: 0; pointer-events: none; white-space: nowrap; padding: 6px 10px; border-radius: 10px; background: #1b1530; color: #fff; font-size: 13px; font-weight: 600; box-shadow: 0 6px 16px rgba(0, 0, 0, .35); transition: opacity .2s ease, transform .2s ease; z-index: 60; }
        .top-icon:hover::after, .top-icon:focus-visible::after { opacity: 1; transform: translateX(-50%) translateY(0) scale(1); }
        @keyframes wiggle { 0%, 100% { transform: rotate(0); } 25% { transform: rotate(-14deg); } 75% { transform: rotate(14deg); } }
        @media (max-width: 600px) {
            .top-icon { width: 34px; height: 34px; font-size: 16px; margin-right: 4px; }
            .top-icon::after { top: 40px; font-size: 12px; }
            .nav-grid { grid-template-columns: 1fr 1fr; }
            .nav-tile { padding: 12px; font-size: 14px; }
            .bell-menu { min-width: 220px; right: -40px; }
        }
        .menu-title { margin: 30px 0 12px; font-size: 14px; letter-spacing: .08em; text-transform: uppercase; opacity: .7; }
        .nav-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr)); gap: 12px; }
        .nav-tile { display: flex; align-items: center; gap: 12px; padding: 14px 16px; border-radius: 16px; background: var(--card); color: inherit; text-decoration: none; font-weight: 600; box-shadow: 0 4px 14px rgba(0, 0, 0, .15); transition: transform .15s ease, box-shadow .15s ease; }
        .nav-tile:hover { transform: translateY(-2px); box-shadow: 0 8px 20px rgba(0, 0, 0, .25); }
        .nav-icon { font-size: 22px; line-height: 1; width: 36px; height: 36px; display: inline-flex; align-items: center; justify-content: center; border-radius: 10px; background: rgba(255, 255, 255, .08); flex-shrink: 0; }    </style>
</head>
<body>
<div class="page">

    <header class="topbar">
        <div class="brand"><?php if (file_exists(__DIR__ . '/img/logo.png')): ?><img src="/img/logo.png" alt="" class="logo-img logo-img-small"><?php endif; ?><span><?= APP_NAME ?></span></div>
        <div class="who">
            <a class="top-icon" href="/ideen.php" data-label="Ideen" aria-label="Ideen"><span>💡</span></a>
            <a class="top-icon" href="/scoreboard.php" data-label="Scoreboard" aria-label="Scoreboard"><span>🏆</span></a>
            <a class="top-icon" href="/profile/freunde.php" data-label="Freunde" aria-label="Freunde"><span>👥</span></a>
            <details class="bell">
                <summary class="bell-btn" aria-label="Benachrichtigungen">
                    <svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
                    <?php if ($notes): ?><span class="bell-count"><?= count($notes) ?></span><?php endif; ?>
                </summary>
                <div class="bell-menu">
                    <?php if (!$notes): ?><p class="muted">Keine neuen Benachrichtigungen.</p><?php endif; ?>
                    <?php foreach ($notes as $t): ?>
                        <a href="<?= htmlspecialchars($t['href']) ?>"><?= htmlspecialchars($t['text']) ?></a>
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

    <h2 class="menu-title">Menü</h2>
    <nav class="nav-grid">
        <a class="nav-tile" href="/shared/fragen/fragen.php"><span class="nav-icon">📚</span>Kategorien &amp; Fragen</a>
        <a class="nav-tile" href="verfuegbarkeit.php"><span class="nav-icon">📅</span>Verfügbarkeit planen</a>
        <?php if (auth_is_admin()): ?>
            <a class="nav-tile" href="/admin/admin_modes.php"><span class="nav-icon">⚙️</span>Modi verwalten</a>
            <a class="nav-tile" href="/admin/admin_users.php"><span class="nav-icon">🎓</span>Benutzer &amp; Lehrer</a>
            <a class="nav-tile" href="/admin/admin_ideen.php"><span class="nav-icon">🗂️</span>Ideen verwalten</a>
        <?php endif; ?>
    </nav>
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
<script>
// Kurzer Ton beim Drüberfahren (und Antippen) eines Icons. Schaltbar über die Sound-Einstellung (kr_sound).
(function () {
    var ctx = null;
    function blip() {
        if (localStorage.getItem('kr_sound') === '0') return;
        try {
            ctx = ctx || new (window.AudioContext || window.webkitAudioContext)();
            if (ctx.state === 'suspended') ctx.resume();
            var t = ctx.currentTime;
            var o = ctx.createOscillator();
            var g = ctx.createGain();
            o.type = 'sine';
            o.frequency.setValueAtTime(660, t);
            o.frequency.exponentialRampToValueAtTime(990, t + 0.08);
            g.gain.setValueAtTime(0.0001, t);
            g.gain.exponentialRampToValueAtTime(0.12, t + 0.01);
            g.gain.exponentialRampToValueAtTime(0.0001, t + 0.12);
            o.connect(g);
            g.connect(ctx.destination);
            o.start(t);
            o.stop(t + 0.13);
        } catch (e) { /* Ton blockiert: ignorieren */ }
    }
    document.querySelectorAll('.top-icon').forEach(function (el) {
        el.addEventListener('pointerenter', blip);
        el.addEventListener('focus', blip);
    });
})();
</script>
</body>
</html>
<!-- Version 3.20 -->
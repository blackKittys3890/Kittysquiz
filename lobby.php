<?php
// Version 3.10
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
?>

<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lobby – <?= APP_NAME ?></title>
    <link rel="stylesheet" href="/css/base.css?v=1.2">
    <link rel="stylesheet" href="/css/lobby.css?v=1.1">
</head>
<body>
<div class="page">

    <header class="topbar">
        <div class="brand"><?php if (file_exists(__DIR__ . '/img/logo.png')): ?><img src="/img/logo.png" alt="" class="logo-img logo-img-small"><?php endif; ?><span><?= APP_NAME ?></span></div>
        <div class="who">
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
<!-- Version 3.10 -->
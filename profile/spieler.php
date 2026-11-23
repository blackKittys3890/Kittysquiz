<?php
// Version 1.0
// Öffentliche Profilseite eines Spielers (nur für angemeldete Nutzer): Name, @-Name, Profilbild, Badges.
// Erfolge folgen später.
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/auth.php';

$me = $_SESSION['discord_user'] ?? null;
if (!$me) { header('Location: /index.php'); exit; }

$handle = strtolower(trim((string)($_GET['handle'] ?? '')));
$p = null;
if ($handle !== '') {
    $st = db()->prepare('SELECT user_key, display_name, handle, avatar FROM kr_profiles WHERE handle = ?');
    $st->execute([$handle]);
    $p = $st->fetch() ?: null;
}

$isMe   = $p && (string)$p['user_key'] === (string)$me['id'];
$badges = $p ? user_badges((string)$p['user_key']) : [];
$name   = $p ? ($p['display_name'] ?: 'Spieler') : '';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($p ? $name : 'Spieler') ?> – <?= htmlspecialchars(defined('APP_NAME') ? APP_NAME : 'KittysRätsel') ?></title>
    <link rel="stylesheet" href="/css/base.css?v=1.6">
    <link rel="stylesheet" href="/css/lobby.css?v=1.1">
</head>
<body>
<div class="page">
    <header class="topbar">
        <div class="brand">Spielerprofil</div>
        <a class="btn btn-ghost btn-small" href="/lobby.php">Zur Lobby</a>
    </header>

    <div class="stack">
        <?php if (!$p): ?>
            <section class="card">
                <h2>Spieler nicht gefunden</h2>
                <p class="muted">Dieses Profil gibt es nicht oder der Benutzername ist falsch.</p>
            </section>
        <?php else: ?>
            <section class="card" style="display:flex;gap:18px;align-items:center;flex-wrap:wrap;">
                <?php if (!empty($p['avatar'])): ?>
                    <img src="<?= htmlspecialchars($p['avatar']) ?>" alt="" style="width:96px;height:96px;border-radius:50%;object-fit:cover;">
                <?php else: ?>
                    <span style="display:inline-block;width:96px;height:96px;border-radius:50%;background:var(--card-strong);text-align:center;line-height:96px;font-size:36px;font-weight:700;"><?= htmlspecialchars(mb_strtoupper(mb_substr($name, 0, 1))) ?></span>
                <?php endif; ?>
                <div>
                    <h2 style="margin:0;"><?= htmlspecialchars($name) ?> <?= badge_imgs($badges) ?></h2>
                    <div class="muted">@<?= htmlspecialchars((string)$p['handle']) ?></div>
                    <?php if ($isMe): ?>
                        <p style="margin:10px 0 0;"><a class="btn btn-ghost btn-small" href="/profile/index.php">Mein Profil bearbeiten</a></p>
                    <?php else: ?>
                        <p style="margin:10px 0 0;"><a class="btn btn-ghost btn-small" href="/profile/freunde.php">Freunde</a></p>
                    <?php endif; ?>
                </div>
            </section>

            <section class="card">
                <h2>Erfolge</h2>
                <p class="muted">Erfolge kommen bald.</p>
            </section>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
<!-- Version 1.0 -->
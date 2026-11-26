<?php
// Version 1.2
// Öffentliche Profilseite eines Spielers (nur für angemeldete Nutzer): Name, @-Name, Profilbild, Badges.
// Erfolge folgen später.
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/auth.php';

// Gäste haben keine Profilseiten: zurück in den Raum
if (is_guest()) {
    $back = (string)($_SESSION['guest_room_page'] ?? '');
    header('Location: ' . ($back !== '' ? $back : '/index.php'));
    exit;
}

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

// Erfolge aus der Spielhistorie: Runden, Siege und Gesamtpunkte
$achievements = [];
if ($p) {
    $rounds = 0; $wins = 0; $total = 0;
    $hs = db()->prepare('SELECT results FROM kr_history WHERE results LIKE ?');
    $hs->execute(['%"id":"' . addcslashes((string)$p['user_key'], '%_\\') . '"%']);
    foreach ($hs->fetchAll() as $row) {
        $teams = json_decode((string)$row['results'], true) ?: [];
        $max = null;
        foreach ($teams as $t) {
            $max = max($max ?? PHP_INT_MIN, (int)($t['points'] ?? 0));
        }
        foreach ($teams as $t) {
            $inTeam = false;
            foreach (($t['members'] ?? []) as $m) {
                if ((string)($m['id'] ?? '') === (string)$p['user_key']) { $inTeam = true; break; }
            }
            if (!$inTeam) continue;
            $rounds++;
            $pts = (int)($t['points'] ?? 0);
            $total += $pts;
            if ($pts === $max) $wins++;
            break;
        }
    }
    $achievements = [
        ['Erste Runde gespielt', $rounds >= 1],
        ['5 Runden gespielt', $rounds >= 5],
        ['20 Runden gespielt', $rounds >= 20],
        ['Erster Sieg', $wins >= 1],
        ['5 Siege', $wins >= 5],
        ['1.000 Punkte gesammelt', $total >= 1000],
        ['10.000 Punkte gesammelt', $total >= 10000],
    ];
}
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
                <ul style="list-style:none;padding:0;margin:0;">
                    <?php foreach ($achievements as [$label, $done]): ?>
                        <li style="padding:6px 0;<?= $done ? '' : 'opacity:.45;' ?>">
                            <?= $done ? '🏆' : '🔒' ?> <?= htmlspecialchars($label) ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
<!-- Version 1.2 -->
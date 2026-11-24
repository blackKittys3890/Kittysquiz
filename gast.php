<?php
// Version 1.0
// Gast-Beitritt über den Lehrer-Link: nur einen Namen eingeben, kein Konto nötig.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$pages = [
    'points'      => '/modes/punkte/board.php',
    'hearts'      => '/modes/herzen/herzen.php',
    'eingestuhlt' => '/modes/eingestuhlt/eingestuhlt.php',
    'dumm_drauf'  => '/modes/hau_drauf/dumm_drauf.php',
];

// Raum und Geheimschlüssel prüfen
$code  = strtoupper(trim((string)($_GET['code'] ?? '')));
$token = (string)($_GET['t'] ?? '');
$room  = null;
if ($code !== '' && $token !== '') {
    $st = db()->prepare('SELECT code, mode, guest_token FROM kr_rooms WHERE code = ?');
    $st->execute([$code]);
    $r = $st->fetch();
    if ($r && !empty($r['guest_token']) && hash_equals((string)$r['guest_token'], $token)) {
        $room = $r;
    }
}

if (!$room) {
    http_response_code(404);
    ?><!DOCTYPE html><html lang="de"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ungültiger Link</title><link rel="stylesheet" href="/css/base.css?v=1.6"></head><body>
    <div class="card" style="max-width:420px;margin:40px auto;"><h2>Ungültiger Link</h2>
    <p class="muted">Dieser Link ist ungültig oder wurde erneuert. Frag deine Lehrkraft nach dem aktuellen Link.</p></div>
    </body></html><?php
    exit;
}

$target = ($pages[(string)($room['mode'] ?? 'points')] ?? $pages['points']) . '?code=' . urlencode((string)$room['code']);

// Ein richtig angemeldetes Konto nicht durch einen Gast ersetzen
$current = $_SESSION['discord_user'] ?? null;
if ($current && empty($current['guest'])) {
    header('Location: ' . $target);
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim(preg_replace('/\s+/', ' ', (string)($_POST['name'] ?? '')));
    $name = mb_substr($name, 0, 20);
    if (mb_strlen($name) < 2) {
        $error = 'Bitte gib mindestens 2 Zeichen ein.';
    } else {
        // Gast-Sitzung: kein Konto, keine Profildaten, nur der Name für diese Runde
        session_regenerate_id(true);
        $_SESSION['discord_user'] = [
            'id'         => 'guest_' . bin2hex(random_bytes(8)),
            'name'       => $name,
            'username'   => $name,
            'avatar'     => null,
            'premium'    => 0,
            'decoration' => null,
            'guest'      => true,
        ];
        $_SESSION['guest_room_page'] = $target;
        header('Location: ' . $target);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Raum beitreten – <?= htmlspecialchars(defined('APP_NAME') ? APP_NAME : 'KittysRätsel') ?></title>
    <link rel="stylesheet" href="/css/base.css?v=1.6">
    <link rel="stylesheet" href="/css/lobby.css?v=1.1">
</head>
<body>
<div class="page">
    <section class="card" style="max-width:420px;margin:40px auto;">
        <h2>Raum <?= htmlspecialchars((string)$room['code']) ?> beitreten</h2>
        <p class="muted">Du spielst als Gast, ohne Konto. Dein Name wird nur für diese Runde verwendet.</p>
        <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
        <form method="post" class="join-row" style="margin-top:12px;">
            <input type="text" name="name" maxlength="20" required placeholder="Dein Name" value="<?= htmlspecialchars((string)($_POST['name'] ?? '')) ?>">
            <button type="submit" class="btn btn-pink">Beitreten</button>
        </form>
    </section>
</div>
</body>
</html>
<!-- Version 1.0 -->
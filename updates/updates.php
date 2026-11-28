<?php
// Version 1.1
// Öffentliche Updates-Seite (Blog). Beiträge schreibt der Admin in updates_admin.php.
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';

function up_h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

$rows = db()->query('SELECT id, title, body, created_at FROM kr_updates ORDER BY created_at DESC, id DESC LIMIT 100')->fetchAll();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Updates – <?= up_h(defined('APP_NAME') ? APP_NAME : 'KittysRätsel') ?></title>
    <style>
        body { margin: 0; padding: 22px; min-height: 100vh; font-family: system-ui, sans-serif; color: #fff;
            background: linear-gradient(160deg, #1d1b3a, #2c1046 60%, #14233b); }
        a { color: #9ff0ff; }
        .card { max-width: 700px; margin: 30px auto; background: rgba(255,255,255,.07); border-radius: 22px; padding: 26px; line-height: 1.6; }
        h1 { margin-top: 0; }
        .post { border-top: 1px solid rgba(255,255,255,.12); padding-top: 18px; margin-top: 18px; }
        .post h2 { font-size: 20px; color: #ffd23f; margin: 0 0 4px; }
        .date { opacity: .65; font-size: 14px; }
        .body { margin-top: 10px; overflow-wrap: anywhere; }
        .muted { opacity: .7; font-size: 14px; }
    </style>
</head>
<body>
    <div class="card">
        <p><a href="/index.php">← Zurück zur Startseite</a> · <a href="/changelog.php">Changelog</a></p>
        <h1>Updates</h1>
        <p class="muted">Neuigkeiten rund um KittysRätsel.</p>

        <?php if (!$rows): ?>
            <p>Noch keine Updates.</p>
        <?php endif; ?>

        <?php foreach ($rows as $r): ?>
            <article class="post" id="u<?= (int)$r['id'] ?>">
                <h2><?= up_h($r['title']) ?></h2>
                <div class="date"><?= up_h(date('d.m.Y, H:i', strtotime((string)$r['created_at']))) ?> Uhr</div>
                <div class="body"><?= nl2br(up_h($r['body'])) ?></div>
            </article>
        <?php endforeach; ?>
    </div>
</body>
</html>
<!-- Version 1.1 -->
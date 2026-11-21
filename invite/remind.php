<?php
// Version 1.1
// Verschickt fällige Erinnerungen. Läuft jede Minute per Cronjob.
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';
require_once __DIR__ . '/mail_helper.php';

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
    if (!defined('CLEANUP_KEY') || !hash_equals(CLEANUP_KEY, $_GET['key'] ?? '')) {
        http_response_code(403);
        exit("forbidden\n");
    }
}

$rows = db()->query('SELECT id, room_code, email FROM kr_reminders WHERE remind_at <= NOW() AND sent_at IS NULL ORDER BY remind_at LIMIT 50')->fetchAll();
$sent = 0;
foreach ($rows as $r) {
    $url = 'https://kittysquiz.app/invite/join.php?code=' . urlencode($r['room_code']);
    $text = "Hallo,\n\nDeine Runde bei KittysRätsel beginnt jetzt bzw. steht an.\n\n"
          . "Raum-Code: {$r['room_code']}\nDirekt beitreten: $url\n\nKittysRätsel\nhttps://kittysquiz.app\n";
    if (send_app_mail($r['email'], 'Erinnerung: KittysRätsel-Runde', $text)) {
        db()->prepare('UPDATE kr_reminders SET sent_at = NOW() WHERE id = ?')->execute([$r['id']]);
        $sent++;
    }
}
echo "Gesendet: $sent\n";
// Version 1.1
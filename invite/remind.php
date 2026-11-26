<?php
// Version 1.4
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
    if (send_invite_mail($r['email'], 'Erinnerung: KittysRätsel-Runde', $text)) {
        db()->prepare('UPDATE kr_reminders SET sent_at = NOW() WHERE id = ?')->execute([$r['id']]);
        $sent++;
    }
}
echo "Gesendet: $sent\n";

// Neue Ideen (von Webseite oder Discord) per Mail weiterleiten
const IDEA_MAIL = 'eliah@sudi.de';
$ideas = db()->query('SELECT id, source, author, text FROM kr_ideas WHERE mail_sent = 0 ORDER BY id LIMIT 20')->fetchAll();
$ideaSent = 0;
foreach ($ideas as $i) {
    $from = $i['author'] ?: 'Unbekannt';
    $body = "Neue Idee (" . $i['source'] . ") von " . $from . ":\n\n" . $i['text'] . "\n\nKittysRätsel\nhttps://kittysquiz.app\n";
    if (send_app_mail(IDEA_MAIL, 'Neue Idee von ' . $from, $body)) {
        db()->prepare('UPDATE kr_ideas SET mail_sent = 1 WHERE id = ?')->execute([$i['id']]);
        $ideaSent++;
    }
}
echo "Ideen-Mails: $ideaSent\n";

// Einmal täglich um 4:00 Uhr (deutsche Zeit) zusätzlich aufräumen,
// damit nur ein Cronjob nötig ist.
$now = new DateTime('now', new DateTimeZone('Europe/Berlin'));
if ((int)$now->format('H') === 4 && (int)$now->format('i') === 0) {
    echo "--- Aufräumen ---\n";
    include dirname(__DIR__) . '/cleanup_rooms.php';
}
// Version 1.4
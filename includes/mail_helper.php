<?php
// Version 1.0
// Gemeinsame Mail-Hilfe für Einladungen und Erinnerungen.

const MAIL_DAILY_LIMIT = 20;

function send_app_mail(string $to, string $subject, string $text): bool
{
    $headers = [
        'From: KittysRätsel <noreply@kittysquiz.app>',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
    ];
    return mail($to, mb_encode_mimeheader($subject, 'UTF-8'), $text, implode("\r\n", $headers));
}

// Wie viele Mails hat dieser Host in den letzten 24 Stunden schon ausgelöst?
function invite_used_today(string $hostKey): int
{
    $a = db()->prepare('SELECT COUNT(*) FROM kr_invites WHERE host_key = ? AND created_at > NOW() - INTERVAL 1 DAY');
    $a->execute([$hostKey]);
    $b = db()->prepare('SELECT COUNT(*) FROM kr_reminders WHERE host_key = ? AND remind_at > NOW() - INTERVAL 1 DAY');
    $b->execute([$hostKey]);
    return (int)$a->fetchColumn() + (int)$b->fetchColumn();
}

function parse_emails(string $raw): array
{
    $parts = preg_split('/[\s,;]+/', strtolower($raw), -1, PREG_SPLIT_NO_EMPTY);
    $ok = [];
    foreach ($parts as $p) {
        if (filter_var($p, FILTER_VALIDATE_EMAIL) && mb_strlen($p) <= 190) {
            $ok[] = $p;
        }
    }
    return array_values(array_unique($ok));
}
// Version 1.0
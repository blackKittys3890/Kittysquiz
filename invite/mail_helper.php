<?php
// Version 1.2
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
// Version 1.2

// Abmelde-Link für Einladungs- und Erinnerungsmails (nicht für Konto-Mails).
// Voraussetzung: MAIL_SECRET in config.php, z. B. define('MAIL_SECRET', 'langer-zufaelliger-text');
function mail_optout_key(string $email): string
{
    return hash_hmac('sha256', strtolower($email), MAIL_SECRET);
}

function mail_optout_url(string $email): ?string
{
    if (!defined('MAIL_SECRET') || MAIL_SECRET === '') return null;
    return 'https://kittysquiz.app/abmelden.php?e=' . rawurlencode(strtolower($email)) . '&k=' . mail_optout_key($email);
}

// Wie send_app_mail, aber: Abgemeldete bekommen nichts, alle anderen bekommen den Abmelde-Link
function send_invite_mail(string $to, string $subject, string $text): bool
{
    $to = strtolower($to);
    $st = db()->prepare('SELECT 1 FROM kr_mail_optout WHERE email = ?');
    $st->execute([$to]);
    if ($st->fetchColumn()) return false;
    $url = mail_optout_url($to);
    if ($url !== null) {
        $text .= "\n---\nKeine weiteren Einladungen per E-Mail: $url\n";
    }
    return send_app_mail($to, $subject, $text);
}

// Version 1.2
<?php
// Version 1.1
// Einladung und Erinnerung per E-Mail. Nur der Host eines Raums darf das.
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/auth.php';
require_once __DIR__ . '/mail_helper.php';

$user = $_SESSION['discord_user'] ?? null;
if (!$user) { header('Location: /index.php'); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: /lobby.php'); exit; }

$me = (string)$user['id'];
$code = strtoupper(trim($_POST['code'] ?? ''));
$mode = $_POST['mode'] ?? 'invite';          // invite = sofort, remind = später
$raw = (string)($_POST['emails'] ?? '');
$when = trim($_POST['remind_at'] ?? '');

function back(string $msg): void
{
    header('Location: /lobby.php?msg=' . urlencode($msg));
    exit;
}

// Host prüfen
$st = db()->prepare('SELECT id FROM kr_rooms WHERE code = ? AND host_id = ?');
$st->execute([$code, $me]);
if (!$st->fetchColumn()) back('Nur der Host kann Einladungen verschicken.');

$emails = parse_emails($raw);
if (!$emails) back('Keine gültige E-Mail-Adresse eingegeben.');
if (count($emails) > 20) back('Maximal 20 Adressen auf einmal.');

// Tageslimit
$left = MAIL_DAILY_LIMIT - invite_used_today($me);
if ($left <= 0) back('Tageslimit erreicht (20 Mails in 24 Stunden). Bitte morgen erneut versuchen.');
if (count($emails) > $left) back("Du kannst heute nur noch $left Mails verschicken.");

$joinUrl = 'https://kittysquiz.app/invite/join.php?code=' . urlencode($code);

if ($mode === 'remind') {
    $dt = DateTime::createFromFormat('Y-m-d\TH:i', $when);
    if (!$dt) back('Bitte Datum und Uhrzeit für die Erinnerung angeben.');
    if ($dt <= new DateTime()) back('Die Erinnerung muss in der Zukunft liegen.');
    foreach ($emails as $e) {
        db()->prepare('INSERT INTO kr_reminders (host_key, room_code, email, remind_at) VALUES (?, ?, ?, ?)')
            ->execute([$me, $code, $e, $dt->format('Y-m-d H:i:s')]);
    }
    back('Erinnerung für ' . count($emails) . ' Adresse(n) am ' . $dt->format('d.m.Y') . ' um ' . $dt->format('H:i') . ' Uhr gespeichert.');
}

// Sofort-Einladung
$hostName = $user['name'] ?? $user['username'] ?? 'Ein Spieler';
$subject = 'Einladung zu KittysRätsel';
$text = "Hallo,\n\n$hostName lädt dich zu einem Spiel auf KittysRätsel ein.\n\n"
      . "Raum-Code: $code\n"
      . "Direkt beitreten: $joinUrl\n\n"
      . "Du brauchst ein Konto (kostenlos). Wenn du die Einladung nicht erwartet hast, ignoriere diese Mail einfach.\n\n"
      . "KittysRätsel\nhttps://kittysquiz.app\n";

$sent = 0;
foreach ($emails as $e) {
    if (send_app_mail($e, $subject, $text)) {
        db()->prepare('INSERT INTO kr_invites (host_key, room_code, email) VALUES (?, ?, ?)')->execute([$me, $code, $e]);
        $sent++;
    }
}
back("Einladung an $sent von " . count($emails) . " Adresse(n) verschickt.");
// Version 1.1
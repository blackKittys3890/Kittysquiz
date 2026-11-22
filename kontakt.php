<?php
// Version 1.0
// Kontaktformular (zweite Kontaktmöglichkeit neben der E-Mail).
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/invite/mail_helper.php';

$sent = false;
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $mail = trim($_POST['email'] ?? '');
    $text = trim($_POST['message'] ?? '');
    // Honeypot: Bots füllen dieses Feld aus
    if (!empty($_POST['website'])) { $sent = true; }
    elseif (mb_strlen($name) < 2 || mb_strlen($name) > 100) $err = 'Bitte gib deinen Namen an.';
    elseif (!filter_var($mail, FILTER_VALIDATE_EMAIL)) $err = 'Bitte eine gültige E-Mail-Adresse angeben.';
    elseif (mb_strlen($text) < 10 || mb_strlen($text) > 3000) $err = 'Die Nachricht braucht 10 bis 3000 Zeichen.';
    else {
        $body = "Kontaktformular KittysRätsel\n\nVon: $name\nE-Mail: $mail\n\n$text\n";
        send_app_mail('eliah@sudi.de', 'Kontakt über KittysRätsel', $body);
        $sent = true;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kontakt – KittysRätsel</title>
    <link rel="stylesheet" href="/css/base.css?v=1.4">
    <link rel="stylesheet" href="/css/lobby.css?v=1.1">
</head>
<body>
<div class="page">
    <header class="topbar"><div class="brand">Kontakt</div><a class="btn btn-ghost btn-small" href="/index.php">Zur Startseite</a></header>
    <section class="card">
        <?php if ($sent): ?>
            <h2>Danke!</h2>
            <p>Deine Nachricht ist angekommen. Wir antworten an die angegebene E-Mail-Adresse.</p>
        <?php else: ?>
            <h2>Nachricht senden</h2>
            <?php if ($err): ?><p class="error"><?= htmlspecialchars($err) ?></p><?php endif; ?>
            <form method="post" class="invite-form">
                <input type="text" name="name" required maxlength="100" placeholder="Dein Name">
                <input type="email" name="email" required maxlength="190" placeholder="Deine E-Mail-Adresse">
                <textarea name="message" required minlength="10" maxlength="3000" rows="6" placeholder="Deine Nachricht"></textarea>
                <input type="text" name="website" autocomplete="off" tabindex="-1" style="display:none;">
                <p class="muted">Deine Angaben nutzen wir nur, um dir zu antworten. Mehr dazu in der <a href="/datenschutz.php">Datenschutzerklärung</a>.</p>
                <button type="submit" class="btn btn-block">Senden</button>
            </form>
        <?php endif; ?>
    </section>
</div>
</body>
</html>
<!-- Version 1.0 -->
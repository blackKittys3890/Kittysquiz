<?php
// Version 1.0
// Abmeldung von Einladungs- und Erinnerungsmails über den Link in der Mail.
ini_set('display_errors', '0');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$email = strtolower(trim((string)($_GET['e'] ?? '')));
$key = (string)($_GET['k'] ?? '');

$ok = false;
$text = 'Der Link ist ungültig.';
if (defined('MAIL_SECRET') && MAIL_SECRET !== '' && $email !== '' && $key !== '') {
    $expected = hash_hmac('sha256', $email, MAIL_SECRET);
    if (hash_equals($expected, $key)) {
        db()->prepare('INSERT IGNORE INTO kr_mail_optout (email) VALUES (?)')->execute([$email]);
        $ok = true;
        $text = 'Du bekommst keine weiteren Einladungen oder Erinnerungen per E-Mail mehr von uns.';
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Abmeldung – KittysRätsel</title>
    <link rel="stylesheet" href="/css/base.css?v=1.6">
</head>
<body>
<div class="page">
    <section class="card">
        <h2>Abmeldung</h2>
        <p><?= htmlspecialchars($text) ?></p>
        <?php if (!$ok): ?><p class="muted">Falls du dich abmelden willst, nutze bitte den Link aus der letzten Mail.</p><?php endif; ?>
    </section>
</div>
</body>
</html>
<!-- Version 1.0 -->
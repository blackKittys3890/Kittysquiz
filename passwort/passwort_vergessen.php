<?php
// Version 1.0
// Passwort vergessen: sendet einen Link zum Zurücksetzen an die hinterlegte E-Mail (nur lokale Konten).
ini_set('display_errors', '0');
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/invite/mail_helper.php';

$sent = false;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    // Die Antwort ist immer gleich, damit man nicht herausfinden kann, wer ein Konto hat
    $sent = true;
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $st = db()->prepare('SELECT user_key FROM kr_emails WHERE email = ?');
        $st->execute([$email]);
        $key = (string)$st->fetchColumn();
        if (str_starts_with($key, 'local_')) {
            db()->prepare('DELETE FROM kr_tokens WHERE user_key = ? AND type = ?')->execute([$key, 'pwreset']);
            $raw = bin2hex(random_bytes(32));
            db()->prepare('INSERT INTO kr_tokens (type, user_key, token_hash, payload, expires_at) VALUES (?, ?, ?, ?, NOW() + INTERVAL 1 HOUR)')
                ->execute(['pwreset', $key, hash('sha256', $raw), '{}']);
            $url = 'https://kittysquiz.app/passwort/passwort_reset.php?token=' . $raw;
            $text = "Hallo,\n\nüber diesen Link kannst du dein KittysRätsel-Passwort neu setzen (1 Stunde gültig):\n\n$url\n\n"
                . "Falls du das nicht angefordert hast, ignoriere diese Mail. Dein Passwort bleibt dann unverändert.\n\nKittysRätsel";
            send_app_mail($email, 'Passwort zurücksetzen', $text);
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Passwort vergessen – KittysRätsel</title>
    <link rel="stylesheet" href="/css/base.css?v=1.6">
</head>
<body>
<div class="page">
    <section class="card">
        <h2>Passwort vergessen</h2>
        <?php if ($sent): ?>
            <p>Wenn ein Konto mit dieser E-Mail-Adresse existiert, haben wir dir einen Link zum Zurücksetzen geschickt. Er ist 1 Stunde gültig.</p>
            <p><a href="/index.php">Zurück zur Anmeldung</a></p>
        <?php else: ?>
            <form method="post">
                <label>E-Mail-Adresse<br><input type="email" name="email" required></label>
                <p><button class="btn" type="submit">Link anfordern</button></p>
            </form>
        <?php endif; ?>
    </section>
</div>
</body>
</html>
<!-- Version 1.0 -->
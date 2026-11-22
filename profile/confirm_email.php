<?php
// Version 1.0
// Bestätigungslink aus der Profil-Mail: übernimmt die neue E-Mail-Adresse.
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';

$raw = $_GET['token'] ?? '';
$st = db()->prepare('SELECT id, user_key, payload FROM kr_tokens WHERE token_hash = ? AND type = ? AND expires_at > NOW()');
$st->execute([hash('sha256', $raw), 'email']);
$row = $st->fetch();

$text = 'Der Link ist ungültig oder abgelaufen.';
if ($row) {
    $data = json_decode($row['payload'], true);
    $new = $data['email'] ?? '';
    $check = db()->prepare('SELECT 1 FROM kr_emails WHERE email = ? AND user_key <> ?');
    $check->execute([$new, $row['user_key']]);
    if ($new && !$check->fetchColumn()) {
        db()->prepare('INSERT INTO kr_emails (user_key, email) VALUES (?, ?) ON DUPLICATE KEY UPDATE email = VALUES(email)')
            ->execute([$row['user_key'], $new]);
        $text = 'Deine neue E-Mail-Adresse ist bestätigt.';
    } else {
        $text = 'Diese E-Mail-Adresse wird inzwischen schon von einem anderen Konto verwendet.';
    }
    db()->prepare('DELETE FROM kr_tokens WHERE id = ?')->execute([$row['id']]);
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>E-Mail bestätigen – KittysRätsel</title>
    <link rel="stylesheet" href="/css/base.css?v=1.2">
    <link rel="stylesheet" href="/css/lobby.css?v=1.1">
</head>
<body>
<div class="page">
    <section class="card">
        <h2>E-Mail bestätigen</h2>
        <p><?= htmlspecialchars($text) ?></p>
        <p><a href="/profile/index.php">Zu meinem Profil</a></p>
    </section>
</div>
</body>
</html>
<!-- Version 1.0 -->
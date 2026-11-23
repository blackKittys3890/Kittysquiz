<?php
// Version 1.2
// Zeigt den Vector-Login, um ein weiteres Konto aus dem Profil heraus zu verbinden.
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/auth.php';

if (empty($_SESSION['discord_user'])) { header('Location: /index.php'); exit; }

// Vector schickt den Token nach dem Klick auf diese Seite zurück: weiter zur Prüfung.
// Der Verknüpfungsmodus steht noch in der Sitzung von der ersten Anfrage.
if (isset($_GET['id_token'])) {
    header('Location: /vector_callback.php?' . http_build_query([
        'id_token' => (string)$_GET['id_token'],
        'state'    => (string)($_GET['state'] ?? ''),
    ]));
    exit;
}

// Der Callback erkennt daran, dass ein Konto verknüpft werden soll
$_SESSION['link_mode'] = 'vector';

$clientId  = defined('VECTOR_CLIENT_ID') ? (string)VECTOR_CLIENT_ID : 'kittysquiz';
$widgetUrl = defined('VECTOR_WIDGET_URL') ? (string)VECTOR_WIDGET_URL : '';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Vector verbinden – <?= APP_NAME ?></title>
    <link rel="stylesheet" href="/css/base.css?v=1.4">
    <link rel="stylesheet" href="/css/lobby.css?v=1.1">
</head>
<body>
<div class="page">
    <header class="topbar">
        <div class="brand">Vector verbinden</div>
        <a class="btn btn-ghost btn-small" href="/profile/index.php">Zurück zum Profil</a>
    </header>

    <section class="card" style="max-width:520px;margin:0 auto;">
        <h2>Konto verbinden</h2>
        <p class="muted">Melde dich mit Vector an, um es mit deinem Profil zu verbinden. Danach kannst du dich auch damit anmelden.</p>
        <div style="display:flex;justify-content:center;margin-top:14px;">
            <div data-vector-login data-client-id="<?= htmlspecialchars($clientId) ?>"></div>
        </div>
        <script src="<?= htmlspecialchars($widgetUrl) ?>"></script>
    </section>
</div>
<footer class="site-footer"><a href="/impressum.php">Impressum</a> · <a href="/datenschutz.php">Datenschutz</a></footer>
</body>
</html>
<!-- Version 1.2 -->
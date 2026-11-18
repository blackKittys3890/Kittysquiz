<?php
// Version 1.0
session_start();
require_once __DIR__ . '/sso.php';

$data = sso_verify_token($_GET['token'] ?? '');
if (!$data) {
    die('Token ungültig oder abgelaufen.');
}

// Daten in der Session der Zielseite speichern
$_SESSION['discord_user'] = $data;

header('Location: index.php');
exit;
// Version 1.0
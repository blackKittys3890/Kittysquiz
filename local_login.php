<?php
// Version 1.0
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

$stmt = db()->prepare('SELECT id, username, avatar, password_hash FROM kr_users WHERE username = ?');
$stmt->execute([$username]);
$row = $stmt->fetch();

if (!$row || !password_verify($password, $row['password_hash'])) {
    $_SESSION['login_error'] = 'Benutzername oder Passwort ist falsch.';
    header('Location: index.php');
    exit;
}

session_regenerate_id(true);
$_SESSION['discord_user'] = [
    'id'       => 'local_' . $row['id'],
    'name'     => $row['username'],
    'username' => $row['username'],
    'avatar'   => $row['avatar'],
];

header('Location: lobby.php');
exit;
// Version 1.0
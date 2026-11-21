<?php
// Version 1.3
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

$login = trim($_POST['login'] ?? '');
$password = $_POST['password'] ?? '';
$row = null;

// Mit E-Mail oder Benutzername anmelden
if (str_contains($login, '@')) {
    $st = db()->prepare('SELECT user_key FROM kr_emails WHERE email = ?');
    $st->execute([strtolower($login)]);
    $key = (string)$st->fetchColumn();
    if (str_starts_with($key, 'local_')) {
        $st = db()->prepare('SELECT id, username, avatar, password_hash FROM kr_users WHERE id = ?');
        $st->execute([(int)substr($key, 6)]);
        $row = $st->fetch();
    }
} else {
    $st = db()->prepare('SELECT id, username, avatar, password_hash FROM kr_users WHERE username = ?');
    $st->execute([$login]);
    $row = $st->fetch();
}

if (!$row || !password_verify($password, $row['password_hash'])) {
    $_SESSION['login_error'] = 'Benutzername/E-Mail oder Passwort ist falsch.';
    header('Location: index.php');
    exit;
}

session_regenerate_id(true);
$user = [
    'id'         => 'local_' . $row['id'],
    'name'       => $row['username'],
    'username'   => $row['username'],
    'avatar'     => $row['avatar'],
    'premium'    => 0,
    'decoration' => null,
];
$_SESSION['discord_user'] = $user;

if (!empty($_POST['remember'])) {
    remember_set($user);
}

$target = $_SESSION['after_login'] ?? 'lobby.php';
unset($_SESSION['after_login']);
header('Location: ' . $target);
exit;
// Version 1.3
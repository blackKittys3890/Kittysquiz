<?php
// Version 1.0
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$error = '';
$allowedTypes = [
    'image/png'  => 'png',
    'image/jpeg' => 'jpg',
    'image/gif'  => 'gif',
    'image/webp' => 'webp',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $avatarName = null;

    if (!preg_match('/^[A-Za-z0-9_\-]{3,20}$/', $username)) {
        $error = 'Benutzername: 3 bis 20 Zeichen, nur Buchstaben, Zahlen, _ und -.';
    } elseif (strlen($password) < 8) {
        $error = 'Das Passwort muss mindestens 8 Zeichen haben.';
    }

    // Optionales Profilbild prüfen
    if (!$error && !empty($_FILES['avatar']['name'])) {
        $file = $_FILES['avatar'];
        $info = $file['error'] === UPLOAD_ERR_OK ? @getimagesize($file['tmp_name']) : false;

        if (!$info || !isset($allowedTypes[$info['mime']]) || $file['size'] > 2 * 1024 * 1024) {
            $error = 'Profilbild: nur PNG, JPG, GIF oder WebP bis 2 MB.';
        } else {
            $avatarName = bin2hex(random_bytes(12)) . '.' . $allowedTypes[$info['mime']];
        }
    }

    // Benutzername frei?
    if (!$error) {
        $stmt = db()->prepare('SELECT id FROM kr_users WHERE username = ?');
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            $error = 'Dieser Benutzername ist schon vergeben.';
        }
    }

    if (!$error) {
        if ($avatarName) {
            if (!is_dir(__DIR__ . '/uploads')) {
                mkdir(__DIR__ . '/uploads', 0755, true);
            }
            move_uploaded_file($_FILES['avatar']['tmp_name'], __DIR__ . '/uploads/' . $avatarName);
        }

        db()->prepare('INSERT INTO kr_users (username, password_hash, avatar) VALUES (?, ?, ?)')
            ->execute([$username, password_hash($password, PASSWORD_DEFAULT), $avatarName]);

        $id = db()->lastInsertId();
        session_regenerate_id(true);
        $_SESSION['discord_user'] = [
            'id'         => 'local_' . $id,
            'name'       => $username,
            'username'   => $username,
            'avatar'     => $avatarName,
            'premium'    => 0,
            'decoration' => null,
        ];

        header('Location: lobby.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Konto erstellen – <?= APP_NAME ?></title>
    <style>
        body { font-family: system-ui, sans-serif; background: linear-gradient(135deg, #2b1d4a, #4b2c6f);
               color: #f5f0ff; min-height: 100vh; margin: 0; display: flex; align-items: center; justify-content: center; }
        .card { background: rgba(255,255,255,.08); border-radius: 20px; padding: 36px; width: 330px; max-width: 90%; box-shadow: 0 10px 30px rgba(0,0,0,.4); }
        label { display: block; margin-top: 14px; font-size: 14px; }
        input { width: 100%; box-sizing: border-box; padding: 11px; margin-top: 6px; border: none; border-radius: 10px; }
        button { width: 100%; margin-top: 20px; padding: 12px; border: none; border-radius: 10px;
                 background: #ff3ea5; color: white; font-weight: bold; cursor: pointer; font-size: 15px; }
        button:hover { background: #d92b89; }
        .error { color: #ff8a8a; }
        a { color: #7fe7d9; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Konto erstellen</h2>

        <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>

        <form method="post" enctype="multipart/form-data">
            <label>Benutzername
                <input name="username" maxlength="20" required value="<?= htmlspecialchars($_POST['username'] ?? '') ?>">
            </label>
            <label>Passwort (mind. 8 Zeichen)
                <input name="password" type="password" minlength="8" required>
            </label>
            <label>Profilbild (optional)
                <input name="avatar" type="file" accept="image/png,image/jpeg,image/gif,image/webp">
            </label>
            <button type="submit">Konto erstellen</button>
        </form>

        <p><a href="index.php">Zurück zur Anmeldung</a></p>
    </div>
</body>
</html>
<!-- Version 1.0 -->

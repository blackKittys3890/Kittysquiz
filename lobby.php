<?php
// Version 1.1
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$user = $_SESSION['discord_user'] ?? null;
if (!$user) {
    header('Location: index.php');
    exit;
}

$error = '';

// Raum erstellen
if (isset($_POST['create'])) {
    $code = strtoupper(bin2hex(random_bytes(3)));
    $stmt = db()->prepare('INSERT INTO kr_rooms (code, host_id) VALUES (?, ?)');
    $stmt->execute([$code, $user['id']]);
    header('Location: raum.php?code=' . $code);
    exit;
}

// Raum beitreten
if (isset($_POST['join'])) {
    $code = strtoupper(trim($_POST['code'] ?? ''));
    $stmt = db()->prepare('SELECT id FROM kr_rooms WHERE code = ?');
    $stmt->execute([$code]);
    if ($stmt->fetch()) {
        header('Location: raum.php?code=' . $code);
        exit;
    }
    $error = 'Raum nicht gefunden.';
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Lobby – <?= APP_NAME ?></title>
    <style>
        body { font-family: system-ui, sans-serif; background: #2b1d4a; color: #f5f0ff;
               display: flex; justify-content: center; padding: 40px; margin: 0; }
        .card { background: rgba(255,255,255,.08); padding: 30px; border-radius: 16px; width: 360px; max-width: 90%; }
        input { padding: 10px; border-radius: 8px; border: none; width: 100%; box-sizing: border-box; }
        button { margin-top: 10px; padding: 10px 16px; border: none; border-radius: 8px;
                 background: #5865f2; color: white; font-weight: bold; cursor: pointer; width: 100%; }
        button:hover { background: #4752c4; }
        .error { color: #ff8a8a; }
        a { color: #c9b8ff; }
    </style>
</head>
<body>
    <div class="card">
        <h2>Hallo <?= htmlspecialchars($user['name'] ?? '') ?></h2>

        <form method="post">
            <button name="create" value="1">Neuen Raum erstellen</button>
        </form>

        <hr>

        <form method="post">
            <input name="code" placeholder="Raumcode eingeben" maxlength="8" required>
            <button name="join" value="1">Raum beitreten</button>
        </form>

        <?php if ($error): ?><p class="error"><?= htmlspecialchars($error) ?></p><?php endif; ?>
        <p><a href="logout.php">Abmelden</a></p>
    </div>
</body>
</html>
<!-- Version 1.1 -->
<?php
// Version 1.2
require_once dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/db.php';
require_once dirname(__DIR__) . '/auth.php';

$user = $_SESSION['discord_user'] ?? null;
if (!$user) { header('Location: /index.php'); exit; }
if (!auth_is_admin()) {
    http_response_code(403);
    exit('Kein Zugriff. <a href="/lobby.php">Zur Lobby</a>');
}

$msg = '';

// --- AKTIONEN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'change_status' && isset($_POST['id'], $_POST['status'])) {
        $stmt = db()->prepare('UPDATE kr_ideas SET status = ? WHERE id = ?');
        $stmt->execute([$_POST['status'], $_POST['id']]);
        $msg = 'Status aktualisiert.';
        
        // Wenn der Status auf "Abgeschlossen" gesetzt wurde, in die Bot-Queue schreiben
        if ($_POST['status'] === 'Abgeschlossen') {
            $stmt = db()->prepare('SELECT text FROM kr_ideas WHERE id = ?');
            $stmt->execute([$_POST['id']]);
            $idea = $stmt->fetch();
            
            if ($idea) {
                db()->prepare('INSERT INTO kr_bot_queue (action, payload) VALUES (?, ?)')
                    ->execute(['idea_completed', json_encode(['text' => $idea['text']])]);
                $msg = 'Status aktualisiert und an Discord gesendet.';
            }
        }
    } elseif ($_POST['action'] === 'delete' && isset($_POST['id'])) {
        $stmt = db()->prepare('DELETE FROM kr_ideas WHERE id = ?');
        $stmt->execute([$_POST['id']]);
        $msg = 'Idee gelöscht.';
    }
}

$ideas = db()->query('SELECT * FROM kr_ideas ORDER BY created_at DESC')->fetchAll();

$status_colors = [
    'Angenommen' => 'var(--mint)',
    'In Arbeit' => 'var(--yellow)',
    'Abgeschlossen' => '#38d26b',
    'Abgelehnt' => 'var(--pink)',
];
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Ideen verwalten – <?= APP_NAME ?></title>
    <link rel="stylesheet" href="/css/base.css?v=1.2">
    <link rel="stylesheet" href="/css/lobby.css?v=1.1">
</head>
<body>
<div class="page">
    <header class="topbar">
        <div class="brand">Ideen verwalten</div>
        <a class="btn btn-ghost btn-small" href="/lobby.php">Zur Lobby</a>
    </header>

    <?php if ($msg): ?><div class="card msg" style="margin-bottom:18px;"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

    <section class="card">
        <h2>Eingereichte Ideen</h2>
        <?php if (empty($ideas)): ?>
            <p class="muted">Noch keine Ideen vorhanden.</p>
        <?php endif; ?>

        <?php foreach ($ideas as $idea): 
            $status = $idea['status'] ?? 'Angenommen';
        ?>
            <div class="room" style="flex-direction: column; align-items: flex-start; gap: 10px; margin-bottom: 12px;">
                <div style="width: 100%; display: flex; justify-content: space-between; align-items: center;">
                    <div>
                        <strong style="color: <?= $status_colors[$status] ?>;"><?= $status ?></strong> 
                        <span class="muted" style="margin-left: 10px;">
                            <?= htmlspecialchars($idea['author'] ?: 'Anonym') ?> am <?= date('d.m.Y H:i', strtotime($idea['created_at'])) ?>
                        </span>
                    </div>
                    <form method="POST" onsubmit="return confirm('Wirklich löschen?');" style="margin:0;">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $idea['id'] ?>">
                        <button type="submit" class="btn btn-ghost btn-small" style="color:var(--pink);">Löschen</button>
                    </form>
                </div>
                
                <div style="white-space: pre-wrap; width: 100%;"><?= htmlspecialchars($idea['text']) ?></div>
                
                <form method="POST" style="display:flex; gap:8px; margin:0; width: 100%; border-top: 1px solid var(--line); padding-top: 10px;">
                    <input type="hidden" name="action" value="change_status">
                    <input type="hidden" name="id" value="<?= $idea['id'] ?>">
                    <select name="status" style="width: auto;">
                        <?php foreach (['Angenommen', 'In Arbeit', 'Abgeschlossen', 'Abgelehnt'] as $s): ?>
                            <option value="<?= $s ?>" <?= $status === $s ? 'selected' : '' ?>><?= $s ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-small" style="background:var(--mint); color:#1f1438;">Status ändern</button>
                </form>
            </div>
        <?php endforeach; ?>
    </section>
</div>
</body>
</html>
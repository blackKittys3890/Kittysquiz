<?php
// Version 1.0
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

$is_admin = auth_is_admin();
$msg = '';

// --- NEUE IDEE POSTEN ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'new_idea') {
    $author = $is_admin ? ($_SESSION['discord_user']['name'] ?? 'Admin') : trim($_POST['author'] ?? '');
    if ($author === '') $author = 'Anonym';
    $text = trim($_POST['text'] ?? '');
    
    if ($text !== '') {
        // Status standardmäßig auf 'Angenommen' gesetzt
        $stmt = db()->prepare('INSERT INTO kr_ideas (source, author, text, status) VALUES (?, ?, ?, ?)');
        $stmt->execute(['web', $author, $text, 'Angenommen']);
        $msg = "Deine Idee wurde erfolgreich eingereicht! 🎉";
    }
}

// --- IDEEN LADEN ---
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
    <title>Ideen – <?= APP_NAME ?></title>
    <link rel="stylesheet" href="/css/base.css?v=1.2">
    <style>
        .idea-card { border-left: 6px solid var(--mint); margin-bottom: 16px; transition: 0.2s; }
        .idea-card.status-in-arbeit { border-left-color: var(--yellow); }
        .idea-card.status-abgeschlossen { border-left-color: #38d26b; }
        .idea-card.status-abgelehnt { border-left-color: var(--pink); opacity: 0.7; }
        .idea-card:hover { background: var(--card-strong); }
        
        .meta { display: flex; justify-content: space-between; font-size: 14px; color: var(--muted); margin-bottom: 10px; }
        .idea-text { font-size: 16px; margin-bottom: 12px; white-space: pre-wrap; }
        .badge { padding: 4px 10px; border-radius: 8px; font-size: 12px; font-weight: bold; color: #1f1438; }
    </style>
</head>
<body>
    <div class="page" style="max-width: 800px; margin: 0 auto; padding: 24px 20px 90px;">
        
        <div style="margin-bottom: 20px; display: flex; justify-content: space-between; align-items: center;">
            <h1>💡 Ideen</h1>
            <a href="/lobby.php" class="btn btn-ghost btn-small">← Zurück zur Lobby</a>
        </div>

        <?php if ($msg): ?><div class="msg" style="color:var(--mint); font-weight:bold; margin-bottom:20px;"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

        <!-- Formular für User (und Admins) -->
        <div class="card" style="margin-bottom: 30px;">
            <h2>Neue Idee einreichen</h2>
            <form method="POST">
                <input type="hidden" name="action" value="new_idea">
                <?php if (!$is_admin): ?>
                    <label>Dein Name (optional):</label>
                    <input type="text" name="author" placeholder="Anonym" style="margin-bottom:10px;">
                <?php endif; ?>
                <label>Deine Idee:</label>
                <textarea name="text" rows="3" required placeholder="Was hast du für eine Idee?" style="margin-bottom:10px;"></textarea>
                <button type="submit" class="btn btn-pink">Idee einreichen</button>
            </form>
        </div>

        <h2>Eingereichte Ideen</h2>
        <?php if (empty($ideas)): ?>
            <p class="muted">Noch keine Ideen vorhanden. Sei der Erste!</p>
        <?php endif; ?>

        <?php foreach ($ideas as $idea): 
            $status = $idea['status'] ?? 'Angenommen';
            $card_class = 'status-' . strtolower(str_replace(' ', '-', $status));
        ?>
            <div class="card idea-card <?= $card_class ?>">
                <div class="meta">
                    <span>👤 <?= htmlspecialchars($idea['author'] ?: 'Anonym') ?> (<?= $idea['source'] ?>)</span>
                    <span>📅 <?= date('d.m.Y H:i', strtotime($idea['created_at'])) ?></span>
                </div>
                <div class="idea-text"><?= nl2br(htmlspecialchars($idea['text'])) ?></div>
                <div style="text-align: right;">
                    <span class="badge" style="background-color: <?= $status_colors[$status] ?? '#c9bde8' ?>;"><?= $status ?></span>
                </div>
            </div>
        <?php endforeach; ?>

    </div>
    <footer class="site-footer"><a href="/impressum.php">Impressum</a> · <a href="/datenschutz.php">Datenschutz</a></footer>
</body>
</html>
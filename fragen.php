<?php
// Version 1.0
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$user = $_SESSION['discord_user'] ?? null;
if (!$user) {
    header('Location: index.php');
    exit;
}
$me = $user['id'];
$allowedPoints = [100, 200, 300, 500];

// Meldungen über die Session, damit sie nach dem Redirect erhalten bleiben
function flash(string $text): void
{
    $_SESSION['flash'] = $text;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $catId  = (int)($_POST['cat'] ?? 0);

    if ($action === 'add_category') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '' || mb_strlen($name) > 100) {
            flash('Kategoriename: 1 bis 100 Zeichen.');
        } else {
            db()->prepare('INSERT INTO kr_categories (owner_id, name) VALUES (?, ?)')
                ->execute([$me, $name]);
            flash('Kategorie gespeichert.');
        }
    }

    elseif ($action === 'delete_category') {
        db()->prepare('DELETE FROM kr_categories WHERE id = ? AND owner_id = ?')
            ->execute([(int)($_POST['id'] ?? 0), $me]);
        flash('Kategorie gelöscht.');
        $catId = 0;
    }

    elseif ($action === 'add_question') {
        $points   = (int)($_POST['points'] ?? 0);
        $question = trim($_POST['question'] ?? '');
        $answer   = trim($_POST['answer'] ?? '');

        $stmt = db()->prepare('SELECT owner_id FROM kr_categories WHERE id = ?');
        $stmt->execute([$catId]);
        $owner = $stmt->fetchColumn();

        if ($owner !== $me) {
            flash('Du kannst nur Kategorien bearbeiten, die du selbst angelegt hast.');
        } elseif (!in_array($points, $allowedPoints, true) || $question === '' || $answer === '') {
            flash('Bitte Punktwert, Frage und Antwort ausfüllen.');
        } else {
            db()->prepare('INSERT INTO kr_questions (category_id, points, question, answer) VALUES (?, ?, ?, ?)')
                ->execute([$catId, $points, $question, $answer]);
            flash('Frage gespeichert.');
        }
    }

    elseif ($action === 'delete_question') {
        db()->prepare('
            DELETE q FROM kr_questions q
            JOIN kr_categories c ON c.id = q.category_id
            WHERE q.id = ? AND c.owner_id = ?
        ')->execute([(int)($_POST['id'] ?? 0), $me]);
        flash('Frage gelöscht.');
    }

    $redirect = 'fragen.php' . ($catId ? '?cat=' . $catId : '');
    header('Location: ' . $redirect);
    exit;
}

$msg = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

// Alle Kategorien mit Anzahl der Fragen
$categories = db()->query('
    SELECT c.id, c.name, c.owner_id, COUNT(q.id) AS qcount
    FROM kr_categories c
    LEFT JOIN kr_questions q ON q.category_id = c.id
    GROUP BY c.id, c.name, c.owner_id
    ORDER BY c.name
')->fetchAll();

// Ausgewählte Kategorie mit Fragen
$selected = null;
$questions = [];
if (!empty($_GET['cat'])) {
    $stmt = db()->prepare('SELECT id, name, owner_id FROM kr_categories WHERE id = ?');
    $stmt->execute([(int)$_GET['cat']]);
    $selected = $stmt->fetch();
    if ($selected) {
        $stmt = db()->prepare('SELECT id, points, question, answer FROM kr_questions WHERE category_id = ? ORDER BY points, id');
        $stmt->execute([$selected['id']]);
        $questions = $stmt->fetchAll();
    }
}
$isOwner = $selected && $selected['owner_id'] === $me;
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kategorien & Fragen – <?= APP_NAME ?></title>
    <style>
        body { font-family: system-ui, sans-serif; background: #1f1438; color: #f5f0ff; margin: 0; padding: 30px; }
        a { color: #7fe7d9; }
        h1, h2 { margin-top: 10px; }
        .panel { background: rgba(255,255,255,.06); border-radius: 14px; padding: 20px; margin-bottom: 24px; }
        input, textarea, select { padding: 10px; border-radius: 8px; border: none; font: inherit; box-sizing: border-box; }
        input[type=text], textarea, select { width: 100%; margin-top: 6px; }
        textarea { min-height: 70px; }
        label { display: block; margin-top: 12px; font-size: 14px; }
        button { padding: 10px 16px; border: none; border-radius: 8px; background: #3ddbc4; color: #1f1438; font-weight: bold; cursor: pointer; margin-top: 12px; }
        button.danger { background: #ff3b5c; color: #fff; }
        table { width: 100%; border-collapse: collapse; }
        td, th { text-align: left; padding: 8px; border-bottom: 1px solid rgba(255,255,255,.1); vertical-align: top; }
        .msg { color: #7fe7d9; font-weight: bold; }
        .muted { opacity: .6; font-size: 13px; }
        .inline { display: inline; }
    </style>
</head>
<body>
    <p><a href="lobby.php">← Zur Lobby</a></p>
    <h1>Kategorien & Fragen</h1>

    <?php if ($msg): ?><p class="msg"><?= htmlspecialchars($msg) ?></p><?php endif; ?>

    <div class="panel">
        <h2>Neue Kategorie</h2>
        <form method="post">
            <input type="hidden" name="action" value="add_category">
            <label>Name
                <input type="text" name="name" maxlength="100" required>
            </label>
            <button type="submit">Kategorie anlegen</button>
        </form>
    </div>

    <div class="panel">
        <h2>Alle Kategorien</h2>
        <table>
            <tr><th>Name</th><th>Fragen</th><th></th></tr>
            <?php foreach ($categories as $c): ?>
                <tr>
                    <td>
                        <a href="?cat=<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['name']) ?></a>
                        <?php if ($c['owner_id'] === $me): ?><span class="muted">(deine)</span><?php endif; ?>
                    </td>
                    <td><?= (int)$c['qcount'] ?></td>
                    <td>
                        <?php if ($c['owner_id'] === $me): ?>
                            <form method="post" class="inline" onsubmit="return confirm('Kategorie mit allen Fragen löschen?')">
                                <input type="hidden" name="action" value="delete_category">
                                <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                <button class="danger" type="submit">Löschen</button>
                            </form>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>

    <?php if ($selected): ?>
        <div class="panel">
            <h2>Fragen: <?= htmlspecialchars($selected['name']) ?></h2>

            <?php if ($isOwner): ?>
                <form method="post">
                    <input type="hidden" name="action" value="add_question">
                    <input type="hidden" name="cat" value="<?= (int)$selected['id'] ?>">
                    <label>Punkte
                        <select name="points" required>
                            <option value="100">100</option>
                            <option value="200">200</option>
                            <option value="300">300</option>
                            <option value="500">500</option>
                        </select>
                    </label>
                    <label>Frage
                        <textarea name="question" required></textarea>
                    </label>
                    <label>Antwort
                        <textarea name="answer" required></textarea>
                    </label>
                    <button type="submit">Frage hinzufügen</button>
                </form>
            <?php else: ?>
                <p class="muted">Diese Kategorie gehört jemand anderem. Du kannst sie nur ansehen.</p>
            <?php endif; ?>

            <table>
                <tr><th>Punkte</th><th>Frage</th><th>Antwort</th><th></th></tr>
                <?php foreach ($questions as $q): ?>
                    <tr>
                        <td><?= (int)$q['points'] ?></td>
                        <td><?= nl2br(htmlspecialchars($q['question'])) ?></td>
                        <td><?= nl2br(htmlspecialchars($q['answer'])) ?></td>
                        <td>
                            <?php if ($isOwner): ?>
                                <form method="post" class="inline">
                                    <input type="hidden" name="action" value="delete_question">
                                    <input type="hidden" name="cat" value="<?= (int)$selected['id'] ?>">
                                    <input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
                                    <button class="danger" type="submit">Löschen</button>
                                </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>
</body>
</html>
<!-- Version 1.0 -->
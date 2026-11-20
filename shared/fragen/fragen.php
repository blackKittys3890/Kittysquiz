<?php
// Version 1.4
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/db.php';

$user = $_SESSION['discord_user'] ?? null;
if (!$user) {
    header('Location: /index.php');
    exit;
}
$me = $user['id'];
$allowedPoints = [100, 200, 300, 400, 500, 600, 1000];

// Vorschau für Bild, Audio oder Video
function mediaThumb(string $url): string
{
    $e = strtolower(pathinfo($url, PATHINFO_EXTENSION));
    $u = htmlspecialchars($url);
    if (in_array($e, ['mp4', 'webm'], true)) return '<video src="' . $u . '" class="thumb" muted></video>';
    if (in_array($e, ['mp3', 'wav', 'ogg', 'm4a'], true)) return '<audio src="' . $u . '" controls></audio>';
    return '<img src="' . $u . '" class="thumb">';
}

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
    elseif ($action === 'edit_category') {
        $name = trim($_POST['name'] ?? '');
        if ($name === '' || mb_strlen($name) > 100) {
            flash('Kategoriename: 1 bis 100 Zeichen.');
        } else {
            db()->prepare('UPDATE kr_categories SET name = ? WHERE id = ? AND owner_id = ?')
                ->execute([$name, $catId, $me]);
            flash('Kategorie umbenannt.');
        }
    }
    elseif ($action === 'delete_category') {
        db()->prepare('DELETE FROM kr_categories WHERE id = ? AND owner_id = ?')
            ->execute([(int)($_POST['id'] ?? 0), $me]);
        flash('Kategorie gelöscht.');
        $catId = 0;
    }
    elseif ($action === 'add_question' || $action === 'edit_question') {
        $qid      = (int)($_POST['qid'] ?? 0);
        $points   = (int)($_POST['points'] ?? 0);
        $question = trim($_POST['question'] ?? '');
        $answer   = trim($_POST['answer'] ?? '');
        $spotify  = trim($_POST['spotify_url'] ?? '');
        $spotifyUrl = $spotify !== '' ? $spotify : null;

        $stmt = db()->prepare('SELECT owner_id FROM kr_categories WHERE id = ?');
        $stmt->execute([$catId]);
        $owner = $stmt->fetchColumn();

        if ($owner !== $me) {
            flash('Du kannst nur Kategorien bearbeiten, die du selbst angelegt hast.');
        } elseif ($spotify !== '' && !preg_match('#^https://open\.spotify\.com/(intl-[a-z]+/)?(track|album|playlist)/[A-Za-z0-9]+#', $spotify)) {
            flash('Der Spotify-Link ist ungültig. Bitte in Spotify „Link kopieren“ wählen und hier einfügen.');
        } elseif (!in_array($points, $allowedPoints, true) || $question === '' || $answer === '') {
            flash('Bitte Punktwert, Frage und Antwort ausfüllen.');
        } else {
            $imageUrl = null;
            
            // Bild-Upload verarbeiten
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'mp3', 'wav', 'ogg', 'm4a', 'mp4', 'webm'])) {
                    $uploadDir = dirname(__DIR__, 2) . '/uploads';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
                    $filename = 'q_' . uniqid() . '.' . $ext;
                    if (move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . '/' . $filename)) {
                        $imageUrl = '/uploads/' . $filename;
                    }
                }
            }
            
            // Wenn beim Bearbeiten kein neues Bild kam, das alte behalten
            if ($action === 'edit_question' && !$imageUrl) {
                $stmt = db()->prepare('SELECT image_url FROM kr_questions WHERE id = ?');
                $stmt->execute([$qid]);
                $imageUrl = $stmt->fetchColumn() ?: null;
            }

            if ($action === 'add_question') {
                db()->prepare('INSERT INTO kr_questions (category_id, points, question, answer, image_url, spotify_url) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$catId, $points, $question, $answer, $imageUrl, $spotifyUrl]);
                flash('Frage gespeichert.');
            } else {
                db()->prepare('UPDATE kr_questions SET points = ?, question = ?, answer = ?, image_url = ?, spotify_url = ? WHERE id = ?')
                    ->execute([$points, $question, $answer, $imageUrl, $spotifyUrl, $qid]);
                flash('Frage aktualisiert.');
            }
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

    header('Location: fragen.php' . ($catId ? '?cat=' . $catId : ''));
    exit;
}

$msg = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);

$categories = db()->query('
    SELECT c.id, c.name, c.owner_id, COUNT(q.id) AS qcount
    FROM kr_categories c
    LEFT JOIN kr_questions q ON q.category_id = c.id
    GROUP BY c.id, c.name, c.owner_id
    ORDER BY c.name
')->fetchAll();

$selected = null;
$questions = [];
$editQ = null;

if (!empty($_GET['cat'])) {
    $stmt = db()->prepare('SELECT id, name, owner_id FROM kr_categories WHERE id = ?');
    $stmt->execute([(int)$_GET['cat']]);
    $selected = $stmt->fetch();
    if ($selected) {
        $stmt = db()->prepare('SELECT * FROM kr_questions WHERE category_id = ? ORDER BY points, id');
        $stmt->execute([$selected['id']]);
        $questions = $stmt->fetchAll();
    }
}

if (!empty($_GET['edit_q'])) {
    $stmt = db()->prepare('SELECT * FROM kr_questions WHERE id = ?');
    $stmt->execute([(int)$_GET['edit_q']]);
    $editQ = $stmt->fetch();
}

$isOwner = $selected && $selected['owner_id'] === $me;
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Kategorien & Fragen – <?= htmlspecialchars(defined('APP_NAME') ? APP_NAME : 'KittysRätsel') ?></title>
    <style>
        body { font-family: system-ui, sans-serif; background: #1f1438; color: #f5f0ff; margin: 0; padding: 30px; }
        a { color: #7fe7d9; text-decoration: none; }
        a:hover { text-decoration: underline; }
        h1, h2 { margin-top: 10px; }
        .panel { background: rgba(255,255,255,.06); border-radius: 14px; padding: 20px; margin-bottom: 24px; }
        input, textarea, select { padding: 10px; border-radius: 8px; border: none; font: inherit; box-sizing: border-box; background: #3b2a6b; color: #fff; }
        input[type=text], input[type=file], textarea, select { width: 100%; margin-top: 6px; }
        textarea { min-height: 70px; }
        label { display: block; margin-top: 12px; font-size: 14px; }
        button { padding: 10px 16px; border: none; border-radius: 8px; background: #3ddbc4; color: #1f1438; font-weight: bold; cursor: pointer; margin-top: 12px; }
        button.danger { background: #ff3b5c; color: #fff; }
        button.grey { background: #444; color: #fff; }
        button.small { padding: 6px 10px; font-size: 13px; margin: 0; }
        table { width: 100%; border-collapse: collapse; margin-top: 10px; }
        td, th { text-align: left; padding: 8px; border-bottom: 1px solid rgba(255,255,255,.1); vertical-align: top; }
        .msg { color: #7fe7d9; font-weight: bold; }
        .muted { opacity: .6; font-size: 13px; }
        .inline { display: inline; }
        .thumb { max-width: 80px; max-height: 80px; border-radius: 8px; display: block; margin-top: 5px; }
        .actions { display: flex; gap: 6px; }
    </style>
</head>
<body>
    <p><a href="/lobby.php">← Zur Lobby</a></p>
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
                            <div class="actions">
                                <form method="post" class="inline" onsubmit="return confirm('Kategorie mit allen Fragen löschen?')">
                                    <input type="hidden" name="action" value="delete_category">
                                    <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                    <button class="danger small" type="submit">Löschen</button>
                                </form>
                            </div>
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
                <?php if ($editQ): ?>
                    <h3>Frage bearbeiten</h3>
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="edit_question">
                        <input type="hidden" name="cat" value="<?= (int)$selected['id'] ?>">
                        <input type="hidden" name="qid" value="<?= (int)$editQ['id'] ?>">
                        <label>Punkte
                            <select name="points" required>
                                <?php foreach ([100, 200, 300, 400, 500, 600, 1000] as $p): ?>
                                    <option value="<?= $p ?>" <?= $editQ['points'] == $p ? 'selected' : '' ?>><?= $p ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Frage
                            <textarea name="question" required><?= htmlspecialchars($editQ['question']) ?></textarea>
                        </label>
                        <label>Antwort
                            <textarea name="answer" required><?= htmlspecialchars($editQ['answer']) ?></textarea>
                        </label>
                        <label>Spotify-Link (optional, der Song ist die Frage)
                            <input type="url" name="spotify_url" value="<?= htmlspecialchars($editQ['spotify_url'] ?? '') ?>" placeholder="https://open.spotify.com/track/...">
                        </label>
                        <label>Bild (optional)
                            <?php if ($editQ['image_url']): ?>
                                <?= mediaThumb($editQ['image_url']) ?>
                            <?php endif; ?>
                            <input type="file" name="image" accept="image/*,audio/*,video/*">
                        </label>
                        <button type="submit">Speichern</button>
                        <a href="?cat=<?= (int)$selected['id'] ?>" class="muted" style="margin-left:10px;">Abbrechen</a>
                    </form>
                <?php else: ?>
                    <h3>Neue Frage hinzufügen</h3>
                    <form method="post" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="add_question">
                        <input type="hidden" name="cat" value="<?= (int)$selected['id'] ?>">
                        <label>Punkte
                            <select name="points" required>
                                <?php foreach ([100, 200, 300, 400, 500, 600, 1000] as $p): ?>
                                    <option value="<?= $p ?>"><?= $p ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>Frage
                            <textarea name="question" required></textarea>
                        </label>
                        <label>Antwort
                            <textarea name="answer" required></textarea>
                        </label>
                        <label>Spotify-Link (optional, der Song ist die Frage)
                            <input type="url" name="spotify_url" placeholder="https://open.spotify.com/track/...">
                        </label>
                        <label>Bild (optional)
                            <input type="file" name="image" accept="image/*,audio/*,video/*">
                        </label>
                        <button type="submit">Frage hinzufügen</button>
                    </form>
                <?php endif; ?>
            <?php else: ?>
                <p class="muted">Diese Kategorie gehört jemand anderem. Du kannst sie nur ansehen.</p>
            <?php endif; ?>

            <table>
                <tr><th>Punkte</th><th>Frage</th><th>Antwort</th><th>Bild</th><th></th></tr>
                <?php foreach ($questions as $q): ?>
                    <tr>
                        <td><?= (int)$q['points'] ?></td>
                        <td><?= nl2br(htmlspecialchars($q['question'])) ?>
                            <?php if (!empty($q['spotify_url'])): ?><div class="muted">🎵 Spotify-Song</div><?php endif; ?>
                        </td>
                        <td><?= nl2br(htmlspecialchars($q['answer'])) ?></td>
                        <td>
                            <?php if (!empty($q['image_url'])): ?>
                                <?= mediaThumb($q['image_url']) ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isOwner): ?>
                                <div class="actions">
                                    <a href="?cat=<?= (int)$selected['id'] ?>&edit_q=<?= (int)$q['id'] ?>" class="btn small" style="background:#7b2ff7;color:#fff;padding:6px 10px;border-radius:8px;text-decoration:none;">Bearbeiten</a>
                                    <form method="post" class="inline" onsubmit="return confirm('Frage löschen?')">
                                        <input type="hidden" name="action" value="delete_question">
                                        <input type="hidden" name="cat" value="<?= (int)$selected['id'] ?>">
                                        <input type="hidden" name="id" value="<?= (int)$q['id'] ?>">
                                        <button class="danger small" type="submit">Löschen</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </table>
        </div>
    <?php endif; ?>
</body>
</html>
<!-- Version 1.4 -->
<?php
// Version 2.0
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/teams.php';

header('Content-Type: application/json; charset=utf-8');

$me = $_SESSION['discord_user']['id'] ?? null;
if (!$me) {
    http_response_code(401);
    echo json_encode(['error' => 'login']);
    exit;
}

$code = strtoupper($_REQUEST['code'] ?? '');
$stmt = db()->prepare('SELECT id, host_id FROM kr_rooms WHERE code = ?');
$stmt->execute([$code]);
$room = $stmt->fetch();
if (!$room) {
    http_response_code(404);
    echo json_encode(['error' => 'room']);
    exit;
}

$roomId = (int)$room['id'];
$isHost = $room['host_id'] === $me;
ensureTeams($roomId);

function fail(int $status, string $error): void
{
    http_response_code($status);
    echo json_encode(['error' => $error]);
    exit;
}

function resetScores(int $roomId): void
{
    db()->prepare('UPDATE kr_teams SET points = 0 WHERE room_id = ?')->execute([$roomId]);
}

function activeQuestion(int $roomId): ?array
{
    $stmt = db()->prepare('SELECT slot, points, question_id FROM kr_active WHERE room_id = ?');
    $stmt->execute([$roomId]);
    return $stmt->fetch() ?: null;
}

// ---------- Änderungen (nur Host) ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$isHost) {
        fail(403, 'host');
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'set_slot') {
        $slot  = (int)($_POST['slot'] ?? -1);
        $catId = (int)($_POST['category_id'] ?? 0);
        $keep  = ($_POST['keep_points'] ?? '1') === '1';

        $stmt = db()->prepare('SELECT id FROM kr_categories WHERE id = ?');
        $stmt->execute([$catId]);
        if ($slot < 0 || $slot > 5 || !$stmt->fetch()) {
            fail(400, 'input');
        }

        if (!$keep) {
            resetScores($roomId);
        }

        db()->prepare('DELETE FROM kr_played WHERE room_id = ? AND slot = ?')->execute([$roomId, $slot]);
        db()->prepare('DELETE FROM kr_active WHERE room_id = ? AND slot = ?')->execute([$roomId, $slot]);
        db()->prepare('REPLACE INTO kr_board (room_id, slot, category_id) VALUES (?, ?, ?)')
            ->execute([$roomId, $slot, $catId]);
    }

    elseif ($action === 'add_points') {
        $teamId = (int)($_POST['team_id'] ?? 0);
        $delta  = (int)($_POST['delta'] ?? 0);
        if (!in_array($teamId, teamIds($roomId), true)
            || !in_array($delta, [-500, -300, -200, -100, 100, 200, 300, 500], true)) {
            fail(400, 'input');
        }
        db()->prepare('UPDATE kr_teams SET points = points + ? WHERE id = ? AND room_id = ?')
            ->execute([$delta, $teamId, $roomId]);
    }

    elseif ($action === 'reset_scores') {
        resetScores($roomId);
    }

    elseif ($action === 'add_team') {
        $name  = trim($_POST['name'] ?? '');
        $color = $_POST['color'] ?? '';
        if ($name === '' || mb_strlen($name) > 40) {
            fail(400, 'team_name');
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            fail(400, 'color');
        }
        $count = count(teamIds($roomId));
        if ($count >= 8) {
            fail(409, 'team_max');
        }
        db()->prepare('INSERT INTO kr_teams (room_id, name, color, points, sort) VALUES (?, ?, ?, 0, ?)')
            ->execute([$roomId, $name, $color, $count + 1]);
    }

    elseif ($action === 'delete_team') {
        $teamId = (int)($_POST['team_id'] ?? 0);
        $ids = teamIds($roomId);
        if (!in_array($teamId, $ids, true)) {
            fail(400, 'input');
        }
        if (count($ids) <= 1) {
            fail(409, 'team_last');
        }
        // Mitglieder des Teams werden ohne Team weitergeführt
        db()->prepare('UPDATE kr_room_players SET team_id = NULL WHERE room_id = ? AND team_id = ?')
            ->execute([$roomId, $teamId]);
        db()->prepare('DELETE FROM kr_teams WHERE id = ? AND room_id = ?')->execute([$teamId, $roomId]);
    }

    elseif ($action === 'open_question') {
        $slot   = (int)($_POST['slot'] ?? -1);
        $points = (int)($_POST['points'] ?? 0);

        if ($slot < 0 || $slot > 5 || !in_array($points, [100, 200, 300, 500], true)) {
            fail(400, 'input');
        }
        if (activeQuestion($roomId)) {
            fail(409, 'busy');
        }

        $stmt = db()->prepare('SELECT 1 FROM kr_played WHERE room_id = ? AND slot = ? AND points = ?');
        $stmt->execute([$roomId, $slot, $points]);
        if ($stmt->fetch()) {
            fail(409, 'played');
        }

        $stmt = db()->prepare('
            SELECT q.id FROM kr_board b
            JOIN kr_questions q ON q.category_id = b.category_id
            WHERE b.room_id = ? AND b.slot = ? AND q.points = ?
            ORDER BY q.id
            LIMIT 1
        ');
        $stmt->execute([$roomId, $slot, $points]);
        $q = $stmt->fetch();
        if (!$q) {
            $stmt = db()->prepare('SELECT 1 FROM kr_board WHERE room_id = ? AND slot = ?');
            $stmt->execute([$roomId, $slot]);
            fail(400, $stmt->fetch() ? 'noquestion' : 'slot');
        }

        // revealed = 0: Frage ist nur für den Host sichtbar, bis er sie anzeigt
        db()->prepare('INSERT INTO kr_active (room_id, slot, points, question_id, revealed) VALUES (?, ?, ?, ?, 0)')
            ->execute([$roomId, $slot, $points, $q['id']]);
    }

    elseif ($action === 'reveal') {
        if (!activeQuestion($roomId)) {
            fail(409, 'noactive');
        }
        db()->prepare('UPDATE kr_active SET revealed = 1 WHERE room_id = ?')->execute([$roomId]);
    }

    elseif ($action === 'resolve') {
        $teamId = (int)($_POST['team_id'] ?? 0); // 0 = niemand
        $active = activeQuestion($roomId);
        if (!$active) {
            fail(409, 'noactive');
        }
        if ($teamId !== 0 && !in_array($teamId, teamIds($roomId), true)) {
            fail(400, 'input');
        }

        $pdo = db();
        $pdo->beginTransaction();
        if ($teamId !== 0) {
            $pdo->prepare('UPDATE kr_teams SET points = points + ? WHERE id = ? AND room_id = ?')
                ->execute([(int)$active['points'], $teamId, $roomId]);
        }
        $pdo->prepare('INSERT IGNORE INTO kr_played (room_id, slot, points) VALUES (?, ?, ?)')
            ->execute([$roomId, (int)$active['slot'], (int)$active['points']]);
        $pdo->prepare('DELETE FROM kr_active WHERE room_id = ?')->execute([$roomId]);
        $pdo->commit();
    }

    elseif ($action === 'unplay') {
        $slot   = (int)($_POST['slot'] ?? -1);
        $points = (int)($_POST['points'] ?? 0);
        if ($slot < 0 || $slot > 5 || !in_array($points, [100, 200, 300, 500], true)) {
            fail(400, 'input');
        }
        db()->prepare('DELETE FROM kr_played WHERE room_id = ? AND slot = ? AND points = ?')
            ->execute([$roomId, $slot, $points]);
    }

    elseif ($action === 'cancel_question') {
        db()->prepare('DELETE FROM kr_active WHERE room_id = ?')->execute([$roomId]);
    }

    echo json_encode(['ok' => true]);
    exit;
}

// ---------- Status für alle Spieler ----------

db()->prepare('UPDATE kr_room_players SET last_seen = NOW() WHERE room_id = ? AND discord_id = ?')
    ->execute([$roomId, $me]);

$stmt = db()->prepare('
    SELECT b.slot, b.category_id, c.name
    FROM kr_board b
    JOIN kr_categories c ON c.id = b.category_id
    WHERE b.room_id = ?
    ORDER BY b.slot
');
$stmt->execute([$roomId]);
$board = array_map(fn($r) => [
    'slot'        => (int)$r['slot'],
    'category_id' => (int)$r['category_id'],
    'name'        => $r['name'],
], $stmt->fetchAll());

$stmt = db()->prepare('SELECT id, name, color, points FROM kr_teams WHERE room_id = ? ORDER BY sort, id');
$stmt->execute([$roomId]);
$teams = array_map(fn($r) => [
    'id'     => (int)$r['id'],
    'name'   => $r['name'],
    'color'  => $r['color'],
    'points' => (int)$r['points'],
], $stmt->fetchAll());

$stmt = db()->prepare('SELECT slot, points FROM kr_played WHERE room_id = ?');
$stmt->execute([$roomId]);
$played = array_map(fn($r) => [
    'slot'   => (int)$r['slot'],
    'points' => (int)$r['points'],
], $stmt->fetchAll());

// Offene Frage: Text nur für Host oder nach dem Anzeigen, Antwort nur für den Host
$active = null;
$stmt = db()->prepare('
    SELECT a.slot, a.points, a.revealed, q.question, q.answer, c.name AS category
    FROM kr_active a
    JOIN kr_questions q ON q.id = a.question_id
    JOIN kr_board b ON b.room_id = a.room_id AND b.slot = a.slot
    JOIN kr_categories c ON c.id = b.category_id
    WHERE a.room_id = ?
');
$stmt->execute([$roomId]);
if ($row = $stmt->fetch()) {
    $revealed = (bool)$row['revealed'];
    $active = [
        'slot'     => (int)$row['slot'],
        'points'   => (int)$row['points'],
        'category' => $row['category'],
        'revealed' => $revealed,
        'question' => ($isHost || $revealed) ? $row['question'] : null,
        'answer'   => $isHost ? $row['answer'] : null,
    ];
}

$categories = array_map(
    fn($r) => ['id' => (int)$r['id'], 'name' => $r['name']],
    db()->query('SELECT id, name FROM kr_categories ORDER BY name')->fetchAll()
);

echo json_encode([
    'isHost'     => $isHost,
    'board'      => $board,
    'teams'      => $teams,
    'played'     => $played,
    'active'     => $active,
    'categories' => $categories,
]);
// Version 2.0

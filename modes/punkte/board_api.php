<?php
// Version 7.12 (mit Debug)

// ---- DEBUG START ----
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);

set_exception_handler(function($e) {
    http_response_code(500);
    if (!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'fatal' => $e->getMessage(),
        'file'  => $e->getFile(),
        'line'  => $e->getLine(),
        'trace' => explode("\n", $e->getTraceAsString()),
    ]);
    exit;
});

set_error_handler(function($no, $str, $file, $line) {
    throw new ErrorException($str, 0, $no, $file, $line);
});
// ---- DEBUG END ----

require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/db.php';
require_once __DIR__ . '/teams.php';

$me = $_SESSION['discord_user']['id'] ?? null;
if (!$me) { http_response_code(401); echo json_encode(['error' => 'login']); exit; }

$code = strtoupper($_REQUEST['code'] ?? '');
$stmt = db()->prepare('SELECT id, host_id, mode, board_round, turn_team_id FROM kr_rooms WHERE code = ?');
$stmt->execute([$code]);
$room = $stmt->fetch();
if (!$room) { http_response_code(404); echo json_encode(['error' => 'room']); exit; }

$rid = (int)$room['id'];
$isHost = $room['host_id'] === $me;
// Runde 1 nutzt Slots 0-5, Runde 2 nutzt Slots 6-11
$roundOff = (int)$room['board_round'] === 2 ? 6 : 0;
$otherOff = $roundOff === 0 ? 6 : 0;

function q(string $sql, array $p = []): PDOStatement { $s = db()->prepare($sql); $s->execute($p); return $s; }
function fail(int $c, string $e): void { http_response_code($c); echo json_encode(['error' => $e]); exit; }

function checkTurn(int $rid, string $me): void {
    $room = q('SELECT turn_team_id FROM kr_rooms WHERE id = ?', [$rid])->fetch();
    $turn = (int)$room['turn_team_id'];
    $myTeam = q('SELECT team_id FROM kr_room_players WHERE room_id = ? AND discord_id = ?', [$rid, $me])->fetchColumn();
    if ((int)$myTeam !== $turn) fail(403, 'turn');
}

// ---------- POST Aktionen ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // --- Modus wechseln ---
    if ($action === 'set_mode') {
        if (!$isHost) fail(403, 'host');
        $mode = $_POST['mode'] ?? 'points';
        if (!in_array($mode, ['points', 'hearts', 'eingestuhlt', 'dumm_drauf'], true)) {
            fail(400, 'input');
        }
        q('UPDATE kr_rooms SET mode = ? WHERE id = ?', [$mode, $rid]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // --- Runde wechseln ---
    if ($action === 'set_round') {
        if (!$isHost) fail(403, 'host');
        $round = (int)($_POST['round'] ?? 1) === 2 ? 2 : 1;
        q('UPDATE kr_rooms SET board_round = ? WHERE id = ?', [$round, $rid]);
        q('DELETE FROM kr_active WHERE room_id = ?', [$rid]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // --- Kategorie (Slot) setzen ---
    if ($action === 'set_slot') {
        if (!$isHost) fail(403, 'host');
        $slot = (int)($_POST['slot'] ?? -1);
        $catId = (int)($_POST['category_id'] ?? 0);
        $keepPoints = ($_POST['keep_points'] ?? '0') === '1';

        if ($slot < 0 || $slot > 5) fail(400, 'input');

        $catOwner = q('SELECT owner_id FROM kr_categories WHERE id = ?', [$catId])->fetchColumn();
        if (!$catOwner) fail(400, 'input');

        if (q('SELECT 1 FROM kr_board WHERE room_id = ? AND category_id = ? AND slot BETWEEN ? AND ?',
              [$rid, $catId, $otherOff, $otherOff + 5])->fetch()) fail(409, 'category_used');

        $slot += $roundOff;
        q('REPLACE INTO kr_board (room_id, slot, category_id) VALUES (?, ?, ?)', [$rid, $slot, $catId]);

        if (!$keepPoints) {
            q('UPDATE kr_teams SET points = 0 WHERE room_id = ?', [$rid]);
            q('DELETE FROM kr_played WHERE room_id = ? AND slot BETWEEN ? AND ?', [$rid, $roundOff, $roundOff + 5]);
        }

        q('DELETE FROM kr_active WHERE room_id = ? AND slot = ?', [$rid, $slot]);

        echo json_encode(['ok' => true]);
        exit;
    }

    // --- Frage öffnen ---
    if ($action === 'open_question') {
        $slot = (int)($_POST['slot'] ?? -1);
        $points = (int)($_POST['points'] ?? 0);
        if ($slot < 0 || $slot > 5 || !$points) fail(400, 'input');
        $slot += $roundOff;

        if (!$isHost) checkTurn($rid, $me);

        if (q('SELECT 1 FROM kr_active WHERE room_id = ?', [$rid])->fetch()) fail(409, 'busy');
        if (q('SELECT 1 FROM kr_played WHERE room_id = ? AND slot = ? AND points = ?', [$rid, $slot, $points])->fetch()) fail(409, 'played');

        $catId = q('SELECT category_id FROM kr_board WHERE room_id = ? AND slot = ?', [$rid, $slot])->fetchColumn();
        if (!$catId) fail(400, 'slot');

        $qid = q('
            SELECT id FROM kr_questions 
            WHERE category_id = ? AND points = ? 
            ORDER BY RAND() LIMIT 1
        ', [$catId, $points])->fetchColumn();

        if (!$qid) fail(400, 'noquestion');

        q('INSERT INTO kr_active (room_id, slot, points, question_id, revealed, buzz_open, timer_used, timer_paused) VALUES (?, ?, ?, ?, 0, 0, 0, 0)', [$rid, $slot, $points, $qid]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // --- Frage wieder freigeben (Unplay) ---
    if ($action === 'unplay') {
        if (!$isHost) fail(403, 'host');
        $slot = (int)($_POST['slot'] ?? -1);
        $points = (int)($_POST['points'] ?? 0);
        $slot += $roundOff;
        q('DELETE FROM kr_played WHERE room_id = ? AND slot = ? AND points = ?', [$rid, $slot, $points]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // --- Frage für alle aufdecken ---
    if ($action === 'reveal') {
        if (!$isHost) fail(403, 'host');
        q('UPDATE kr_active SET revealed = 1 WHERE room_id = ?', [$rid]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // --- Frage verbergen ---
    if ($action === 'hide') {
        if (!$isHost) fail(403, 'host');
        q('UPDATE kr_active SET revealed = 0, buzz_open = 0 WHERE room_id = ?', [$rid]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // --- Buzzer freigeben ---
    if ($action === 'buzz_open') {
        if (!$isHost) fail(403, 'host');
        q('UPDATE kr_active SET buzz_open = 1 WHERE room_id = ?', [$rid]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // --- Timer Aktionen ---
    if ($action === 'start_timer') {
        if (!$isHost) fail(403, 'host');
        q('UPDATE kr_active SET timer_started_at = NOW(), timer_paused = 0 WHERE room_id = ?', [$rid]);
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($action === 'timer_pause') {
        if (!$isHost) fail(403, 'host');
        q('
            UPDATE kr_active 
            SET timer_paused = 1, timer_used = timer_used + TIMESTAMPDIFF(SECOND, timer_started_at, NOW()) 
            WHERE room_id = ?
        ', [$rid]);
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($action === 'timer_resume') {
        if (!$isHost) fail(403, 'host');
        q('UPDATE kr_active SET timer_paused = 0, timer_started_at = NOW() WHERE room_id = ?', [$rid]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // --- Frage abbrechen ---
    if ($action === 'cancel_question') {
        if (!$isHost) fail(403, 'host');
        q('DELETE FROM kr_active WHERE room_id = ?', [$rid]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // --- Auflösen (Punkte vergeben) ---
    if ($action === 'resolve') {
        if (!$isHost) fail(403, 'host');
        $active = q('
            SELECT a.*, q.answer 
            FROM kr_active a 
            JOIN kr_questions q ON q.id = a.question_id 
            WHERE a.room_id = ?
        ', [$rid])->fetch();
        if (!$active) fail(400, 'noactive');

        $mode = $_POST['mode'] ?? 'none';
        $stealTeamId = (int)($_POST['steal_team_id'] ?? 0);
        $stealMode = $_POST['steal_mode'] ?? 'none';

        $turnTeamId = (int)$room['turn_team_id'];

        if ($mode === 'right') {
            q('UPDATE kr_teams SET points = points + ? WHERE id = ?', [$active['points'], $turnTeamId]);
        } elseif ($mode === 'wrong') {
            q('UPDATE kr_teams SET points = points - ? WHERE id = ?', [floor($active['points'] / 2), $turnTeamId]);
        }

        if ($stealTeamId && $stealTeamId !== $turnTeamId && $stealMode === 'right') {
            q('UPDATE kr_teams SET points = points + ? WHERE id = ?', [floor($active['points'] / 2), $stealTeamId]);
        }

        q('INSERT IGNORE INTO kr_played (room_id, slot, points) VALUES (?, ?, ?)', [$rid, $active['slot'], $active['points']]);
        q('DELETE FROM kr_active WHERE room_id = ?', [$rid]);

        $teamIds = q('SELECT id FROM kr_teams WHERE room_id = ? ORDER BY sort, id', [$rid])->fetchAll(PDO::FETCH_COLUMN);
        if (count($teamIds) > 1) {
            $currentIdx = array_search($turnTeamId, $teamIds);
            $nextIdx = ($currentIdx + 1) % count($teamIds);
            q('UPDATE kr_rooms SET turn_team_id = ? WHERE id = ?', [$teamIds[$nextIdx], $rid]);
        }

        echo json_encode(['ok' => true]);
        exit;
    }

    // --- Joker spielen ---
    if ($action === 'use_joker') {
        if (!$isHost) checkTurn($rid, $me);
        echo json_encode(['ok' => true]);
        exit;
    }

    // --- Team hinzufügen ---
    if ($action === 'add_team') {
        if (!$isHost) fail(403, 'host');
        $name = trim($_POST['name'] ?? '');
        $color = $_POST['color'] ?? '#3b82ff';

        $count = q('SELECT COUNT(*) FROM kr_teams WHERE room_id = ?', [$rid])->fetchColumn();
        if ($count >= 8) fail(400, 'team_max');
        if (strlen($name) < 1 || strlen($name) > 40) fail(400, 'team_name');

        q('INSERT INTO kr_teams (room_id, name, color, sort) VALUES (?, ?, ?, ?)', [$rid, $name, $color, $count + 1]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // --- Team löschen ---
    if ($action === 'delete_team') {
        if (!$isHost) fail(403, 'host');
        $teamId = (int)($_POST['team_id'] ?? 0);
        $count = q('SELECT COUNT(*) FROM kr_teams WHERE room_id = ?', [$rid])->fetchColumn();
        if ($count <= 1) fail(400, 'team_last');

        q('DELETE FROM kr_teams WHERE id = ? AND room_id = ?', [$teamId, $rid]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // --- Punkte manuell anpassen ---
    if ($action === 'add_points') {
        if (!$isHost) fail(403, 'host');
        $teamId = (int)($_POST['team_id'] ?? 0);
        $delta = (int)($_POST['delta'] ?? 0);
        q('UPDATE kr_teams SET points = points + ? WHERE id = ? AND room_id = ?', [$delta, $teamId, $rid]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // --- Punkte zurücksetzen ---
    if ($action === 'reset_scores') {
        if (!$isHost) fail(403, 'host');
        q('UPDATE kr_teams SET points = 0 WHERE room_id = ?', [$rid]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // --- Undo (vereinfacht) ---
    if ($action === 'undo') {
        if (!$isHost) fail(403, 'host');
        $lastPlayed = q('SELECT slot, points FROM kr_played WHERE room_id = ? AND slot BETWEEN ? AND ? ORDER BY slot DESC LIMIT 1',
                        [$rid, $roundOff, $roundOff + 5])->fetch();
        if ($lastPlayed) {
            q('DELETE FROM kr_played WHERE room_id = ? AND slot = ? AND points = ?', [$rid, $lastPlayed['slot'], $lastPlayed['points']]);
        }
        echo json_encode(['ok' => true]);
        exit;
    }

    fail(400, 'action');
}

// ---------- GET Status ----------
$round = (int)$room['board_round'] === 2 ? 2 : 1;

$teams = q('SELECT id, name, color, points FROM kr_teams WHERE room_id = ? ORDER BY sort, id', [$rid])->fetchAll();

$board = q('
    SELECT b.slot - ? AS slot, b.category_id, c.name 
    FROM kr_board b 
    JOIN kr_categories c ON c.id = b.category_id 
    WHERE b.room_id = ? AND b.slot BETWEEN ? AND ?
', [$roundOff, $rid, $roundOff, $roundOff + 5])->fetchAll();

$played = q('SELECT slot - ? AS slot, points FROM kr_played WHERE room_id = ? AND slot BETWEEN ? AND ?', [$roundOff, $rid, $roundOff, $roundOff + 5])->fetchAll();

$active = q('
    SELECT a.*, c.name AS category, q.question, q.answer, q.image_url, q.spotify_url 
    FROM kr_active a 
    JOIN kr_questions q ON q.id = a.question_id 
    JOIN kr_categories c ON c.id = q.category_id 
    WHERE a.room_id = ?
', [$rid])->fetch();

$activeData = null;
if ($active) {
    $elapsed = (int)$active['timer_used'];
    if ($active['timer_started_at'] && !$active['timer_paused']) {
        $elapsed += max(0, time() - strtotime($active['timer_started_at']));
    }
    $activeData = [
        'slot' => (int)$active['slot'] % 6,
        'points' => (int)$active['points'],
        'base' => (int)$active['points'],
        'category' => $active['category'],
        'question' => $active['question'],
        'answer' => $active['answer'],
        'image_url' => $active['image_url'],
        'spotify_url' => $active['spotify_url'] ?? null,
        'revealed' => (bool)$active['revealed'],
        'buzzOpen' => (bool)$active['buzz_open'],
        'timer_started_at' => $active['timer_started_at'],
        'timerPaused' => (bool)$active['timer_paused'],
        'timerElapsed' => $elapsed,
    ];
}

$categories = q('SELECT id, name FROM kr_categories WHERE id NOT IN (SELECT category_id FROM kr_board WHERE room_id = ? AND slot BETWEEN ? AND ?) ORDER BY name',
                [$rid, $otherOff, $otherOff + 5])->fetchAll();

$myTeamId = (int)q('SELECT team_id FROM kr_room_players WHERE room_id = ? AND discord_id = ?', [$rid, $me])->fetchColumn();
$turnTeamId = (int)$room['turn_team_id'];
$canPick = $isHost || ($myTeamId > 0 && $myTeamId === $turnTeamId);

echo json_encode([
    'round' => $round,
    'teams' => $teams,
    'played' => $played,
    'active' => $activeData,
    'board' => $board,
    'categories' => $categories,
    'turnTeamId' => $turnTeamId,
    'canPick' => $canPick,
    'canUndo' => false,
    'myTeamId' => $myTeamId,
    'myJokerAvailable' => false,
]);
// Version 7.12
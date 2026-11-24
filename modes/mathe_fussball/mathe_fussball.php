<?php
// Version 1.0
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
// modes/mathe_fussball/mathe_fussball.php – Mathe-Fußball. NUR für Lehrkräfte, läuft auf einem Bildschirm (Tafel/Beamer).
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/db.php';

const MF_LEN = 9;     // Spielfeld: Positionen 0 bis 8
const MF_START = 4;   // Ball in der Mitte

function mf_json(array $d, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

function mf_question(string $level): array
{
    switch ($level) {
        case 'schwer':
            $a = random_int(6, 15);
            $b = random_int(6, 12);
            $c = random_int(2, 20);
            if (random_int(0, 1)) {
                $text = "($a + $b) × $c";
                $ans = ($a + $b) * $c;
            } else {
                $text = "$a × $b − $c";
                $ans = $a * $b - $c;
            }
            break;
        case 'mittel':
            if (random_int(0, 1)) {
                $a = random_int(2, 12);
                $b = random_int(2, 12);
                $text = "$a × $b";
                $ans = $a * $b;
            } else {
                $b = random_int(2, 12);
                $ans = random_int(2, 12);
                $a = $b * $ans;
                $text = "$a ÷ $b";
            }
            break;
        default:
            $a = random_int(1, 20);
            $b = random_int(1, 20);
            if (random_int(0, 1)) {
                $text = "$a + $b";
                $ans = $a + $b;
            } else {
                if ($a < $b) [$a, $b] = [$b, $a];
                $text = "$a − $b";
                $ans = $a - $b;
            }
    }
    return ['text' => $text, 'answer' => $ans];
}

function mf_fresh(string $level, string $nameA, string $nameB): array
{
    return [
        'ball' => MF_START,
        'turn' => 'A',          // Team, das angreift
        'stage' => 'main',      // 'main' = Angriff, 'steal' = Gegner darf klauen
        'level' => $level,
        'names' => ['A' => $nameA, 'B' => $nameB],
        'score' => ['A' => 0, 'B' => 0],
        'q' => mf_question($level),
        'msg' => 'Los geht’s! ' . $nameA . ' ist am Zug.',
    ];
}

// ---------- Zugriff: nur Lehrkräfte ----------
$user = $_SESSION['discord_user'] ?? null;
if (!$user || !empty($user['guest'])) {
    header('Location: /index.php');
    exit;
}
$me = (string)$user['id'];
$stT = db()->prepare('SELECT is_teacher FROM kr_profiles WHERE user_key = ?');
$stT->execute([$me]);
if ((int)$stT->fetchColumn() !== 1) {
    http_response_code(403);
    exit('Mathe-Fußball ist nur für Lehrkräfte.');
}

// ---------- Aktionen ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act'])) {
    $act = (string)$_POST['act'];
    $s = $_SESSION['mf'] ?? mf_fresh('leicht', 'Team A', 'Team B');

    if ($act === 'new') {
        $level = in_array($_POST['level'] ?? '', ['leicht', 'mittel', 'schwer'], true) ? (string)$_POST['level'] : 'leicht';
        $nA = trim((string)($_POST['nameA'] ?? '')) ?: 'Team A';
        $nB = trim((string)($_POST['nameB'] ?? '')) ?: 'Team B';
        $s = mf_fresh($level, mb_substr($nA, 0, 20), mb_substr($nB, 0, 20));
    } elseif ($act === 'answer') {
        $right = ($_POST['result'] ?? '') === 'right';
        $names = $s['names'];
        $team = $s['stage'] === 'main' ? $s['turn'] : ($s['turn'] === 'A' ? 'B' : 'A');
        $opp = $team === 'A' ? 'B' : 'A';

        if ($s['stage'] === 'main' && !$right) {
            // Falsch im Angriff: der Gegner darf klauen (neue Aufgabe)
            $s['stage'] = 'steal';
            $s['q'] = mf_question($s['level']);
            $s['msg'] = $names[$opp] . ' darf klauen!';
        } else {
            $dir = $team === 'A' ? 1 : -1;  // A schiebt Richtung Position 8, B Richtung 0
            if ($right) {
                $s['ball'] += $dir;
                $s['msg'] = $names[$team] . ' bringt den Ball voran.';
            } else {
                $s['msg'] = 'Auch falsch – der Ball bleibt liegen.';
            }
            if ($s['ball'] >= MF_LEN - 1) {
                $s['score']['A']++;
                $s['ball'] = MF_START;
                $s['msg'] = 'TOR für ' . $names['A'] . '!';
            } elseif ($s['ball'] <= 0) {
                $s['score']['B']++;
                $s['ball'] = MF_START;
                $s['msg'] = 'TOR für ' . $names['B'] . '!';
            }
            // Runde vorbei: nächste Aufgabe, anderes Team greift an
            $s['turn'] = $s['turn'] === 'A' ? 'B' : 'A';
            $s['stage'] = 'main';
            $s['q'] = mf_question($s['level']);
        }
    } else {
        mf_json(['ok' => false, 'error' => 'Unbekannte Aktion.'], 400);
    }

    $_SESSION['mf'] = $s;
    mf_json(['ok' => true, 'state' => $s]);
}

$state = $_SESSION['mf'] ?? mf_fresh('leicht', 'Team A', 'Team B');
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Mathe-Fußball</title>
<style>
body { margin:0; font-family: system-ui, sans-serif; background:#1d1030; color:#fff; }
.wrap { max-width:820px; margin:0 auto; padding:14px; }
a { color:#ff9ad5; }
.card { background:rgba(255,255,255,.08); border-radius:16px; padding:18px; margin:12px 0; text-align:center; }
.field { display:grid; grid-template-columns:repeat(9, 1fr); gap:4px; background:#2e7d32; border-radius:14px; padding:10px; }
.cell { aspect-ratio:1; background:rgba(255,255,255,.15); border-radius:8px; display:flex; align-items:center; justify-content:center; font-size:26px; }
.cell.goalA { background:#3498db; }
.cell.goalB { background:#e74c3c; }
.score { font-size:30px; font-weight:800; margin:6px 0; }
.question { font-size:44px; font-weight:800; margin:10px 0; }
.solution { font-size:16px; opacity:.8; }
.btn { border:none; border-radius:14px; padding:14px 22px; font-size:20px; font-weight:800; cursor:pointer; color:#fff; margin:6px; }
.ok { background:#2ecc71; }
.bad { background:#e74c3c; }
.btn-ghost { background:rgba(255,255,255,.2); font-size:15px; font-weight:700; }
input, select { padding:8px; border-radius:10px; border:none; font-size:15px; margin:4px; }
.msg { font-size:20px; min-height:28px; color:#ffd166; }
</style>
</head>
<body>
<div class="wrap">
    <p><a href="/lobby.php">← Lobby</a></p>
    <h1>Mathe-Fußball</h1>

    <div class="card">
        <form id="newForm">
            <input name="nameA" maxlength="20" placeholder="Name Team A" value="Team A">
            <input name="nameB" maxlength="20" placeholder="Name Team B" value="Team B">
            <select name="level">
                <option value="leicht">leicht (+ und −)</option>
                <option value="mittel">mittel (× und ÷)</option>
                <option value="schwer">schwer (mehrere Rechenschritte)</option>
            </select>
            <button class="btn btn-ghost" type="submit">Neues Spiel</button>
        </form>
    </div>

    <div class="card" id="game"></div>
</div>
<script>
const URL_MF = '/modes/mathe_fussball/mathe_fussball.php';
let S = <?= json_encode($state, JSON_UNESCAPED_UNICODE) ?>;
const game = document.getElementById('game');

function esc(s) { const d = document.createElement('div'); d.textContent = String(s ?? ''); return d.innerHTML; }

function render() {
    const cells = [];
    for (let i = 0; i < 9; i++) {
        let cls = 'cell';
        if (i === 0) cls += ' goalB';
        if (i === 8) cls += ' goalA';
        cells.push(`<div class="${cls}">${i === S.ball ? '⚽' : ''}</div>`);
    }
    const mainTeam = S.turn;
    const who = S.stage === 'main'
        ? `Am Ball: ${esc(S.names[mainTeam])}`
        : `Klau-Chance für ${esc(S.names[mainTeam === 'A' ? 'B' : 'A'])}`;
    game.innerHTML = `
        <div class="score">${esc(S.names.A)} ${S.score.A} : ${S.score.B} ${esc(S.names.B)}</div>
        <div class="field">${cells.join('')}</div>
        <p>${who}</p>
        <div class="question">${esc(S.q.text)} = ?</div>
        <p class="solution">Lösung: ${S.q.answer}</p>
        <button class="btn ok" data-result="right">✅ Richtig</button>
        <button class="btn bad" data-result="wrong">❌ Falsch</button>
        <p class="msg">${esc(S.msg)}</p>`;
}

async function send(params) {
    const r = await fetch(URL_MF, { method: 'POST', body: new URLSearchParams(params) });
    const j = await r.json();
    if (j.ok) { S = j.state; render(); }
}

game.addEventListener('click', (e) => {
    const b = e.target.closest('button[data-result]');
    if (b) send({ act: 'answer', result: b.dataset.result });
});

document.getElementById('newForm').addEventListener('submit', (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    send({ act: 'new', nameA: fd.get('nameA'), nameB: fd.get('nameB'), level: fd.get('level') });
});

render();
</script>
</body>
</html>
<!-- Version 1.0 -->
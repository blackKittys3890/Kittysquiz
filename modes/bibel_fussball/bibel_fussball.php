<?php
// Version 1.1
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
// modes/bibel_fussball/bibel_fussball.php – Bibelfußball. NUR für Lehrkräfte, läuft auf einem Bildschirm (Tafel/Beamer).
require_once dirname(__DIR__, 2) . '/config.php';
require_once dirname(__DIR__, 2) . '/db.php';

const BF_LEN = 9;     // Spielfeld: Positionen 0 bis 8
const BF_START = 4;   // Ball in der Mitte

// Fragen: [Bibelstelle, Frage, richtige Antwort, falsch 1, falsch 2, falsch 3]
const BF_QUESTIONS = [
    ['Psalm 23,1', 'Wie beginnt Psalm 23? „Der HERR ist mein …“', 'Hirte', 'König', 'Vater', 'Fels'],
    ['Johannes 3,16', 'Wen hat Gott so sehr geliebt, dass er seinen Sohn gab?', 'die Welt', 'Israel', 'Maria', 'die Engel'],
    ['1. Mose 1,1', 'Was schuf Gott im Anfang neben dem Himmel?', 'die Erde', 'das Meer', 'die Sterne', 'das Licht'],
    ['2. Mose 20', 'Wie viele Gebote gibt Gott Mose auf dem Berg Sinai?', '10', '7', '12', '5'],
    ['Matthäus 5,3', 'Wem gehört laut Matthäus 5,3 das Himmelreich?', 'den geistlich Armen', 'den Reichen', 'den Königen', 'den Starken'],
    ['Lukas 2,7', 'Wohin legte Maria das neugeborene Jesuskind?', 'in eine Krippe', 'auf ein Bett', 'in einen Korb', 'in eine Höhle'],
    ['1. Mose 2,21–22', 'Woraus formte Gott die Frau (Eva)?', 'aus einer Rippe Adams', 'aus einer Hand Adams', 'aus dem Kopf Adams', 'aus dem Fuß Adams'],
    ['Matthäus 26,15', 'Wie viele Silberstücke bekam Judas für den Verrat?', '30', '20', '40', '50'],
    ['Daniel 6,17', 'Wohin wurde Daniel geworfen, weil er betete?', 'in die Löwengrube', 'in den Feuerofen', 'in den Kerker', 'in die Wüste'],
    ['Lukas 2,4', 'In welcher Stadt wurde Jesus geboren?', 'Bethlehem', 'Jerusalem', 'Nazareth', 'Kapernaum'],
];

function bf_json(array $d, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

// Zufällige Frage, Antworten in zufälliger Reihenfolge
function bf_question(): array
{
    $item = BF_QUESTIONS[random_int(0, count(BF_QUESTIONS) - 1)];
    $opts = [$item[2], $item[3], $item[4], $item[5]];
    $order = [0, 1, 2, 3];
    shuffle($order);
    $shuffled = array_map(fn($k) => $opts[$k], $order);
    return [
        'ref' => $item[0],
        'text' => $item[1],
        'options' => $shuffled,
        'correct' => (int)array_search(0, $order, true),
        'answer' => $item[2],
    ];
}

function bf_fresh(string $nameA, string $nameB): array
{
    return [
        'ball' => BF_START,
        'turn' => 'A',
        'stage' => 'main',
        'names' => ['A' => $nameA, 'B' => $nameB],
        'score' => ['A' => 0, 'B' => 0],
        'q' => bf_question(),
        'msg' => 'Los geht’s! ' . $nameA . ' ist am Zug.',
        'reveal' => false,
    ];
}

// Publikum sieht die Lösung nur, wenn die Lehrkraft sie für alle freigegeben hat
function bf_public(array $s): array
{
    if (empty($s['reveal'])) unset($s['q']['answer']);
    return $s;
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
    exit('Bibelfußball ist nur für Lehrkräfte.');
}

// Zustand für das Publikum-Fenster (ohne Lösung, solange nicht freigegeben)
if (isset($_GET['state'])) {
    bf_json(['ok' => true, 'state' => bf_public($_SESSION['bf'] ?? bf_fresh('Team A', 'Team B'))]);
}

// ---------- Aktionen ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['act'])) {
    $act = (string)$_POST['act'];
    $s = $_SESSION['bf'] ?? bf_fresh('Team A', 'Team B');

    if ($act === 'new') {
        $nA = trim((string)($_POST['nameA'] ?? '')) ?: 'Team A';
        $nB = trim((string)($_POST['nameB'] ?? '')) ?: 'Team B';
        $s = bf_fresh(mb_substr($nA, 0, 20), mb_substr($nB, 0, 20));
    } elseif ($act === 'reveal') {
        $s['reveal'] = empty($s['reveal']);
    } elseif ($act === 'answer') {
        $s['reveal'] = false;
        $right = ($_POST['result'] ?? '') === 'right';
        $names = $s['names'];
        $team = $s['stage'] === 'main' ? $s['turn'] : ($s['turn'] === 'A' ? 'B' : 'A');
        $opp = $team === 'A' ? 'B' : 'A';

        if ($s['stage'] === 'main' && !$right) {
            $s['stage'] = 'steal';
            $s['q'] = bf_question();
            $s['msg'] = $names[$opp] . ' darf klauen!';
        } else {
            $dir = $team === 'A' ? 1 : -1;  // A schiebt Richtung Position 8, B Richtung 0
            if ($right) {
                $s['ball'] += $dir;
                $s['msg'] = $names[$team] . ' bringt den Ball voran.';
            } else {
                $s['msg'] = 'Auch falsch – der Ball bleibt liegen.';
            }
            if ($s['ball'] >= BF_LEN - 1) {
                $s['score']['A']++;
                $s['ball'] = BF_START;
                $s['msg'] = 'TOR für ' . $names['A'] . '!';
            } elseif ($s['ball'] <= 0) {
                $s['score']['B']++;
                $s['ball'] = BF_START;
                $s['msg'] = 'TOR für ' . $names['B'] . '!';
            }
            $s['turn'] = $s['turn'] === 'A' ? 'B' : 'A';
            $s['stage'] = 'main';
            $s['q'] = bf_question();
        }
    } else {
        bf_json(['ok' => false, 'error' => 'Unbekannte Aktion.'], 400);
    }

    $_SESSION['bf'] = $s;
    bf_json(['ok' => true, 'state' => $s]);
}

$state = $_SESSION['bf'] ?? bf_fresh('Team A', 'Team B');
$pub = isset($_GET['publikum']);
if ($pub) {
    $state = bf_public($state);
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Bibelfußball</title>
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
.ref { font-size:18px; opacity:.85; margin-top:6px; }
.question { font-size:30px; font-weight:800; margin:10px 0; }
.options { font-size:18px; margin:8px 0; }
.solution { font-size:15px; opacity:.8; }
.btn { border:none; border-radius:14px; padding:14px 22px; font-size:20px; font-weight:800; cursor:pointer; color:#fff; margin:6px; }
.ok { background:#2ecc71; }
.bad { background:#e74c3c; }
.btn-ghost { background:rgba(255,255,255,.2); font-size:15px; font-weight:700; }
input { padding:8px; border-radius:10px; border:none; font-size:15px; margin:4px; }
.msg { font-size:20px; min-height:28px; color:#ffd166; }
</style>
</head>
<body>
<div class="wrap">
    <p><a href="/lobby.php">← Lobby</a> · <a href="/modes/bibel_fussball/bibel_fussball.php?publikum=1" target="_blank">Publikum-Fenster öffnen</a></p>
    <h1>Bibelfußball</h1>

    <div class="card">
        <form id="newForm">
            <input name="nameA" maxlength="20" placeholder="Name Team A" value="Team A">
            <input name="nameB" maxlength="20" placeholder="Name Team B" value="Team B">
            <button class="btn btn-ghost" type="submit">Neues Spiel</button>
        </form>
    </div>

    <div class="card" id="game"></div>
</div>
<script>
const URL_BF = '/modes/bibel_fussball/bibel_fussball.php';
let S = <?= json_encode($state, JSON_UNESCAPED_UNICODE) ?>;
const PUB = <?= $pub ? 'true' : 'false' ?>;
let localShow = false;   // Lösung nur auf diesem Gerät
const game = document.getElementById('game');
const LETTERS = ['A', 'B', 'C', 'D'];

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
    const opts = S.q.options.map((o, i) => `${LETTERS[i]}: ${esc(o)}`).join('  ·  ');
    game.innerHTML = `
        <div class="score">${esc(S.names.A)} ${S.score.A} : ${S.score.B} ${esc(S.names.B)}</div>
        <div class="field">${cells.join('')}</div>
        <p>${who}</p>
        <div class="ref">${esc(S.q.ref)}</div>
        <div class="question">${esc(S.q.text)}</div>
        <div class="options">${opts}</div>
        ${PUB
            ? (S.reveal ? `<p class="solution big">Lösung: ${esc(S.q.answer)}</p>` : '')
            : `<p class="solution">${localShow ? 'Lösung: ' + esc(S.q.answer) : ''}
               <button class="btn btn-ghost" data-local="1">${localShow ? 'Lösung hier ausblenden' : 'Lösung nur hier zeigen'}</button>
               <button class="btn btn-ghost" data-reveal="1">${S.reveal ? 'Lösung für alle ausblenden' : 'Lösung für alle zeigen'}</button></p>`}
        ${PUB ? '' : `<button class="btn ok" data-result="right">✅ Richtig</button>
        <button class="btn bad" data-result="wrong">❌ Falsch</button>`}
        <p class="msg">${esc(S.msg)}</p>`;
}

async function send(params) {
    const r = await fetch(URL_BF, { method: 'POST', body: new URLSearchParams(params) });
    const j = await r.json();
    if (j.ok) { S = j.state; render(); }
}

game.addEventListener('click', (e) => {
    const b = e.target.closest('button');
    if (!b) return;
    if (b.dataset.result) send({ act: 'answer', result: b.dataset.result });
    else if (b.dataset.reveal) send({ act: 'reveal' });
    else if (b.dataset.local) { localShow = !localShow; render(); }
});

document.getElementById('newForm').addEventListener('submit', (e) => {
    e.preventDefault();
    const fd = new FormData(e.target);
    send({ act: 'new', nameA: fd.get('nameA'), nameB: fd.get('nameB') });
});

render();
if (PUB) {
    // Publikum-Fenster: keine Steuerung, aktualisiert sich selbst
    document.getElementById('newForm').parentElement.style.display = 'none';
    setInterval(async () => {
        const r = await fetch(URL_BF + '?state=1', { cache: 'no-store' });
        const j = await r.json();
        if (j.ok) { S = j.state; render(); }
    }, 1000);
}
</script>
</body>
</html>
<!-- Version 1.1 -->
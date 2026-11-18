<?php
// Version 1.1
date_default_timezone_set('Europe/Berlin');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$user = $_SESSION['discord_user'] ?? null;
if (!$user) {
    header('Location: index.php');
    exit;
}
$me = $user['id'];
$myName = $user['name'] ?? $user['username'];

function flash(string $text): void
{
    $_SESSION['av_flash'] = $text;
}

function loadPoll(string $code): ?array
{
    $s = db()->prepare('SELECT * FROM kr_av_polls WHERE code = ?');
    $s->execute([$code]);
    return $s->fetch() ?: null;
}

// Eine Auswahl aus Tag und Uhrzeit in ein Datum umwandeln. 24:00 ist der Beginn des Folgetags.
function slotToDateTime(string $day, string $time): ?DateTime
{
    if ($time === '24:00') {
        $d = DateTime::createFromFormat('Y-m-d H:i', $day . ' 00:00');
        return $d ? $d->modify('+1 day') : null;
    }
    return DateTime::createFromFormat('Y-m-d H:i', $day . ' ' . $time) ?: null;
}

// Uhrzeiten in halben Stunden von 00:00 bis 24:00
function timeOptions(): array
{
    $out = [];
    for ($i = 0; $i <= 48; $i++) {
        $m = $i * 30;
        $out[] = sprintf('%02d:%02d', intdiv($m, 60), $m % 60);
    }
    return $out;
}

function dayLabel(string $day): string
{
    $wd = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'][(int)date('w', strtotime($day))];
    return $wd . ' ' . date('d.m.', strtotime($day));
}

$msg = $_SESSION['av_flash'] ?? '';
unset($_SESSION['av_flash']);

$code = strtoupper(trim($_GET['p'] ?? $_POST['p'] ?? ''));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create_poll') {
        $title = trim($_POST['title'] ?? '');
        $sd = DateTime::createFromFormat('Y-m-d', $_POST['start'] ?? '');
        $ed = DateTime::createFromFormat('Y-m-d', $_POST['end'] ?? '');

        if ($title === '' || mb_strlen($title) > 80) {
            flash('Titel: 1 bis 80 Zeichen.');
        } elseif (!$sd || !$ed || $ed < $sd) {
            flash('Bitte einen gültigen Zeitraum angeben.');
        } elseif ($sd->diff($ed)->days > 30) {
            flash('Der Zeitraum darf höchstens 31 Tage lang sein.');
        } else {
            $newCode = strtoupper(bin2hex(random_bytes(3)));
            db()->prepare('INSERT INTO kr_av_polls (code, title, start_date, end_date, owner_id) VALUES (?, ?, ?, ?, ?)')
                ->execute([$newCode, $title, $sd->format('Y-m-d'), $ed->format('Y-m-d'), $me]);
            header('Location: verfuegbarkeit.php?p=' . $newCode);
            exit;
        }
    }

    elseif ($action === 'join') {
        $c = strtoupper(trim($_POST['code'] ?? ''));
        if (loadPoll($c)) {
            header('Location: verfuegbarkeit.php?p=' . $c);
            exit;
        }
        flash('Umfrage nicht gefunden.');
    }

    elseif ($action === 'add_entry') {
        $poll = loadPoll($code);
        $times = timeOptions();

        $fromDay = $_POST['from_day'] ?? '';
        $fromTime = $_POST['from_time'] ?? '';
        $toDay = $_POST['to_day'] ?? '';
        $toTime = $_POST['to_time'] ?? '';
        $status = ($_POST['status'] ?? '') === 'yes' ? 1 : 0;

        // Alle erlaubten Tage der Umfrage
        $allowedDays = [];
        if ($poll) {
            $x = new DateTime($poll['start_date']);
            $end = new DateTime($poll['end_date']);
            while ($x <= $end) {
                $allowedDays[] = $x->format('Y-m-d');
                $x->modify('+1 day');
            }
        }

        $from = slotToDateTime($fromDay, $fromTime);
        $to = slotToDateTime($toDay, $toTime);

        if (!$poll) {
            flash('Umfrage nicht gefunden.');
        } elseif (!in_array($fromDay, $allowedDays, true) || !in_array($toDay, $allowedDays, true)
            || !in_array($fromTime, $times, true) || !in_array($toTime, $times, true)) {
            flash('Bitte Tag und Uhrzeit aus der Auswahl wählen.');
        } elseif (!$from || !$to || $to <= $from) {
            flash('Das Ende muss nach dem Anfang liegen.');
        } elseif ($fromTime === '24:00') {
            flash('Der Anfang kann nicht 24:00 Uhr sein.');
        } else {
            db()->prepare('INSERT INTO kr_av_entries (poll_id, user_id, username, from_dt, to_dt, status) VALUES (?, ?, ?, ?, ?, ?)')
                ->execute([$poll['id'], $me, $myName, $from->format('Y-m-d H:i:s'), $to->format('Y-m-d H:i:s'), $status]);
        }
    }

    elseif ($action === 'delete_entry') {
        $poll = loadPoll($code);
        if ($poll) {
            $eid = (int)($_POST['entry_id'] ?? 0);
            if ($poll['owner_id'] === $me) {
                db()->prepare('DELETE FROM kr_av_entries WHERE id = ? AND poll_id = ?')->execute([$eid, $poll['id']]);
            } else {
                db()->prepare('DELETE FROM kr_av_entries WHERE id = ? AND poll_id = ? AND user_id = ?')
                    ->execute([$eid, $poll['id'], $me]);
            }
        }
    }

    header('Location: verfuegbarkeit.php' . ($code ? '?p=' . $code : ''));
    exit;
}

// ---------- Auswertung ----------

// Status eines Tages für eine Person: yes, yes_part, no, no_part, mixed oder none
function dayStatus(array $entries, string $day): string
{
    $ds = strtotime($day . ' 00:00:00');
    $de = $ds + 86400;
    $yes = 0;
    $no = 0;

    foreach ($entries as $en) {
        $a = max($ds, strtotime($en['from_dt']));
        $b = min($de, strtotime($en['to_dt']));
        if ($b > $a) {
            $min = intdiv($b - $a, 60);
            if ($en['status']) $yes += $min;
            else $no += $min;
        }
    }
    $yes = min(1440, $yes);
    $no  = min(1440, $no);

    if ($yes >= 1440) return 'yes';
    if ($no >= 1440) return 'no';
    if ($yes > 0 && $no === 0) return 'yes_part';
    if ($no > 0 && $yes === 0) return 'no_part';
    if ($yes > 0 || $no > 0) return 'mixed';
    return 'none';
}

const SYMBOLS = [
    'yes' => '✓', 'yes_part' => '✓ teils', 'no' => '✗', 'no_part' => '✗ teils', 'mixed' => '~', 'none' => '–',
];

$poll = $code ? loadPoll($code) : null;
$entries = [];
$days = [];
if ($poll) {
    $s = db()->prepare('SELECT * FROM kr_av_entries WHERE poll_id = ? ORDER BY username, from_dt');
    $s->execute([$poll['id']]);
    $entries = $s->fetchAll();

    $x = new DateTime($poll['start_date']);
    $end = new DateTime($poll['end_date']);
    while ($x <= $end) {
        $days[] = $x->format('Y-m-d');
        $x->modify('+1 day');
    }
}

// Einträge je Person gruppieren
$byUser = [];
$names = [];
foreach ($entries as $en) {
    $byUser[$en['user_id']][] = $en;
    $names[$en['user_id']] = $en['username'];
}

$times = timeOptions();
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Verfügbarkeit – <?= APP_NAME ?></title>
    <style>
        body { font-family: system-ui, sans-serif; background: #1f1438; color: #f5f0ff; margin: 0; padding: 24px; }
        a { color: #7fe7d9; }
        h1 { margin: 10px 0; }
        h2 { margin: 6px 0 10px; font-size: 20px; }
        .panel { background: rgba(255,255,255,.06); border-radius: 14px; padding: 18px; margin-bottom: 20px; }
        input, select { padding: 10px; border-radius: 8px; border: none; font: inherit; box-sizing: border-box; background: #3b2a6b; color: #fff; }
        input[type=text], input[type=date], select { width: 100%; margin-top: 6px; }
        label { display: block; margin-top: 12px; font-size: 14px; }
        button { margin-top: 12px; padding: 10px 16px; border: none; border-radius: 8px; background: #3ddbc4; color: #1f1438; font-weight: bold; cursor: pointer; }
        button.danger { background: #ff3b5c; color: #fff; padding: 6px 10px; font-size: 13px; margin: 0; }
        .msg { color: #7fe7d9; font-weight: bold; }
        .muted { opacity: .65; font-size: 14px; }
        .share { word-break: break-all; background: rgba(0,0,0,.25); padding: 10px; border-radius: 8px; }

        .grid { overflow-x: auto; border-radius: 12px; }
        table { border-collapse: collapse; background: rgba(255,255,255,.04); }
        th, td { padding: 6px 8px; border: 1px solid rgba(255,255,255,.1); text-align: center; min-width: 64px; font-size: 14px; }
        th.day { white-space: nowrap; }
        td.nm { text-align: left; position: sticky; left: 0; background: #2c1d52; min-width: 120px; font-weight: bold; }
        .yes      { background: #1f7a5c; color: #fff; }
        .yes_part { background: #2f9e7a; color: #fff; }
        .no       { background: #7a1f2c; color: #fff; }
        .no_part  { background: #a0404d; color: #fff; }
        .mixed    { background: #7a6a1f; color: #fff; }
        .none     { color: #777; }
        tfoot td  { font-weight: bold; background: rgba(0,0,0,.2); }

        .entry { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid rgba(255,255,255,.1); flex-wrap: wrap; }
        .tag { display: inline-block; padding: 2px 8px; border-radius: 7px; font-size: 12px; font-weight: bold; }
        .tag.yes { background: #1f7a5c; }
        .tag.no  { background: #7a1f2c; }

        .formrow { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .slot { display: grid; grid-template-columns: 1.4fr 1fr; gap: 8px; }
        @media (max-width: 700px) {
            body { padding: 14px; }
            .formrow, .slot { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <p><a href="lobby.php">← Lobby</a></p>
    <h1>Verfügbarkeit</h1>

    <?php if ($msg): ?><p class="msg"><?= htmlspecialchars($msg) ?></p><?php endif; ?>

    <?php if (!$poll): ?>
        <div class="panel">
            <h2>Neue Umfrage</h2>
            <form method="post">
                <input type="hidden" name="action" value="create_poll">
                <label>Titel
                    <input type="text" name="title" maxlength="80" placeholder="z. B. Spieleabend im Dezember" required>
                </label>
                <div class="formrow">
                    <label>Von
                        <input type="date" name="start" required>
                    </label>
                    <label>Bis
                        <input type="date" name="end" required>
                    </label>
                </div>
                <button type="submit">Umfrage erstellen</button>
            </form>
        </div>

        <div class="panel">
            <h2>Umfrage beitreten</h2>
            <form method="post">
                <input type="hidden" name="action" value="join">
                <label>Code
                    <input type="text" name="code" maxlength="8" placeholder="Code eingeben" required>
                </label>
                <button type="submit">Beitreten</button>
            </form>
        </div>
    <?php else: ?>
        <div class="panel">
            <h2><?= htmlspecialchars($poll['title']) ?></h2>
            <p class="muted">Zeitraum: <?= htmlspecialchars(date('d.m.Y', strtotime($poll['start_date']))) ?> bis <?= htmlspecialchars(date('d.m.Y', strtotime($poll['end_date']))) ?></p>
            <p class="muted">Link zum Teilen:</p>
            <div class="share">verfuegbarkeit.php?p=<?= htmlspecialchars($poll['code']) ?></div>
        </div>

        <div class="panel">
            <h2>Übersicht</h2>
            <div class="grid">
                <table>
                    <thead>
                        <tr>
                            <th class="nm" style="text-align:left">Name</th>
                            <?php foreach ($days as $d): ?>
                                <th class="day"><?= htmlspecialchars(dayLabel($d)) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$byUser): ?>
                            <tr><td class="nm" colspan="<?= count($days) + 1 ?>">Noch keine Einträge.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($byUser as $uid => $list): ?>
                            <tr>
                                <td class="nm"><?= htmlspecialchars($names[$uid]) ?></td>
                                <?php foreach ($days as $d):
                                    $st = dayStatus($list, $d); ?>
                                    <td class="<?= $st ?>"><?= SYMBOLS[$st] ?></td>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <?php if ($byUser): ?>
                    <tfoot>
                        <tr>
                            <td class="nm" style="text-align:left">Verfügbar</td>
                            <?php foreach ($days as $d):
                                $count = 0;
                                foreach ($byUser as $list) {
                                    $st = dayStatus($list, $d);
                                    if ($st === 'yes' || $st === 'yes_part') $count++;
                                } ?>
                                <td><?= $count ?></td>
                            <?php endforeach; ?>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
            <p class="muted">✓ = ganz verfügbar, ✓ teils = nur ein Teil des Tages, ✗ = nicht verfügbar, – = keine Angabe.</p>
        </div>

        <div class="panel">
            <h2>Meine Zeiten eintragen</h2>
            <form method="post">
                <input type="hidden" name="action" value="add_entry">
                <input type="hidden" name="p" value="<?= htmlspecialchars($poll['code']) ?>">

                <label>Von</label>
                <div class="slot">
                    <select name="from_day" required>
                        <?php foreach ($days as $d): ?>
                            <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars(dayLabel($d)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="from_time" required>
                        <?php foreach ($times as $t): if ($t === '24:00') continue; ?>
                            <option value="<?= $t ?>" <?= $t === '18:00' ? 'selected' : '' ?>><?= $t ?> Uhr</option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <label>Bis</label>
                <div class="slot">
                    <select name="to_day" required>
                        <?php foreach ($days as $d): ?>
                            <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars(dayLabel($d)) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <select name="to_time" required>
                        <?php foreach ($times as $t): if ($t === '00:00') continue; ?>
                            <option value="<?= $t ?>" <?= $t === '22:00' ? 'selected' : '' ?>><?= $t === '24:00' ? '24:00 Uhr (Tagesende)' : $t . ' Uhr' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <label>Status
                    <select name="status">
                        <option value="yes">Ich kann</option>
                        <option value="no">Ich kann nicht</option>
                    </select>
                </label>
                <button type="submit">Eintragen</button>
            </form>
        </div>

        <div class="panel">
            <h2>Alle Einträge</h2>
            <?php if (!$entries): ?>
                <p class="muted">Noch keine Einträge.</p>
            <?php endif; ?>
            <?php foreach ($entries as $en):
                $canDelete = $en['user_id'] === $me || $poll['owner_id'] === $me; ?>
                <div class="entry">
                    <div>
                        <strong><?= htmlspecialchars($en['username']) ?></strong>
                        <span class="tag <?= $en['status'] ? 'yes' : 'no' ?>"><?= $en['status'] ? 'kann' : 'kann nicht' ?></span>
                        <span class="muted"><?= htmlspecialchars(date('d.m. H:i', strtotime($en['from_dt']))) ?> – <?= htmlspecialchars(date('d.m. H:i', strtotime($en['to_dt']))) ?> Uhr</span>
                    </div>
                    <?php if ($canDelete): ?>
                        <form method="post" onsubmit="return confirm('Eintrag löschen?')">
                            <input type="hidden" name="action" value="delete_entry">
                            <input type="hidden" name="p" value="<?= htmlspecialchars($poll['code']) ?>">
                            <input type="hidden" name="entry_id" value="<?= (int)$en['id'] ?>">
                            <button class="danger" type="submit">Löschen</button>
                        </form>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
<footer style="text-align:center;margin:30px 0 10px;font-size:14px;opacity:.8;"><a href="/impressum.php" style="color:#9ff0ff;">Impressum</a> · <a href="/datenschutz.php" style="color:#9ff0ff;">Datenschutz</a></footer>
        <p><a href="verfuegbarkeit.php">← Andere Umfrage öffnen oder neue erstellen</a></p>
    <?php endif; ?>
</body>
</html>
<!-- Version 1.1 -->
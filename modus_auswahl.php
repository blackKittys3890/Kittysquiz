// Version 1.2
<?php
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
// modus_auswahl.php
if (!isset($room) || !isset($isHost) || !$isHost) return;

$code = $room['code'] ?? strtoupper($_GET['code'] ?? '');
$currentMode = $room['mode'] ?? 'points';

// Wenn das Formular abgeschickt wurde, direkt weiterleiten
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['switch_mode'])) {
    $newMode = $_POST['switch_mode'];
    if (in_array($newMode, ['points', 'hearts', 'eingestuhlt', 'dumm_drauf'], true)) {
        $upd = db()->prepare('UPDATE kr_rooms SET mode = ? WHERE code = ?');
        $upd->execute([$newMode, $code]);
        
        if ($newMode === 'points') { header('Location: board.php?code=' . $code); exit; }
        elseif ($newMode === 'hearts') { header('Location: herzen.php?code=' . $code); exit; }
        elseif ($newMode === 'eingestuhlt') { header('Location: eingestuhlt.php?code=' . $code); exit; }
        elseif ($newMode === 'dumm_drauf') { header('Location: dumm_drauf.php?code=' . $code); exit; }
    }
}
?>

<style>
    .mode-switch-box {
        position: fixed;
        top: 10px;
        left: 10px;
        z-index: 1000;
        background: rgba(20, 10, 40, 0.95);
        padding: 8px 12px;
        border-radius: 10px;
        border: 2px solid #7b2ff7;
        box-shadow: 0 4px 15px rgba(0,0,0,0.5);
        display: flex;
        align-items: center;
        gap: 8px;
        font-family: system-ui, sans-serif;
    }
    .mode-switch-box select {
        padding: 6px 10px;
        border-radius: 6px;
        border: none;
        background: #3b2a6b;
        color: #fff;
        font-weight: bold;
        cursor: pointer;
    }
</style>

<form method="POST" class="mode-switch-box" id="modeForm">
    <span style="font-size:14px; color:#fff;">Modus:</span>
    <select name="switch_mode" id="globalModeSel">
        <option value="points" <?= $currentMode === 'points' ? 'selected' : '' ?>>Punkte-Board</option>
        <option value="hearts" <?= $currentMode === 'hearts' ? 'selected' : '' ?>>Der Dümmste Fliegt</option>
        <option value="eingestuhlt" <?= $currentMode === 'eingestuhlt' ? 'selected' : '' ?>>Eingeschult</option>
        <option value="dumm_drauf" <?= $currentMode === 'dumm_drauf' ? 'selected' : '' ?>>Hau drauf</option>
    </select>
</form>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const sel = document.getElementById('globalModeSel');
    if (!sel) return;
    
    sel.addEventListener('change', (e) => {
        const text = sel.options[sel.selectedIndex].text;
        if (confirm(`Wirklich in den Modus "` + text + `" wechseln? Das aktuelle Spiel wird verlassen.`)) {
            document.getElementById('modeForm').submit();
        } else {
            sel.value = "<?= $currentMode ?>";
        }
    });
});
</script>
// Version 1.2
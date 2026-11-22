<?php
// Version 1.3
// Profilbild unten links (Kreis). Führt zu „Mein Profil“. Nur für angemeldete Nutzer.
if (empty($_SESSION['discord_user'])) return;
// Auf dem Spielfeld kein Profil-Kreis
if (str_contains($_SERVER['SCRIPT_NAME'] ?? '', 'board.php')) return;
$u = $_SESSION['discord_user'];
$avatar = kr_avatar((string)$u['id'], $u['avatar'] ?? null);
$name = (string)($u['name'] ?? $u['username'] ?? '');
?>
<?php $side = str_contains($_SERVER['SCRIPT_NAME'] ?? '', 'board.php') ? 'right' : 'left'; ?>
<a class="profile-bubble <?= $side ?>" href="/profile/index.php" title="Mein Profil: <?= htmlspecialchars($name) ?>">
    <img src="<?= htmlspecialchars($avatar) ?>" alt="">
</a>
<?php
// Version 1.3
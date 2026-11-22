<?php
// Version 1.0
// Profilbild unten links (Kreis). Führt zu „Mein Profil“. Nur für angemeldete Nutzer.
if (empty($_SESSION['discord_user'])) return;
$u = $_SESSION['discord_user'];
$avatar = getAvatarUrl(['id' => $u['id'], 'avatar' => $u['avatar'] ?? null]);
$name = (string)($u['name'] ?? $u['username'] ?? '');
?>
<a class="profile-bubble" href="/profile/index.php" title="Mein Profil: <?= htmlspecialchars($name) ?>">
    <img src="<?= htmlspecialchars($avatar) ?>" alt="">
</a>
<?php
// Version 1.0
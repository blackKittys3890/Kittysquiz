<?php
// Version 1.0
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Changelog – KittysRätsel</title>
    <style>
        body { margin: 0; padding: 22px; min-height: 100vh; font-family: system-ui, sans-serif; color: #fff;
            background: linear-gradient(160deg, #1d1b3a, #2c1046 60%, #14233b); }
        a { color: #9ff0ff; }
        .card { max-width: 700px; margin: 40px auto; background: rgba(255,255,255,.07); border-radius: 22px; padding: 28px; line-height: 1.6; }
        h1 { margin-top: 0; }
        h2 { font-size: 18px; margin-top: 26px; color: #ffd23f; }
        h3 { font-size: 15px; margin-top: 16px; color: #9ff0ff; }
        ul { padding-left: 20px; }
        li { margin-bottom: 6px; }
        .stand { opacity: .75; font-size: 14px; }
    </style>
</head>
<body>
    <div class="card">
        <p><a href="/index.php">← Zurück zur Startseite</a></p>

        <h1>Changelog</h1>

        <h2>Version 1.0 <span class="stand">· Stand 19. November 2026</span></h2>

        <h3>Spielmodi</h3>
        <ul>
            <li><strong>Punkte:</strong> Spielfeld mit Teams, Punkten, Timer und Endtafel.</li>
            <li><strong>Herzen:</strong> Spielmodus mit Herzen-System und Abstimmung.</li>
            <li><strong>Eingeschult:</strong> Fragenrunden mit Import von Fragen per CSV und Excel-Vorlage.</li>
            <li><strong>Hau drauf:</strong> Begriffsraten mit Tipps, Punkten je nach Anzahl der gezeigten Tipps und Auflösung.</li>
        </ul>

        <h3>Räume und Spieler</h3>
        <ul>
            <li>Räume mit Code erstellen und beitreten.</li>
            <li>Anmeldung mit Discord, Google oder lokalem Konto.</li>
            <li>Spielerliste zeigt nur aktive Spieler.</li>
            <li>„Wieder beitreten“-Hinweis in der Lobby, wenn du zuletzt in einem Raum warst.</li>
            <li>Moduswechsel durch den Host, alle Spieler folgen automatisch.</li>
        </ul>

        <h3>Kamera und Mikrofon</h3>
        <ul>
            <li>Kamera und Mikrofon auf Klick, direkt zwischen den Spielern übertragen.</li>
            <li>Anzeige von Video oder Profilbild sowie Status für Kamera und Mikrofon.</li>
        </ul>

        <h3>Ton</h3>
        <ul>
            <li>Sound-Schalter im Spielfeld, die Einstellung gilt für das jeweilige Gerät.</li>
        </ul>

        <h3>Verwaltung und Technik</h3>
        <ul>
            <li>Automatisches Aufräumen: Räume und Spieldaten werden nach 30 Tagen gelöscht.</li>
            <li>Gemeinsamer Ordner für Fragen, Modi getrennt nach Ordnern.</li>
            <li>Automatische Bereitstellung über GitHub Actions und SFTP.</li>
        </ul>

        <h3>Rechtliches</h3>
        <ul>
            <li>Impressum und Datenschutzerklärung mit Fußzeile auf den Hauptseiten.</li>
        </ul>
    </div>
</body>
</html>
<!-- Version 1.0 -->
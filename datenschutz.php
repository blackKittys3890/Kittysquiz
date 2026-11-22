<?php
// Version 1.10
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Datenschutzerklärung – KittysRätsel</title>
    <style>
        body { margin: 0; padding: 22px; min-height: 100vh; font-family: system-ui, sans-serif; color: #fff;
            background: linear-gradient(160deg, #1d1b3a, #2c1046 60%, #14233b); }
        a { color: #9ff0ff; }
        .card { max-width: 700px; margin: 40px auto; background: rgba(255,255,255,.07); border-radius: 22px; padding: 28px; line-height: 1.6; }
        h1 { margin-top: 0; }
        h2 { font-size: 18px; margin-top: 24px; color: #ffd23f; }
        h3 { font-size: 15px; margin-top: 16px; color: #9ff0ff; }
        ul { padding-left: 20px; }
        li { margin-bottom: 6px; }
        .platzhalter { background: #ffd23f; color: #2a1b00; padding: 0 4px; border-radius: 4px; font-weight: bold; }
    </style>
</head>
<body>
    <div class="card">
        <p><a href="/index.php">← Zurück zur Startseite</a> · <a href="/impressum.php">Impressum</a></p>

        <h1>Datenschutzerklärung</h1>

        <h2>1. Verantwortliche Stelle</h2>
        <p>
            Verantwortlich für die Datenverarbeitung auf dieser Website ist:
        </p>
        <p>
            Eliah Weichert<br>
            c/o Impressumservice Dein-Impressum<br>
            Stettiner Str. 41<br>
            35410 Hungen<br>
            Deutschland<br>
            E-Mail: <a href="mailto:eliah@sudi.de">eliah@sudi.de</a>
        </p>

        <h2>2. Überblick</h2>
        <p>
            KittysRätsel ist ein Quiz- und Spielportal für Gruppen. Spieler melden sich an, treten Räumen bei und
            spielen gemeinsam, optional mit Kamera und Mikrofon. Im Folgenden erfahren Sie, welche Daten dabei
            verarbeitet werden, warum und wie lange.
        </p>

        <h2>3. Hosting und Server-Logdateien</h2>
        <p>
            Die Website wird bei der <strong>Hetzner Online GmbH</strong> (Industriestr. 25, 91710 Gunzenhausen) gehostet.
            Beim Aufruf der Seiten erfasst der Server technisch notwendige Daten wie IP-Adresse, Datum und Uhrzeit,
            aufgerufene Seite und Browser-Kennung. Details finden Sie in der Datenschutzerklärung von Hetzner:
            <a href="https://www.hetzner.com/de/legal/privacy-policy/" target="_blank" rel="noopener">https://www.hetzner.com/de/legal/privacy-policy/</a>.
            Rechtsgrundlage ist Art. 6 Abs. 1 lit. f DSGVO (sicherer und stabiler Betrieb der Website).
        </p>
        <p>
            Die Spieldaten werden in einer Datenbank bei der Hetzner Online GmbH gespeichert. Die Datenbank-Server stehen in Deutschland.
        </p>

        <h2>4. Anmeldung</h2>
        <p>
            Sie können sich auf drei Wegen anmelden. Die Anmeldung ist freiwillig. Ohne Anmeldung können Sie nicht spielen.
        </p>

        <h3>a) Anmeldung mit Discord</h3>
        <p>
            Bei der Anmeldung mit Discord übermittelt Discord (Discord Inc., USA) an uns Ihre Discord-ID, Ihren
            Benutzernamen, Ihr Profilbild und, falls vorhanden, Ihre Profil-Dekoration. Wir speichern diese Angaben,
            um Ihr Spielerprofil im Raum anzuzeigen. Zusätzlich fragen wir die E-Mail-Adresse Ihres Discord-Kontos ab, sofern Sie
            sie bei Discord bestätigt haben und sie bei uns noch nicht verwendet wird (siehe Abschnitt 4d).
        </p>

        <h3>b) Anmeldung mit Google</h3>
        <p>
            Bei der Anmeldung mit Google übermittelt Google LLC (USA) Ihren Namen und Ihr Profilbild an uns. Wir speichern
            diese Angaben, um Ihr Spielerprofil anzuzeigen. Zusätzlich übermittelt Google die E-Mail-Adresse Ihres Google-Kontos,
            sofern sie bestätigt ist und bei uns noch nicht verwendet wird (siehe Abschnitt 4d).
        </p>

        <h3>c) Lokales Konto</h3>
        <p>
            Bei einem lokalen Konto speichern wir Ihren gewählten Benutzernamen, Ihre E-Mail-Adresse, optional ein
            von Ihnen hochgeladenes Profilbild und Ihr Passwort. Das Passwort wird nur als nicht umkehrbarer Hash (bcrypt-Verfahren
            über PHP <code>password_hash</code>) gespeichert, nie im Klartext.
        </p>

        <h3>d) E-Mail-Adresse</h3>
        <p>
            Für jedes Konto speichern wir eine E-Mail-Adresse. Sie wird bei lokalen Konten bei der Registrierung angegeben.
            Bei Discord- und Google-Konten übernehmen wir die bestätigte Adresse des Anbieters. Ist keine vorhanden oder
            schon bei uns verwendet, fragen wir sie direkt ab.
            Wir nutzen sie ausschließlich, um Ihnen den Zugang zurückzusetzen und wichtige Hinweise zum Konto zu senden.
            Die Angabe ist Pflicht, um die Seite zu nutzen. Eine Weitergabe an Dritte erfolgt nicht.
            Rechtsgrundlage ist Art. 6 Abs. 1 lit. b DSGVO.
        </p>

        <p>
            Rechtsgrundlage für die Anmeldung ist Art. 6 Abs. 1 lit. b DSGVO (Nutzung der Spielfunktionen).
        </p>

        <h2>5. Sitzung und Cookies</h2>
        <p>
            Nach der Anmeldung setzen wir ein Sitzungs-Cookie, damit Sie angemeldet bleiben. Dieses Cookie ist technisch
            zwingend nötig und wird beim Abmelden bzw. nach Ende der Sitzung gelöscht.
            Wenn Sie „Angemeldet bleiben“ wählen, setzen wir zusätzlich ein Cookie <code>kr_remember</code>. Es enthält
            einen zufälligen Schlüssel, der 30 Tage gültig ist. In der Datenbank speichern wir davon nur einen nicht
            umkehrbaren Hash sowie die Angaben zu Ihrem Konto. Beim Abmelden wird das Cookie gelöscht. Die Nutzung
            ist freiwillig und erfolgt auf Ihre Entscheidung hin. Im Browser speichern wir außerdem
            kleine Einstellungen (z. B. den zuletzt besuchten Raum und die Ton-Einstellung). Diese liegen nur auf Ihrem
            Gerät im sogenannten Local Storage und werden nicht an uns übertragen. Rechtsgrundlage ist § 25 Abs. 2 TDDDG
            sowie Art. 6 Abs. 1 lit. f DSGVO.
        </p>

        <h2>6. Spielräume und Spieldaten</h2>
        <p>
            Wenn Sie einem Raum beitreten, speichern wir Ihre Discord-ID bzw. Kontokennung, Ihren Anzeigenamen,
            Ihr Profilbild, Ihre Team-Zuordnung, Ihre Punkte und Spielergebnisse sowie den Zeitpunkt Ihrer letzten
            Aktivität im Raum. Die Daten dienen allein dem Spielbetrieb.
        </p>
        <p>
            <strong>Speicherdauer:</strong> Räume und die zugehörigen Spieldaten werden 30 Tage nach ihrer Erstellung
            automatisch gelöscht. Benutzerkonten bleiben bestehen, bis Sie deren Löschung verlangen.
            Rechtsgrundlage ist Art. 6 Abs. 1 lit. b DSGVO.
        </p>

        <h2>7. Kamera und Mikrofon</h2>
        <p>
            Im Spiel können Sie Ihre Kamera und Ihr Mikrofon aktivieren. Das geschieht nur auf Ihren Klick hin und Sie
            können es jederzeit wieder ausschalten. Ihr Bild und Ton werden direkt zwischen den Teilnehmenden des Raums
            übertragen (Peer-to-Peer-Verbindung über WebRTC). Wir speichern keine Bild- oder Tonaufnahmen.
        </p>
        <p>
            Für den Verbindungsaufbau nutzt der Browser einen öffentlichen STUN-Server von Google (stun.l.google.com).
            Dabei wird Ihre IP-Adresse an Google übermittelt. Rechtsgrundlage ist Ihre Einwilligung durch die Aktivierung
            von Kamera oder Mikrofon (Art. 6 Abs. 1 lit. a DSGVO).
        </p>

        <h2>8. Videodienst LiveKit</h2>
        <p>
            Für die Videoverbindung nutzen wir den Dienst LiveKit Cloud der LiveKit Inc. (USA). Dabei erhält der Dienst
            einen Verbindungs-Token, Ihre IP-Adresse und technische Verbindungsdaten. Der Server steht in
            den Vereinigten Staaten von Amerika (USA). Mit dem Anbieter besteht ein Vertrag zur
            Auftragsverarbeitung nach Art. 28 DSGVO. Rechtsgrundlage ist Art. 6 Abs. 1 lit. b und lit. f DSGVO.
        </p>

        <h2>9. Externe Dienste im Browser</h2>
        <p>
            Die Seite lädt Profilbilder vom Discord-CDN (cdn.discordapp.com) und eine Bibliothek vom CDN jsDelivr
            (cdn.jsdelivr.net). Beim Laden dieser Inhalte wird Ihre IP-Adresse an die jeweiligen Anbieter übermittelt.
            Rechtsgrundlage ist Art. 6 Abs. 1 lit. f DSGVO (Darstellung und Funktion der Seite).
        </p>

        <h2>10. Weitergabe an Dritte</h2>
        <p>
            Eine Weitergabe Ihrer Daten erfolgt nur, soweit dies für den Betrieb der Seite nötig ist (Hosting, Datenbank,
            Mailversand, Videodienst, Anmeldedienste) oder gesetzlich vorgeschrieben ist. Hosting, Datenbank und Mailversand
            erfolgen über die Hetzner Online GmbH. Eine Weitergabe zu Werbezwecken findet nicht statt.
        </p>

        <h2>11. Einladungen und Erinnerungen per E-Mail</h2>
        <p>
            Der Host eines Raums kann Personen per E-Mail einladen oder ihnen zu einem Termin eine Erinnerung schicken.
            Dafür gibt er die E-Mail-Adressen der Empfänger ein. Wir speichern dabei:
        </p>
        <ul>
            <li>die E-Mail-Adresse des Empfängers,</li>
            <li>den Raum-Code,</li>
            <li>die interne Kennung des Hosts,</li>
            <li>den Zeitpunkt des Versands bzw. der geplanten Erinnerung.</li>
        </ul>
        <p>
            Der Empfänger erhält eine einmalige Einladung bzw. Erinnerung mit einem Link zum Raum. Der Versand erfolgt
            über den Mailserver von Hetzner (Absender noreply@kittysquiz.app). Die Versandmails enthalten keine Werbung.
            Ein Host darf in 24 Stunden höchstens 20 Mails auslösen. Die Angaben zu Einladungen und Erinnerungen werden
            nach 30 Tagen gelöscht. Rechtsgrundlage ist Art. 6 Abs. 1 lit. b DSGVO (Durchführung des Spiels) sowie
            Art. 6 Abs. 1 lit. f DSGVO (Schutz vor Missbrauch des Versands).
        </p>
        <p>
            Wenn Sie als Empfänger keine Einladung mehr möchten, antworten Sie auf die Mail oder schreiben Sie an die
            oben genannte Kontaktadresse. Wir entfernen die Adresse dann aus unseren Daten.
        </p>

        <h2>12. Ideen</h2>
        <p>
            Du kannst Ideen für das Spiel einreichen, auf der Webseite oder über den Discord-Server. Dabei speichern wir
            den Ideentext und den Namen, unter dem du angemeldet bist oder im Discord auftrittst. Die Ideen nutzen wir
            ausschließlich, um das Spiel weiterzuentwickeln. Eine Weitergabe an Dritte erfolgt nicht.
            Rechtsgrundlage ist Art. 6 Abs. 1 lit. a DSGVO (deine Einreichung ist freiwillig). Ideen werden gespeichert,
            bis sie umgesetzt oder verworfen sind. Auf Wunsch entfernen wir deine Idee gern vorher.
        </p>

        <h2>13. Discord-Server und Bot</h2>
        <p>
            Auf dem öffentlichen Discord-Server von KittysRätsel verarbeitet unser Bot die Befehle, die Mitglieder
            ausführen (z. B. /idee). Dabei sieht der Bot den Benutzernamen und den Befehlsinhalt. Der Server selbst unterliegt
            den Datenschutzbestimmungen von Discord Inc. (USA). Wir betreiben den Bot auf eigener Infrastruktur, die Daten
            werden nur für die Funktion des Servers genutzt. Rechtsgrundlage ist Art. 6 Abs. 1 lit. b und lit. f DSGVO.
        </p>

        <h2>14. Profilbilder und verknüpfte Konten</h2>
        <p>
            Du kannst ein Profilbild wählen: ein eigenes Bild, ein Standardbild oder das Bild deines Discord- bzw.
            Google-Kontos. Eigene Bilder speichern wir auf unserem Server. Die Bild-Adressen deiner Discord- und
            Google-Konten speichern wir ebenfalls. Discord- und Google-Bilder werden von den Servern dieser Anbieter geladen,
            dabei wird deine IP-Adresse an sie übermittelt. Wir speichern außerdem einen Verlauf deiner bisher genutzten Bilder,
            damit du sie wiederverwenden kannst. Du kannst Bilder jederzeit ersetzen und einzelne verknüpfte Konten trennen.
            Rechtsgrundlage ist Art. 6 Abs. 1 lit. b DSGVO.
        </p>
        <p>
            Mehrere Anmeldungen (Discord, Google, lokal) können zu einem Profil verbunden werden. Dazu speichern wir die
            interne Kennung jeder verbundenen Anmeldung. Beim Löschen des Kontos werden alle zugehörigen Angaben entfernt.
        </p>

        <h2>15. Spendenseite (whydonate)</h2>
        <p>
            In der Fußzeile verlinken wir auf unsere Unterstützungsseite bei <strong>whydonate.com</strong>
            (Betreiber: Whydonate, Deutschland). Die Seite wird erst geöffnet, wenn Sie auf den Link klicken.
            Dort gelten die Datenschutzbestimmungen von whydonate, die Sie direkt auf deren Seite einsehen können.
            Wir erhalten dabei nur die Angaben, die Sie auf der Spendenseite selbst machen.
            Rechtsgrundlage für den Aufruf der Seite ist Art. 6 Abs. 1 lit. f DSGVO (Hinweis auf Unterstützungsmöglichkeiten).
        </p>

        <h2>16. Ihre Rechte</h2>
        <p>
            Sie haben das Recht auf Auskunft über Ihre gespeicherten Daten, auf Berichtigung, Löschung und
            Einschränkung der Verarbeitung sowie auf Datenübertragbarkeit. Erteilte Einwilligungen können Sie
            jederzeit mit Wirkung für die Zukunft widerrufen. Außerdem können Sie der Verarbeitung auf Grundlage
            berechtigter Interessen widersprechen.
        </p>
        <p>
            Zur Ausübung Ihrer Rechte wenden Sie sich bitte an die oben genannte Kontaktadresse. Sie haben zudem das Recht,
            sich bei einer Datenschutz-Aufsichtsbehörde zu beschweren. Zuständig ist für Baden-Württemberg der
            Landesbeauftragte für den Datenschutz und die Informationsfreiheit.
        </p>

        <h2>17. Stand</h2>
        <p>
            Diese Datenschutzerklärung ist aktuell gültig. Wir passen sie an, sobald sich Funktionen oder Dienste ändern.
        </p>
    </div>
</body>
</html>
<!-- Version 1.10 -->
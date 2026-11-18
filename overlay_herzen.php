<?php
// Version 1.0
$code = strtoupper($_GET['code'] ?? '');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Der Dümmste Fliegt – Overlay <?= htmlspecialchars($code) ?></title>
    <style>
        html, body { margin: 0; padding: 0; background: transparent; overflow: hidden; }
        body { font-family: system-ui, sans-serif; color: #fff; width: 1920px; height: 1080px; position: relative; }
        .panel { position: absolute; inset: 0; background: rgba(8,6,22,.82); }

        .top { position: absolute; top: 28px; left: 48px; right: 48px; display: flex; justify-content: space-between; align-items: center; }
        .round { font-size: 44px; font-weight: 900; letter-spacing: 1px; color: #7fe7ff; }
        .title { font-size: 46px; font-weight: 900; color: #fff; text-align: center; }
        .title span { color: #ffd23f; }
        .timer { font-size: 44px; font-weight: 900; color: #ffd23f; min-width: 200px; text-align: right; font-variant-numeric: tabular-nums; }
        .timer small { display: block; font-size: 14px; color: #aaa; letter-spacing: 2px; }

        .grid { position: absolute; top: 140px; left: 300px; right: 300px; bottom: 170px;
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 18px; align-content: center; }
        .card { position: relative; border-radius: 16px; overflow: hidden; background: #241a40;
            border: 3px solid #3d2e66; aspect-ratio: 16 / 10; }
        .card.cur { border-color: #ffd23f; box-shadow: 0 0 26px rgba(255,210,63,.7); }
        .card.out { filter: grayscale(1); opacity: .4; }
        .av { position: absolute; left: 50%; top: 46%; transform: translate(-50%, -50%);
            width: 42%; aspect-ratio: 1; border-radius: 50%; border: 4px solid #3d2e66; background: #15102a; box-sizing: border-box; }
        .deco { position: absolute; left: 50%; top: 46%; transform: translate(-50%, -50%);
            width: 56%; aspect-ratio: 1; pointer-events: none; }
        .coin { position: absolute; top: 12px; left: 12px; width: 40px; height: 40px; border-radius: 50%;
            background: #ffd23f; color: #5a4300; font-weight: 900; font-size: 22px; text-align: center; line-height: 40px; }
        .coin.used { background: #555; color: #999; }
        .hearts { position: absolute; top: 12px; right: 12px; padding: 4px 10px; border-radius: 12px; background: rgba(0,0,0,.55);
            font-size: 26px; letter-spacing: 4px; }
        .heart-on { color: #ff3b5c; }
        .heart-off { color: #444; }
        .name { position: absolute; left: 50%; bottom: 12px; transform: translateX(-50%);
            padding: 6px 22px; border-radius: 22px; background: #2a52c9; font-weight: 900; font-size: 24px;
            max-width: 90%; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; box-sizing: border-box; }
        .card.cur .name { background: #b8860b; }
        .out-tag { position: absolute; top: 50%; left: 0; right: 0; text-align: center; font-size: 30px; font-weight: 900; color: #ff8a8a; }

        .bar { position: absolute; left: 300px; right: 300px; bottom: 36px; height: 112px; border-radius: 18px;
            background: rgba(10,10,35,.9); border: 2px solid #2a6f8a; display: flex; align-items: center; padding: 0 28px; gap: 24px; box-sizing: border-box; }
        .tag { padding: 8px 18px; border-radius: 12px; border: 2px solid #7fe7ff; color: #7fe7ff; font-weight: 900; font-size: 20px; white-space: nowrap; }
        .qtext { font-size: 36px; font-weight: 800; text-align: center; flex: 1; }
        .status { font-size: 32px; font-weight: 800; flex: 1; text-align: center; color: #ddd; }

        .winner { position: absolute; inset: 0; display: none; flex-direction: column; align-items: center; justify-content: center;
            background: rgba(8,6,22,.88); }
        .winner .l { font-size: 52px; font-weight: 900; color: #ffd23f; }
        .winner .n { font-size: 150px; font-weight: 900; color: #fff; margin-top: 10px; }
    </style>
</head>
<body>
    <div class="panel"></div>

    <div class="top">
        <div class="round" id="round">ROUND 1</div>
        <div class="title">DER DÜMMSTE <span>FLIEGT</span></div>
        <div class="timer" id="timer"><small>TIME LEFT</small>–</div>
    </div>

    <div class="grid" id="grid"></div>

    <div class="bar">
        <div class="tag" id="tag">WARTEN</div>
        <div class="qtext" id="qtext" style="display:none"></div>
        <div class="status" id="status">Der Host startet gleich …</div>
    </div>

    <div class="winner" id="winner">
        <div class="l">🏆 GEWINNER</div>
        <div class="n" id="winnerName"></div>
    </div>

    <script>
        const code = <?= json_encode($code) ?>;
        const grid = document.getElementById('grid');
        const roundEl = document.getElementById('round');
        const timerEl = document.getElementById('timer');
        const tagEl = document.getElementById('tag');
        const qEl = document.getElementById('qtext');
        const statusEl = document.getElementById('status');
        const winnerEl = document.getElementById('winner');
        const winnerName = document.getElementById('winnerName');

        const PHASE = {
            idle: ['WARTEN', 'Der Host startet gleich …'],
            asking: ['QUESTION', ''],
            answered: ['BEWERTET', 'Antwort bewertet'],
            review: ['RUNDE VORBEI', 'Abstimmung folgt'],
            voting: ['ABSTIMMUNG', 'Wer hat die dümmsten Fehler gemacht?'],
            result: ['ERGEBNIS', 'Ein Herz wird abgezogen'],
            finished: ['ENDE', ''],
        };

        let timerEnd = 0;

        function el(tag, cls, text) {
            const e = document.createElement(tag);
            if (cls) e.className = cls;
            if (text !== undefined) e.textContent = text;
            return e;
        }

        function draw(s) {
            roundEl.textContent = 'ROUND ' + s.round;

            // Timer: Restzeit vom Server, lokal weiterzählen
            if (s.phase === 'asking' && s.secondsLeft !== null) {
                timerEnd = Date.now() + s.secondsLeft * 1000;
            } else {
                timerEnd = 0;
            }

            // Frage oder Phasentext
            const [tag, sub] = PHASE[s.phase] || PHASE.idle;
            tagEl.textContent = tag;
            if (s.phase === 'asking' && s.question) {
                qEl.style.display = '';
                qEl.textContent = s.question;
                statusEl.style.display = 'none';
            } else {
                qEl.style.display = 'none';
                statusEl.style.display = '';
                statusEl.textContent = sub;
            }

            // Spielerkarten
            grid.innerHTML = '';
            for (const p of s.players) {
                const card = el('div', 'card' + (p.current ? ' cur' : '') + (p.out ? ' out' : ''));
                card.append(el('div', 'coin' + (p.joker ? '' : ' used'), 'J'));

                const hearts = el('div', 'hearts');
                for (let i = 0; i < 3; i++) {
                    hearts.append(el('span', i < p.hearts ? 'heart-on' : 'heart-off', '♥'));
                }
                card.append(hearts);

                const img = el('img', 'av');
                img.src = p.avatar;
                img.alt = '';
                card.append(img);
                if (p.decoration) {
                    const d = el('img', 'deco');
                    d.src = p.decoration;
                    d.alt = '';
                    card.append(d);
                }

                const n = el('div', 'name', (p.premium ? '💎 ' : '') + p.name);
                card.append(n);
                if (p.out) card.append(el('div', 'out-tag', 'RAUS'));
                grid.append(card);
            }

            // Gewinner
            if (s.winner) {
                winnerName.textContent = s.winner;
                winnerEl.style.display = 'flex';
            } else {
                winnerEl.style.display = 'none';
            }
        }

        function tick() {
            if (!timerEnd) {
                timerEl.lastChild.textContent = '–';
                return;
            }
            const left = Math.max(0, Math.ceil((timerEnd - Date.now()) / 1000));
            timerEl.lastChild.textContent = '00:' + String(left).padStart(2, '0');
        }

        async function poll() {
            try {
                const res = await fetch('overlay_herzen_api.php?code=' + encodeURIComponent(code), { cache: 'no-store' });
                if (res.ok) draw(await res.json());
            } catch (e) {
                // nächster Durchlauf versucht es erneut
            }
            setTimeout(poll, 1000);
        }

        setInterval(tick, 250);
        poll();
    </script>
</body>
</html>
<!-- Version 1.0 -->

<?php
// Version 1.1
$code = strtoupper($_GET['code'] ?? '');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Overlay <?= htmlspecialchars($code) ?></title>
    <style>
        html, body { background: transparent !important; margin: 0; padding: 0; overflow: hidden; }
        body { font-family: system-ui, sans-serif; color: #fff; padding: 10px; box-sizing: border-box; }
        .top { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 8px; }
        .board {
            font-weight: 800; font-size: 20px; padding: 4px 14px; border-radius: 10px;
            background: rgba(20,10,40,.85); text-shadow: 0 2px 4px rgba(0,0,0,.6);
        }
        .timer {
            font-weight: 900; font-size: 34px; padding: 4px 16px; border-radius: 12px;
            background: rgba(20,10,40,.85); color: #f5b400; font-variant-numeric: tabular-nums;
            text-shadow: 0 2px 4px rgba(0,0,0,.6); min-width: 90px; text-align: center;
        }
        .teams { display: flex; gap: 10px; flex-wrap: wrap; }
        .team {
            flex: 1 1 0; min-width: 160px; border-radius: 14px; padding: 10px 14px;
            background: rgba(20,10,40,.85); border: 3px solid #666; transition: transform .3s ease, box-shadow .3s ease;
        }
        .team.turn { transform: scale(1.04); box-shadow: 0 0 18px rgba(255,255,255,.35); }
        .tn { font-weight: 800; font-size: 20px; text-shadow: 0 2px 4px rgba(0,0,0,.6); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .tp { font-weight: 900; font-size: 30px; margin-top: 2px; text-shadow: 0 2px 4px rgba(0,0,0,.6); font-variant-numeric: tabular-nums; }
        
        .question-box {
            display: none; margin-top: 10px; padding: 20px; border-radius: 14px;
            background: rgba(20,10,40,.85); border: 2px solid #3ddbc4;
            text-align: center; width: 100%; box-sizing: border-box;
        }
        .q-cat { font-size: 14px; opacity: .8; margin-bottom: 8px; }
        .q-text { font-size: 24px; font-weight: bold; }
        
        .q-image { 
            display: block; 
            margin: 12px auto; 
            max-width: 100%; 
            max-height: 200px; 
            border-radius: 8px; 
            object-fit: contain; 
            cursor: zoom-in;
            transition: transform .2s;
        }
        .q-image:hover { transform: scale(1.02); }

        .image-zoom {
            position: fixed; inset: 0; background: rgba(0,0,0,.95);
            display: none; align-items: center; justify-content: center;
            z-index: 100; cursor: zoom-out; padding: 20px; box-sizing: border-box;
        }
        .image-zoom img {
            max-width: 100%; max-height: 100%; border-radius: 8px;
            object-fit: contain; box-shadow: 0 0 40px rgba(255,255,255,0.1);
        }
        .winnerBig {
            position: fixed; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center;
            background: rgba(10,5,25,.6); z-index: 90; pointer-events: none;
        }
        .winnerBig .wl { font-size: 36px; font-weight: 900; color: #f5b400; text-shadow: 0 4px 12px rgba(0,0,0,.7); }
        .winnerBig .wn { font-size: 120px; font-weight: 900; color: #fff; text-shadow: 0 6px 20px rgba(0,0,0,.8);
                         animation: krPop .8s ease-out; }
        @keyframes krPop { 0% { transform: scale(.4); opacity: 0; } 100% { transform: scale(1); opacity: 1; } }
    </style>
</head>
<body>
    <div class="top">
        <div class="board" id="boardLabel">Lade …</div>
        <div class="timer" id="timer">–</div>
    </div>
    <div class="teams" id="teams"></div>

    <div class="question-box" id="questionBox">
        <div class="q-cat" id="qCat"></div>
        <div class="q-text" id="qText"></div>
        <img id="qImage" class="q-image" alt="Frage Bild" onclick="openImageZoom(this.src)">
    </div>

    <div class="image-zoom" id="imageZoom" onclick="closeImageZoom()">
        <img id="imageZoomSrc" src="" alt="Großansicht">
    </div>

    <script>
        const code = <?= json_encode($code) ?>;
        const boardLabel = document.getElementById('boardLabel');
        const teamsEl = document.getElementById('teams');
        const timerEl = document.getElementById('timer');
        const SECONDS = 20;

        const questionBox = document.getElementById('questionBox');
        const qCat = document.getElementById('qCat');
        const qText = document.getElementById('qText');
        const qImage = document.getElementById('qImage');

        let lastKey = '';
        let timerEnd = 0;

        // NEU: Bild Zoom
        function openImageZoom(src) {
            if (!src) return;
            document.getElementById('imageZoomSrc').src = src;
            document.getElementById('imageZoom').style.display = 'flex';
        }

        function closeImageZoom() {
            document.getElementById('imageZoom').style.display = 'none';
            document.getElementById('imageZoomSrc').src = '';
        }

        async function poll() {
            try {
                const res = await fetch('overlay_api.php?code=' + encodeURIComponent(code), { cache: 'no-store' });
                if (res.ok) draw(await res.json());
            } catch (e) {}
            setTimeout(poll, 1000);
        }

        let winnerShown = false;
        function playJingle() {
            const C = window.AudioContext || window.webkitAudioContext;
            if (!C) return;
            const c = new C();
            [523, 659, 784, 1047].forEach((f, i) => {
                const t = c.currentTime + i * 0.18;
                const o = c.createOscillator();
                const g = c.createGain();
                o.type = 'triangle';
                o.frequency.setValueAtTime(f, t);
                g.gain.setValueAtTime(0.25, t);
                g.gain.exponentialRampToValueAtTime(0.0001, t + 0.35);
                o.connect(g);
                g.connect(c.destination);
                o.start(t);
                o.stop(t + 0.4);
            });
        }
        function showWinner(name) {
            const box = document.createElement('div');
            box.className = 'winnerBig';
            const l = document.createElement('div');
            l.className = 'wl';
            l.textContent = '🏆 Gewinner';
            const n = document.createElement('div');
            n.className = 'wn';
            n.textContent = name;
            box.append(l, n);
            document.body.append(box);
            playJingle();
        }

        function draw(s) {
            if (s.finished && s.winner && !winnerShown) {
                winnerShown = true;
                showWinner(s.winner);
            }
            let label = `Board ${s.round}`;
            boardLabel.textContent = label;

            teamsEl.innerHTML = '';
            for (const t of s.teams) {
                const box = document.createElement('div');
                box.className = 'team' + (t.id === s.turnTeamId ? ' turn' : '');
                box.style.borderColor = t.color;

                const name = document.createElement('div');
                name.className = 'tn';
                name.textContent = t.name;

                const pts = document.createElement('div');
                pts.className = 'tp';
                pts.textContent = t.points;

                box.append(name, pts);
                teamsEl.append(box);
            }

            const a = s.active;
            const key = a && a.revealed ? a.slot + ':' + a.points : '';
            if (key !== lastKey) {
                lastKey = key;
                timerEnd = key ? Date.now() + SECONDS * 1000 : 0;
            }

            if (a && a.revealed) {
                questionBox.style.display = 'block';
                qCat.textContent = a.category || '';
                qText.textContent = a.question || '';
                if (a.image_url) {
                    qImage.src = a.image_url;
                    qImage.style.display = 'block';
                } else {
                    qImage.style.display = 'none';
                }
            } else {
                questionBox.style.display = 'none';
            }
        }

        function tick() {
            if (!timerEnd) {
                timerEl.textContent = '–';
                return;
            }
            const left = Math.max(0, Math.ceil((timerEnd - Date.now()) / 1000));
            timerEl.textContent = left > 0 ? left + ' s' : '0 s';
        }

        setInterval(tick, 250);
        poll();
    </script>
</body>
</html>
<!-- Version 1.1 -->
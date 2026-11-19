// Version 1.6
// Zusatzeffekte fürs Spielfeld: Countdown, Sounds, Endtafel
(() => {
    const code = (new URLSearchParams(location.search).get('code') || '').toUpperCase();
    if (!code) return;

    const SECONDS = 20;
    const TOTAL_FIELDS = 6 * 7; // 6 Spalten × 7 Stufen pro Board

    let ctx = null;
    let last = null;
    let timerKey = '';
    let timerEnd = 0;
    let lastSecond = -1;
    let timeoutPlayed = false;
    let shownRound = null;

    // ---------- Audio ----------

    // Browser erlauben Sound erst nach einer Nutzer-Interaktion
    function audio() {
        if (!ctx) {
            const C = window.AudioContext || window.webkitAudioContext;
            if (!C) return null;
            ctx = new C();
        }
        if (ctx.state === 'suspended') ctx.resume().catch(() => {});
        return ctx;
    }
    ['click', 'keydown', 'touchstart'].forEach(ev =>
        document.addEventListener(ev, audio, { passive: true }));

    // Ton an/aus pro Gerät, gespeichert im Browser
    function soundOff() {
        try { return localStorage.getItem('kr_sound') === 'off'; } catch (e) { return false; }
    }

    function beep(freq, dur = 0.15, type = 'sine', delay = 0, vol = 0.2) {
        if (soundOff()) return;
        const c = audio();
        if (!c) return;
        const t = c.currentTime + delay;
        const o = c.createOscillator();
        const g = c.createGain();
        o.type = type;
        o.frequency.setValueAtTime(freq, t);
        g.gain.setValueAtTime(vol, t);
        g.gain.exponentialRampToValueAtTime(0.0001, t + dur);
        o.connect(g);
        g.connect(c.destination);
        o.start(t);
        o.stop(t + dur + 0.02);
    }

    const sfx = {
        tick: () => beep(880, 0.08, 'square', 0, 0.12),
        timeout: () => {
            beep(220, 0.3, 'sawtooth', 0, 0.2);
            beep(160, 0.4, 'sawtooth', 0.25, 0.2);
        },
        right: () => {
            beep(523, 0.12, 'triangle');
            beep(659, 0.12, 'triangle', 0.1);
            beep(784, 0.25, 'triangle', 0.2);
        },
        wrong: () => {
            beep(330, 0.2, 'sawtooth', 0, 0.2);
            beep(220, 0.35, 'sawtooth', 0.18, 0.2);
        },
        buzz: () => {
            beep(1200, 0.12, 'square', 0, 0.15);
            beep(1600, 0.2, 'square', 0.08, 0.15);
        },
        finish: () => {
            beep(523, 0.15, 'triangle');
            beep(659, 0.15, 'triangle', 0.15);
            beep(784, 0.15, 'triangle', 0.3);
            beep(1047, 0.4, 'triangle', 0.45);
        },
    };

    // ---------- Timer-Anzeige ----------

    const box = document.createElement('div');
    box.id = 'krTimer';
    box.style.cssText = [
        'position:fixed', 'top:12px', 'left:50%', 'transform:translateX(-50%)',
        'z-index:60', 'display:none', 'padding:8px 22px', 'border-radius:14px',
        'background:rgba(20,10,40,.92)', 'border:2px solid #f5b400', 'color:#f5b400',
        'font:800 28px system-ui,sans-serif', 'font-variant-numeric:tabular-nums',
        'box-shadow:0 6px 20px rgba(0,0,0,.5)', 'pointer-events:none',
    ].join(';');
    document.body.append(box);

    // ---------- Endtafel ----------

    const endBox = document.createElement('div');
    endBox.id = 'krEnd';
    endBox.style.cssText = [
        'position:fixed', 'inset:0', 'z-index:70', 'display:none', 'align-items:center',
        'justify-content:center', 'padding:24px', 'box-sizing:border-box',
        'background:rgba(10,5,25,.94)',
    ].join(';');
    document.body.append(endBox);

    // ---------- Sound-Schalter ----------

    const muteBtn = document.createElement('button');
    muteBtn.type = 'button';
    muteBtn.style.cssText = [
        'position:fixed', 'left:12px', 'bottom:12px', 'z-index:65', 'padding:8px 12px',
        'border-radius:12px', 'border:2px solid #f5b400', 'background:rgba(20,10,40,.92)',
        'color:#fff', 'font:700 18px system-ui,sans-serif', 'cursor:pointer',
    ].join(';');
    function paintMute() {
        const off = soundOff();
        muteBtn.textContent = off ? '🔇' : '🔊';
        muteBtn.title = off ? 'Ton einschalten' : 'Ton ausschalten';
    }
    muteBtn.onclick = () => {
        try { localStorage.setItem('kr_sound', soundOff() ? 'on' : 'off'); } catch (e) {}
        paintMute();
    };
    document.body.append(muteBtn);
    paintMute();

    function showEnd(s) {
        endBox.innerHTML = '';
        const card = document.createElement('div');
        card.style.cssText = [
            'background:linear-gradient(160deg,#2c1d52,#1a1030)', 'border:3px solid #3ddbc4',
            'border-radius:20px', 'padding:32px', 'width:min(640px,100%)', 'text-align:center',
            'color:#f5f0ff', 'font-family:system-ui,sans-serif', 'box-sizing:border-box',
        ].join(';');

        const title = document.createElement('div');
        title.style.cssText = 'font-size:28px;font-weight:900;margin-bottom:6px;';
        title.textContent = s.round === 1 ? 'Board 1 ist fertig!' : 'Board 2 ist fertig!';
        card.append(title);

        const sub = document.createElement('div');
        sub.style.cssText = 'opacity:.8;margin-bottom:20px;';
        sub.textContent = s.round === 1
            ? 'Der Host kann jetzt auf Board 2 wechseln.'
            : 'Alle Felder sind gespielt.';
        card.append(sub);

        const ranked = [...s.teams].sort((a, b) => b.points - a.points);
        const medals = ['🥇', '🥈', '🥉'];
        ranked.forEach((t, i) => {
            const row = document.createElement('div');
            row.style.cssText = [
                'display:flex', 'justify-content:space-between', 'align-items:center', 'gap:12px',
                'padding:12px 16px', 'margin-bottom:8px', 'border-radius:12px',
                'background:rgba(0,0,0,.25)', 'border-left:6px solid ' + t.color,
                'font-size:20px', 'font-weight:800',
            ].join(';');
            const name = document.createElement('span');
            name.textContent = (medals[i] || (i + 1) + '.') + ' ' + t.name;
            const pts = document.createElement('span');
            pts.textContent = t.points + ' Punkte';
            row.append(name, pts);
            card.append(row);
        });

        const close = document.createElement('button');
        close.type = 'button';
        close.textContent = 'Schließen';
        close.style.cssText = 'margin-top:20px;padding:12px 24px;border:none;border-radius:10px;background:#444;color:#fff;font-weight:bold;font-size:16px;cursor:pointer;';
        close.addEventListener('click', () => { endBox.style.display = 'none'; });
        card.append(close);

        endBox.append(card);
        endBox.style.display = 'flex';
        sfx.finish();
        confetti();
    }

    // ---------- Stand auswerten ----------

    let pausedLeft = null;

    function handle(s) {
        onPoints(s.teams);
        const a = s.active;
        const key = a ? a.slot + ':' + a.points + ':' + a.category : '';
        const points = {};
        for (const t of s.teams) points[t.id] = t.points;

        // Frage wurde geschlossen: Punkte vergleichen, um richtig oder falsch zu erkennen
        if (last && last.key && !key) {
            let up = false;
            let down = false;
            for (const id of Object.keys(points)) {
                const before = last.points[id] ?? points[id];
                if (points[id] > before) up = true;
                if (points[id] < before) down = true;
            }
            if (up) sfx.right();
            else if (down) sfx.wrong();
        }

        // Timer startet, sobald die Frage für alle angezeigt wird
        if (a && a.revealed && (a.timer_started_at || a.timerPaused)) {
            if (timerKey !== key) {
                timerKey = key;
                lastSecond = -1;
                timeoutPlayed = false;
            }
            // Restzeit kommt vom Server, damit Pause und Weiter auf allen Geräten stimmen
            const left = Math.max(0, SECONDS - (a.timerElapsed || 0));
            if (a.timerPaused) {
                pausedLeft = left;
                timerEnd = 0;
            } else {
                pausedLeft = null;
                timerEnd = Date.now() + left * 1000;
            }
        } else {
            timerKey = '';
            timerEnd = 0;
            pausedLeft = null;
        }

        // Endtafel: wenn das aktuelle Board vollständig gespielt ist und keine Frage offen ist
        if (!a && s.played.length >= TOTAL_FIELDS && shownRound !== s.round) {
            shownRound = s.round;
            showEnd(s);
        }

        last = { key, points };
    }

    // Timer-Anzeige aktualisieren
    function tick() {
        if (!timerEnd) {
            if (pausedLeft !== null) {
                box.style.display = 'block';
                box.textContent = `⏸ Pause · ${pausedLeft} s`;
            } else {
                box.style.display = 'none';
            }
            return;
        }
        const left = Math.max(0, Math.ceil((timerEnd - Date.now()) / 1000));
        box.style.display = 'block';
        box.textContent = left > 0 ? `⏱ ${left} s` : '⏰ Zeit ist um!';

        if (left > 0 && left <= 5 && left !== lastSecond) {
            lastSecond = left;
            sfx.tick();
        }
        if (left === 0 && !timeoutPlayed) {
            timeoutPlayed = true;
            sfx.timeout();
            setTimeout(() => {
                if (Date.now() >= timerEnd) timerEnd = 0;
            }, 2000);
        }
    }

    // ---------- Buzzer-Sound ----------

    document.addEventListener('click', (e) => {
        if (e.target.closest && e.target.closest('.btn.buzz')) sfx.buzz();
    }, true);

    document.addEventListener('keydown', (e) => {
        if (e.code !== 'Space' || e.repeat) return;
        const tag = (e.target.tagName || '').toUpperCase();
        if (['INPUT', 'SELECT', 'TEXTAREA', 'BUTTON'].includes(tag)) return;
        if (document.querySelector('.btn.buzz')) sfx.buzz();
    });

    // ---------- Punkte-Animation, Flash, Konfetti ----------

    const prevPoints = {};
    let pointsInit = false;

    const flashCss = document.createElement('style');
    flashCss.textContent = `
        @keyframes krFlashG { 0% { box-shadow: 0 0 0 0 #38ff7a; } 30% { box-shadow: 0 0 0 6px #38ff7a, 0 0 40px #38ff7a; } 100% { box-shadow: none; } }
        @keyframes krFlashR { 0% { box-shadow: 0 0 0 0 #ff3b5c; } 30% { box-shadow: 0 0 0 6px #ff3b5c, 0 0 40px #ff3b5c; } 100% { box-shadow: none; } }
        .pcard.flashG { animation: krFlashG 1.2s ease-out; }
        .pcard.flashR { animation: krFlashR 1.2s ease-out; }
    `;
    document.head.append(flashCss);

    // Zahl hochzählen. Wiederholt es, falls das Board gerade neu gezeichnet wird.
    function countUp(id, from, to, tries = 0) {
        const targets = document.querySelectorAll('.pcard[data-team="' + id + '"] .pscore');
        const ready = [...targets].filter(s => s.textContent === String(to));
        if (!ready.length) {
            if (tries < 20) setTimeout(() => countUp(id, from, to, tries + 1), 80);
            return;
        }
        ready.forEach(s => {
            const t0 = performance.now();
            const step = (now) => {
                const k = Math.min(1, (now - t0) / 700);
                s.textContent = String(Math.round(from + (to - from) * k));
                if (k < 1) requestAnimationFrame(step);
            };
            requestAnimationFrame(step);
        });
    }

    function flashTeam(id, good, tries = 0) {
        const cards = document.querySelectorAll('.pcard[data-team="' + id + '"]');
        if (!cards.length) {
            if (tries < 20) setTimeout(() => flashTeam(id, good, tries + 1), 80);
            return;
        }
        cards.forEach(c => {
            c.classList.remove('flashG', 'flashR');
            void c.offsetWidth;
            c.classList.add(good ? 'flashG' : 'flashR');
            setTimeout(() => c.classList.remove('flashG', 'flashR'), 1300);
        });
    }

    function onPoints(teams) {
        for (const t of teams) {
            const before = prevPoints[t.id];
            if (pointsInit && before !== undefined && before !== t.points) {
                countUp(t.id, before, t.points);
                flashTeam(t.id, t.points > before);
            }
            prevPoints[t.id] = t.points;
        }
        pointsInit = true;
    }

    function confetti() {
        const cv = document.createElement('canvas');
        cv.style.cssText = 'position:fixed;inset:0;width:100%;height:100%;z-index:80;pointer-events:none;';
        cv.width = window.innerWidth;
        cv.height = window.innerHeight;
        document.body.append(cv);
        const g = cv.getContext('2d');
        const colors = ['#ff3ea5', '#f5b400', '#3ddbc4', '#7b2ff7', '#38d26b', '#ffffff'];
        const parts = Array.from({ length: 160 }, () => ({
            x: Math.random() * cv.width,
            y: -Math.random() * cv.height * 0.3,
            vx: (Math.random() - 0.5) * 4,
            vy: 2 + Math.random() * 4,
            s: 6 + Math.random() * 8,
            r: Math.random() * 6,
            vr: (Math.random() - 0.5) * 0.3,
            c: colors[Math.floor(Math.random() * colors.length)],
        }));
        const end = performance.now() + 4000;
        const frame = (now) => {
            g.clearRect(0, 0, cv.width, cv.height);
            for (const p of parts) {
                p.x += p.vx;
                p.y += p.vy;
                p.vy += 0.03;
                p.r += p.vr;
                g.save();
                g.translate(p.x, p.y);
                g.rotate(p.r);
                g.fillStyle = p.c;
                g.fillRect(-p.s / 2, -p.s / 4, p.s, p.s / 2);
                g.restore();
            }
            if (now < end) requestAnimationFrame(frame);
            else cv.remove();
        };
        requestAnimationFrame(frame);
    }

    // ---------- Abruf ----------

    async function poll() {
        try {
            const res = await fetch('/modes/punkte/board_api.php?code=' + encodeURIComponent(code), { cache: 'no-store' });
            if (res.ok) handle(await res.json());
        } catch (e) {
            // nächster Durchlauf versucht es erneut
        }
        setTimeout(poll, 1000);
    }

    setInterval(tick, 250);
    poll();
})();
// Version 1.6
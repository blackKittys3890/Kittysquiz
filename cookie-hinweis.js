// Version 1.0
// Schlichter Hinweis zu technisch notwendigen Cookies und Browser-Speicher.
// Wird nur einmal pro Gerät angezeigt.
(function () {
    const KEY = 'kr_cookie_ok';
    try { if (localStorage.getItem(KEY) === '1') return; } catch (e) { return; }

    const bar = document.createElement('div');
    bar.setAttribute('role', 'dialog');
    bar.style.cssText = [
        'position:fixed', 'left:12px', 'right:12px', 'bottom:44px', 'z-index:80',
        'max-width:620px', 'margin:0 auto', 'padding:12px 14px', 'border-radius:14px',
        'background:#1f1438', 'border:2px solid #3ddbc4', 'color:#f5f0ff',
        'font:14px/1.4 system-ui,sans-serif', 'display:flex', 'gap:12px',
        'align-items:center', 'justify-content:space-between', 'box-shadow:0 8px 24px rgba(0,0,0,.4)',
    ].join(';');

    const text = document.createElement('span');
    text.innerHTML = 'Diese Seite nutzt nur technisch notwendige Cookies und Browser-Speicher, etwa für den Login und deine Einstellungen. ' +
        '<a href="/datenschutz.php" style="color:#9ff0ff;">Datenschutz</a>';

    const ok = document.createElement('button');
    ok.type = 'button';
    ok.textContent = 'Verstanden';
    ok.style.cssText = 'padding:8px 14px;border:none;border-radius:10px;background:#3ddbc4;color:#1f1438;font-weight:bold;cursor:pointer;white-space:nowrap;';
    ok.addEventListener('click', () => {
        try { localStorage.setItem(KEY, '1'); } catch (e) {}
        bar.remove();
    });

    bar.append(text, ok);
    document.body.append(bar);
})();
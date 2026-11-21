// Version 1.0
// Schalter für größere Schrift. Merkt sich die Einstellung pro Gerät.
(function () {
    const KEY = 'kr_big';
    const apply = (on) => document.documentElement.classList.toggle('kr-big', on);
    let on = false;
    try { on = localStorage.getItem(KEY) === '1'; } catch (e) {}
    apply(on);

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'kr-big-toggle';
    const paint = () => {
        btn.textContent = document.documentElement.classList.contains('kr-big') ? 'A−' : 'A+';
        btn.title = 'Schrift ' + (document.documentElement.classList.contains('kr-big') ? 'normal' : 'größer') + ' anzeigen';
    };
    btn.addEventListener('click', () => {
        on = !document.documentElement.classList.contains('kr-big');
        apply(on);
        try { localStorage.setItem(KEY, on ? '1' : '0'); } catch (e) {}
        paint();
    });
    paint();
    document.body.append(btn);
})();
// Version 1.0
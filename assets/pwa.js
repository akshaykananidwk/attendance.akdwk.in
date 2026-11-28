/* Registers the service worker and shows the "Install app" sheet once a day until installed. */
(function () {
    var base = (document.currentScript && document.currentScript.src || '').replace(/assets\/pwa\.js.*$/, '');
    if (!base) return;
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () { navigator.serviceWorker.register(base + 'sw.js', { scope: base }).catch(function () {}); });
    }
    var KEY = 'akdwk_pwa_later', DONE = 'akdwk_pwa_installed';
    function today() { var d = new Date(); return d.getFullYear() + '-' + (d.getMonth() + 1) + '-' + d.getDate(); }
    function get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } }
    function set(k, v) { try { localStorage.setItem(k, v); } catch (e) {} }
    var standalone = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches) || window.navigator.standalone === true;
    if (standalone) { set(DONE, '1'); return; }
    if (/[?&]source=pwa/.test(location.search)) return;
    var ua = navigator.userAgent || '';
    var isIOS = /iphone|ipad|ipod/i.test(ua) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
    var isMobile = isIOS || /android|mobile/i.test(ua);
    var deferred = null;

    function css() {
        if (document.getElementById('pwaCss')) return;
        var s = document.createElement('style'); s.id = 'pwaCss';
        s.textContent = '.pwa-sheet{position:fixed;left:12px;right:12px;bottom:calc(76px + env(safe-area-inset-bottom));z-index:2000;max-width:420px;margin:0 auto;background:#fff;color:#0f172a;border-radius:18px;box-shadow:0 18px 50px rgba(2,8,23,.35);padding:14px 14px 12px;font-family:inherit;animation:pwaUp .35s ease}'
            + '.pwa-sheet.pwa-top{bottom:auto;top:calc(12px + env(safe-area-inset-top))}'
            + '@media(min-width:769px){.pwa-sheet{left:auto;right:24px;bottom:24px}.pwa-sheet.pwa-top{top:24px;bottom:auto}}'
            + '[data-bs-theme=dark] .pwa-sheet{background:#111a2e;color:#e5ecff;border:1px solid #26324f}'
            + '.pwa-row{display:flex;gap:12px;align-items:center}.pwa-row img{width:52px;height:52px;border-radius:14px;flex:0 0 52px}'
            + '.pwa-t{font-weight:800;font-size:1rem;line-height:1.2}.pwa-s{font-size:.82rem;opacity:.75;margin-top:2px}'
            + '.pwa-btns{display:flex;gap:8px;margin-top:12px}.pwa-btns button{flex:1;border:0;border-radius:12px;padding:10px;font-weight:700;font-size:.92rem;cursor:pointer}'
            + '.pwa-go{background:linear-gradient(90deg,#1a5bd6,#2563eb);color:#fff}.pwa-no{background:#eef2f7;color:#334155}[data-bs-theme=dark] .pwa-no{background:#1f2a44;color:#cbd5e1}'
            + '.pwa-ios{font-size:.84rem;margin-top:10px;line-height:1.5}.pwa-ios b{white-space:nowrap}'
            + '@keyframes pwaUp{from{transform:translateY(30px);opacity:0}to{transform:none;opacity:1}}';
        document.head.appendChild(s);
    }
    function close(later) { var el = document.getElementById('pwaSheet'); if (el) el.remove(); if (later) set(KEY, today()); }
    function show(mode) {
        if (document.getElementById('pwaSheet') || get(KEY) === today()) return;
        css();
        var name = (document.querySelector('meta[name="application-name"]') || {}).content || 'Krishna Attendance';
        var d = document.createElement('div'); d.className = 'pwa-sheet' + (document.querySelector('.ui-bottom-nav') ? '' : ' pwa-top'); d.id = 'pwaSheet'; d.setAttribute('role', 'dialog');
        var body = '<div class="pwa-row"><img src="' + base + 'assets/icons/icon-192.png" alt=""><div><div class="pwa-t">Install ' + name + ' app</div><div class="pwa-s">એપ ઇન્સ્ટોલ કરો — હોમ સ્ક્રીન પરથી સીધું ખોલો, ઝડપી અને સરળ.</div></div></div>';
        if (mode === 'ios') {
            body += '<div class="pwa-ios">1. નીચે <b>Share ⬆︎</b> બટન દબાવો<br>2. <b>"Add to Home Screen" ➕</b> પસંદ કરો<br>3. <b>Add</b> દબાવો</div><div class="pwa-btns"><button class="pwa-no" data-a="later">Later</button></div>';
        } else {
            body += '<div class="pwa-btns"><button class="pwa-no" data-a="later">Later</button><button class="pwa-go" data-a="install">Install app</button></div>';
        }
        d.innerHTML = body;
        d.addEventListener('click', function (e) {
            var a = e.target.getAttribute('data-a'); if (!a) return;
            if (a === 'later') close(true);
            if (a === 'install' && deferred) {
                deferred.prompt();
                deferred.userChoice.then(function (c) { if (c.outcome === 'accepted') set(DONE, '1'); close(c.outcome !== 'accepted'); deferred = null; });
            }
        });
        document.body.appendChild(d);
    }
    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault(); deferred = e;
        setTimeout(function () { show('native'); }, 1500);
    });
    window.addEventListener('appinstalled', function () { set(DONE, '1'); close(false); });
    if (isIOS && isMobile && !/crios|fxios/i.test(ua)) { setTimeout(function () { show('ios'); }, 2000); }
})();

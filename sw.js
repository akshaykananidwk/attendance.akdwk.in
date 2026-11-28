/* Krishna Attendance service worker — offline page + fast static assets.
 * PHP pages (salary, attendance) are NEVER cached: always fresh from the server. */
const VERSION = 'v2.6.0';
const STATIC = 'akdwk-static-' + VERSION;
const PRECACHE = ['offline.html', 'assets/icons/icon-192.png', 'assets/icons/icon-512.png', 'assets/pwa.js'];

self.addEventListener('install', (e) => {
    e.waitUntil(caches.open(STATIC).then((c) => c.addAll(PRECACHE)).then(() => self.skipWaiting()));
});
self.addEventListener('activate', (e) => {
    e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k.startsWith('akdwk-') && k !== STATIC).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});
self.addEventListener('fetch', (e) => {
    const req = e.request;
    if (req.method !== 'GET') return;
    const url = new URL(req.url);
    // Page navigations: network first, offline page if there is no connection.
    if (req.mode === 'navigate') {
        e.respondWith(fetch(req).catch(() => caches.match('offline.html')));
        return;
    }
    const sameOrigin = url.origin === self.location.origin;
    const isStatic = /\.(css|js|png|jpg|jpeg|svg|webp|woff2?|ico)$/i.test(url.pathname);
    const isCdn = url.hostname === 'cdn.jsdelivr.net';
    if ((sameOrigin && isStatic && !url.pathname.endsWith('/sw.js')) || isCdn) {
        // stale-while-revalidate
        e.respondWith(caches.open(STATIC).then((cache) => cache.match(req).then((hit) => {
            const net = fetch(req).then((res) => { if (res && (res.ok || res.type === 'opaque')) cache.put(req, res.clone()); return res; }).catch(() => hit);
            return hit || net;
        })));
    }
});

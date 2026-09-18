/*
 * Service worker for Filament.
 *
 * The point is modest: the app should open and show the collection when
 * you are standing in the shed with one bar of signal. Photos you have
 * already looked at stay available too.
 *
 * Raise CACHE when you change the css or js, otherwise people keep the
 * old files.
 */

const CACHE = 'filament-v1';

const SHELL = [
    'assets/app.css',
    'assets/app.js',
    'assets/icon-192.png',
    'assets/icon-512.png',
    'manifest.json'
];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE)
            // If one file fails, the whole install need not strand.
            .then((cache) => Promise.allSettled(SHELL.map((url) => cache.add(url))))
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(
                keys.filter((k) => k !== CACHE).map((k) => caches.delete(k))
            ))
            .then(() => self.clients.claim())
    );
});

self.addEventListener('fetch', (event) => {
    const req = event.request;

    // Only plain GET traffic. Saving a spool is a POST that really has to
    // reach the server.
    if (req.method !== 'GET') {
        return;
    }

    const url = new URL(req.url);

    if (url.origin !== self.location.origin) {
        return;
    }

    // Never hold on to the login or the installer.
    if (url.pathname.endsWith('login.php') || url.pathname.endsWith('install.php')) {
        return;
    }

    // The pages themselves: network first, because a stale list of spools
    // is the one thing this app should not show. Fall back to the last
    // copy that came through.
    if (req.mode === 'navigate' || url.pathname.endsWith('.php')) {
        event.respondWith(
            fetch(req)
                .then((res) => {
                    const copy = res.clone();
                    caches.open(CACHE).then((c) => c.put(req, copy));
                    return res;
                })
                .catch(() => caches.match(req).then((hit) => hit || caches.match('index.php')))
        );
        return;
    }

    // Photos, css and js: straight from the cache, refreshed in the
    // background. Otherwise you stay on the old css and js after a change
    // until someone remembers to raise the cache name.
    event.respondWith(
        caches.match(req).then((hit) => {
            const fresh = fetch(req)
                .then((res) => {
                    if (res && res.ok) {
                        const copy = res.clone();
                        caches.open(CACHE).then((c) => c.put(req, copy));
                    }
                    return res;
                })
                .catch(() => hit);

            return hit || fresh;
        })
    );
});

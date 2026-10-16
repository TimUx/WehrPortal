const CACHE = 'openwehr-shell-v2';
const OFFLINE = '/offline';
const STATIC_PATHS = ['/build/assets/', '/images/', '/fonts/'];
const BYPASS_PATHS = ['/livewire/', '/broadcasting/', '/api/', '/anmelden', '/e-mail-bestaetigen', '/login', '/auth/login', '/auth/email', '/admin/logout'];

self.addEventListener('install', (event) => {
    event.waitUntil(caches.open(CACHE).then((cache) => cache.add(new Request(OFFLINE, { cache: 'reload' }))));
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key.startsWith('openwehr-') && key !== CACHE).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET' || new URL(request.url).origin !== self.location.origin) {
        return;
    }

    const url = new URL(request.url);
    if (BYPASS_PATHS.some((path) => url.pathname === path || url.pathname.startsWith(path))) {
        return;
    }

    if (request.mode === 'navigate') {
        event.respondWith(fetch(request).catch(() => caches.match(OFFLINE)));
        return;
    }

    if (!STATIC_PATHS.some((path) => url.pathname.startsWith(path))) {
        return;
    }

    event.respondWith(caches.match(request).then((cached) => cached || fetch(request).then(async (response) => {
        if (response.status === 200 && !response.redirected && response.type === 'basic'
            && !/private|no-store/i.test(response.headers.get('Cache-Control') || '')
            && !response.headers.has('Set-Cookie')) {
            await caches.open(CACHE).then((cache) => cache.put(request, response.clone()));
        }

        return response;
    })));
});

self.addEventListener('message', (event) => {
    if (event.data?.type === 'CLEAR_OFFLINE_DATA') {
        event.waitUntil(caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key.startsWith('openwehr-')).map((key) => caches.delete(key))))
            .then(() => caches.open(CACHE))
            .then((cache) => cache.add(new Request(OFFLINE, { cache: 'reload' })).catch(() => {})));
    }
});

self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch (e) {
        data = { body: event.data ? event.data.text() : '' };
    }
    event.waitUntil(self.registration.showNotification(data.title || 'openwehr', {
        body: data.body || '',
        icon: '/images/icon-192.png',
        badge: '/images/icon-192.png',
        data: { url: data.url || '/' },
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = new URL(event.notification.data?.url || '/', self.location.origin).href;
    event.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
        const open = windows.find((w) => w.url === url);
        return open ? open.focus() : self.clients.openWindow(url);
    }));
});

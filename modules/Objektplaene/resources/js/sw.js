// WehrPortal Objektpläne: keeps the plans usable without network (cellar, rural area, dead zone).
// Plan files never change (a new upload is a new version), so they come from the cache first;
// pages are fetched fresh and only taken from the cache when the network is gone. "Für offline
// speichern" on the library page sends the list of pages and files to cache.
const FILES = 'objektplaene-files-v1';
const PAGES = 'objektplaene-pages-v1';
const ASSETS = 'objektplaene-assets-v1';

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (event) => event.waitUntil(self.clients.claim()));

const isFile = (path) => path.startsWith('/objektplaene/datei/');

// Scripts and styles of a page (Vite build, Livewire), so a cached page still works offline
async function cacheAssets(html) {
    const cache = await caches.open(ASSETS);
    const urls = [...html.matchAll(/(?:src|href)="([^"]+)"/g)].map((m) => new URL(m[1], self.location.origin))
        .filter((u) => u.origin === self.location.origin && (u.pathname.startsWith('/build/') || u.pathname.startsWith('/livewire')));
    await Promise.all(urls.map((u) => cache.add(u.href).catch(() => null)));
}

async function cacheAll(urls, client) {
    let done = 0;
    let failed = 0;
    for (const url of urls) {
        try {
            const path = new URL(url, self.location.origin).pathname;
            const response = await fetch(url, { credentials: 'same-origin' });
            if (response.ok && !response.redirected) {
                await (await caches.open(isFile(path) ? FILES : PAGES)).put(path, response.clone());
                if (!isFile(path)) {
                    await cacheAssets(await response.text());
                }
            } else {
                failed++;
            }
        } catch {
            failed++;
        }
        done++;
        client?.postMessage({ type: 'objektplaene-progress', done, total: urls.length });
    }
    client?.postMessage({ type: 'objektplaene-done', done, failed });
}

self.addEventListener('message', (event) => {
    if (event.data?.type === 'objektplaene-cache') {
        event.waitUntil(cacheAll(event.data.urls ?? [], event.source));
    }
});

const offline = () => new Response('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width"><p style="font-family:sans-serif;padding:1rem">Offline – diese Seite ist nicht gespeichert. Speichere die Objektpläne vorher mit „Für offline speichern“.</p>',
    { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } });

self.addEventListener('fetch', (event) => {
    const request = event.request;
    if (request.method !== 'GET') {
        return;
    }
    const url = new URL(request.url);
    if (url.origin !== self.location.origin) {
        return;
    }

    if (isFile(url.pathname)) {
        event.respondWith(caches.match(url.pathname).then((hit) => hit || fetch(request)));
        return;
    }

    if (request.mode === 'navigate' && url.pathname.startsWith('/objektplaene')) {
        event.respondWith(fetch(request).then((response) => {
            if (response.ok && !response.redirected) {
                const copy = response.clone();
                caches.open(PAGES).then((cache) => cache.put(url.pathname, copy));
            }
            return response;
        }).catch(() => caches.match(url.pathname).then((hit) => hit || offline())));
        return;
    }

    if (url.pathname.startsWith('/build/') || url.pathname.startsWith('/livewire')) {
        event.respondWith(caches.match(request).then((hit) => hit || fetch(request).then((response) => {
            if (response.ok) {
                const copy = response.clone();
                caches.open(ASSETS).then((cache) => cache.put(request, copy));
            }
            return response;
        })));
    }
});

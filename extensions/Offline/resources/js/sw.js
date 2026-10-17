const CACHE = 'openwehr-shell-v2';
const OFFLINE = '/offline';
const STATIC_PATHS = ['/build/assets/', '/images/', '/fonts/'];
const BYPASS_PATHS = ['/livewire/', '/broadcasting/', '/api/', '/anmelden', '/e-mail-bestaetigen', '/login', '/auth/login', '/auth/email', '/admin/logout'];
const REFERENCE_PAGES = ['/gefahrenmatrix', '/gefahrstoffe'];
const REFERENCE_DATA = '/gefahrstoffe/katalog.json';

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
        if (REFERENCE_PAGES.includes(url.pathname)) {
            event.respondWith(fetch(request).catch(() => offlineReferencePage(url.pathname)));
        } else {
            event.respondWith(fetch(request).catch(() => caches.match(OFFLINE)));
        }
        return;
    }

    if (url.pathname === REFERENCE_DATA) {
        event.respondWith(fetch(request).then(async (response) => {
            if (response.ok && !response.redirected && response.headers.get('Content-Type')?.includes('application/json')) {
                await caches.open(CACHE).then((cache) => cache.put(REFERENCE_DATA, response.clone()));
            }

            return response;
        }).catch(async () => (await caches.match(REFERENCE_DATA)) || new Response('{"materials":[]}', { headers: { 'Content-Type': 'application/json' } })));
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
    if (event.data?.type === 'SAVE_REFERENCE_DATA') {
        event.waitUntil(fetch(REFERENCE_DATA, { credentials: 'same-origin', cache: 'reload' })
            .then(async (response) => {
                if (!response.ok || response.redirected || !response.headers.get('Content-Type')?.includes('application/json')) {
                    throw new Error('Reference data unavailable');
                }
                await caches.open(CACHE).then((cache) => cache.put(REFERENCE_DATA, response));
                event.ports[0]?.postMessage({ ok: true });
            })
            .catch(() => event.ports[0]?.postMessage({ ok: false })));
        return;
    }

    if (event.data?.type === 'CLEAR_OFFLINE_DATA') {
        event.waitUntil(caches.keys()
            .then((keys) => Promise.all(keys.filter((key) => key.startsWith('openwehr-')).map((key) => caches.delete(key))))
            .then(() => caches.open(CACHE))
            .then((cache) => cache.add(new Request(OFFLINE, { cache: 'reload' })).catch(() => {})));
    }
});

function offlineReferencePage(path) {
    const matrix = path === '/gefahrenmatrix';
    const title = matrix ? 'Gefahrenmatrix' : 'Gefahrstoffdatenbank';
    const body = matrix
        ? `<main><h1>Gefahrenmatrix</h1><p>Offline-Merkstütze · Auswahl wird nicht gespeichert.</p>${[['AAAA', ['Atemgift', 'Angstreaktion', 'Ausbreitung', 'Atomare Gefahr']], ['CCCC', ['Chemische Stoffe', 'CBRNEx', 'Container', 'Korrosiv (ätzend)']], ['EEEE', ['Elektrizität', 'Erkrankung / Seuche', 'Explosion', 'Einsturz / Absturz']], ['Weitere Gefahren', ['Gefährliche Tiere', 'Gewalt', 'Wasser', 'Hitze / Kälte', 'Verkehr', 'Umwelt', 'Öl / Kraftstoffe', 'Radioaktive Strahlung']]].map(([group, hazards]) => `<section><h2>${group}</h2>${hazards.map((hazard) => `<button type="button" aria-pressed="false">${hazard}</button>`).join('')}</section>`).join('')}<section><h2>Ausgewählte Gefahren</h2><ul id="selected"></ul><button id="clear" type="button">Auswahl zurücksetzen</button></section><p>Keine Schutzgüter oder Maßnahmen werden abgeleitet.</p></main><script>const selected=new Set();document.querySelectorAll('section button[aria-pressed]').forEach(button=>button.addEventListener('click',()=>{const value=button.textContent;if(selected.has(value)){selected.delete(value);button.setAttribute('aria-pressed','false')}else{selected.add(value);button.setAttribute('aria-pressed','true')}render()}));document.querySelector('#clear').addEventListener('click',()=>{selected.clear();document.querySelectorAll('[aria-pressed=true]').forEach(button=>button.setAttribute('aria-pressed','false'));render()});function render(){document.querySelector('#selected').replaceChildren(...[...selected].map(value=>{const li=document.createElement('li');li.textContent=value;return li}))}</script>`
        : '<main><h1>Gefahrstoffdatenbank</h1><p>Offline-Katalog · Suche nach UN-Nummer, Stoffname oder Synonym.</p><input id="q" type="search" placeholder="Suchen"><p id="status">Katalog wird geladen …</p><section id="items"></section></main><script>fetch("/gefahrstoffe/katalog.json").then(r=>r.json()).then(data=>{const items=data.materials||[],q=document.querySelector("#q"),out=document.querySelector("#items"),status=document.querySelector("#status");function render(){const term=q.value.trim().toLocaleLowerCase("de"),matches=items.filter(m=>[m.un_number,m.name,m.synonyms,m.description].some(v=>String(v||"").toLocaleLowerCase("de").includes(term))).slice(0,100);status.textContent=items.length?matches.length+" Treffer":"Keine offline gespeicherten Daten";out.replaceChildren(...matches.map(m=>{const details=document.createElement("details"),summary=document.createElement("summary"),text=document.createElement("p");summary.textContent="UN "+m.un_number+" · "+m.name;const lines=[m.adr_class&&"ADR "+m.adr_class,m.packing_group&&"VG "+m.packing_group,m.description,m.dangers,m.first_aid,m.firefighting,m.spillage,"Quelle: "+m.source_name+(m.source_version?" · "+m.source_version:"")].filter(Boolean);text.textContent=lines.join("\\\\n");details.append(summary,text);return details}))}q.addEventListener("input",render);render()}).catch(()=>document.querySelector("#status").textContent="Keine offline gespeicherten Gefahrstoffdaten verfügbar.")</script>';

    return new Response(`<!doctype html><html lang="de"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>${title} – offline</title><style>body{font:16px/1.5 system-ui,sans-serif;margin:0;background:#f8fafc;color:#1f2937}main{max-width:56rem;margin:auto;padding:1rem}button,summary,input{font:inherit}button{padding:.75rem;margin:.25rem;border:1px solid #cbd5e1;border-radius:.75rem;background:white}button[aria-pressed=true]{background:#1b2645;color:white}section,details{margin:1rem 0;padding:1rem;background:white;border:1px solid #e5e7eb;border-radius:1rem}input{box-sizing:border-box;width:100%;padding:.75rem}</style><body>${body}</body></html>`, { headers: { 'Content-Type': 'text/html; charset=utf-8' } });
}

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

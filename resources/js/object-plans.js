// Objektpläne: "Für offline speichern" – registers the module's service worker (scope /objektplaene)
// and hands it the pages and plan files to keep. The date of the last save stays in this browser.
const KEY = 'objektplaene-offline-at';

document.addEventListener('alpine:init', () => {
    window.Alpine.data('offlinePlans', ({ urls, worker }) => ({
        supported: 'serviceWorker' in navigator && 'caches' in window,
        busy: false,
        done: 0,
        total: 0,
        failed: 0,
        savedAt: null,
        async init() {
            try {
                this.savedAt = localStorage.getItem(KEY);
            } catch {
                this.savedAt = null;
            }
            if (!this.supported) {
                return;
            }
            navigator.serviceWorker.register(worker, { scope: '/objektplaene' }).catch(() => (this.supported = false));
            navigator.serviceWorker.addEventListener('message', (event) => {
                if (event.data?.type === 'objektplaene-progress') {
                    this.done = event.data.done;
                } else if (event.data?.type === 'objektplaene-done') {
                    this.busy = false;
                    this.failed = event.data.failed;
                    this.savedAt = new Date().toLocaleString('de-DE', { dateStyle: 'short', timeStyle: 'short' });
                    try {
                        localStorage.setItem(KEY, this.savedAt);
                    } catch {
                        // private window: the date is just not remembered
                    }
                }
            });
        },
        async save() {
            const registration = await navigator.serviceWorker.ready;
            this.busy = true;
            this.done = 0;
            this.failed = 0;
            this.total = urls.length;
            registration.active.postMessage({ type: 'objektplaene-cache', urls });
        },
        async clear() {
            for (const key of await caches.keys()) {
                if (key.startsWith('objektplaene')) {
                    await caches.delete(key);
                }
            }
            try {
                localStorage.removeItem(KEY);
            } catch {
                // nothing remembered
            }
            this.savedAt = null;
        },
    }));
});

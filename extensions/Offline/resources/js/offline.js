const DB_NAME = 'openwehr-offline-v1';
const STORE = 'drafts';
const MAX_AGE = 30 * 24 * 60 * 60 * 1000;
const root = document.querySelector('[data-offline-pwa]');
const userId = root?.dataset.userId;

function openDatabase() {
    return new Promise((resolve, reject) => {
        if (!('indexedDB' in window) || !userId) {
            reject(new Error('Offline-Speicher nicht verfügbar'));
            return;
        }

        const request = indexedDB.open(DB_NAME, 1);
        request.onupgradeneeded = () => request.result.createObjectStore(STORE, { keyPath: 'id' });
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

async function withStore(mode, action) {
    const db = await openDatabase();

    return new Promise((resolve, reject) => {
        const transaction = db.transaction(STORE, mode);
        const request = action(transaction.objectStore(STORE));
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
        transaction.oncomplete = () => db.close();
        transaction.onerror = () => {
            db.close();
            reject(transaction.error);
        };
    });
}

const draftId = (form) => `${userId}:${form.dataset.offlineDraft}:${location.pathname}${location.search}`;
const fields = (form) => [...form.querySelectorAll('[data-offline-draft-field][wire\\:model]')];

function valuesOf(form) {
    const values = {};
    for (const field of fields(form)) {
        const name = field.getAttribute('wire:model');
        if (!name || ['password', 'file', 'hidden'].includes(field.type)) continue;
        if (field.type === 'radio') {
            if (field.checked) values[name] = field.value;
        } else if (field.type === 'checkbox') {
            values[name] = field.checked;
        } else if (field.multiple) {
            values[name] = [...field.selectedOptions].map((option) => option.value);
        } else {
            values[name] = field.value;
        }
    }

    return values;
}

function hasValues(values) {
    return Object.values(values).some((value) => Array.isArray(value) ? value.length > 0 : value !== '' && value !== false);
}

function applyValues(form, values) {
    const grouped = new Map();
    for (const field of fields(form)) {
        const name = field.getAttribute('wire:model');
        if (!name || !(name in values)) continue;
        if (field.type === 'radio') {
            field.checked = field.value === values[name];
        } else if (field.type === 'checkbox') {
            field.checked = Boolean(values[name]);
        } else if (field.multiple && Array.isArray(values[name])) {
            for (const option of field.options) option.selected = values[name].includes(option.value);
        } else if (!field.options || [...field.options].some((option) => option.value === String(values[name]))) {
            field.value = values[name];
        }

        if (!grouped.has(name) || field.checked) grouped.set(name, field);
    }

    for (const field of grouped.values()) {
        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.dispatchEvent(new Event('change', { bubbles: true }));
    }
}

function renderStatus(isOnline = navigator.onLine) {
    const indicator = root?.querySelector('[data-offline-indicator]');
    const banner = root?.querySelector('[data-offline-banner]');
    if (indicator) indicator.textContent = isOnline ? 'Online' : 'Offline';
    if (banner) banner.hidden = isOnline;
}

async function listDrafts() {
    const all = await withStore('readonly', (store) => store.getAll());
    const ownDrafts = all.filter((draft) => draft.userId === userId);
    const expired = ownDrafts.filter((draft) => Date.now() - draft.savedAt > MAX_AGE);
    if (expired.length) {
        await withStore('readwrite', (store) => {
            let request;
            expired.forEach((draft) => {
                request = store.delete(draft.id);
            });

            return request;
        });
    }

    return ownDrafts.filter((draft) => !expired.includes(draft));
}

async function refreshDrafts() {
    const drafts = await listDrafts().catch(() => []);
    const count = root?.querySelector('[data-offline-count]');
    const list = root?.querySelector('[data-offline-drafts]');
    const button = root?.querySelector('[data-offline-open-drafts]');
    if (count) count.textContent = String(drafts.length);
    if (button) button.hidden = drafts.length === 0;
    if (!list) return;

    list.replaceChildren();
    for (const draft of drafts) {
        const item = document.createElement('li');
        const link = document.createElement('a');
        let target;
        try {
            target = new URL(draft.url, location.origin);
        } catch {
            continue;
        }
        if (target.origin !== location.origin) continue;
        link.href = `${target.pathname}${target.search}`;
        link.textContent = draft.label || 'Entwurf öffnen';
        link.className = 'underline';
        item.append(link);
        list.append(item);
    }
}

function addDraftControls(form, onRestore, onDiscard) {
    const controls = document.createElement('div');
    controls.className = 'offline-draft-controls';
    controls.hidden = true;
    controls.innerHTML = '<p>Ein lokaler Entwurf ist vorhanden. Er wird nicht automatisch gesendet.</p><button type="button" data-restore>Entwurf wiederherstellen</button> <button type="button" data-discard>Verwerfen</button>';
    form.before(controls);
    controls.querySelector('[data-restore]').addEventListener('click', async () => {
        const draft = await withStore('readonly', (store) => store.get(draftId(form))).catch(() => null);
        if (!draft) return;
        onRestore(true);
        try {
            applyValues(form, draft.values);
            await withStore('readwrite', (store) => store.delete(draft.id));
            controls.hidden = true;
            await refreshDrafts();
        } finally {
            onRestore(false);
        }
    });
    controls.querySelector('[data-discard]').addEventListener('click', async () => {
        onDiscard();
        await withStore('readwrite', (store) => store.delete(draftId(form))).catch(() => {});
        controls.hidden = true;
        await refreshDrafts();
    });

    return controls;
}

async function initializeForm(form) {
    if (!userId || !form.dataset.offlineDraft || form.dataset.offlineDraftReady) return;
    form.dataset.offlineDraftReady = 'true';
    let restoring = false;
    let savedValues = JSON.stringify(valuesOf(form));
    let timer;
    const controls = addDraftControls(form, (value) => {
        restoring = value;
        if (!restoring) savedValues = JSON.stringify(valuesOf(form));
    }, () => {
        clearTimeout(timer);
        savedValues = JSON.stringify(valuesOf(form));
    });
    const draft = await withStore('readonly', (store) => store.get(draftId(form))).catch(() => null);
    if (draft) controls.hidden = false;

    const storeDraft = async (values) => {
        const id = draftId(form);
        if (!hasValues(values)) {
            await withStore('readwrite', (store) => store.delete(id)).catch(() => {});
            controls.hidden = true;
        } else {
            await withStore('readwrite', (store) => store.put({
                id,
                userId,
                url: `${location.pathname}${location.search}`,
                label: document.querySelector('h1')?.textContent?.trim() || 'Formularentwurf',
                values,
                savedAt: Date.now(),
            })).catch(() => {});
            controls.hidden = false;
        }
        await refreshDrafts();
    };
    const saveDraft = () => {
        if (restoring) return;
        savedValues = JSON.stringify(valuesOf(form));
        clearTimeout(timer);
        timer = setTimeout(async () => {
            const values = JSON.parse(savedValues);
            await storeDraft(values);
        }, 350);
    };

    form.addEventListener('input', saveDraft);
    form.addEventListener('change', saveDraft);
    const poll = setInterval(() => {
        const current = JSON.stringify(valuesOf(form));
        if (current !== savedValues) saveDraft();
    }, 1000);
    form.offlineDraftPoll = poll;
    window.addEventListener('pagehide', () => clearInterval(poll), { once: true });
    form.addEventListener('submit', () => {
        sessionStorage.setItem('offline-draft-submit', JSON.stringify({ id: draftId(form), path: location.pathname }));
        clearTimeout(timer);
        storeDraft(valuesOf(form));
    }, { capture: true });
}

async function clearUserData() {
    if ('indexedDB' in window) {
        await withStore('readwrite', (store) => store.clear()).catch(() => {});
    }
    const worker = navigator.serviceWorker?.controller || (await navigator.serviceWorker?.ready.catch(() => null))?.active;
    worker?.postMessage({ type: 'CLEAR_OFFLINE_DATA' });
}

async function cleanupSubmittedDraft() {
    const submitted = sessionStorage.getItem('offline-draft-submit');
    if (!submitted) return;
    try {
        const data = JSON.parse(submitted);
        if (data.path !== location.pathname) {
            await withStore('readwrite', (store) => store.delete(data.id)).catch(() => {});
            sessionStorage.removeItem('offline-draft-submit');
        }
    } catch {
        sessionStorage.removeItem('offline-draft-submit');
    }
}

function initialize() {
    if (!root) return;
    renderStatus();
    document.querySelectorAll('form[data-offline-draft]').forEach(initializeForm);
    refreshDrafts();
    cleanupSubmittedDraft();
}

if (root) {
    window.addEventListener('online', () => renderStatus(true));
    window.addEventListener('offline', () => renderStatus(false));
    root.querySelector('[data-offline-open-drafts]')?.addEventListener('click', () => {
        const list = root.querySelector('[data-offline-drafts]');
        if (list) list.hidden = !list.hidden;
    });
    root.querySelector('[data-offline-sync]')?.addEventListener('click', async () => {
        renderStatus();
        await navigator.serviceWorker?.ready.then((registration) => registration.update()).catch(() => {});
        await refreshDrafts();
        root.querySelector('[data-offline-message]').textContent = navigator.onLine
            ? 'Entwürfe bleiben lokal. Stelle sie im Formular wieder her und sende sie dort wie gewohnt ab.'
            : 'Keine Verbindung. Entwürfe bleiben auf diesem Gerät gespeichert.';
    });
    document.addEventListener('submit', async (event) => {
        const form = event.target;
        if (!(form instanceof HTMLFormElement) || !['/abmelden', '/admin/logout', '/admin/abmelden'].includes(new URL(form.action, location.href).pathname)) return;
        event.preventDefault();
        await clearUserData();
        form.submit();
    }, { capture: true });
    document.addEventListener('livewire:navigated', initialize);
    document.addEventListener('livewire:navigating', () => {
        document.querySelectorAll('form[data-offline-draft]').forEach((form) => clearInterval(form.offlineDraftPoll));
    });
    initialize();
}

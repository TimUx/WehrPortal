document.addEventListener('alpine:init', () => {
    window.Alpine.data('hazardMatrix', (groups, matrixEntities, matrixEffects) => ({
        groups,
        matrixEntities,
        matrixEffects,
        selected: [],
        toggle(key) {
            this.selected = this.selected.includes(key) ? this.selected.filter((item) => item !== key) : [...this.selected, key];
        },
        clear() {
            this.selected = [];
        },
        label(key) {
            return Object.values(this.groups).flatMap((group) => Object.entries(group.hazards)).find(([hazard]) => hazard === key)?.[1] || '';
        },
        get matrixRows() {
            return Object.values(this.groups)
                .flatMap((group) => Object.entries(group.hazards))
                .filter(([key]) => this.selected.includes(key));
        },
        appliesTo(hazard, entity) {
            return this.matrixEffects[hazard]?.includes(entity) ?? false;
        },
    }));

    window.Alpine.data('hazardousMaterials', () => ({
        items: [],
        query: '',
        loading: true,
        error: '',
        loadedAt: '',
        sections: [
            ['Synonyme', 'synonyms'],
            ['Beschreibung', 'description'],
            ['Gefahren', 'dangers'],
            ['Erste Hilfe', 'first_aid'],
            ['Brandbekämpfung', 'firefighting'],
            ['Freisetzung', 'spillage'],
        ],
        get filtered() {
            const query = this.query.trim().toLocaleLowerCase('de');
            return this.items.filter((item) => !query || [
                item.un_number, item.name, item.synonyms, item.description, item.adr_class, item.hazard_number,
            ].some((value) => String(value || '').toLocaleLowerCase('de').includes(query)));
        },
        async init() {
            try {
                const response = await fetch('/gefahrstoffe/katalog.json');
                if (!response.ok) throw new Error('Der Katalog konnte nicht geladen werden.');
                const result = await response.json();
                this.items = result.materials || [];
                this.loadedAt = result.generated_at ? new Date(result.generated_at).toLocaleString('de-DE') : '';
            } catch {
                this.error = 'Der Katalog ist offline nicht verfügbar. Stelle online eine Verbindung her und speichere ihn vorher für die Offline-Nutzung.';
            } finally {
                this.loading = false;
            }
        },
        format(value) {
            return Array.isArray(value) ? value.join(', ') : value;
        },
    }));

    window.Alpine.data('referenceOffline', () => ({
        saving: false,
        message: '',
        async save() {
            if (!('serviceWorker' in navigator)) {
                this.message = 'Dieser Browser unterstützt keine Offline-Speicherung.';
                return;
            }
            this.saving = true;
            this.message = '';
            try {
                const registration = await navigator.serviceWorker.ready;
                const worker = registration.active;
                if (!worker) throw new Error();

                const channel = new MessageChannel();
                const result = new Promise((resolve, reject) => {
                    channel.port1.onmessage = (event) => event.data?.ok ? resolve(event.data) : reject(new Error());
                    setTimeout(() => reject(new Error()), 15000);
                });
                worker.postMessage({ type: 'SAVE_REFERENCE_DATA' }, [channel.port2]);
                await result;
                this.message = 'Gefahrenmatrix und Gefahrstoffdatenbank sind auf diesem Gerät offline verfügbar.';
            } catch {
                this.message = 'Offline-Speicherung fehlgeschlagen. Prüfe die Verbindung und versuche es erneut.';
            } finally {
                this.saving = false;
            }
        },
    }));
});

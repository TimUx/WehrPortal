<x-layouts.app title="Dateien">
    @livewire('dateien.explorer')
    <script>
        window.dateienUpload = () => ({
            items: [], over: false, busy: false, seq: 0, current: null, error: '', directories: [],
            add(files, directories = []) {
                if (this.busy) return;
                const multiple = this.$refs.input.multiple;
                const incoming = Array.from(files).map((item) => item.file ? item : {
                    file: item,
                    path: item.webkitRelativePath ? item.webkitRelativePath.split('/').slice(0, -1).join('/') : '',
                });
                if (incoming.length > (multiple ? 50 : 1)) {
                    this.error = 'Pro Upload können höchstens 50 Dateien ausgewählt werden.';
                    return;
                }
                this.error = '';
                this.directories = multiple ? [...new Set(directories)] : [];
                for (const entry of incoming) {
                    const file = entry.file;
                    const path = multiple ? entry.path : '';
                    if (path) {
                        const parts = path.split('/');
                        for (let i = 1; i <= parts.length; i++) this.directories.push(parts.slice(0, i).join('/'));
                    }
                    const item = { id: ++this.seq, file, name: path ? `${path}/${file.name}` : file.name, path, progress: 0, state: 'waiting', message: 'Wartet' };
                    if (file.size > Number(this.$root.dataset.maxKb) * 1024) {
                        Object.assign(item, { state: 'error', message: 'Zu groß' });
                    }
                    this.items.push(item);
                }
                this.directories = [...new Set(this.directories)];
                if (this.directories.length > 500) {
                    this.error = 'Pro Upload können höchstens 500 Ordner angelegt werden.';
                    this.items = [];
                    this.directories = [];
                    return;
                }
                this.run();
            },
            async drop(event) {
                this.over = false;
                if (this.busy) return;
                const files = [], directories = [];
                const walk = async (entry, path = '') => {
                    const current = path ? `${path}/${entry.name}` : entry.name;
                    if (entry.isFile) {
                        const file = await new Promise((resolve, reject) => entry.file(resolve, reject));
                        files.push({ file, path });
                        return;
                    }
                    if (!entry.isDirectory) return;
                    if (directories.length >= 500) throw new Error('Pro Upload können höchstens 500 Ordner angelegt werden.');
                    directories.push(current);
                    const reader = entry.createReader();
                    let children = [];
                    do {
                        children = await new Promise((resolve, reject) => reader.readEntries(resolve, reject));
                        for (const child of children) await walk(child, current);
                    } while (children.length > 0);
                };
                try {
                    const items = Array.from(event.dataTransfer.items || []);
                    if (items.length && items.every((item) => typeof item.webkitGetAsEntry === 'function')) {
                        for (const item of items) {
                            const entry = item.webkitGetAsEntry();
                            if (entry) await walk(entry);
                        }
                    } else {
                        this.add(event.dataTransfer.files);
                        return;
                    }
                    const total = files.length;
                    if (total > 50) throw new Error('Pro Upload können höchstens 50 Dateien ausgewählt werden.');
                    if (total === 0 && directories.length === 0) throw new Error('Der Browser konnte den abgelegten Inhalt nicht lesen.');
                    this.add(files, directories);
                } catch (error) {
                    this.error = error.message || 'Der Ordner konnte nicht gelesen werden.';
                }
            },
            cancel(item) {
                if (item.state === 'running' && this.current) {
                    this.current.abort();
                } else {
                    Object.assign(item, { state: 'error', message: 'Abgebrochen' });
                }
            },
            async run() {
                if (this.busy) return;
                this.busy = true;
                let done = 0, nodeId = null;
                let foldersCreated = 0;
                if (this.directories.length) {
                    foldersCreated = await this.sendDirectories();
                    if (foldersCreated === null) {
                        this.items.filter((item) => item.state === 'waiting').forEach((item) => Object.assign(item, { state: 'error', message: 'Ordner konnten nicht angelegt werden' }));
                        this.busy = false;
                        return;
                    }
                }
                for (const item of this.items) {
                    if (item.state !== 'waiting') continue;
                    const result = await this.send(item);
                    if (result) { done++; nodeId = result.id; }
                }
                this.busy = false;
                if (done > 0) {
                    this.items = this.items.filter((item) => item.state !== 'done');
                    await this.$wire.uploaded(done, this.$root.dataset.node ? nodeId : null);
                } else if (foldersCreated > 0) {
                    await this.$wire.$refresh();
                }
            },
            sendDirectories() {
                return new Promise((resolve) => {
                    const data = new FormData();
                    data.append('space', this.$root.dataset.space);
                    if (this.$root.dataset.parent) data.append('parent', this.$root.dataset.parent);
                    this.directories.forEach((path, index) => data.append(`directories[${index}]`, path));
                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', this.$root.dataset.url);
                    xhr.setRequestHeader('Accept', 'application/json');
                    xhr.setRequestHeader('X-CSRF-TOKEN', document.querySelector('meta[name=csrf-token]').content);
                    xhr.onload = () => {
                        if (xhr.status >= 200 && xhr.status < 300) {
                            let body = {};
                            try { body = JSON.parse(xhr.responseText); } catch (e) {}
                            return resolve(Number(body.created) || 0);
                        }
                        let body = {};
                        try { body = JSON.parse(xhr.responseText); } catch (e) {}
                        const first = body.errors ? Object.values(body.errors)[0] : null;
                        this.error = (first && first[0]) || 'Ordner konnten nicht angelegt werden.';
                        resolve(null);
                    };
                    xhr.onerror = () => { this.error = 'Netzwerkfehler beim Anlegen der Ordner.'; resolve(null); };
                    xhr.send(data);
                });
            },
            send(item) {
                return new Promise((resolve) => {
                    const data = new FormData();
                    data.append('file', item.file);
                    if (item.path && !this.$root.dataset.node) data.append('path', item.path);
                    for (const [key, field] of [['space', 'space'], ['parent', 'parent'], ['node', 'node'], ['expected_version', 'expected']]) {
                        if (this.$root.dataset[field]) data.append(key, this.$root.dataset[field]);
                    }
                    const xhr = new XMLHttpRequest();
                    this.current = xhr;
                    xhr.open('POST', this.$root.dataset.url);
                    xhr.setRequestHeader('Accept', 'application/json');
                    xhr.setRequestHeader('X-CSRF-TOKEN', document.querySelector('meta[name=csrf-token]').content);
                    Object.assign(item, { state: 'running', message: '' });
                    xhr.upload.onprogress = (e) => { if (e.lengthComputable) item.progress = Math.round(e.loaded * 100 / e.total); };
                    xhr.onload = () => {
                        let body = {};
                        try { body = JSON.parse(xhr.responseText); } catch (e) {}
                        if (xhr.status >= 200 && xhr.status < 300) {
                            Object.assign(item, { state: 'done', message: 'Fertig', progress: 100 });
                            if (body.version) this.$root.dataset.expected = body.version;
                            return resolve(body);
                        }
                        const messages = { 403: 'Keine Berechtigung', 404: 'Ziel nicht gefunden', 413: 'Zu groß', 419: 'Sitzung abgelaufen, bitte Seite neu laden', 429: 'Zu viele Uploads, bitte kurz warten' };
                        const first = body.errors ? Object.values(body.errors)[0] : null;
                        Object.assign(item, { state: 'error', message: (first && first[0]) || messages[xhr.status] || 'Fehler beim Hochladen' });
                        resolve(null);
                    };
                    xhr.onerror = () => { Object.assign(item, { state: 'error', message: 'Netzwerkfehler' }); resolve(null); };
                    xhr.onabort = () => { Object.assign(item, { state: 'error', message: 'Abgebrochen' }); resolve(null); };
                    xhr.send(data);
                });
            },
        });
    </script>
</x-layouts.app>

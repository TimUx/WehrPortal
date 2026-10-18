<x-layouts.app title="Dateien">
    @livewire('dateien.explorer')
    <script>
        window.dateienUpload = () => ({
            items: [], over: false, busy: false, seq: 0, current: null,
            add(files) {
                const multiple = this.$refs.input.multiple;
                for (const file of Array.from(files).slice(0, multiple ? 50 : 1)) {
                    const item = { id: ++this.seq, file, name: file.name, progress: 0, state: 'waiting', message: 'Wartet' };
                    if (file.size > Number(this.$root.dataset.maxKb) * 1024) {
                        Object.assign(item, { state: 'error', message: 'Zu groß' });
                    }
                    this.items.push(item);
                }
                this.run();
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
                for (const item of this.items) {
                    if (item.state !== 'waiting') continue;
                    const result = await this.send(item);
                    if (result) { done++; nodeId = result.id; }
                }
                this.busy = false;
                if (done > 0) {
                    this.items = this.items.filter((item) => item.state !== 'done');
                    await this.$wire.uploaded(done, this.$root.dataset.node ? nodeId : null);
                }
            },
            send(item) {
                return new Promise((resolve) => {
                    const data = new FormData();
                    data.append('file', item.file);
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

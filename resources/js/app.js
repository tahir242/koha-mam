/*
 * Staff application JavaScript. Livewire ships Alpine; this file only
 * registers small Alpine components.
 */

/**
 * Resumable chunked uploader (Content-Range), compatible with
 * pion/laravel-chunk-upload's ContentRangeUploadHandler. Large files are
 * sent in chunks so no single request is long-running.
 */
// List keys for upload cards. crypto.randomUUID() is unavailable on plain HTTP.
let nextUploadId = 0;

function chunkedUploader(config) {
    return {
        items: [],
        dragging: false,
        busy: false,

        get hasItems() {
            return this.items.length > 0;
        },

        pick(event) {
            this.add([...event.target.files]);
            event.target.value = '';
        },

        drop(event) {
            this.dragging = false;
            this.add([...event.dataTransfer.files]);
        },

        add(files) {
            for (const file of files) {
                const ext = (file.name.split('.').pop() || '').toLowerCase();
                const item = {
                    id: ++nextUploadId,
                    file,
                    name: file.name,
                    size: file.size,
                    progress: 0,
                    status: 'queued',
                    message: '',
                    controller: null,
                };

                if (!config.extensions.includes(ext)) {
                    item.status = 'error';
                    item.message = `“.${ext}” files are not supported.`;
                } else if (file.size > config.maxBytes) {
                    item.status = 'error';
                    item.message = 'This file is larger than the maximum upload size.';
                } else if (file.size === 0) {
                    item.status = 'error';
                    item.message = 'This file is empty.';
                }

                this.items.push(item);
            }

            this.run();
        },

        async run() {
            if (this.busy) return;
            this.busy = true;

            let next;
            while ((next = this.items.find((i) => i.status === 'queued'))) {
                await this.upload(next);
            }

            this.busy = false;
        },

        async upload(item) {
            item.status = 'uploading';
            item.controller = new AbortController();
            const chunkSize = config.chunkBytes;
            let start = 0;

            try {
                while (start < item.size) {
                    const end = Math.min(start + chunkSize, item.size);
                    const body = new FormData();
                    body.append('file', item.file.slice(start, end), item.name);
                    body.append('biblio_id', config.biblioId);
                    if (config.itemId) body.append('item_id', config.itemId);
                    if (config.replace) body.append('replace', config.replace);

                    const response = await this.send(body, `bytes ${start}-${end - 1}/${item.size}`, item.controller.signal);
                    const data = await response.json().catch(() => ({}));

                    if (!response.ok) {
                        throw new Error(data.message || `Upload failed (HTTP ${response.status}).`);
                    }

                    start = end;
                    item.progress = Math.round((start / item.size) * 100);

                    if (data.done) {
                        item.status = 'done';
                        item.message = (data.warnings || []).join(' ') || 'Uploaded — validation and processing have started.';
                        window.Livewire?.dispatch('asset-uploaded', { asset: data.asset });
                    }
                }
            } catch (error) {
                if (error.name === 'AbortError') {
                    item.status = 'cancelled';
                    item.message = 'Upload cancelled.';
                } else {
                    item.status = 'error';
                    item.message = error.message;
                }
            }
        },

        async send(body, range, signal, attempt = 1) {
            try {
                const response = await fetch(config.endpoint, {
                    method: 'POST',
                    body,
                    signal,
                    credentials: 'same-origin',
                    headers: {
                        'Content-Range': range,
                        'X-CSRF-TOKEN': config.csrf,
                        Accept: 'application/json',
                    },
                });

                // Transient errors: retry the same chunk with backoff.
                if ([429, 502, 503, 504].includes(response.status) && attempt < 5) {
                    await new Promise((r) => setTimeout(r, 1000 * 2 ** attempt));
                    return this.send(body, range, signal, attempt + 1);
                }

                return response;
            } catch (error) {
                if (error.name !== 'AbortError' && attempt < 5) {
                    await new Promise((r) => setTimeout(r, 1000 * 2 ** attempt));
                    return this.send(body, range, signal, attempt + 1);
                }
                throw error;
            }
        },

        cancel(item) {
            item.controller?.abort();
            if (item.status === 'queued') {
                item.status = 'cancelled';
            }
        },

        clearFinished() {
            this.items = this.items.filter((i) => ['queued', 'uploading'].includes(i.status));
        },

        humanSize(bytes) {
            const units = ['B', 'KB', 'MB', 'GB', 'TB'];
            let i = 0;
            while (bytes >= 1024 && i < units.length - 1) {
                bytes /= 1024;
                i++;
            }
            return `${bytes.toFixed(i ? 1 : 0)} ${units[i]}`;
        },
    };
}

document.addEventListener('alpine:init', () => {
    window.Alpine.data('chunkedUploader', chunkedUploader);
});

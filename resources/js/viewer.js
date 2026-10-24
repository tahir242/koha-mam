/*
 * Public viewer — vanilla JS, no eval, compatible with the strict CSP.
 *
 * There is intentionally no download or print control. This deters casual
 * redistribution only: anything a browser can display can be captured.
 */

const root = document.getElementById('viewer');

if (root) {
    const type = root.dataset.type;
    const sources = JSON.parse(root.dataset.sources || '{}');

    const init = { document: initDocument, video: initVideo, audio: initAudio, image: initImage }[type];
    init?.(root, sources).catch((error) => showError(root, error));

    // Deterrents only (not security controls).
    root.addEventListener('contextmenu', (e) => e.preventDefault());
    root.addEventListener('dragstart', (e) => e.preventDefault());

    trackView(root, type);
}

/* ------------------------------------------------------------------ */
/* Views: like video platforms, opening the page is not a view. A view   */
/* is reported once the visitor has actually watched (audio/video play   */
/* time) or read (time with the page visible) for the qualifying time.   */
/* The server verifies the elapsed time and decides whether it counts.   */
/* ------------------------------------------------------------------ */

function trackView(root, type) {
    const endpoint = root.dataset.viewEndpoint;
    const token = root.dataset.viewToken;
    let required = Number(root.dataset.viewSeconds || 0);

    if (!endpoint || !token || !(required > 0)) return;

    let engaged = 0;
    let sent = false;

    const report = () => {
        if (sent || engaged < required) return;
        sent = true;

        fetch(endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ token }),
            credentials: 'same-origin',
            keepalive: true,
        })
            .then((response) => (response.ok ? response.json() : null))
            .then((data) => {
                const label = document.querySelector('[data-view-count]');
                if (data?.counted && label) {
                    label.textContent = `${Number(data.views).toLocaleString('en-US')} ${data.views === 1 ? 'view' : 'views'}`;
                }
            })
            .catch(() => {});
    };

    const player = root.querySelector('video, audio');

    if (player && (type === 'video' || type === 'audio')) {
        // Count real playback time only: no seeking, no time while paused.
        let last = null;
        const shorten = () => {
            if (Number.isFinite(player.duration) && player.duration > 0) {
                required = Math.min(required, Math.ceil(player.duration / 2));
            }
        };

        player.addEventListener('loadedmetadata', shorten);
        player.addEventListener('playing', () => (last = player.currentTime));
        player.addEventListener('seeking', () => (last = null));
        player.addEventListener('pause', () => (last = null));
        player.addEventListener('timeupdate', () => {
            if (player.paused || player.seeking) return;
            if (last !== null) {
                const delta = player.currentTime - last;
                if (delta > 0 && delta < 1.5) engaged += delta;
            }
            last = player.currentTime;
            shorten();
            report();
        });
        player.addEventListener('ended', report);
        return;
    }

    // Documents and images: time spent with the page visible.
    const timer = setInterval(() => {
        if (document.visibilityState !== 'visible') return;
        engaged += 1;
        report();
        if (sent) clearInterval(timer);
    }, 1000);
}

function showError(root, error) {
    console.error(error);
    const box = root.querySelector('[data-error]');
    if (box) {
        box.hidden = false;
    }
    root.querySelector('[data-loading]')?.setAttribute('hidden', '');
}

function hideLoading(root) {
    root.querySelector('[data-loading]')?.setAttribute('hidden', '');
}

/* ------------------------------------------------------------------ */
/* Documents: PDF.js viewer with paging, zoom, search and fullscreen.  */
/* ------------------------------------------------------------------ */

async function initDocument(root, sources) {
    if (sources.pages?.length && !sources.pdf) {
        return initPageImages(root, sources.pages);
    }

    const pdfjs = await import('pdfjs-dist');
    const workerUrl = (await import('pdfjs-dist/build/pdf.worker.min.mjs?url')).default;
    pdfjs.GlobalWorkerOptions.workerSrc = workerUrl;
    // pdf_viewer.mjs reads the core library from this global.
    globalThis.pdfjsLib = pdfjs;

    const { EventBus, PDFLinkService, PDFFindController, PDFViewer } = await import('pdfjs-dist/web/pdf_viewer.mjs');

    const container = root.querySelector('[data-pdf-container]');
    const eventBus = new EventBus();
    const linkService = new PDFLinkService({ eventBus });
    const findController = new PDFFindController({ eventBus, linkService });
    const viewer = new PDFViewer({
        container,
        eventBus,
        linkService,
        findController,
        removePageBorders: false,
        // Annotation forms and scripting are not needed for reading.
        annotationMode: pdfjs.AnnotationMode.ENABLE,
        enableScripting: false,
    });
    linkService.setViewer(viewer);

    const assets = root.dataset.pdfjsAssets;
    const pdf = await pdfjs.getDocument({
        url: sources.pdf,
        withCredentials: true,
        cMapUrl: `${assets}/cmaps/`,
        cMapPacked: true,
        standardFontDataUrl: `${assets}/standard_fonts/`,
        wasmUrl: `${assets}/wasm/`,
        iccUrl: `${assets}/iccs/`,
        isEvalSupported: false,
        enableXfa: false,
        disableAutoFetch: true,
    }).promise;

    viewer.setDocument(pdf);
    linkService.setDocument(pdf);

    const pageInput = root.querySelector('[data-page-input]');
    const pageCount = root.querySelector('[data-page-count]');
    const zoomLabel = root.querySelector('[data-zoom-label]');
    pageCount.textContent = pdf.numPages;
    pageInput.max = pdf.numPages;

    eventBus.on('pagesinit', () => {
        viewer.currentScaleValue = window.innerWidth < 768 ? 'page-width' : 'auto';
        hideLoading(root);
    });
    eventBus.on('pagechanging', ({ pageNumber }) => {
        pageInput.value = pageNumber;
    });
    eventBus.on('scalechanging', ({ scale }) => {
        zoomLabel.textContent = `${Math.round(scale * 100)}%`;
    });

    const action = (name, fn) => root.querySelectorAll(`[data-action="${name}"]`).forEach((el) => el.addEventListener('click', fn));

    action('prev', () => viewer.previousPage());
    action('next', () => viewer.nextPage());
    action('zoom-in', () => viewer.increaseScale());
    action('zoom-out', () => viewer.decreaseScale());
    action('fit', () => (viewer.currentScaleValue = 'page-width'));
    action('fullscreen', () => toggleFullscreen(root));

    pageInput.addEventListener('change', () => {
        const page = Math.min(Math.max(1, parseInt(pageInput.value, 10) || 1), pdf.numPages);
        viewer.currentPageNumber = page;
    });

    const searchForm = root.querySelector('[data-search-form]');
    const searchInput = root.querySelector('[data-search-input]');
    const searchStatus = root.querySelector('[data-search-status]');

    const find = (findPrevious = false, type = 'again') => {
        eventBus.dispatch('find', {
            source: searchForm,
            type,
            query: searchInput.value,
            caseSensitive: false,
            entireWord: false,
            highlightAll: true,
            findPrevious,
            matchDiacritics: false,
        });
    };

    searchForm.addEventListener('submit', (e) => {
        e.preventDefault();
        find(false, '');
    });
    searchInput.addEventListener('input', () => find(false, ''));
    action('search-next', () => find(false));
    action('search-prev', () => find(true));

    eventBus.on('updatefindmatchescount', ({ matchesCount }) => {
        searchStatus.textContent = matchesCount.total ? `${matchesCount.current} of ${matchesCount.total}` : searchInput.value ? 'No matches' : '';
    });
    eventBus.on('updatefindcontrolstate', ({ state, matchesCount }) => {
        if (state === 1 && !matchesCount?.total) searchStatus.textContent = searchInput.value ? 'No matches' : '';
    });

    document.addEventListener('keydown', (e) => {
        if (e.target.matches('input')) return;
        if (e.key === 'ArrowRight' || e.key === 'PageDown') viewer.nextPage();
        if (e.key === 'ArrowLeft' || e.key === 'PageUp') viewer.previousPage();
        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'f') {
            e.preventDefault();
            searchInput.focus();
        }
    });
}

function initPageImages(root, pages) {
    const container = root.querySelector('[data-pdf-container]');
    const list = document.createElement('div');
    list.className = 'page-images';

    for (const page of pages) {
        const figure = document.createElement('figure');
        const img = document.createElement('img');
        img.src = page.url;
        img.loading = 'lazy';
        img.width = page.width;
        img.height = page.height;
        img.alt = `Page ${page.page}`;
        img.draggable = false;
        figure.append(img);
        list.append(figure);
    }

    container.append(list);
    root.querySelector('[data-page-count]').textContent = pages.length;
    hideLoading(root);
    root.querySelectorAll('[data-pdf-only]').forEach((el) => el.setAttribute('hidden', ''));
    root.querySelectorAll('[data-action="fullscreen"]').forEach((el) => el.addEventListener('click', () => toggleFullscreen(root)));

    return Promise.resolve();
}

/* ------------------------------------------------------------------ */
/* Video: HLS via hls.js (or native HLS on Safari/iOS).                */
/* ------------------------------------------------------------------ */

async function initVideo(root, sources) {
    const video = root.querySelector('video');

    if (video.canPlayType('application/vnd.apple.mpegurl')) {
        video.src = sources.hls;
        hideLoading(root);
        return;
    }

    const { default: Hls } = await import('hls.js');

    if (!Hls.isSupported()) {
        throw new Error('HLS playback is not supported by this browser.');
    }

    const hls = new Hls({
        xhrSetup: (xhr) => {
            xhr.withCredentials = true;
        },
        enableWorker: true,
        capLevelToPlayerSize: true,
    });

    hls.on(Hls.Events.MANIFEST_PARSED, () => hideLoading(root));
    hls.on(Hls.Events.ERROR, (_event, data) => {
        if (!data.fatal) return;
        if (data.type === Hls.ErrorTypes.MEDIA_ERROR) {
            hls.recoverMediaError();
        } else {
            hls.destroy();
            showError(root, new Error(data.details));
        }
    });

    hls.loadSource(sources.hls);
    hls.attachMedia(video);
}

/* ------------------------------------------------------------------ */
/* Audio and images.                                                   */
/* ------------------------------------------------------------------ */

async function initAudio(root) {
    hideLoading(root);
}

async function initImage(root) {
    const img = root.querySelector('[data-image]');
    const stage = root.querySelector('[data-image-stage]');
    const toggle = root.querySelectorAll('[data-action="zoom-toggle"]');

    const done = () => hideLoading(root);
    img.complete ? done() : img.addEventListener('load', done, { once: true });
    img.addEventListener('error', () => showError(root, new Error('Image failed to load')), { once: true });

    const flip = () => {
        const zoomed = stage.classList.toggle('is-zoomed');
        toggle.forEach((b) => b.setAttribute('aria-pressed', zoomed ? 'true' : 'false'));
    };

    toggle.forEach((b) => b.addEventListener('click', flip));
    img.addEventListener('dblclick', flip);
    root.querySelectorAll('[data-action="fullscreen"]').forEach((el) => el.addEventListener('click', () => toggleFullscreen(root)));
}

function toggleFullscreen(element) {
    if (document.fullscreenElement) {
        document.exitFullscreen();
    } else {
        element.requestFullscreen?.();
    }
}

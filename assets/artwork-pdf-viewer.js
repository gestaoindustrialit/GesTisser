const viewer = document.querySelector('[data-artwork-viewer][data-pdf-url]');
const localPdfJsUrl = new URL('../node_modules/pdfjs-dist/legacy/build/pdf.min.mjs', import.meta.url).href;
const localWorkerUrl = new URL('../node_modules/pdfjs-dist/legacy/build/pdf.worker.min.mjs', import.meta.url).href;
const fallbackPdfJsUrl = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@4.10.38/legacy/build/pdf.mjs';
const fallbackWorkerUrl = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@4.10.38/legacy/build/pdf.worker.mjs';
let activeWorkerUrl = localWorkerUrl;

const reportStartupError = (error) => {
    console.error('[PDF Preview] startup error', {
        name: error && error.name,
        message: error && error.message,
        stack: error && error.stack,
        error
    });
    if (viewer) viewer.querySelector('[data-artwork-status]').textContent = 'Não foi possível iniciar o visualizador PDF.';
};

const loadPdfJs = async () => {
    try {
        return await import(localPdfJsUrl);
    } catch (localError) {
        console.warn('[PDF Preview] local PDF.js unavailable; loading fallback', localError);
        activeWorkerUrl = fallbackWorkerUrl;
        return import(fallbackPdfJsUrl);
    }
};

const loadPdfJsWithTimeout = () => Promise.race([
    loadPdfJs(),
    new Promise((resolve, reject) => window.setTimeout(() => reject(new Error('Tempo limite ao carregar PDF.js.')), 15000))
]);

if (viewer) {
loadPdfJsWithTimeout().then((pdfjsLib) => {
    const modal = document.getElementById('articleArtworkModal');
    const stage = viewer.querySelector('[data-artwork-stage]');
    const canvas = viewer.querySelector('[data-artwork-canvas]');
    const status = viewer.querySelector('[data-artwork-status]');
    const currentOutput = viewer.querySelector('[data-artwork-page-current]');
    const zoomOutput = viewer.querySelector('[data-artwork-zoom-value]');
    const previous = viewer.querySelector('[data-artwork-page-previous]');
    const next = viewer.querySelector('[data-artwork-page-next]');
    const zoomOut = viewer.querySelector('[data-artwork-zoom-out]');
    const zoomIn = viewer.querySelector('[data-artwork-zoom-in]');
    const zoomReset = viewer.querySelector('[data-artwork-zoom-reset]');
    const pdfUrl = new URL(viewer.dataset.pdfUrl, window.location.href).href;
    const maxCanvasDimension = 4096;
    const maxCanvasPixels = 16777216;
    let pdfDocument = null;
    let loadingTask = null;
    let renderTask = null;
    let sourceBytes = null;
    let pageNumber = 1;
    let pageCount = Math.max(1, Number(viewer.dataset.pageCount) || 1);
    let zoom = 1;
    let renderSequence = 0;
    let loadSequence = 0;
    let fetchController = null;
    let resizeTimer = null;

    pdfjsLib.GlobalWorkerOptions.workerSrc = activeWorkerUrl;

    const logError = (error) => {
        console.error('[PDF Preview] error', {
            name: error && error.name,
            message: error && error.message,
            stack: error && error.stack,
            error
        });
    };

    const cancelRender = async () => {
        if (!renderTask) return;
        const task = renderTask;
        renderTask = null;
        task.cancel();
        try { await task.promise; } catch (error) {
            if (!error || error.name !== 'RenderingCancelledException') logError(error);
        }
    };

    const updateControls = () => {
        currentOutput.value = `${pageNumber} / ${pageCount}`;
        currentOutput.textContent = `${pageNumber} / ${pageCount}`;
        zoomOutput.value = `${Math.round(zoom * 100)}%`;
        zoomOutput.textContent = `${Math.round(zoom * 100)}%`;
        previous.disabled = !pdfDocument || pageNumber <= 1;
        next.disabled = !pdfDocument || pageNumber >= pageCount;
    };

    const loadWithWorkerFallback = async (bytes) => {
        try {
            loadingTask = pdfjsLib.getDocument({ data: bytes.slice(), disableWorker: false });
            return await loadingTask.promise;
        } catch (workerError) {
            console.warn('[PDF Preview] worker failed; retrying with disableWorker: true', workerError);
            if (loadingTask) await loadingTask.destroy().catch(() => {});
            loadingTask = pdfjsLib.getDocument({ data: bytes.slice(), disableWorker: true });
            return await loadingTask.promise;
        }
    };

    const renderPage = async () => {
        if (!pdfDocument) return;
        const sequence = ++renderSequence;
        await cancelRender();
        console.log('[PDF Preview] rendering page', pageNumber);
        status.textContent = `A renderizar página ${pageNumber}…`;
        const page = await pdfDocument.getPage(pageNumber);
        if (sequence !== renderSequence) { page.cleanup(); return; }

        const baseViewport = page.getViewport({ scale: 1 });
        const availableWidth = Math.max(1, stage.clientWidth);
        const cssScale = (availableWidth / baseViewport.width) * zoom;
        const dpr = Math.min(window.devicePixelRatio || 1, 2);
        let renderScale = cssScale * dpr;
        let viewport = page.getViewport({ scale: renderScale });
        const dimensionScale = Math.min(1, maxCanvasDimension / viewport.width, maxCanvasDimension / viewport.height);
        const pixelScale = Math.min(1, Math.sqrt(maxCanvasPixels / Math.max(1, viewport.width * viewport.height)));
        renderScale *= Math.min(dimensionScale, pixelScale);
        viewport = page.getViewport({ scale: renderScale });

        canvas.width = Math.max(1, Math.floor(viewport.width));
        canvas.height = Math.max(1, Math.floor(viewport.height));
        canvas.style.width = `${Math.max(1, Math.floor(baseViewport.width * cssScale))}px`;
        canvas.style.height = `${Math.max(1, Math.floor(baseViewport.height * cssScale))}px`;
        canvas.setAttribute('aria-label', `Página ${pageNumber} de ${pageCount}`);
        console.log('[PDF Preview] canvas WxH', `${canvas.width}x${canvas.height}`);

        renderTask = page.render({ canvasContext: canvas.getContext('2d', { alpha: false }), viewport });
        try {
            await renderTask.promise;
            if (sequence !== renderSequence) return;
            console.log('[PDF Preview] render complete', pageNumber);
            status.textContent = `Página ${pageNumber} de ${pageCount}`;
        } finally {
            if (sequence === renderSequence) renderTask = null;
            page.cleanup();
        }
        updateControls();
    };

    const loadPdf = async () => {
        if (pdfDocument || loadingTask) return;
        const sequence = ++loadSequence;
        try {
            console.log('[PDF Preview] URL', pdfUrl);
            status.textContent = 'A carregar PDF…';
            fetchController = new AbortController();
            const response = await fetch(pdfUrl, { credentials: 'same-origin', signal: fetchController.signal });
            console.log('[PDF Preview] fetch status', response.status, response.statusText);
            if (!response.ok) throw new Error(`HTTP ${response.status} ${response.statusText}`);
            sourceBytes = new Uint8Array(await response.arrayBuffer());
            console.log('[PDF Preview] bytes', sourceBytes.byteLength);
            pdfDocument = await loadWithWorkerFallback(sourceBytes);
            if (sequence !== loadSequence) { await pdfDocument.destroy(); return; }
            console.log('[PDF Preview] PDF loaded');
            pageCount = pdfDocument.numPages;
            console.log('[PDF Preview] pages', pageCount);
            pageNumber = Math.min(pageNumber, pageCount);
            updateControls();
            await renderPage();
        } catch (error) {
            if (error && error.name === 'AbortError') return;
            logError(error);
            status.textContent = 'Não foi possível apresentar a maquete PDF.';
        } finally {
            fetchController = null;
        }
    };

    const destroyPdf = async () => {
        loadSequence += 1;
        renderSequence += 1;
        if (fetchController) fetchController.abort();
        fetchController = null;
        await cancelRender();
        if (loadingTask) await loadingTask.destroy().catch(logError);
        else if (pdfDocument) await pdfDocument.destroy().catch(logError);
        loadingTask = null;
        pdfDocument = null;
        sourceBytes = null;
        pageNumber = 1;
        zoom = 1;
        canvas.width = 1;
        canvas.height = 1;
        canvas.removeAttribute('style');
        updateControls();
    };

    previous.addEventListener('click', async () => { if (pageNumber > 1) { pageNumber -= 1; updateControls(); await renderPage().catch(logError); } });
    next.addEventListener('click', async () => { if (pageNumber < pageCount) { pageNumber += 1; updateControls(); await renderPage().catch(logError); } });
    zoomOut.addEventListener('click', async () => { zoom = Math.max(.5, Math.round((zoom - .25) * 4) / 4); updateControls(); await renderPage().catch(logError); });
    zoomIn.addEventListener('click', async () => { zoom = Math.min(4, Math.round((zoom + .25) * 4) / 4); updateControls(); await renderPage().catch(logError); });
    zoomReset.addEventListener('click', async () => { zoom = 1; stage.scrollTo({ top: 0, left: 0 }); updateControls(); await renderPage().catch(logError); });
    modal.addEventListener('shown.bs.modal', () => { loadPdf(); });
    modal.addEventListener('hidden.bs.modal', () => { destroyPdf(); });
    new ResizeObserver(() => {
        if (!pdfDocument) return;
        window.clearTimeout(resizeTimer);
        resizeTimer = window.setTimeout(() => { renderPage().catch(logError); }, 120);
    }).observe(stage);
    updateControls();
}).catch(reportStartupError);
}

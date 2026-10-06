/* PDF.js renders PDFs in-app because Chrome Android does not consistently
 * provide a native PDF viewer. The server thumbnail remains visible if the
 * library, network, fetch, or PDF parsing fails. */
const pdfJsUrl = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@5.4.624/legacy/build/pdf.min.mjs';
const pdfJsWorkerUrl = 'https://cdn.jsdelivr.net/npm/pdfjs-dist@5.4.624/legacy/build/pdf.worker.min.mjs';

const viewer = document.querySelector('[data-artwork-viewer][data-pdf-url]');

if (viewer) {
    const image = viewer.querySelector('[data-artwork-image]');
    const pages = viewer.querySelector('[data-artwork-pages]');
    const status = viewer.querySelector('[data-artwork-status]');

    const showFallback = (message) => {
        pages.hidden = true;
        image.hidden = false;
        status.textContent = message;
    };

    const renderDocument = async () => {
        const pdfjsLib = await import(pdfJsUrl);
        pdfjsLib.GlobalWorkerOptions.workerSrc = pdfJsWorkerUrl;
        const response = await fetch(viewer.dataset.pdfUrl, { credentials: 'same-origin' });
        if (!response.ok) throw new Error(`HTTP ${response.status}`);

        const data = await response.arrayBuffer();
        const documentTask = pdfjsLib.getDocument({ data });
        const pdf = await documentTask.promise;
        const fragment = document.createDocumentFragment();

        for (let pageNumber = 1; pageNumber <= pdf.numPages; pageNumber += 1) {
            status.textContent = `A preparar página ${pageNumber} de ${pdf.numPages}…`;
            const page = await pdf.getPage(pageNumber);
            const naturalViewport = page.getViewport({ scale: 1 });
            const availableWidth = Math.max(320, viewer.clientWidth - 32);
            const cssScale = availableWidth / naturalViewport.width;
            const outputScale = Math.min(window.devicePixelRatio || 1, 2);
            const viewport = page.getViewport({ scale: cssScale * outputScale });
            const canvas = document.createElement('canvas');
            canvas.className = 'shopfloor-artwork-page';
            canvas.width = Math.floor(viewport.width);
            canvas.height = Math.floor(viewport.height);
            canvas.setAttribute('aria-label', `Página ${pageNumber} de ${pdf.numPages}`);
            await page.render({ canvasContext: canvas.getContext('2d'), viewport }).promise;
            fragment.appendChild(canvas);
        }

        pages.replaceChildren(fragment);
        pages.hidden = false;
        image.hidden = true;
        status.textContent = `${pdf.numPages} página${pdf.numPages === 1 ? '' : 's'} · documento apresentado com PDF.js`;
    };

    renderDocument().catch(() => {
        showFallback('Não foi possível carregar o PDF completo. A apresentar a primeira página.');
    });
}

/* Tablet-safe artwork navigation. Each PDF page is rendered on the server,
 * avoiding native Android PDF support, external CDNs and session/path issues. */
const viewer = document.querySelector('[data-artwork-viewer][data-page-url]');

if (viewer) {
    const image = viewer.querySelector('[data-artwork-image]');
    const status = viewer.querySelector('[data-artwork-status]');
    const current = viewer.querySelector('[data-artwork-page-current]');
    const previous = viewer.querySelector('[data-artwork-page-previous]');
    const next = viewer.querySelector('[data-artwork-page-next]');
    const pageCount = Math.max(1, Number(viewer.dataset.pageCount) || 1);
    let page = 1;

    const refresh = () => {
        current.textContent = `${page} / ${pageCount}`;
        previous.disabled = page <= 1;
        next.disabled = page >= pageCount;
        status.textContent = `A carregar página ${page}…`;
        const url = new URL(viewer.dataset.pageUrl, window.location.href);
        url.searchParams.set('page', String(page));
        image.src = url.toString();
    };

    image.addEventListener('load', () => {
        status.textContent = `Página ${page} de ${pageCount}`;
    });
    image.addEventListener('error', () => {
        status.textContent = 'Não foi possível apresentar esta página da maquete.';
    });
    previous.addEventListener('click', () => { if (page > 1) { page -= 1; refresh(); } });
    next.addEventListener('click', () => { if (page < pageCount) { page += 1; refresh(); } });
    refresh();
}

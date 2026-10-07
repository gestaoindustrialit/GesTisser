(function () {
    'use strict';

    var viewer = document.querySelector('[data-artwork-viewer][data-page-url]');
    if (!viewer) return;

    var modal = document.getElementById('articleArtworkModal');
    var stage = viewer.querySelector('[data-artwork-stage]');
    var image = viewer.querySelector('[data-artwork-image]');
    var status = viewer.querySelector('[data-artwork-status]');
    var currentOutput = viewer.querySelector('[data-artwork-page-current]');
    var zoomOutput = viewer.querySelector('[data-artwork-zoom-value]');
    var previous = viewer.querySelector('[data-artwork-page-previous]');
    var next = viewer.querySelector('[data-artwork-page-next]');
    var zoomOut = viewer.querySelector('[data-artwork-zoom-out]');
    var zoomIn = viewer.querySelector('[data-artwork-zoom-in]');
    var zoomReset = viewer.querySelector('[data-artwork-zoom-reset]');
    var pageUrl = viewer.getAttribute('data-page-url');
    var pageCount = Math.max(1, parseInt(viewer.getAttribute('data-page-count'), 10) || 1);
    var pageNumber = 1;
    var zoom = 1;
    var requestedPage = 1;

    function urlForPage(page) {
        var separator = pageUrl.indexOf('?') === -1 ? '?' : '&';
        return pageUrl + separator + 'page=' + encodeURIComponent(page);
    }

    function updateControls() {
        var pageLabel = pageNumber + ' / ' + pageCount;
        var zoomLabel = Math.round(zoom * 100) + '%';
        currentOutput.value = pageLabel;
        currentOutput.textContent = pageLabel;
        zoomOutput.value = zoomLabel;
        zoomOutput.textContent = zoomLabel;
        previous.disabled = pageNumber <= 1;
        next.disabled = pageNumber >= pageCount;
        image.style.width = zoomLabel;
    }

    function showPage(page) {
        if (page < 1 || page > pageCount) return;
        pageNumber = page;
        requestedPage = page;
        status.textContent = 'A carregar página ' + page + '…';
        image.setAttribute('aria-label', 'Página ' + page + ' de ' + pageCount);
        image.src = urlForPage(page);
        updateControls();
    }

    function changeZoom(amount) {
        zoom = Math.max(0.5, Math.min(4, Math.round((zoom + amount) * 4) / 4));
        updateControls();
    }

    image.addEventListener('load', function () {
        status.textContent = 'Página ' + requestedPage + ' de ' + pageCount;
    });
    image.addEventListener('error', function () {
        status.textContent = 'Não foi possível gerar esta página. Use “Abrir PDF original”.';
    });
    previous.addEventListener('click', function () { showPage(pageNumber - 1); });
    next.addEventListener('click', function () { showPage(pageNumber + 1); });
    zoomOut.addEventListener('click', function () { changeZoom(-0.25); });
    zoomIn.addEventListener('click', function () { changeZoom(0.25); });
    zoomReset.addEventListener('click', function () {
        zoom = 1;
        stage.scrollTop = 0;
        stage.scrollLeft = 0;
        updateControls();
    });

    if (modal) {
        modal.addEventListener('shown.bs.modal', function () {
            if (!image.getAttribute('src')) showPage(pageNumber);
        });
    }
    updateControls();
    if (image.complete && image.naturalWidth) {
        status.textContent = 'Página 1 de ' + pageCount;
    }
}());

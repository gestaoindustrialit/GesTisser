(function () {
    'use strict';
    // Delegation survives the existing table's client-side sorting and pagination.
    document.addEventListener('click', function (event) {
        var row = event.target.closest('[data-customer-url]');
        if (!row || event.target.closest('a,button,input,select,textarea,label') || window.getSelection().toString()) return;
        var url = row.getAttribute('data-customer-url');
        if (event.ctrlKey || event.metaKey) window.open(url, '_blank', 'noopener');
        else window.location.assign(url);
    });
    document.addEventListener('keydown', function (event) {
        if (!event.target.matches('[data-customer-url]') || (event.key !== 'Enter' && event.key !== ' ')) return;
        event.preventDefault();
        window.location.assign(event.target.getAttribute('data-customer-url'));
    });
    document.addEventListener('DOMContentLoaded', function () {
        var toggle = document.querySelector('[data-customer-edit-toggle]');
        if (!toggle) return;
        var form = document.querySelector('[data-customer-form]');
        var deliveries = form && form.querySelector('[data-cp-deliveries]');
        var initialDeliveries = deliveries ? deliveries.innerHTML : '';
        function updateEmptyState() {
            var empty = form.querySelector('[data-cp-empty-deliveries]');
            if (empty) empty.hidden = deliveries.children.length > 0;
        }
        function setEditing(enabled) {
            var url = new URL(window.location.href);
            url.searchParams.delete('saved');
            if (enabled) url.searchParams.set('edit', '1');
            else url.searchParams.delete('edit');
            if (!enabled && form.getAttribute('data-reload-on-cancel') === '1') {
                window.location.assign(url.pathname + url.search);
                return;
            }
            history.replaceState(null, '', url.pathname + url.search + url.hash);
            form.classList.toggle('is-readonly', !enabled);
            form.querySelector('[data-customer-fields]').disabled = !enabled;
            toggle.checked = enabled;
            if (!enabled) {
                form.reset();
                deliveries.innerHTML = initialDeliveries;
                updateEmptyState();
            }
        }
        toggle.addEventListener('change', function () {
            if (!form) {
                if (toggle.checked) window.location.assign(toggle.getAttribute('data-edit-url'));
                return;
            }
            setEditing(toggle.checked);
            if (toggle.checked) form.querySelector('[name="name"]').focus();
        });
        if (!form) return;
        form.addEventListener('click', function (event) {
            if (event.target.closest('[data-cp-cancel-edit]')) { setEditing(false); return; }
            if (!toggle.checked) return;
            if (event.target.closest('[data-cp-add-delivery]')) {
                deliveries.appendChild(form.querySelector('[data-cp-delivery-template]').content.cloneNode(true));
                updateEmptyState();
            }
            var remove = event.target.closest('[data-cp-remove-delivery]');
            if (remove) { remove.closest('[data-cp-delivery-row]').remove(); updateEmptyState(); }
        });
    });
})();

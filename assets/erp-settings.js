(function () {
    'use strict';
    var root = document.querySelector('.erp-settings-page');
    if (!root) return;
    function reveal(element) {
        for (var node = element; node && node !== root; node = node.parentElement) {
            if (node.tagName === 'DETAILS') node.open = true;
        }
    }
    function followHash() {
        if (!location.hash) return;
        var id;
        try { id = decodeURIComponent(location.hash.slice(1)); } catch (_) { return; }
        var target = document.getElementById(id);
        if (target && root.contains(target)) {
            reveal(target);
            target.scrollIntoView({block: 'start'});
        }
    }
    root.querySelectorAll('[data-settings-expand]').forEach(function (button) {
        button.addEventListener('click', function () {
            var expand = button.dataset.settingsExpand === '1';
            root.querySelectorAll('details.erp-settings-block, details.printing-section').forEach(function (block) { block.open = expand; });
        });
    });
    root.addEventListener('invalid', function (event) { reveal(event.target); }, true);
    root.querySelectorAll('.erp-settings-nav a').forEach(function (link) {
        link.addEventListener('click', function () { if (link.hash === location.hash) followHash(); });
    });
    window.addEventListener('hashchange', followHash);
    followHash();
}());

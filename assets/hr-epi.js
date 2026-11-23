(function () {
    const lines = document.getElementById('epi-lines');
    const template = document.getElementById('epi-line-template');
    const add = document.getElementById('epi-add-line');
    let index = 0;

    function updateButtons() {
        lines.querySelectorAll('[data-remove-line]').forEach(function (button) {
            button.disabled = lines.children.length === 1;
        });
        add.disabled = lines.children.length >= 50;
    }

    function appendLine(focus) {
        const line = template.content.firstElementChild.cloneNode(true);
        line.querySelectorAll('[data-field]').forEach(function (input) {
            input.name = 'items[' + index + '][' + input.dataset.field + ']';
        });
        line.querySelector('[data-remove-line]').addEventListener('click', function () {
            line.remove();
            updateButtons();
            add.focus();
        });
        lines.appendChild(line);
        index++;
        updateButtons();
        if (focus) {
            line.querySelector('select').focus();
        }
    }

    add.addEventListener('click', function () {
        appendLine(true);
    });
    appendLine(false);
})();

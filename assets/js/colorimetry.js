(function () {
    'use strict';
    function cloneRow(button, selector, group) {
        var box = button.parentNode, rows = box.querySelectorAll(selector), clone = rows[rows.length - 1].cloneNode(true), index = 0;
        rows.forEach(function (row) { var field = row.querySelector('[name]'), match = field && field.name.match(/\]\[(\d+)\]/); if (match) index = Math.max(index, Number(match[1]) + 1); });
        clone.querySelectorAll('input,select').forEach(function (field) {
            field.name = field.name.replace(new RegExp('data\\[' + group + '\\]\\[\\d+\\]'), 'data[' + group + '][' + index + ']');
            if (field.type !== 'hidden') field.value = /\[unit\]$/.test(field.name) ? 'g' : '';
        });
        var remove = document.createElement('button'); remove.type = 'button'; remove.className = 'btn btn-outline-danger mb-3'; remove.textContent = 'Remover';
        remove.onclick = function () { clone.remove(); }; clone.appendChild(remove); box.insertBefore(clone, button);
    }
    document.querySelectorAll('[data-add-component]').forEach(function (b) { b.onclick = function () { cloneRow(b, '[data-component-row]', 'components'); }; });
    document.querySelectorAll('[data-add-container]').forEach(function (b) { b.onclick = function () { cloneRow(b, '[data-container-row]', 'containers'); }; });
    var target = document.querySelector('input[name="data[quantity]"]');
    var targetUnit = document.querySelector('select[name="data[unit]"]');
    function calculate() {
        var targetMg = Math.round(Number(target.value.replace(',', '.')) * (targetUnit && targetUnit.value === 'kg' ? 1000000 : 1000)), used = 0, cumulative = 0;
        document.querySelectorAll('[data-calculate-mg]').forEach(function (cell) {
            cumulative += Number(cell.dataset.calculateMg);
            var next = Math.round(targetMg * (cumulative / Number(cell.dataset.baseMg)));
            cell.textContent = targetMg > 0 ? ((next - used) / 1000).toLocaleString('pt-PT', {minimumFractionDigits: 3, maximumFractionDigits: 3}) : '—'; used = next;
        });
    }
    if (target) target.addEventListener('input', calculate);
    if (targetUnit) targetUnit.addEventListener('change', calculate);
}());

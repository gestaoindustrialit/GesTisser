(function (root, factory) {
    var api = factory();
    if (typeof module === 'object' && module.exports) module.exports = api;
    if (root && root.document) api.bind(root.document);
}(typeof globalThis !== 'undefined' ? globalThis : this, function () {
    'use strict';

    var money = new Intl.NumberFormat('pt-PT', {
        style: 'currency',
        currency: 'EUR',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });

    function numberValue(input) {
        if (!input) return 0;
        var value = Number(String(input.value || '').replace(',', '.'));
        return Number.isFinite(value) && value > 0 ? value : 0;
    }

    function calculate(values) {
        var total = values.costs.reduce(function (sum, cost) { return sum + cost; }, 0);
        var revenue = values.quantity * values.salePrice;
        return {
            total: total,
            unitCost: values.quantity > 0 ? total / values.quantity : 0,
            margin: revenue - total,
            thousandCost: values.quantity > 0 ? total / values.quantity * 1000 : 0
        };
    }

    function setMoney(document, selector, value) {
        var output = document.querySelector(selector);
        if (output) output.textContent = money.format(value);
    }

    function refresh(document, form) {
        var costInputs = Array.prototype.slice.call(form.querySelectorAll('input[name^="report[cost_"]'));
        var result = calculate({
            costs: costInputs.map(numberValue),
            quantity: numberValue(form.querySelector('[name="report[produced_quantity]"]')),
            salePrice: numberValue(form.querySelector('[name="report[sale_unit_price]"]'))
        });

        setMoney(document, '[data-report-total]', result.total);
        setMoney(document, '[data-report-unit-cost]', result.unitCost);
        setMoney(document, '[data-report-margin]', result.margin);
        setMoney(document, '[data-cost-summary="current-cost"]', result.total);
        setMoney(document, '[data-cost-summary="thousand-cost"]', result.thousandCost);
        return result;
    }

    function bind(document) {
        var form = document.querySelector('[data-cost-report]');
        if (!form) return;
        form.addEventListener('input', function (event) {
            if (event.target && event.target.matches('input[name^="report[cost_"], input[name="report[produced_quantity]"], input[name="report[sale_unit_price]"]')) {
                refresh(document, form);
            }
        });
    }

    return { calculate: calculate, numberValue: numberValue, refresh: refresh, bind: bind };
}));

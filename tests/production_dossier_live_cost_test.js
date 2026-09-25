'use strict';

const assert = require('assert');
const costs = require('../assets/production-dossier.js');

const result = costs.calculate({ costs: [12.5, 7.5, 30], quantity: 10, salePrice: 8 });
assert.strictEqual(result.total, 50);
assert.strictEqual(result.unitCost, 5);
assert.strictEqual(result.margin, 30);
assert.strictEqual(result.thousandCost, 5000);

const withoutProduction = costs.calculate({ costs: [20], quantity: 0, salePrice: 8 });
assert.strictEqual(withoutProduction.unitCost, 0);
assert.strictEqual(withoutProduction.thousandCost, 0);
assert.strictEqual(withoutProduction.margin, -20);
assert.strictEqual(costs.numberValue({ value: '-2' }), 0);
assert.strictEqual(costs.numberValue({ value: '2,5' }), 2.5);

function automaticCostForm() {
    const inputs = {
        energia: { value: '' },
        embalagem: { value: '' }
    };
    return {
        dataset: { energyUnitCost: '0.005', palletUnitCost: '7.5' },
        querySelector(selector) {
            if (selector.includes('cost_energia')) return inputs.energia;
            if (selector.includes('cost_embalagem')) return inputs.embalagem;
            return null;
        },
        inputs
    };
}

const form = automaticCostForm();
costs.applyAutomaticCosts(form, { name: 'report[produced_quantity]', value: '100' });
costs.applyAutomaticCosts(form, { name: 'report[pallet_count]', value: '2' });
assert.strictEqual(form.inputs.energia.value, '0.50');
assert.strictEqual(form.inputs.embalagem.value, '15.00');

console.log('production_dossier_live_cost_test: OK');

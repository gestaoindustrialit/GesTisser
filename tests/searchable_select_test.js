'use strict';

const assert = require('node:assert/strict');
const searchableSelect = require('../assets/searchable-select.js');

assert.equal(searchableSelect.normalize('TINTA DOURÁDO — PANTONE 871'), 'tinta dourado — pantone 871');
assert.equal(searchableSelect.normalize('São João').includes(searchableSelect.normalize('sao')), true);

function select(name, optionCount, classes = [], setting) {
    return {
        name,
        id: '',
        options: {length: optionCount},
        dataset: {searchableSelect: setting},
        classList: {contains: (className) => classes.includes(className)}
    };
}

assert.equal(searchableSelect.shouldEnhance(select('raw_material_id', 50)), true);
assert.equal(searchableSelect.shouldEnhance(select('customer_id', 2)), false);
assert.equal(searchableSelect.shouldEnhance(select('status', 50)), false);
assert.equal(searchableSelect.shouldEnhance(select('anything', 2, ['js-searchable-select'])), true);
assert.equal(searchableSelect.shouldEnhance(select('machine_id', 50, [], 'off')), false);

console.log('Searchable select normalization and selection policy: OK');

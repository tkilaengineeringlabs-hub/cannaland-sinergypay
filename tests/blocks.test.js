'use strict';
// Real registration scripts executed with Woo/React doubles. No browser or provider integration.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
const root = path.resolve(__dirname, '..');
const fixtures = [
    ['cannaland_cards', 'modules/cards/blocks.js', 'Tarjeta'],
    ['cannaland_spei', 'assets/js/blocks.js', 'Transferencia SPEI'],
    ['cannaland_cash', 'modules/cash/blocks.js', 'Pago en efectivo']
];
let checks = 0;
for (const active of [[], ['cannaland_spei'], ['cannaland_cards'], fixtures.map(f => f[0])]) {
    const registered = new Map();
    const window = {
        wc: {
            wcSettings: { getSetting: (key) => {
                const id = key.replace(/_data$/, '');
                return { title: 'Custom ' + id, description: 'Description ' + id, available: active.includes(id), supports: ['products'] };
            } },
            wcBlocksRegistry: { registerPaymentMethod: (method) => {
                assert(!registered.has(method.name), 'Duplicate registration');
                registered.set(method.name, method);
            } }
        },
        wp: { htmlEntities: { decodeEntities: text => text }, element: { createElement: (type, props, ...children) => ({ type, props, children }) } }
    };
    const context = vm.createContext({ window });
    for (const [, script] of fixtures) vm.runInContext(fs.readFileSync(path.join(root, script), 'utf8'), context);
    assert.equal(registered.size, 3); checks++;
    for (const [id] of fixtures) {
        const method = registered.get(id);
        assert.equal(method.label, 'Custom ' + id); checks++;
        assert.equal(method.canMakePayment(), active.includes(id)); checks++;
        const content = method.content.type();
        assert.equal(content.type, 'p'); checks++;
        assert.equal(content.children[0], 'Description ' + id); checks++;
        assert(!['select', 'input', 'iframe'].includes(content.type)); checks++;
    }
}
const defaults = new Map();
const fallbackContext = vm.createContext({ window: {
    wc: { wcSettings: { getSetting: () => ({}) }, wcBlocksRegistry: { registerPaymentMethod: method => defaults.set(method.name, method) } },
    wp: { htmlEntities: { decodeEntities: text => text }, element: { createElement: (type, props, ...children) => ({ type, props, children }) } }
} });
for (const [, script] of fixtures) vm.runInContext(fs.readFileSync(path.join(root, script), 'utf8'), fallbackContext);
for (const [id, , label] of fixtures) {
    assert.equal(defaults.get(id).label, label); checks++;
    assert.equal(defaults.get(id).canMakePayment(), false); checks++;
    assert(!/SinergyPay|Dapp/i.test(defaults.get(id).label)); checks++;
}
console.log(`PASS: ${checks} JS assertions; three independent Blocks registrations, own settings and readiness (synthetic).`);

'use strict';
// Execute the shipped script with DOM/Clipboard doubles; no provider or payment operations.
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const root = process.env.CSP_PLUGIN_ROOT || path.resolve(__dirname, '..');
const script = fs.readFileSync(path.join(root, 'assets/js/spei-instructions.js'), 'utf8');
let checks = 0;
function check(name, value) { assert(value, name); checks++; }
function environment(mode = 'clipboard') {
    const writes = [], fallbackWrites = [], timers = [], roots = [], buttons = [];
    let listener, removed = 0, restored = 0, fallbackResult = true;
    const document = {
        activeElement: { focus() { restored++; } },
        createElement(tag) {
            assert.equal(tag, 'textarea');
            return { style: {}, focus() {}, select() {}, setSelectionRange() {}, remove() { removed++; } };
        },
        body: { appendChild(input) { fallbackWrites.push(input); } },
        execCommand(command) { assert.equal(command, 'copy'); if (fallbackResult === 'throw') throw new Error('denied'); return fallbackResult; },
        querySelectorAll() { return buttons; },
        addEventListener(type, callback) { assert.equal(type, 'click'); listener = callback; }
    };
    function component(prefix) {
        const values = { beneficiary: 'Cannaland', clabe: prefix === 'one' ? '012345678901234567' : '646180511460387343', reference: 'csp-'+prefix+'-12345678-1234-4234-8234-123456789abc', amount: '$8,700.00 MXN', expiration: '01/11/2026, 01:10' };
        const labels = { beneficiary: 'Beneficiario', clabe: 'CLABE', reference: 'Referencia', amount: 'Importe', expiration: 'Válida hasta' };
        const status = { textContent: '' };
        const item = {
            values, labels, status, buttons: {},
            querySelector(selector) {
                if (selector === '[role="status"]') return status;
                const m = selector.match(/^\[data-spei-(value|label)="([a-z]+)"\]$/);
                return m && (m[1] === 'value' ? values[m[2]] : labels[m[2]]) ? { textContent: m[1] === 'value' ? values[m[2]] : labels[m[2]] } : null;
            }
        };
        for (const key of ['beneficiary', 'clabe', 'reference', 'amount', 'all']) {
            const button = { hidden: true, disabled: false, textContent: key === 'all' ? 'Copiar datos' : 'Copiar',
                dataset: { speiCopy: key, copyLabel: key === 'all' ? 'Copiar datos' : 'Copiar', copiedLabel: 'Copiado ✓', copyError: 'Selecciona el valor y cópialo manualmente.' },
                closest(selector) { return selector === '[data-cannaland-spei]' ? item : this; }
            };
            item.buttons[key] = button; buttons.push(button);
        }
        roots.push(item); return item;
    }
    const one = component('one'), two = component('two');
    const navigator = mode === 'unavailable' ? {} : { clipboard: { async writeText(text) { if (mode === 'denied') throw new Error('denied'); writes.push(text); } } };
    vm.runInNewContext(script, { document, navigator, window: { clearTimeout() {}, setTimeout(callback) { timers.push(callback); return timers.length; } } });
    return { one, two, writes, fallbackWrites, timers, buttons,
        click(button) { return listener({ target: { closest() { return button; } } }); },
        setResult(value) { fallbackResult = value; }, removed: () => removed, restored: () => restored
    };
}
(async function () {
    const env = environment();
    check('progressive enhancement reveals all controls',env.buttons.every(b=>b.hidden===false));
    for (const key of ['beneficiary', 'clabe', 'reference', 'amount']) {
        const button = env.one.buttons[key];
        await env.click(button);
        check('individual '+key+' copies exact visible value',env.writes.at(-1)===env.one.values[key]);
        check('individual '+key+' success feedback accessible',button.textContent==='Copiado ✓' && env.one.status.textContent==='Copiado ✓' && !button.disabled);
        env.timers.at(-1)();
        check('individual '+key+' label restored',button.textContent==='Copiar' && env.one.status.textContent==='');
    }
    await env.click(env.one.buttons.all);
    const expected='Beneficiario: Cannaland\nCLABE: 012345678901234567\nReferencia: '+env.one.values.reference+'\nImporte: $8,700.00 MXN\nVálida hasta: 01/11/2026, 01:10';
    check('copy all contains actual five values in order',env.writes.at(-1)===expected);
    check('copy-all success feedback',env.one.buttons.all.textContent==='Copiado ✓');
    env.timers.at(-1)(); check('copy-all original label restored',env.one.buttons.all.textContent==='Copiar datos');
    await env.click(env.two.buttons.reference);
    check('second order copies full own reference',env.writes.at(-1)===env.two.values.reference && !env.writes.at(-1).includes('csp-one'));
    await env.click(env.two.buttons.all);
    check('copy-all isolated to current component',env.writes.at(-1).includes(env.two.values.clabe) && !env.writes.at(-1).includes(env.one.values.clabe));
    const before=env.writes.length;env.one.buttons.clabe.disabled=true;await env.click(env.one.buttons.clabe);
    check('in-flight disabled control cannot duplicate action',env.writes.length===before);env.one.buttons.clabe.disabled=false;
    env.one.buttons.clabe.dataset.speiCopy='api_key';await env.click(env.one.buttons.clabe);
    check('unknown clipboard keys cannot copy secrets',env.writes.length===before);
    env.one.buttons.clabe.dataset.speiCopy='clabe';env.one.values.clabe='';await env.click(env.one.buttons.clabe);
    check('empty value not copied or marked successful',env.writes.length===before && env.one.buttons.clabe.textContent==='Copiar');
    const detached={disabled:false,dataset:{speiCopy:'clabe'},closest(){return null;}};await env.click(detached);
    check('buttons outside component ignored',env.writes.length===before);
    for (const mode of ['unavailable','denied']) {
        const fallback=environment(mode);await fallback.click(fallback.one.buttons.reference);
        check(mode+' clipboard falls back to full reference',fallback.fallbackWrites[0].value===fallback.one.values.reference);
        check(mode+' fallback input read-only and removed',fallback.fallbackWrites[0].readOnly===true && fallback.removed()===1);
        check(mode+' fallback restores focus',fallback.restored()===1);
        check(mode+' fallback reports success',fallback.one.buttons.reference.textContent==='Copiado ✓');
        fallback.setResult(false);await fallback.click(fallback.one.buttons.clabe);
        check(mode+' copy failure reports manual alternative',fallback.one.buttons.clabe.textContent==='Copiar' && fallback.one.status.textContent===fallback.one.buttons.clabe.dataset.copyError && !fallback.one.buttons.clabe.disabled);
        fallback.setResult('throw');await fallback.click(fallback.one.buttons.amount);
        check(mode+' fallback exception cleaned up safely',fallback.removed()===3 && fallback.restored()===3 && fallback.one.buttons.amount.textContent==='Copiar');
    }
    const css=fs.readFileSync(path.join(root,'assets/css/spei-instructions.css'),'utf8');
    check('responsive long-value wrapping and mobile breakpoint',css.includes('overflow-wrap: anywhere') && css.includes('minmax(0, 1fr)') && css.includes('@media (max-width: 600px)'));
    check('touch targets and keyboard focus styles',css.includes('min-height: 44px') && css.includes(':focus-visible'));
    check('no external dependency or financial request',!/(fetch\(|XMLHttpRequest|jQuery|https?:\/\/)/.test(script));
    console.log('PASS: '+checks+' SPEI clipboard/CSS JS assertions (DOM doubles).');
}()).catch(error=>{ console.error(error); process.exitCode=1; });

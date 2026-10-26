/* Clipboard controls for visible SPEI instructions only. No payment or provider operations. */
(function () {
    'use strict';
    const keys = ['beneficiary', 'clabe', 'reference', 'amount', 'expiration'];
    function legacyCopy(text) {
        const previous = document.activeElement;
        const input = document.createElement('textarea');
        input.value = text;
        input.readOnly = true;
        input.style.position = 'fixed';
        input.style.opacity = '0';
        input.style.pointerEvents = 'none';
        document.body.appendChild(input);
        try {
            input.focus({ preventScroll: true });
            input.select();
            input.setSelectionRange(0, text.length);
            return document.execCommand('copy') === true;
        } catch (_) {
            return false;
        } finally {
            input.remove();
            if (previous && typeof previous.focus === 'function') previous.focus({ preventScroll: true });
        }
    }
    async function copy(text) {
        if (navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
            try {
                await navigator.clipboard.writeText(text);
                return true;
            } catch (_) { /* Permissions/secure-context restrictions: try the local fallback. */ }
        }
        return legacyCopy(text);
    }
    function value(root, key) {
        const node = root.querySelector('[data-spei-value="' + key + '"]');
        return node ? node.textContent.trim() : '';
    }
    document.querySelectorAll('[data-cannaland-spei] button[data-spei-copy]').forEach(button => { button.hidden = false; });
    document.addEventListener('click', async function (event) {
        const button = event.target.closest('button[data-spei-copy]');
        if (!button || button.disabled) return;
        const root = button.closest('[data-cannaland-spei]');
        const key = button.dataset.speiCopy;
        if (!root || (key !== 'all' && !keys.includes(key))) return;
        const status = root.querySelector('[role="status"]');
        const text = key === 'all' ? keys.map(name => {
            const label = root.querySelector('[data-spei-label="' + name + '"]');
            return label && value(root, name) ? label.textContent.trim() + ': ' + value(root, name) : '';
        }).filter(Boolean).join('\n') : value(root, key);
        button.disabled = true;
        try {
            const success = text !== '' && await copy(text);
            button.textContent = success ? button.dataset.copiedLabel : button.dataset.copyLabel;
            if (status) status.textContent = success ? button.dataset.copiedLabel : button.dataset.copyError;
            if (success) {
                window.clearTimeout(button.copyResetTimer);
                button.copyResetTimer = window.setTimeout(() => {
                    button.textContent = button.dataset.copyLabel;
                    if (status) status.textContent = '';
                }, 2200);
            }
        } finally {
            button.disabled = false;
        }
    });
}());

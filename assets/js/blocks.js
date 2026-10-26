(function () {
    'use strict';
    const settings = window.wc.wcSettings.getSetting('cannaland_spei_data', {});
    const text = window.wp.htmlEntities.decodeEntities;
    const label = text(settings.title || 'Transferencia SPEI');
    const Content = function () {
        return window.wp.element.createElement('p', null, text(settings.description || ''));
    };
    window.wc.wcBlocksRegistry.registerPaymentMethod({
        name: 'cannaland_spei',
        label: label,
        ariaLabel: label,
        content: window.wp.element.createElement(Content),
        edit: window.wp.element.createElement(Content),
        canMakePayment: function () { return settings.available === true; },
        supports: { features: settings.supports || ['products'] }
    });
})();

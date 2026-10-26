(function () {
    'use strict';
    const settings = window.wc.wcSettings.getSetting('cannaland_sinergypay_data', {});
    const text = window.wp.htmlEntities.decodeEntities;
    const label = text(settings.title || 'Cannaland SinergyPay');
    const Content = function () {
        return window.wp.element.createElement('p', null, text(settings.description || ''));
    };
    window.wc.wcBlocksRegistry.registerPaymentMethod({
        name: 'cannaland_sinergypay',
        label: label,
        ariaLabel: label,
        content: window.wp.element.createElement(Content),
        edit: window.wp.element.createElement(Content),
        canMakePayment: function () { return true; },
        supports: { features: settings.supports || ['products'] }
    });
})();

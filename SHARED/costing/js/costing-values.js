(function () {
    'use strict';

    window.SharedCostingValues = Object.freeze({
        editableUnitCost: function (value) {
            const text = String(value ?? '').trim();
            if (text === '' || !Number.isFinite(Number(text)) || Number(text) === 0) {
                return '';
            }

            return text;
        },
    });
})();

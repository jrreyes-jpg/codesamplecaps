/* Simple shared focus para makita agad ang unang maling field. */
(function () {
    'use strict';

    const focus = function (field) {
        if (!field) return null;
        field.scrollIntoView({ behavior: 'smooth', block: 'center' });
        window.setTimeout(function () {
            field.focus({ preventScroll: true });
        }, 180);
        return field;
    };

    const focusFirstInvalid = function (form) {
        return focus(form?.querySelector(':invalid:not(:disabled)'));
    };

    window.EdgeFormValidation = { focus, focusFirstInvalid };
}());

(function () {
    'use strict';

    const countNonSpaceCharacters = function (value) {
        return String(value || '').replace(/\s/g, '').length;
    };

    const validateMinimumNonSpace = function (input, errorBox, options) {
        const settings = options || {};
        const minimum = Number(settings.minimum || 1);
        const isValid = countNonSpaceCharacters(input?.value) >= minimum;
        const showError = settings.showError === true && !isValid;

        input?.classList.toggle('shared-field-invalid', showError);
        if (input) {
            if (showError) {
                input.setAttribute('aria-invalid', 'true');
            } else {
                input.removeAttribute('aria-invalid');
            }
        }

        if (errorBox) {
            errorBox.textContent = showError ? String(settings.message || '') : '';
            errorBox.hidden = !showError;
        }

        return isValid;
    };

    window.EdgeFormValidation = Object.freeze({
        countNonSpaceCharacters,
        validateMinimumNonSpace,
    });
})();

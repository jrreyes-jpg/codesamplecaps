document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('[data-material-stock-in-form]');
    const material = document.querySelector('[data-material-stock-in-material]');
    const quantity = document.querySelector('[data-material-stock-in-quantity]');
    const error = document.querySelector('[data-material-stock-in-error]');
    const toast = document.querySelector('[data-material-stock-in-toast]');
    const toastClose = document.querySelector('[data-material-stock-in-toast-close]');
    const wholeCountUnits = ['pcs', 'roll', 'box', 'pack', 'set', 'bundle', 'sheet', 'pair', 'tube'];
    let quantityTouched = false;

    const selectedUnit = function () {
        return material?.selectedOptions[0]?.dataset.unit || '';
    };

    const setQuantityInputRules = function () {
        if (!quantity) {
            return;
        }

        const isWholeCount = wholeCountUnits.includes(selectedUnit());
        quantity.min = isWholeCount ? '1' : '0.01';
        quantity.step = isWholeCount ? '1' : '0.01';
        quantity.inputMode = isWholeCount ? 'numeric' : 'decimal';
    };

    const validateQuantity = function (showError) {
        if (!quantity) {
            return true;
        }

        const rawValue = quantity.value.trim();
        const value = Number(rawValue);
        const unit = selectedUnit();
        let message = '';

        if (unit !== '' && (rawValue === '' || !Number.isFinite(value) || value <= 0)) {
            message = 'Enter a quantity greater than zero.';
        } else if (unit !== '' && wholeCountUnits.includes(unit) && !Number.isInteger(value)) {
            message = 'Use a whole number for ' + unit + '.';
        }

        quantity.setCustomValidity(message);
        quantity.setAttribute('aria-invalid', message !== '' ? 'true' : 'false');
        if (showError && error) {
            error.textContent = message;
        } else if (!showError && error && error.textContent !== '') {
            error.textContent = '';
        }
        return message === '';
    };

    material?.addEventListener('change', function () {
        setQuantityInputRules();
        if (quantityTouched) {
            validateQuantity(true);
        }
    });

    quantity?.addEventListener('input', function () {
        quantityTouched = true;
        validateQuantity(true);
    });
    quantity?.addEventListener('blur', function () {
        quantityTouched = true;
        validateQuantity(true);
    });
    quantity?.addEventListener('keydown', function (event) {
        if (['e', 'E', '+', '-'].includes(event.key)) {
            event.preventDefault();
        }
    });
    form?.addEventListener('submit', function (event) {
        quantityTouched = true;
        if (!validateQuantity(true)) {
            event.preventDefault();
            quantity?.focus();
        }
    });

    const closeToast = function () {
        if (!toast) {
            return;
        }
        toast.classList.add('is-hiding');
        window.setTimeout(function () { toast.remove(); }, 180);
    };

    toastClose?.addEventListener('click', closeToast);
    if (toast) {
        window.setTimeout(closeToast, 4000);
    }

    setQuantityInputRules();
});

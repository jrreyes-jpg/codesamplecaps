document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('[data-material-stock-in-form]');
    const material = document.querySelector('[data-material-stock-in-material]');
    const quantity = document.querySelector('[data-material-stock-in-quantity]');
    const error = document.querySelector('[data-material-stock-in-error]');
    const materialError = document.querySelector('[data-material-stock-in-material-error]');
    const toast = document.querySelector('[data-material-stock-in-toast]');
    const toastClose = document.querySelector('[data-material-stock-in-toast-close]');
    const confirmModal = document.querySelector('[data-material-stock-in-confirm]');
    const confirmCancel = document.querySelector('[data-material-stock-in-confirm-cancel]');
    const confirmSubmit = document.querySelector('[data-material-stock-in-confirm-submit]');
    const wholeCountUnits = ['pcs', 'roll', 'box', 'pack', 'set', 'bundle', 'sheet', 'pair', 'tube'];
    let quantityTouched = false;
    let isSaving = false;

    const selectedUnit = function () {
        return material?.selectedOptions[0]?.dataset.unit || '';
    };

    const selectedOption = function () {
        return material?.selectedOptions[0] || null;
    };

    const formatQuantity = function (rawQuantity, unit) {
        const amount = Number(rawQuantity);
        const displayAmount = Number.isInteger(amount) ? String(amount) : String(amount);
        const isOne = Math.abs(amount - 1) < 0.00001;
        const displayUnit = {
            pcs: isOne ? 'pc' : 'pcs',
            box: isOne ? 'box' : 'boxes',
            kg: 'kg',
        }[unit] || (isOne ? unit : unit + 's');

        return displayAmount + ' ' + displayUnit;
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

    const validateMaterial = function (showError) {
        const message = material?.value ? '' : 'Select a material.';
        material?.setCustomValidity(message);
        material?.setAttribute('aria-invalid', message !== '' ? 'true' : 'false');
        if (showError && materialError) {
            materialError.textContent = message;
        }
        return message === '';
    };

    const validateQuantity = function (showError, requireValue) {
        if (!quantity) {
            return true;
        }

        const rawValue = quantity.value.trim();
        const value = Number(rawValue);
        const unit = selectedUnit();
        let message = '';

        if (unit !== '' && rawValue === '' && requireValue) {
            message = 'Quantity In is required.';
        } else if (unit !== '' && rawValue !== '' && (!Number.isFinite(value) || value <= 0)) {
            message = 'Enter a quantity greater than zero.';
        } else if (unit !== '' && wholeCountUnits.includes(unit) && !Number.isInteger(value)) {
            message = 'Enter a whole number for ' + unit + '.';
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
        validateMaterial(true);
        if (quantityTouched && quantity?.value.trim() !== '') {
            validateQuantity(true, false);
        }
    });

    quantity?.addEventListener('input', function () {
        quantityTouched = true;
        if (quantity.value.trim() === '') {
            validateQuantity(false, false);
            return;
        }
        validateQuantity(true, false);
    });
    quantity?.addEventListener('blur', function () {
        quantityTouched = true;
        validateQuantity(true, true);
    });
    quantity?.addEventListener('keydown', function (event) {
        if (['e', 'E', '+', '-'].includes(event.key)) {
            event.preventDefault();
        }
    });
    form?.addEventListener('submit', function (event) {
        event.preventDefault();
        if (isSaving) {
            return;
        }
        quantityTouched = true;
        const materialValid = validateMaterial(true);
        const quantityValid = validateQuantity(true, true);
        if (!materialValid || !quantityValid) {
            if (!materialValid) {
                material?.focus();
                return;
            }
            quantity?.focus();
            return;
        }

        const option = selectedOption();
        const unit = selectedUnit();
        const currentPhysical = Number(option?.dataset.physicalQuantity || 0);
        const quantityIn = Number(quantity?.value || 0);
        const remarks = document.getElementById('remarks')?.value.trim() || '';
        const setText = function (selector, value) {
            const target = confirmModal?.querySelector(selector);
            if (target) {
                target.textContent = value;
            }
        };

        setText('[data-confirm-material]', option?.dataset.materialName || '');
        setText('[data-confirm-quantity]', formatQuantity(quantityIn, unit));
        setText('[data-confirm-current]', formatQuantity(currentPhysical, unit));
        setText('[data-confirm-new]', formatQuantity(currentPhysical + quantityIn, unit));
        const remarksRow = confirmModal?.querySelector('[data-confirm-remarks-row]');
        setText('[data-confirm-remarks]', remarks);
        if (remarksRow) {
            remarksRow.hidden = remarks === '';
        }
        confirmModal?.removeAttribute('hidden');
        confirmCancel?.focus();
    });

    const closeConfirmModal = function () {
        if (isSaving) {
            return;
        }
        confirmModal?.setAttribute('hidden', '');
    };

    confirmCancel?.addEventListener('click', closeConfirmModal);
    confirmModal?.addEventListener('click', function (event) {
        if (event.target === confirmModal) {
            closeConfirmModal();
        }
    });
    confirmSubmit?.addEventListener('click', function () {
        if (isSaving) {
            return;
        }
        isSaving = true;
        confirmSubmit.disabled = true;
        confirmSubmit.textContent = 'Saving...';
        form?.submit();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !confirmModal?.hidden) {
            closeConfirmModal();
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

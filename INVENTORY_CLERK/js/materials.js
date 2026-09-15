document.addEventListener('DOMContentLoaded', function () {
    const materialName = document.querySelector('[data-material-name]');
    const category = document.querySelector('[data-material-category]');
    const unit = document.querySelector('[data-material-unit]');
    const suggestion = document.querySelector('[data-material-suggestion]');
    const reorderLevel = document.querySelector('[data-reorder-level]');
    const form = document.querySelector('[data-material-form]');
    const unitChangeMessage = document.querySelector('[data-unit-change-message]');
    const fieldErrors = {
        materialName: document.querySelector('[data-material-error="material_name"]'),
        category: document.querySelector('[data-material-error="category"]'),
        unit: document.querySelector('[data-material-error="unit"]'),
        reorderLevel: document.querySelector('[data-material-error="reorder_level"]'),
    };
    const toast = document.querySelector('[data-material-toast]');
    const toastClose = document.querySelector('[data-material-toast-close]');
    const availableTooltip = document.querySelector('.materials-table-tooltip');
    let floatingTooltip = null;

    const closeAvailableTooltip = function () {
        if (!floatingTooltip) {
            return;
        }

        floatingTooltip.remove();
        floatingTooltip = null;
        availableTooltip?.setAttribute('aria-expanded', 'false');
    };

    const openAvailableTooltip = function () {
        if (!availableTooltip || floatingTooltip) {
            return;
        }

        floatingTooltip = document.createElement('div');
        floatingTooltip.className = 'materials-floating-tooltip';
        floatingTooltip.textContent = availableTooltip.dataset.tooltip || '';
        document.body.appendChild(floatingTooltip);

        const triggerBox = availableTooltip.getBoundingClientRect();
        const tooltipBox = floatingTooltip.getBoundingClientRect();
        const viewportPadding = 8;
        const top = triggerBox.bottom + tooltipBox.height + viewportPadding <= window.innerHeight
            ? triggerBox.bottom + viewportPadding
            : Math.max(viewportPadding, triggerBox.top - tooltipBox.height - viewportPadding);
        const left = Math.min(
            Math.max(viewportPadding, triggerBox.right - tooltipBox.width),
            window.innerWidth - tooltipBox.width - viewportPadding
        );

        floatingTooltip.style.top = top + 'px';
        floatingTooltip.style.left = left + 'px';
        availableTooltip.setAttribute('aria-expanded', 'true');
    };

    availableTooltip?.addEventListener('pointerenter', openAvailableTooltip);
    availableTooltip?.addEventListener('pointerleave', closeAvailableTooltip);
    availableTooltip?.addEventListener('focus', openAvailableTooltip);
    availableTooltip?.addEventListener('blur', closeAvailableTooltip);
    availableTooltip?.addEventListener('click', function (event) {
        event.stopPropagation();
        if (floatingTooltip) {
            closeAvailableTooltip();
            return;
        }
        openAvailableTooltip();
    });
    availableTooltip?.addEventListener('keydown', function (event) {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            availableTooltip.click();
        }
    });
    document.addEventListener('click', closeAvailableTooltip);
    window.addEventListener('resize', closeAvailableTooltip);
    window.addEventListener('scroll', closeAvailableTooltip, true);

    if (!materialName || !category || !unit) {
        return;
    }

    const suggestions = [
        { words: ['pako', 'bolt', 'screw', 'nut'], category: 'Fasteners & Hardware', unit: 'pcs' },
        { words: ['wire', 'cable'], category: 'Cable & Wire', unit: 'meter' },
        { words: ['rj45', 'connector', 'terminal'], category: 'Connectors & Terminals', unit: 'pcs' },
        { words: ['conduit'], category: 'Conduit & Raceway', unit: 'meter' },
    ];
    const wholeCountUnits = ['pcs', 'roll', 'box', 'pack', 'set', 'bundle', 'sheet', 'pair', 'tube'];
    let categoryChangedByUser = false;
    let unitChangedByUser = false;
    let reorderLevelTouched = false;

    const isWholeCountUnit = function (unitValue) {
        return wholeCountUnits.includes(unitValue ?? unit.value);
    };

    const isValidReorderValue = function (rawValue, unitValue) {
        const value = Number(rawValue);
        return rawValue.trim() !== ''
            && Number.isFinite(value)
            && value > 0
            && (!isWholeCountUnit(unitValue) || Number.isInteger(value));
    };

    const setFieldError = function (field, errorElement, message) {
        field.setCustomValidity(message);
        field.setAttribute('aria-invalid', message !== '' ? 'true' : 'false');
        if (errorElement) {
            errorElement.textContent = message;
        }
        return message === '';
    };

    const validateMaterialName = function () {
        const message = materialName.value.trim() === '' ? 'Enter a material name.' : '';
        return setFieldError(materialName, fieldErrors.materialName, message);
    };

    const validateCategory = function () {
        const message = category.value === '' ? 'Select a category.' : '';
        return setFieldError(category, fieldErrors.category, message);
    };

    const validateUnit = function () {
        const message = unit.value === '' ? 'Select a unit.' : '';
        return setFieldError(unit, fieldErrors.unit, message);
    };

    let unitChangeMessageTimer = 0;
    const showUnitChangeMessage = function (message) {
        if (!unitChangeMessage) {
            return;
        }

        window.clearTimeout(unitChangeMessageTimer);
        unitChangeMessage.textContent = message;
        unitChangeMessageTimer = window.setTimeout(function () {
            unitChangeMessage.textContent = '';
        }, 4000);
    };

    const updateReorderLevelForUnit = function () {
        if (!reorderLevel) {
            return;
        }

        const isWholeUnit = isWholeCountUnit();
        reorderLevel.min = isWholeUnit ? '1' : '0.01';
        reorderLevel.step = isWholeUnit ? '1' : '0.01';
        reorderLevel.inputMode = isWholeUnit ? 'numeric' : 'decimal';

        if (reorderLevel.value.trim() !== '' && !isValidReorderValue(reorderLevel.value, unit.value)) {
            reorderLevel.value = '';
            showUnitChangeMessage('Low Stock Alert Level was cleared for ' + unit.value + '.');
        }
        validateReorderLevel(reorderLevelTouched);
    };

    const findSuggestion = function (value) {
        const name = value.trim().toLowerCase();
        return suggestions.find(function (item) {
            return item.words.some(function (word) {
                return name.includes(word);
            });
        });
    };

    const applySuggestion = function () {
        const match = findSuggestion(materialName.value);
        if (!match) {
            if (suggestion) {
                suggestion.textContent = '';
                suggestion.removeAttribute('title');
            }
            return;
        }

        if (!categoryChangedByUser) {
            category.value = match.category;
        }
        if (!unitChangedByUser) {
            unit.value = match.unit;
            updateReorderLevelForUnit();
        }
        if (suggestion) {
            suggestion.textContent = 'Suggested';
            suggestion.title = match.category + ' / ' + match.unit;
        }
    };

    category.addEventListener('change', function () {
        categoryChangedByUser = true;
        validateCategory();
    });
    unit.addEventListener('change', function () {
        unitChangedByUser = true;
        validateUnit();
        updateReorderLevelForUnit();
    });
    materialName.addEventListener('input', function () {
        applySuggestion();
        validateMaterialName();
    });

    const validateReorderLevel = function (showError) {
        if (!reorderLevel) {
            return true;
        }

        const value = Number(reorderLevel.value);
        const hasValue = reorderLevel.value.trim() !== '';
        const needsWholeNumber = isWholeCountUnit();
        let message = '';

        if (!hasValue || !Number.isFinite(value) || value <= 0) {
            message = 'Enter a reorder level greater than zero.';
        } else if (needsWholeNumber && !Number.isInteger(value)) {
            message = 'Use a whole number for ' + unit.value + '.';
        }

        if (showError) {
            reorderLevel.setCustomValidity(message);
            reorderLevel.setAttribute('aria-invalid', message !== '' ? 'true' : 'false');
            if (fieldErrors.reorderLevel) {
                fieldErrors.reorderLevel.textContent = message;
            }
        } else {
            // Huwag magpakita ng error bago pa mag-input ang clerk.
            reorderLevel.setCustomValidity('');
            reorderLevel.removeAttribute('aria-invalid');
        }

        return message === '';
    };

    reorderLevel?.addEventListener('input', function () {
        reorderLevelTouched = true;
        validateReorderLevel(true);
    });
    reorderLevel?.addEventListener('blur', function () {
        reorderLevelTouched = true;
        validateReorderLevel(true);
    });
    reorderLevel?.addEventListener('keydown', function (event) {
        if (['e', 'E', '+', '-'].includes(event.key)) {
            event.preventDefault();
        }
    });
    updateReorderLevelForUnit();
    form?.addEventListener('submit', function (event) {
        const nameValid = validateMaterialName();
        const categoryValid = validateCategory();
        const unitValid = validateUnit();
        reorderLevelTouched = true;
        const reorderValid = validateReorderLevel(true);
        const isValid = nameValid && categoryValid && unitValid && reorderValid;
        if (!isValid) {
            event.preventDefault();
            const invalidField = [materialName, category, unit, reorderLevel].find(function (field) {
                return field.getAttribute('aria-invalid') === 'true';
            });
            invalidField?.focus();
        }
    });

    const closeToast = function () {
        if (!toast) {
            return;
        }

        toast.classList.add('is-hiding');
        window.setTimeout(function () {
            toast.remove();
        }, 180);
    };

    toastClose?.addEventListener('click', closeToast);
    if (toast) {
        window.setTimeout(closeToast, 4000);
    }
});

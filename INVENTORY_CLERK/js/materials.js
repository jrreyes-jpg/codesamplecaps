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
    const tooltipTriggers = document.querySelectorAll('.materials-info-tooltip, .materials-table-tooltip');
    const actionForms = document.querySelectorAll('[data-material-confirm]');
    const actionMenus = document.querySelectorAll('[data-material-action-menu]');
    let floatingTooltip = null;
    let floatingTooltipTrigger = null;

    const closeFloatingTooltip = function () {
        if (!floatingTooltip) {
            return;
        }

        floatingTooltip.remove();
        floatingTooltip = null;
        floatingTooltipTrigger?.setAttribute('aria-expanded', 'false');
        floatingTooltipTrigger = null;
    };

    const openFloatingTooltip = function (trigger) {
        if (!trigger || floatingTooltipTrigger === trigger) {
            return;
        }

        closeFloatingTooltip();

        floatingTooltip = document.createElement('div');
        floatingTooltip.className = 'materials-floating-tooltip';
        floatingTooltip.textContent = trigger.dataset.tooltip || '';
        document.body.appendChild(floatingTooltip);

        const triggerBox = trigger.getBoundingClientRect();
        const tooltipBox = floatingTooltip.getBoundingClientRect();
        const viewportPadding = 8;
        const top = triggerBox.bottom + tooltipBox.height + viewportPadding <= window.innerHeight
            ? triggerBox.bottom + viewportPadding
            : Math.max(viewportPadding, triggerBox.top - tooltipBox.height - viewportPadding);
        const left = Math.min(
            Math.max(viewportPadding, triggerBox.left + (triggerBox.width / 2) - (tooltipBox.width / 2)),
            window.innerWidth - tooltipBox.width - viewportPadding
        );

        floatingTooltip.style.top = top + 'px';
        floatingTooltip.style.left = left + 'px';
        floatingTooltipTrigger = trigger;
        trigger.setAttribute('aria-expanded', 'true');
    };

    tooltipTriggers.forEach(function (trigger) {
        trigger.addEventListener('pointerenter', function () { openFloatingTooltip(trigger); });
        trigger.addEventListener('pointerleave', closeFloatingTooltip);
        trigger.addEventListener('focus', function () { openFloatingTooltip(trigger); });
        trigger.addEventListener('blur', closeFloatingTooltip);
        trigger.addEventListener('click', function (event) {
            event.stopPropagation();
            if (floatingTooltipTrigger === trigger) {
                closeFloatingTooltip();
                return;
            }
            openFloatingTooltip(trigger);
        });
        trigger.addEventListener('keydown', function (event) {
            if (event.key === 'Enter' || event.key === ' ') {
                event.preventDefault();
                trigger.click();
            }
        });
    });
    document.addEventListener('click', closeFloatingTooltip);
    window.addEventListener('resize', closeFloatingTooltip);
    window.addEventListener('scroll', closeFloatingTooltip, true);

    actionForms.forEach(function (actionForm) {
        actionForm.addEventListener('submit', function (event) {
            if (!window.confirm(actionForm.dataset.confirmMessage || 'Continue?')) {
                event.preventDefault();
            }
        });
    });

    const closeActionMenus = function (exceptMenu) {
        actionMenus.forEach(function (actionMenu) {
            if (actionMenu === exceptMenu) {
                return;
            }

            const toggle = actionMenu.querySelector('[data-material-action-toggle]');
            const panel = actionMenu._floatingPanel || actionMenu.querySelector('[data-material-action-panel]');
            toggle?.setAttribute('aria-expanded', 'false');
            if (panel) {
                panel.hidden = true;
                if (panel.parentElement !== actionMenu) {
                    actionMenu.appendChild(panel);
                }
                panel.style.top = '';
                panel.style.left = '';
                actionMenu._floatingPanel = null;
            }
        });
    };

    const placeActionMenu = function (toggle, panel) {
        if (!toggle || !panel) {
            return;
        }

        const toggleBox = toggle.getBoundingClientRect();
        const panelWidth = panel.offsetWidth;
        const panelHeight = panel.offsetHeight;
        const gap = 6;
        const top = toggleBox.bottom + panelHeight + gap <= window.innerHeight
            ? toggleBox.bottom + gap
            : Math.max(gap, toggleBox.top - panelHeight - gap);
        const left = Math.max(gap, Math.min(toggleBox.right - panelWidth, window.innerWidth - panelWidth - gap));
        panel.style.top = top + 'px';
        panel.style.left = left + 'px';
    };

    actionMenus.forEach(function (actionMenu) {
        const toggle = actionMenu.querySelector('[data-material-action-toggle]');
        const panel = actionMenu.querySelector('[data-material-action-panel]');
        toggle?.addEventListener('click', function (event) {
            event.stopPropagation();
            const willOpen = panel?.hidden;
            closeActionMenus(willOpen ? actionMenu : null);
            if (panel) {
                if (willOpen) {
                    document.body.appendChild(panel);
                    actionMenu._floatingPanel = panel;
                    panel.hidden = false;
                    placeActionMenu(toggle, panel);
                } else {
                    panel.hidden = true;
                    actionMenu.appendChild(panel);
                    actionMenu._floatingPanel = null;
                }
            }
            toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        });
    });
    document.addEventListener('click', function (event) {
        if (event.target.closest('[data-material-action-menu], [data-material-action-panel]')) {
            return;
        }
        closeActionMenus(null);
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            closeActionMenus(null);
        }
    });
    window.addEventListener('resize', function () {
        closeActionMenus(null);
    });

    if (!materialName || !category || !unit) {
        return;
    }

    const suggestions = [
        { words: ['pako', 'nail'], category: 'Fasteners & Hardware', unit: 'kg' },
        { words: ['bolt', 'screw', 'nut'], category: 'Fasteners & Hardware', unit: 'pcs' },
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
        const toastDelay = toast.classList.contains('materials-toast--success') ? 4000 : 5000;
        window.setTimeout(closeToast, toastDelay);
    }
});

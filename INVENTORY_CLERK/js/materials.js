document.addEventListener('DOMContentLoaded', function () {
    const materialName = document.querySelector('[data-material-name]');
    const materialNameSuggestionPanel = document.querySelector('[data-material-name-suggestions]');
    const category = document.querySelector('[data-material-category]');
    const unit = document.querySelector('[data-material-unit]');
    const description = document.querySelector('[data-material-description]');
    const suggestion = document.querySelector('[data-material-suggestion]');
    const reorderLevel = document.querySelector('[data-reorder-level]');
    const form = document.querySelector('[data-material-form]');
    const formSubmitButton = form?.querySelector('button[type="submit"]');
    const materialModal = document.querySelector('[data-material-modal]');
    const modalOpenButton = document.querySelector('[data-material-modal-open]');
    const modalCloseButtons = document.querySelectorAll('[data-material-modal-close]');
    const clearFormButton = document.querySelector('[data-material-clear-form]');
    const clearConfirmationModal = document.querySelector('[data-material-clear-confirmation]');
    const clearConfirmationKeepButton = document.querySelector('[data-material-clear-keep]');
    const clearConfirmationConfirmButton = document.querySelector('[data-material-clear-confirm]');
    const unitChangeMessage = document.querySelector('[data-unit-change-message]');
    const fieldErrors = {
        materialName: document.querySelector('[data-material-error="material_name"]'),
        category: document.querySelector('[data-material-error="category"]'),
        unit: document.querySelector('[data-material-error="unit"]'),
        reorderLevel: document.querySelector('[data-material-error="reorder_level"]'),
    };
    const toast = document.querySelector('[data-material-toast]');
    const toastClose = document.querySelector('[data-material-toast-close]');
    const materialCreatedToast = document.querySelector('[data-material-created-toast]');
    const draftMessage = document.querySelector('[data-material-draft-message]');
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
    const materialNameSuggestionValues = [
        'Pako',
        'Screw',
        'Wire CAT 5',
        'RJ45 Connector',
        'PVC Conduit',
    ];
    let activeMaterialNameSuggestionIndex = -1;
    const wholeCountUnits = ['pcs', 'roll', 'box', 'pack', 'set', 'bundle', 'sheet', 'pair', 'tube'];
    let categoryChangedByUser = false;
    let unitChangedByUser = false;
    let reorderLevelTouched = fieldErrors.reorderLevel?.textContent.trim() !== '';
    const trackedFields = [materialName, category, unit, reorderLevel, description].filter(Boolean);
    const initialFieldValues = new Map(trackedFields.map(function (field) {
        return [field.name, field.value];
    }));
    const initialFormState = trackedFields.map(function (field) {
        return field.name + '=' + field.value;
    }).join('&');
    const materialDraftKey = materialModal?.dataset.materialDraftKey || 'edge_inventory_clerk_material_draft';
    const materialDraftMaxAgeMs = 24 * 60 * 60 * 1000;
    let materialFormSubmitting = false;

    const currentMaterialFormState = function () {
        return trackedFields.map(function (field) {
            return field.name + '=' + field.value;
        }).join('&');
    };

    const hasUnsavedMaterialChanges = function () {
        if (materialFormSubmitting || !materialModal || materialModal.hidden) {
            return false;
        }

        return currentMaterialFormState() !== initialFormState;
    };

    const clearLocalMaterialDraft = function () {
        try {
            window.localStorage.removeItem(materialDraftKey);
        } catch (error) {
            // Hindi critical kapag blocked ang local storage.
        }
    };

    const saveLocalMaterialDraft = function () {
        if (!materialModal || currentMaterialFormState() === initialFormState) {
            clearLocalMaterialDraft();
            return;
        }

        const fields = {};
        trackedFields.forEach(function (field) {
            fields[field.name] = field.value;
        });

        try {
            window.localStorage.setItem(materialDraftKey, JSON.stringify({
                savedAt: Date.now(),
                fields: fields,
            }));
        } catch (error) {
            // Hindi dapat hadlangan ang normal form kapag blocked ang local storage.
        }
    };

    let draftMessageTimer = 0;
    const showDraftMessage = function () {
        if (!draftMessage) {
            return;
        }

        window.clearTimeout(draftMessageTimer);
        draftMessage.hidden = false;
        draftMessageTimer = window.setTimeout(function () {
            draftMessage.hidden = true;
        }, 4000);
    };

    const restoreLocalMaterialDraft = function () {
        let draft = null;
        try {
            draft = JSON.parse(window.localStorage.getItem(materialDraftKey) || 'null');
        } catch (error) {
            clearLocalMaterialDraft();
            return false;
        }

        if (!draft || !draft.fields || !Number.isFinite(Number(draft.savedAt))
            || Date.now() - Number(draft.savedAt) > materialDraftMaxAgeMs) {
            clearLocalMaterialDraft();
            return false;
        }

        trackedFields.forEach(function (field) {
            if (typeof draft.fields[field.name] === 'string') {
                field.value = draft.fields[field.name];
            }
        });
        return currentMaterialFormState() !== initialFormState;
    };

    window.addEventListener('beforeunload', function (event) {
        if (!hasUnsavedMaterialChanges()) {
            return;
        }

        saveLocalMaterialDraft();
        event.preventDefault();
        event.returnValue = '';
    });

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

    const normalizeMaterialName = function (value) {
        return value.trim().replace(/\s+/g, ' ');
    };

    const isValidMaterialName = function (value) {
        return /^[\p{L}\p{N}\s\-/.()]+$/u.test(value) && /\p{L}/u.test(value);
    };

    const closeMaterialNameSuggestions = function () {
        if (!materialNameSuggestionPanel) {
            return;
        }

        activeMaterialNameSuggestionIndex = -1;
        materialNameSuggestionPanel.replaceChildren();
        materialNameSuggestionPanel.hidden = true;
        materialName.setAttribute('aria-expanded', 'false');
    };

    const getMatchingMaterialNameSuggestions = function () {
        const value = normalizeMaterialName(materialName.value);
        const query = value.toLowerCase().replace(/[^\p{L}\p{N}]+/gu, '');
        if (query.length < 2 || !/\p{L}/u.test(value)) {
            return [];
        }

        return materialNameSuggestionValues.filter(function (item) {
            return item.toLowerCase().replace(/[^\p{L}\p{N}]+/gu, '').includes(query);
        });
    };

    const selectMaterialNameSuggestion = function (value) {
        materialName.value = value;
        closeMaterialNameSuggestions();
        applySuggestion();
        validateMaterialName(false, false);
        saveLocalMaterialDraft();
        materialName.focus();
    };

    const updateMaterialNameSuggestions = function () {
        if (!materialNameSuggestionPanel) {
            return;
        }

        const matches = getMatchingMaterialNameSuggestions();
        closeMaterialNameSuggestions();
        if (matches.length === 0) {
            return;
        }

        matches.forEach(function (item, index) {
            const option = document.createElement('button');
            option.type = 'button';
            option.className = 'materials-name-suggestions__item';
            option.dataset.materialSuggestionIndex = String(index);
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            option.textContent = item;
            option.addEventListener('mousedown', function (event) {
                event.preventDefault();
            });
            option.addEventListener('click', function () {
                selectMaterialNameSuggestion(item);
            });
            materialNameSuggestionPanel.appendChild(option);
        });
        materialNameSuggestionPanel.hidden = false;
        materialName.setAttribute('aria-expanded', 'true');
    };

    const setActiveMaterialNameSuggestion = function (index) {
        if (!materialNameSuggestionPanel || materialNameSuggestionPanel.hidden) {
            return;
        }

        const options = Array.from(materialNameSuggestionPanel.querySelectorAll('[role="option"]'));
        if (options.length === 0) {
            return;
        }

        activeMaterialNameSuggestionIndex = (index + options.length) % options.length;
        options.forEach(function (option, optionIndex) {
            const isActive = optionIndex === activeMaterialNameSuggestionIndex;
            option.classList.toggle('is-active', isActive);
            option.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
    };

    const validateMaterialName = function (showError, requireValue) {
        const value = normalizeMaterialName(materialName.value);
        let message = '';

        if (value === '') {
            message = requireValue ? 'Material Name is required.' : '';
        } else if (!isValidMaterialName(value)) {
            message = 'Enter a valid material name.';
        }

        if (showError) {
            return setFieldError(materialName, fieldErrors.materialName, message);
        }

        materialName.setCustomValidity('');
        materialName.removeAttribute('aria-invalid');
        if (fieldErrors.materialName) {
            fieldErrors.materialName.textContent = '';
        }
        return message === '';
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

        let clearedForUnitChange = false;
        if (reorderLevel.value.trim() !== '' && !isValidReorderValue(reorderLevel.value, unit.value)) {
            reorderLevel.value = '';
            clearedForUnitChange = true;
            showUnitChangeMessage('Low Stock Alert Level was cleared for ' + unit.value + '.');
        }
        validateReorderLevel(
            !clearedForUnitChange && reorderLevelTouched,
            !clearedForUnitChange && reorderLevelTouched
        );
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
            if (!categoryChangedByUser) {
                category.value = '';
            }
            if (!unitChangedByUser) {
                unit.value = '';
                updateReorderLevelForUnit();
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
        updateMaterialNameSuggestions();
        validateMaterialName(false, false);
    });
    materialName.addEventListener('blur', function () {
        materialName.value = normalizeMaterialName(materialName.value);
        closeMaterialNameSuggestions();
        validateMaterialName(true, true);
        saveLocalMaterialDraft();
    });
    materialName.addEventListener('keydown', function (event) {
        if (!materialNameSuggestionPanel || materialNameSuggestionPanel.hidden) {
            return;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            setActiveMaterialNameSuggestion(activeMaterialNameSuggestionIndex + 1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            setActiveMaterialNameSuggestion(activeMaterialNameSuggestionIndex - 1);
        } else if (event.key === 'Enter' && activeMaterialNameSuggestionIndex >= 0) {
            event.preventDefault();
            const activeOption = materialNameSuggestionPanel.querySelector('[data-material-suggestion-index="' + activeMaterialNameSuggestionIndex + '"]');
            activeOption?.click();
        } else if (event.key === 'Escape') {
            event.preventDefault();
            event.stopPropagation();
            closeMaterialNameSuggestions();
        }
    });

    const validateReorderLevel = function (showError, requireValue) {
        if (!reorderLevel) {
            return true;
        }

        const value = Number(reorderLevel.value);
        const hasValue = reorderLevel.value.trim() !== '';
        const needsWholeNumber = isWholeCountUnit();
        let message = '';

        if (!hasValue) {
            message = requireValue ? 'Required.' : '';
        } else if (!Number.isFinite(value) || value <= 0) {
            message = 'Must be greater than 0.';
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
            if (fieldErrors.reorderLevel) {
                fieldErrors.reorderLevel.textContent = '';
            }
        }

        return message === '';
    };

    const openMaterialModal = function () {
        if (!materialModal) {
            return;
        }

        materialModal.hidden = false;
        document.body.classList.add('materials-modal-open');
        window.setTimeout(function () {
            materialName?.focus();
        }, 0);
    };

    const resetMaterialForm = function () {
        trackedFields.forEach(function (field) {
            field.value = initialFieldValues.get(field.name) ?? '';
            field.setCustomValidity('');
            field.removeAttribute('aria-invalid');
        });
        Object.values(fieldErrors).forEach(function (errorElement) {
            if (errorElement) {
                errorElement.textContent = '';
            }
        });
        categoryChangedByUser = false;
        unitChangedByUser = false;
        reorderLevelTouched = false;
        if (unitChangeMessage) {
            unitChangeMessage.textContent = '';
        }
        clearLocalMaterialDraft();
        updateReorderLevelForUnit();
    };

    const clearMaterialForm = function () {
        trackedFields.forEach(function (field) {
            field.value = '';
            field.setCustomValidity('');
            field.removeAttribute('aria-invalid');
        });
        Object.values(fieldErrors).forEach(function (errorElement) {
            if (errorElement) {
                errorElement.textContent = '';
            }
        });
        categoryChangedByUser = false;
        unitChangedByUser = false;
        reorderLevelTouched = false;
        if (suggestion) {
            suggestion.textContent = '';
            suggestion.removeAttribute('title');
        }
        closeMaterialNameSuggestions();
        if (unitChangeMessage) {
            unitChangeMessage.textContent = '';
        }
        clearLocalMaterialDraft();
        updateReorderLevelForUnit();
        materialName.focus();
    };

    const hasMaterialFormValues = function () {
        return trackedFields.some(function (field) {
            return field.value.trim() !== '';
        });
    };

    const closeClearConfirmation = function (returnFocus = true) {
        if (!clearConfirmationModal) {
            return;
        }

        clearConfirmationModal.hidden = true;
        if (returnFocus) {
            clearFormButton?.focus();
        }
    };

    const requestClearMaterialForm = function () {
        if (!hasMaterialFormValues()) {
            clearMaterialForm();
            return;
        }

        if (!clearConfirmationModal) {
            return;
        }

        clearConfirmationModal.hidden = false;
        window.setTimeout(function () {
            clearConfirmationKeepButton?.focus();
        }, 0);
    };

    const closeMaterialModal = function () {
        if (!materialModal) {
            return;
        }

        materialModal.hidden = true;
        document.body.classList.remove('materials-modal-open');
        modalOpenButton?.focus();
    };

    const requestMaterialModalClose = function () {
        if (hasUnsavedMaterialChanges() && !window.confirm('Discard unsaved changes?')) {
            return;
        }

        if (hasUnsavedMaterialChanges()) {
            resetMaterialForm();
        }
        closeMaterialModal();
    };

    reorderLevel?.addEventListener('input', function () {
        reorderLevelTouched = true;
        validateReorderLevel(reorderLevel.value.trim() !== '', false);
    });
    reorderLevel?.addEventListener('blur', function () {
        reorderLevelTouched = true;
        validateReorderLevel(true, true);
    });
    reorderLevel?.addEventListener('keydown', function (event) {
        if (['e', 'E', '+', '-'].includes(event.key)) {
            event.preventDefault();
        }
    });
    updateReorderLevelForUnit();
    trackedFields.forEach(function (field) {
        field.addEventListener('input', saveLocalMaterialDraft);
        field.addEventListener('change', saveLocalMaterialDraft);
    });
    modalOpenButton?.addEventListener('click', openMaterialModal);
    modalCloseButtons.forEach(function (button) {
        button.addEventListener('click', requestMaterialModalClose);
    });
    clearFormButton?.addEventListener('click', requestClearMaterialForm);
    clearConfirmationKeepButton?.addEventListener('click', closeClearConfirmation);
    clearConfirmationConfirmButton?.addEventListener('click', function () {
        closeClearConfirmation(false);
        clearMaterialForm();
    });
    materialModal?.addEventListener('click', function (event) {
        if (event.target === materialModal) {
            requestMaterialModalClose();
        }
    });
    document.addEventListener('click', function (event) {
        if (event.target !== materialName && !materialNameSuggestionPanel?.contains(event.target)) {
            closeMaterialNameSuggestions();
        }
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && clearConfirmationModal && !clearConfirmationModal.hidden) {
            event.preventDefault();
            closeClearConfirmation();
            return;
        }
        if (event.key === 'Escape' && materialModal && !materialModal.hidden) {
            requestMaterialModalClose();
        }
    });
    if (materialCreatedToast) {
        clearLocalMaterialDraft();
    }
    const restoredDraft = !materialCreatedToast && restoreLocalMaterialDraft();
    if (restoredDraft) {
        updateReorderLevelForUnit();
        openMaterialModal();
        showDraftMessage();
    } else if (materialModal?.dataset.openOnLoad === 'true') {
        openMaterialModal();
    }
    form?.addEventListener('submit', function (event) {
        if (materialFormSubmitting) {
            event.preventDefault();
            return;
        }

        materialName.value = normalizeMaterialName(materialName.value);
        const nameValid = validateMaterialName(true, true);
        const categoryValid = validateCategory();
        const unitValid = validateUnit();
        reorderLevelTouched = true;
        const reorderValid = validateReorderLevel(true, true);
        const isValid = nameValid && categoryValid && unitValid && reorderValid;
        if (!isValid) {
            event.preventDefault();
            const invalidField = [materialName, category, unit, reorderLevel].find(function (field) {
                return field.getAttribute('aria-invalid') === 'true';
            });
            invalidField?.focus();
            return;
        }

        saveLocalMaterialDraft();
        materialFormSubmitting = true;
        if (formSubmitButton) {
            formSubmitButton.disabled = true;
            formSubmitButton.textContent = 'Adding Material...';
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

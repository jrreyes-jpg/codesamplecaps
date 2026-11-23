(function () {
    'use strict';

    const searchInput = document.querySelector('[data-inventory-report-search]');
    const reportRows = Array.from(document.querySelectorAll('[data-inventory-report-row]'));
    const noResultsRow = document.querySelector('[data-inventory-report-empty]');

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            const query = searchInput.value.trim().toLocaleLowerCase();
            let visibleCount = 0;

            reportRows.forEach(function (row) {
                const matches = query === '' || row.textContent.toLocaleLowerCase().includes(query);
                row.hidden = !matches;
                if (matches) {
                    visibleCount += 1;
                }
            });

            if (noResultsRow) {
                noResultsRow.hidden = visibleCount !== 0 || reportRows.length === 0;
            }
        });
    }

    const descriptionModal = document.querySelector('[data-material-description-modal]');
    const descriptionPanel = descriptionModal ? descriptionModal.querySelector('[role="dialog"]') : null;
    const descriptionTitle = descriptionModal ? descriptionModal.querySelector('[data-material-description-title]') : null;
    const descriptionText = descriptionModal ? descriptionModal.querySelector('[data-material-description-text]') : null;
    const descriptionCloseButtons = descriptionModal ? Array.from(descriptionModal.querySelectorAll('[data-material-description-close]')) : [];
    let descriptionReturnFocus = null;

    function closeDescriptionModal() {
        if (!descriptionModal) return;
        descriptionModal.hidden = true;
        document.body.classList.remove('material-create-modal-open');
        descriptionReturnFocus?.focus();
    }

    document.querySelectorAll('[data-material-description-open]').forEach(function (button) {
        button.addEventListener('click', function () {
            if (!descriptionModal || !descriptionTitle || !descriptionText) return;
            descriptionReturnFocus = button;
            descriptionTitle.textContent = button.dataset.materialName || 'Material Description';
            descriptionText.textContent = button.dataset.materialDescription || 'No description.';
            descriptionModal.hidden = false;
            document.body.classList.add('material-create-modal-open');
            window.requestAnimationFrame(function () { descriptionPanel?.focus(); });
        });
    });
    descriptionCloseButtons.forEach(function (button) {
        button.addEventListener('click', closeDescriptionModal);
    });
    descriptionModal?.addEventListener('click', function (event) {
        if (event.target === descriptionModal) closeDescriptionModal();
    });
    descriptionModal?.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            event.preventDefault();
            closeDescriptionModal();
        }
        if (event.key === 'Tab' && descriptionCloseButtons.length > 0) {
            event.preventDefault();
            descriptionCloseButtons[event.shiftKey ? descriptionCloseButtons.length - 1 : 0].focus();
        }
    });

    document.querySelectorAll('[data-material-archive-blocked]').forEach(function (button) {
        button.addEventListener('click', function () {
            if (typeof window.showToast === 'function') {
                window.showToast(button.dataset.materialArchiveBlocked || 'This material cannot be archived yet.', 'warning');
            }
        });
    });

    const archiveModal = document.querySelector('[data-material-archive-modal]');
    const archiveTitle = archiveModal ? archiveModal.querySelector('[data-material-confirm-title]') : null;
    const archiveMessage = archiveModal ? archiveModal.querySelector('[data-material-archive-message]') : null;
    const archiveClose = archiveModal ? archiveModal.querySelector('[data-material-archive-close]') : null;
    const archiveConfirm = archiveModal ? archiveModal.querySelector('[data-material-archive-confirm]') : null;
    let pendingArchiveForm = null;
    let archiveReturnFocus = null;
    let archiveConfirmed = false;

    function closeArchiveModal() {
        if (!archiveModal) return;
        archiveModal.hidden = true;
        document.body.classList.remove('material-create-modal-open');
        pendingArchiveForm = null;
        if (archiveReturnFocus) archiveReturnFocus.focus();
    }

    function openArchiveModal(form, message, returnFocus, title, confirmLabel) {
        if (!archiveModal || !archiveMessage) return;
        pendingArchiveForm = form;
        archiveReturnFocus = returnFocus || document.activeElement;
        archiveMessage.textContent = message;
        if (archiveTitle) archiveTitle.textContent = title || 'Archive material?';
        if (archiveConfirm) archiveConfirm.textContent = confirmLabel || 'Archive';
        archiveModal.hidden = false;
        document.body.classList.add('material-create-modal-open');
        window.requestAnimationFrame(function () { archiveClose?.focus(); });
    }

    document.querySelectorAll('[data-material-archive-form]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (archiveConfirmed) {
                archiveConfirmed = false;
                return;
            }
            event.preventDefault();
            const materialName = form.querySelector('[data-material-name]')?.dataset.materialName || 'this material';
            openArchiveModal(form, 'Archive ' + materialName + '? You can restore it later.', form.querySelector('[data-material-name]'));
        });
    });
    document.querySelectorAll('[data-material-confirm-form]').forEach(function (form) {
        form.addEventListener('submit', function (event) {
            if (archiveConfirmed) {
                archiveConfirmed = false;
                return;
            }
            event.preventDefault();
            openArchiveModal(
                form,
                form.dataset.confirmMessage || 'Continue with this action?',
                form.querySelector('button[type="submit"]'),
                form.dataset.confirmTitle || 'Confirm action?',
                form.dataset.confirmLabel || 'Confirm'
            );
        });
    });
    archiveClose?.addEventListener('click', closeArchiveModal);
    archiveModal?.addEventListener('click', function (event) {
        if (event.target === archiveModal) closeArchiveModal();
    });
    archiveConfirm?.addEventListener('click', function () {
        if (!pendingArchiveForm) return;
        archiveConfirmed = true;
        pendingArchiveForm.requestSubmit();
    });
    const menuToggles = Array.from(document.querySelectorAll('[data-material-menu-toggle]'));

    function closeRowMenus(exceptToggle) {
        menuToggles.forEach(function (toggle) {
            if (toggle === exceptToggle) return;
            toggle.setAttribute('aria-expanded', 'false');
            const menu = toggle.closest('[data-material-row-menu]');
            const panel = menu ? menu.querySelector('[data-material-menu-panel]') : null;
            if (panel) panel.hidden = true;
        });
    }

    function placeRowMenu(toggle, panel) {
        panel.hidden = false;
        const toggleRect = toggle.getBoundingClientRect();
        const panelRect = panel.getBoundingClientRect();
        const left = Math.max(12, Math.min(toggleRect.right - panelRect.width, window.innerWidth - panelRect.width - 12));
        let top = toggleRect.bottom + 6;
        if (top + panelRect.height > window.innerHeight - 12) {
            top = Math.max(12, toggleRect.top - panelRect.height - 6);
        }
        panel.style.left = left + 'px';
        panel.style.top = top + 'px';
    }

    function rowMenuItems(panel) {
        return Array.from(panel.querySelectorAll('[role="menuitem"]:not(:disabled)'));
    }

    menuToggles.forEach(function (toggle) {
        toggle.addEventListener('click', function (event) {
            event.stopPropagation();
            const menu = toggle.closest('[data-material-row-menu]');
            const panel = menu ? menu.querySelector('[data-material-menu-panel]') : null;
            if (!panel) return;
            const willOpen = panel.hidden;
            closeRowMenus(toggle);
            panel.hidden = !willOpen;
            toggle.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
            if (willOpen) placeRowMenu(toggle, panel);
        });
        toggle.addEventListener('keydown', function (event) {
            if (event.key !== 'ArrowDown' && event.key !== 'ArrowUp') return;
            event.preventDefault();
            const menu = toggle.closest('[data-material-row-menu]');
            const panel = menu ? menu.querySelector('[data-material-menu-panel]') : null;
            if (!panel) return;
            closeRowMenus(toggle);
            placeRowMenu(toggle, panel);
            toggle.setAttribute('aria-expanded', 'true');
            const items = rowMenuItems(panel);
            const target = event.key === 'ArrowUp' ? items[items.length - 1] : items[0];
            if (target) target.focus();
        });
    });

    document.querySelectorAll('[data-material-menu-panel]').forEach(function (panel) {
        panel.addEventListener('keydown', function (event) {
            const items = rowMenuItems(panel);
            const currentIndex = items.indexOf(document.activeElement);
            if (items.length === 0 || currentIndex === -1) return;
            let nextIndex = null;
            if (event.key === 'ArrowDown') nextIndex = (currentIndex + 1) % items.length;
            if (event.key === 'ArrowUp') nextIndex = (currentIndex - 1 + items.length) % items.length;
            if (event.key === 'Home') nextIndex = 0;
            if (event.key === 'End') nextIndex = items.length - 1;
            if (nextIndex === null) return;
            event.preventDefault();
            items[nextIndex].focus();
        });
    });

    document.addEventListener('click', function () {
        closeRowMenus();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape' || (removeModal && !removeModal.hidden)) return;
        if (archiveModal && !archiveModal.hidden) {
            closeArchiveModal();
            return;
        }
        const openToggle = document.querySelector('[data-material-menu-toggle][aria-expanded="true"]');
        closeRowMenus();
        if (openToggle) openToggle.focus();
    });
    window.addEventListener('resize', function () { closeRowMenus(); });
    window.addEventListener('scroll', function () { closeRowMenus(); }, true);

    const removeModal = document.querySelector('[data-material-remove-modal]');
    const removeMessage = removeModal ? removeModal.querySelector('[data-material-remove-message]') : null;
    const removeClose = removeModal ? removeModal.querySelector('[data-material-remove-close]') : null;
    const removeConfirm = removeModal ? removeModal.querySelector('[data-material-remove-confirm]') : null;
    let pendingRemoveForm = null;
    let removeReturnFocus = null;
    let removeSubmitting = false;

    function closeRemoveModal() {
        if (!removeModal || removeSubmitting) return;
        removeModal.hidden = true;
        document.body.classList.remove('material-create-modal-open');
        pendingRemoveForm = null;
        if (removeReturnFocus) removeReturnFocus.focus();
    }

    document.querySelectorAll('[data-material-remove-open]').forEach(function (button) {
        button.addEventListener('click', function () {
            if (!removeModal || !removeMessage) return;
            pendingRemoveForm = button.closest('form');
            removeReturnFocus = button.closest('[data-material-row-menu]')?.querySelector('[data-material-menu-toggle]') || button;
            removeMessage.textContent = 'Remove ' + (button.dataset.materialName || 'this material') + '? This cannot be undone.';
            closeRowMenus();
            removeModal.hidden = false;
            document.body.classList.add('material-create-modal-open');
            window.requestAnimationFrame(function () { removeClose.focus(); });
        });
    });

    if (removeClose) removeClose.addEventListener('click', closeRemoveModal);
    if (removeModal) {
        removeModal.addEventListener('click', function (event) {
            if (event.target === removeModal) closeRemoveModal();
        });
        removeModal.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                closeRemoveModal();
            }
            if (event.key === 'Tab' && removeClose && removeConfirm) {
                if (event.shiftKey && document.activeElement === removeClose) {
                    event.preventDefault();
                    removeConfirm.focus();
                } else if (!event.shiftKey && document.activeElement === removeConfirm) {
                    event.preventDefault();
                    removeClose.focus();
                }
            }
        });
    }
    if (removeConfirm) {
        removeConfirm.addEventListener('click', function () {
            if (!pendingRemoveForm || removeSubmitting) return;
            removeSubmitting = true;
            removeConfirm.disabled = true;
            removeClose.disabled = true;
            removeConfirm.textContent = 'Removing...';
            pendingRemoveForm.requestSubmit();
        });
    }

    const modal = document.querySelector('[data-material-modal]');
    const openButton = document.querySelector('[data-material-modal-open]');
    if (!modal || !openButton) {
        return;
    }

    const panel = modal.querySelector('[role="dialog"]');
    const closeButtons = Array.from(modal.querySelectorAll('[data-material-modal-close]'));
    const form = modal.querySelector('[data-material-create-form]');
    const submitButton = modal.querySelector('[data-material-submit]');
    const generalError = modal.querySelector('[data-material-general-error]');
    const discardModal = document.querySelector('[data-material-discard-modal]');
    const discardContinue = discardModal?.querySelector('[data-material-discard-continue]');
    const discardConfirm = discardModal?.querySelector('[data-material-discard-confirm]');
    const modalTitle = modal.querySelector('#materialCreateModalTitle');
    const formAction = form ? form.querySelector('[data-material-form-action]') : null;
    const formId = form ? form.querySelector('[data-material-form-id]') : null;
    const requestToken = form ? form.querySelector('[data-material-request-token]') : null;
    const lockedUnit = form ? form.querySelector('[data-material-locked-unit]') : null;
    const unitLockReason = modal.querySelector('[data-material-unit-lock]');
    const specificCategoryGroup = modal.querySelector('[data-material-specific-category-field]');
    const editButtons = Array.from(document.querySelectorAll('[data-material-edit-open]'));
    const tooltipWrappers = Array.from(modal.querySelectorAll('[data-material-tooltip]'));
    const fields = form ? {
        material_name: form.querySelector('#material_name'),
        category: form.querySelector('#category'),
        specific_category: form.querySelector('#specific_category'),
        unit: form.querySelector('#unit'),
        reorder_level: form.querySelector('#reorder_level'),
        maximum_stock_level: form.querySelector('#maximum_stock_level'),
        description: form.querySelector('#description')
    } : {};
    const fieldErrors = {};
    modal.querySelectorAll('[data-material-field-error]').forEach(function (error) {
        fieldErrors[error.dataset.materialFieldError] = error;
    });
    let returnFocus = openButton;
    let isSubmitting = false;

    const focusableSelector = 'button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [href], [tabindex]:not([tabindex="-1"])';
    const addDraftFieldNames = ['material_name', 'category', 'specific_category', 'unit', 'reorder_level', 'maximum_stock_level', 'description'];
    const draftScope = String(modal.dataset.draftScope || 'unknown');
    const draftKeyPrefix = 'edge:admin:add-material-draft:';
    const draftScopeMarker = 'edge:admin:add-material-draft-scope';
    const addDraftKey = draftKeyPrefix + draftScope;
    const addModalOpenKey = 'edge:admin:add-material-modal-open:' + draftScope;
    let materialNameIndex = [];
    try {
        const parsedIndex = JSON.parse(modal.dataset.materialNameIndex || '[]');
        materialNameIndex = Array.isArray(parsedIndex) ? parsedIndex : [];
    } catch (error) {
        materialNameIndex = [];
    }

    function isAddMode() {
        return Boolean(formAction && formAction.value === 'create');
    }

    function clearStoredAddDraft() {
        try {
            window.sessionStorage.removeItem(addDraftKey);
        } catch (error) {
            // Tuloy pa rin ang form kapag hindi available ang browser storage.
        }
    }

    function setStoredModalOpen(isOpen) {
        try {
            if (isOpen) window.sessionStorage.setItem(addModalOpenKey, 'true');
            else window.sessionStorage.removeItem(addModalOpenKey);
        } catch (error) {
            // Tuloy pa rin ang modal kapag hindi available ang browser storage.
        }
    }

    function wasModalOpenBeforeReload() {
        try {
            return window.sessionStorage.getItem(addModalOpenKey) === 'true';
        } catch (error) {
            return false;
        }
    }

    function prepareDraftStorage() {
        try {
            const previousScope = window.sessionStorage.getItem(draftScopeMarker);
            if (previousScope && previousScope !== draftScope) {
                window.sessionStorage.removeItem(draftKeyPrefix + previousScope);
                window.sessionStorage.removeItem('edge:admin:add-material-modal-open:' + previousScope);
            }
            window.sessionStorage.setItem(draftScopeMarker, draftScope);
        } catch (error) {
            // Tuloy pa rin ang form kapag hindi available ang browser storage.
        }
    }

    function saveAddDraft() {
        if (!isAddMode()) return;
        const draft = {};
        let hasValue = false;
        addDraftFieldNames.forEach(function (name) {
            const value = fields[name] ? fields[name].value : '';
            draft[name] = value;
            if (value.trim() !== '') hasValue = true;
        });
        try {
            if (hasValue) window.sessionStorage.setItem(addDraftKey, JSON.stringify(draft));
            else window.sessionStorage.removeItem(addDraftKey);
        } catch (error) {
            // Nasa form pa rin ang values kahit hindi ma-save sa browser storage.
        }
    }

    function readAddDraft() {
        try {
            const draft = JSON.parse(window.sessionStorage.getItem(addDraftKey) || 'null');
            return draft && typeof draft === 'object' ? draft : null;
        } catch (error) {
            clearStoredAddDraft();
            return null;
        }
    }

    function fieldDescriptionIds(name, includeError) {
        const ids = [];
        if (name === 'unit' && unitLockReason && !unitLockReason.hidden) ids.push(unitLockReason.id);
        if (includeError && fieldErrors[name]) ids.push(fieldErrors[name].id);
        return ids.join(' ');
    }

    function setFieldError(name, message) {
        const field = fields[name];
        const error = fieldErrors[name];
        if (!field || !error) return;
        const hasError = message !== '';
        error.textContent = message;
        error.hidden = !hasError;
        if (hasError) {
            field.setAttribute('aria-invalid', 'true');
        } else {
            field.removeAttribute('aria-invalid');
        }
        const describedBy = fieldDescriptionIds(name, hasError);
        if (describedBy) {
            field.setAttribute('aria-describedby', describedBy);
        } else {
            field.removeAttribute('aria-describedby');
        }
    }

    function selectedUnitNeedsWholeNumber() {
        const option = fields.unit && fields.unit.selectedOptions ? fields.unit.selectedOptions[0] : null;
        return Boolean(option && option.dataset.wholeNumber === 'true');
    }

    function normalizeMaterialName(value) {
        return value.trim().replace(/\s+/gu, ' ').toLocaleLowerCase();
    }

    function findDuplicateMaterial(value) {
        const identity = normalizeMaterialName(value);
        const currentId = Number(formId?.value || 0);
        if (identity === '') return null;
        return materialNameIndex.find(function (material) {
            return Number(material.id || 0) !== currentId
                && normalizeMaterialName(String(material.name || '')) === identity;
        }) || null;
    }

    function validateField(name) {
        const field = fields[name];
        if (!field) return true;
        const value = field.value.trim();
        let message = '';

        if (name === 'material_name') {
            const normalized = value.replace(/\s+/gu, ' ');
            if (normalized === '') {
                message = 'Enter a material name.';
            } else if (Array.from(normalized).length > 180) {
                message = 'Material name must be 180 characters or less.';
            } else if (!/[\p{L}\p{N}]/u.test(normalized) || /\p{C}/u.test(normalized)) {
                message = 'Enter a valid material name.';
            } else {
                const duplicate = findDuplicateMaterial(normalized);
                if (duplicate) {
                    const detail = [duplicate.category, duplicate.unit].filter(Boolean).join(' • ');
                    message = 'Material already exists: ' + duplicate.name
                        + (detail ? ' (' + detail + ')' : '')
                        + (duplicate.status === 'inactive'
                            ? '. Restore the archived material.'
                            : '. Edit it or log a delivery receipt.');
                }
            }
        } else if (name === 'category') {
            const valid = Array.from(field.options).some(function (option) {
                return !option.disabled && option.value === value;
            });
            if (!valid) message = 'Select a category.';
        } else if (name === 'specific_category') {
            if (fields.category.value === 'Other') {
                const normalized = value.replace(/\s+/gu, ' ');
                if (normalized === '') {
                    message = 'Enter the specific category.';
                } else if (Array.from(normalized).length > 80) {
                    message = 'Specific category must be 80 characters or less.';
                } else if (!/[\p{L}\p{N}]/u.test(normalized) || /\p{C}/u.test(normalized)) {
                    message = 'Enter a valid specific category.';
                }
            }
        } else if (name === 'unit') {
            const valid = Array.from(field.options).some(function (option) {
                return !option.disabled && option.value === value;
            });
            if (!valid) message = 'Select a unit.';
        } else if (name === 'reorder_level' || name === 'maximum_stock_level') {
            if (value === '' || /^-/.test(value)) {
                message = name === 'maximum_stock_level'
                    ? 'Enter a maximum stock level greater than zero.'
                    : 'Enter a low stock alert level greater than zero.';
            } else if (!/^\d+(?:\.\d+)?$/.test(value)) {
                message = 'Enter a valid number.';
            } else {
                const parts = value.split('.');
                const fraction = parts[1] || '';
                const unit = fields.unit.value || 'this unit';
                const integer = parts[0].replace(/^0+(?=\d)/, '');
                if (fraction.length > 2) {
                    message = 'For ' + unit + ', use no more than 2 decimal places.';
                } else if (selectedUnitNeedsWholeNumber() && fraction.replace(/0/g, '') !== '') {
                    message = 'For ' + unit + ', enter a whole number.';
                } else if (integer.length > 10 || (integer.length === 10 && integer > '9999999999')) {
                    const maximum = selectedUnitNeedsWholeNumber() ? '9,999,999,999' : '9,999,999,999.99';
                    message = 'Maximum allowed value is ' + maximum + ' ' + unit + '.';
                } else if (name === 'reorder_level' && Number(value) <= 0) {
                    message = 'Enter a low stock alert level greater than zero.';
                } else if (name === 'maximum_stock_level' && Number(value) <= 0) {
                    message = 'Enter a maximum stock level greater than zero.';
                } else if (name === 'maximum_stock_level' && Number(value) <= Number(fields.reorder_level.value || 0)) {
                    message = 'Maximum stock must be greater than the low stock alert level.';
                }
            }
        } else if (name === 'description') {
            if (Array.from(field.value).length > 255) {
                message = 'Description must be 255 characters or less.';
            } else if (/\p{C}/u.test(field.value.replace(/[\r\n\t]/gu, ''))) {
                message = 'Description contains invalid characters.';
            }
        }

        setFieldError(name, message);
        if (name === 'material_name' && submitButton) {
            submitButton.disabled = Boolean(findDuplicateMaterial(value));
        }
        return message === '';
    }

    function validateForm() {
        const order = ['material_name', 'category', 'specific_category', 'unit', 'reorder_level', 'maximum_stock_level', 'description'];
        let firstInvalid = null;
        order.forEach(function (name) {
            if (!validateField(name) && !firstInvalid) firstInvalid = fields[name];
        });
        return firstInvalid;
    }

    function updateReorderStep() {
        const wholeNumber = selectedUnitNeedsWholeNumber();
        ['reorder_level', 'maximum_stock_level'].forEach(function (name) {
            if (fields[name]) {
                const field = fields[name];
                field.step = wholeNumber ? '1' : '0.01';
                field.min = wholeNumber ? '1' : '0.01';
                field.max = wholeNumber ? '9999999999' : '9999999999.99';
            }
        });
    }

    function updateSpecificCategoryField() {
        if (!specificCategoryGroup || !fields.specific_category || !fields.category) return;
        const isOther = fields.category.value === 'Other';
        specificCategoryGroup.hidden = !isOther;
        fields.specific_category.disabled = !isOther;
        fields.specific_category.required = isOther;
        if (!isOther) {
            fields.specific_category.value = '';
            setFieldError('specific_category', '');
        }
    }

    function positionTooltip(wrapper) {
        const button = wrapper.querySelector('button');
        const tooltip = wrapper.querySelector('[role="tooltip"]');
        if (!button || !tooltip || !wrapper.classList.contains('is-open')) return;

        const edge = 16;
        const gap = 8;
        const buttonRect = button.getBoundingClientRect();
        const width = Math.min(310, Math.max(180, window.innerWidth - (edge * 2)));
        tooltip.style.width = width + 'px';
        tooltip.style.left = Math.max(edge, Math.min(buttonRect.left, window.innerWidth - width - edge)) + 'px';
        tooltip.style.top = '0px';
        const tooltipHeight = tooltip.getBoundingClientRect().height;
        const fitsBelow = buttonRect.bottom + gap + tooltipHeight <= window.innerHeight - edge;
        const top = fitsBelow
            ? buttonRect.bottom + gap
            : Math.max(edge, buttonRect.top - gap - tooltipHeight);
        tooltip.style.top = top + 'px';
    }

    function openTooltip(wrapper) {
        closeTooltips(false);
        wrapper.classList.add('is-open');
        const button = wrapper.querySelector('button');
        if (button) button.setAttribute('aria-expanded', 'true');
        positionTooltip(wrapper);
    }

    function closeTooltips(returnFocus) {
        tooltipWrappers.forEach(function (wrapper) {
            if (!wrapper.classList.contains('is-open')) return;
            wrapper.classList.remove('is-open');
            const button = wrapper.querySelector('button');
            if (button) {
                button.setAttribute('aria-expanded', 'false');
                if (returnFocus) button.focus();
            }
        });
    }

    function configureUnitLock(isEditable) {
        if (!fields.unit || !lockedUnit || !unitLockReason) return;
        fields.unit.disabled = !isEditable;
        lockedUnit.disabled = isEditable;
        lockedUnit.value = isEditable ? '' : fields.unit.value;
        unitLockReason.hidden = isEditable;
        const hasError = fields.unit.hasAttribute('aria-invalid');
        const describedBy = fieldDescriptionIds('unit', hasError);
        if (describedBy) fields.unit.setAttribute('aria-describedby', describedBy);
        else fields.unit.removeAttribute('aria-describedby');
    }

    function setFormMode(mode, data) {
        const isEdit = mode === 'edit';
        if (modalTitle) modalTitle.textContent = isEdit ? 'Edit Material' : 'Add Material';
        if (formAction) formAction.value = isEdit ? 'update' : 'create';
        if (formId) formId.value = isEdit ? String(data.materialId || '') : '';
        if (requestToken) requestToken.value = isEdit ? modal.dataset.updateToken : modal.dataset.createToken;
        if (submitButton) submitButton.textContent = isEdit ? 'Save Changes' : 'Add Material';

        if (isEdit) {
            fields.material_name.value = data.materialName || '';
            fields.description.value = data.materialDescription || '';
            fields.category.value = data.materialCategory || '';
            fields.specific_category.value = data.materialSpecificCategory || '';
            fields.unit.value = data.materialUnit || '';
            fields.reorder_level.value = data.materialReorder || '';
            fields.maximum_stock_level.value = data.materialMaximum || '';
            configureUnitLock(data.unitEditable === 'true');
        } else {
            configureUnitLock(true);
        }
        updateSpecificCategoryField();
        updateReorderStep();
    }

    function clearAddForm(clearDraft) {
        if (!form) return;
        ['material_name', 'specific_category', 'maximum_stock_level', 'description'].forEach(function (name) {
            if (fields[name]) fields[name].value = '';
        });
        if (fields.reorder_level) fields.reorder_level.value = '';
        ['category', 'unit'].forEach(function (name) {
            if (fields[name]) fields[name].selectedIndex = 0;
        });
        Object.keys(fields).forEach(function (name) {
            if (fields[name]) delete fields[name].dataset.materialDirty;
        });
        Object.keys(fieldErrors).forEach(function (name) { setFieldError(name, ''); });
        if (generalError) generalError.hidden = true;
        closeTooltips(false);
        configureUnitLock(true);
        updateSpecificCategoryField();
        updateReorderStep();
        if (clearDraft) clearStoredAddDraft();
    }

    function restoreAddDraft() {
        const draft = readAddDraft();
        if (!draft) return;

        ['material_name', 'specific_category', 'reorder_level', 'maximum_stock_level', 'description'].forEach(function (name) {
            if (fields[name] && typeof draft[name] === 'string') fields[name].value = draft[name];
        });
        ['category', 'unit'].forEach(function (name) {
            if (!fields[name] || typeof draft[name] !== 'string') return;
            const hasOption = Array.from(fields[name].options).some(function (option) {
                return !option.disabled && option.value === draft[name];
            });
            fields[name].value = hasOption ? draft[name] : '';
        });
        updateSpecificCategoryField();
        updateReorderStep();
        addDraftFieldNames.forEach(function (name) {
            if (!fields[name] || fields[name].value.trim() === '') return;
            fields[name].dataset.materialDirty = 'true';
            validateField(name);
        });
    }

    function resetMaterialForm() {
        if (!form) return;
        form.reset();
        clearAddForm(false);
        closeButtons.forEach(function (button) { button.disabled = false; });
        if (submitButton) submitButton.disabled = false;
        setFormMode('add', {});
    }

    function openModal(showServerError, preferredReturnFocus) {
        returnFocus = preferredReturnFocus || (document.activeElement instanceof HTMLElement ? document.activeElement : openButton);
        modal.hidden = false;
        setStoredModalOpen(true);
        document.body.classList.add('material-create-modal-open');
        window.requestAnimationFrame(function () {
            const firstInvalid = showServerError ? form.querySelector('[aria-invalid="true"]') : null;
            const target = firstInvalid || (showServerError ? generalError : null) || panel;
            if (target) {
                target.focus({ preventScroll: true });
                if (showServerError && target !== panel) {
                    target.scrollIntoView({ block: 'center', behavior: 'smooth' });
                }
            }
        });
    }

    function hasUnsavedAddValues() {
        if (!isAddMode()) return false;
        return addDraftFieldNames.some(function (name) {
            const value = fields[name] ? fields[name].value.trim() : '';
            return name === 'reorder_level' ? value !== '' && value !== '0' : value !== '';
        });
    }

    function finishCloseModal(discardAddValues) {
        if (isSubmitting) {
            return;
        }
        const wasAddMode = isAddMode();
        if (wasAddMode && discardAddValues) clearAddForm(true);
        modal.hidden = true;
        setStoredModalOpen(false);
        document.body.classList.remove('material-create-modal-open');
        if (!wasAddMode) resetMaterialForm();
        if (returnFocus && typeof returnFocus.focus === 'function') {
            returnFocus.focus();
        }
    }

    function closeModal() {
        if (hasUnsavedAddValues() && discardModal && discardContinue && discardConfirm) {
            discardModal.hidden = false;
            discardContinue.focus();
            return;
        }
        finishCloseModal(false);
    }

    openButton.addEventListener('click', function () {
        resetMaterialForm();
        restoreAddDraft();
        openModal(false);
    });
    editButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            const menuToggle = button.closest('[data-material-row-menu]')?.querySelector('[data-material-menu-toggle]');
            resetMaterialForm();
            closeRowMenus();
            setFormMode('edit', button.dataset);
            openModal(false, menuToggle || button);
        });
    });
    closeButtons.forEach(function (button) {
        button.addEventListener('click', closeModal);
    });
    discardContinue?.addEventListener('click', function () {
        discardModal.hidden = true;
        panel?.focus();
    });
    discardConfirm?.addEventListener('click', function () {
        discardModal.hidden = true;
        finishCloseModal(true);
    });

    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeModal();
        }
    });

    modal.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            const hasOpenTooltip = tooltipWrappers.some(function (wrapper) {
                return wrapper.classList.contains('is-open');
            });
            event.preventDefault();
            if (hasOpenTooltip) {
                closeTooltips(true);
                return;
            }
            closeModal();
            return;
        }
        if (event.key !== 'Tab') {
            return;
        }

        const focusable = Array.from(modal.querySelectorAll(focusableSelector));
        if (focusable.length === 0) {
            event.preventDefault();
            return;
        }
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    if (form) {
        Object.keys(fields).forEach(function (name) {
            const field = fields[name];
            const eventName = field instanceof HTMLSelectElement ? 'change' : 'input';
            field.addEventListener(eventName, function () {
                field.dataset.materialDirty = 'true';
                if (name === 'unit') {
                    updateReorderStep();
                    if (fields.reorder_level.value.trim() !== '') {
                        validateField('reorder_level');
                    }
                    if (fields.maximum_stock_level.value.trim() !== '') {
                        validateField('maximum_stock_level');
                    } else if (fields.maximum_stock_level.dataset.materialDirty !== 'true') {
                        setFieldError('maximum_stock_level', '');
                    }
                }
                if (name === 'category') {
                    updateSpecificCategoryField();
                    if (fields.category.value === 'Other') validateField('specific_category');
                }
                validateField(name);
                if (name === 'reorder_level') {
                    if (fields.maximum_stock_level.value.trim() !== ''
                        || fields.maximum_stock_level.dataset.materialDirty === 'true') {
                        validateField('maximum_stock_level');
                    } else {
                        setFieldError('maximum_stock_level', '');
                    }
                }
                saveAddDraft();
            });
            field.addEventListener('blur', function () {
                if (field.dataset.materialDirty === 'true') validateField(name);
            });
        });

        form.addEventListener('submit', function (event) {
            if (isSubmitting) {
                event.preventDefault();
                return;
            }
            const firstInvalid = validateForm();
            if (firstInvalid) {
                event.preventDefault();
                firstInvalid.focus({ preventScroll: true });
                firstInvalid.scrollIntoView({ block: 'center', behavior: 'smooth' });
                return;
            }
            isSubmitting = true;
            saveAddDraft();
            closeButtons.forEach(function (button) {
                button.disabled = true;
            });
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.textContent = 'Saving...';
            }
            if (window.edgeActionLoading) {
                window.edgeActionLoading.show({
                    title: isAddMode() ? 'Adding material...' : 'Saving material...',
                    message: 'Please wait while the inventory is updated.'
                });
            }
        });
    }

    tooltipWrappers.forEach(function (wrapper) {
        const button = wrapper.querySelector('button');
        if (!button) return;
        wrapper.addEventListener('pointerenter', function (event) {
            if (event.pointerType === 'mouse') openTooltip(wrapper);
        });
        wrapper.addEventListener('pointerleave', function (event) {
            if (event.pointerType === 'mouse' && document.activeElement !== button) closeTooltips(false);
        });
        button.addEventListener('pointerdown', function () {
            wrapper.dataset.pointerFocus = 'true';
        });
        button.addEventListener('focus', function () {
            if (wrapper.dataset.pointerFocus === 'true') return;
            openTooltip(wrapper);
        });
        button.addEventListener('blur', function () {
            delete wrapper.dataset.pointerFocus;
            wrapper.classList.remove('is-open');
            button.setAttribute('aria-expanded', 'false');
        });
        button.addEventListener('click', function (event) {
            event.stopPropagation();
            delete wrapper.dataset.pointerFocus;
            if (event.detail === 0 && wrapper.classList.contains('is-open')) return;
            const willOpen = !wrapper.classList.contains('is-open');
            if (willOpen) openTooltip(wrapper);
            else closeTooltips(false);
        });
    });
    document.addEventListener('click', function () { closeTooltips(false); });
    window.addEventListener('resize', function () {
        tooltipWrappers.forEach(positionTooltip);
    });
    modal.addEventListener('scroll', function () {
        tooltipWrappers.forEach(positionTooltip);
    }, true);
    window.addEventListener('edge:logout-complete', function () {
        clearStoredAddDraft();
        setStoredModalOpen(false);
        try {
            window.sessionStorage.removeItem(draftScopeMarker);
        } catch (error) {
            // Wala nang gagawin kapag sarado ang browser storage.
        }
    });

    prepareDraftStorage();
    const navigationEntry = window.performance?.getEntriesByType?.('navigation')?.[0];
    const isPageReload = navigationEntry?.type === 'reload';
    const restoreOpenModal = isPageReload && wasModalOpenBeforeReload();
    if (modal.dataset.createSucceeded === 'true') {
        clearStoredAddDraft();
        setStoredModalOpen(false);
    }
    updateReorderStep();
    if (fields.material_name?.value.trim() !== '') validateField('material_name');
    if (modal.dataset.autoOpen === 'true') {
        if (modal.dataset.autoMode === 'add') saveAddDraft();
        openModal(true);
    } else if (restoreOpenModal && modal.dataset.createSucceeded !== 'true') {
        resetMaterialForm();
        restoreAddDraft();
        openModal(false);
    } else {
        setStoredModalOpen(false);
    }
})();

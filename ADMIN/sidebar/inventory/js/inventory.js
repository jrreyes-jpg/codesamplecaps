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
    const modalTitle = modal.querySelector('#materialCreateModalTitle');
    const formAction = form ? form.querySelector('[data-material-form-action]') : null;
    const formId = form ? form.querySelector('[data-material-form-id]') : null;
    const requestToken = form ? form.querySelector('[data-material-request-token]') : null;
    const lockedUnit = form ? form.querySelector('[data-material-locked-unit]') : null;
    const unitLockReason = modal.querySelector('[data-material-unit-lock]');
    const editButtons = Array.from(document.querySelectorAll('[data-material-edit-open]'));
    const tooltipWrappers = Array.from(modal.querySelectorAll('[data-material-tooltip]'));
    const fields = form ? {
        material_name: form.querySelector('#material_name'),
        category: form.querySelector('#category'),
        unit: form.querySelector('#unit'),
        reorder_level: form.querySelector('#reorder_level'),
        description: form.querySelector('#description')
    } : {};
    const fieldErrors = {};
    modal.querySelectorAll('[data-material-field-error]').forEach(function (error) {
        fieldErrors[error.dataset.materialFieldError] = error;
    });
    let returnFocus = openButton;
    let isSubmitting = false;

    const focusableSelector = 'button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [href], [tabindex]:not([tabindex="-1"])';

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
            }
        } else if (name === 'category') {
            const valid = Array.from(field.options).some(function (option) {
                return !option.disabled && option.value === value;
            });
            if (!valid) message = 'Select a category.';
        } else if (name === 'unit') {
            const valid = Array.from(field.options).some(function (option) {
                return !option.disabled && option.value === value;
            });
            if (!valid) message = 'Select a unit.';
        } else if (name === 'reorder_level') {
            if (value === '' || /^-/.test(value)) {
                message = 'Enter zero or a positive number.';
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
                    message = 'For ' + unit + ', enter a whole number such as 0, 1, or 2.';
                } else if (integer.length > 10 || (integer.length === 10 && integer > '9999999999')) {
                    const maximum = selectedUnitNeedsWholeNumber() ? '9,999,999,999' : '9,999,999,999.99';
                    message = 'Maximum allowed value is ' + maximum + ' ' + unit + '.';
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
        return message === '';
    }

    function validateForm() {
        const order = ['material_name', 'category', 'unit', 'reorder_level', 'description'];
        let firstInvalid = null;
        order.forEach(function (name) {
            if (!validateField(name) && !firstInvalid) firstInvalid = fields[name];
        });
        return firstInvalid;
    }

    function updateReorderStep() {
        if (fields.reorder_level) {
            const wholeNumber = selectedUnitNeedsWholeNumber();
            fields.reorder_level.step = wholeNumber ? '1' : '0.01';
            fields.reorder_level.max = wholeNumber ? '9999999999' : '9999999999.99';
        }
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
            fields.unit.value = data.materialUnit || '';
            fields.reorder_level.value = data.materialReorder || '';
            configureUnitLock(data.unitEditable === 'true');
        } else {
            configureUnitLock(true);
        }
        updateReorderStep();
    }

    function resetMaterialForm() {
        if (!form) return;
        form.reset();
        ['material_name', 'reorder_level', 'description'].forEach(function (name) {
            if (fields[name]) fields[name].value = '';
        });
        ['category', 'unit'].forEach(function (name) {
            if (fields[name]) fields[name].selectedIndex = 0;
        });
        Object.keys(fields).forEach(function (name) {
            if (fields[name]) delete fields[name].dataset.materialDirty;
        });
        Object.keys(fieldErrors).forEach(function (name) { setFieldError(name, ''); });
        if (generalError) generalError.hidden = true;
        closeTooltips(false);
        closeButtons.forEach(function (button) { button.disabled = false; });
        if (submitButton) submitButton.disabled = false;
        setFormMode('add', {});
    }

    function openModal(showServerError, preferredReturnFocus) {
        returnFocus = preferredReturnFocus || (document.activeElement instanceof HTMLElement ? document.activeElement : openButton);
        modal.hidden = false;
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

    function closeModal() {
        if (isSubmitting) {
            return;
        }
        modal.hidden = true;
        document.body.classList.remove('material-create-modal-open');
        resetMaterialForm();
        if (returnFocus && typeof returnFocus.focus === 'function') {
            returnFocus.focus();
        }
    }

    openButton.addEventListener('click', function () { openModal(false); });
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
                    if (fields.reorder_level.hasAttribute('aria-invalid')) validateField('reorder_level');
                }
                if (field.hasAttribute('aria-invalid')) validateField(name);
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
            closeButtons.forEach(function (button) {
                button.disabled = true;
            });
            if (submitButton) {
                submitButton.disabled = true;
                submitButton.textContent = 'Saving...';
            }
        });
    }

    tooltipWrappers.forEach(function (wrapper) {
        const button = wrapper.querySelector('button');
        if (!button) return;
        button.addEventListener('pointerdown', function () {
            wrapper.dataset.pointerFocus = 'true';
        });
        button.addEventListener('focus', function () {
            if (wrapper.dataset.pointerFocus === 'true') return;
            closeTooltips(false);
            wrapper.classList.add('is-open');
            button.setAttribute('aria-expanded', 'true');
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
            closeTooltips(false);
            wrapper.classList.toggle('is-open', willOpen);
            button.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
        });
    });
    document.addEventListener('click', function () { closeTooltips(false); });

    updateReorderStep();
    if (modal.dataset.autoOpen === 'true') {
        openModal(true);
    }
})();

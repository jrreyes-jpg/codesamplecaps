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
    const fields = form ? {
        material_name: form.elements.namedItem('material_name'),
        category: form.elements.namedItem('category'),
        unit: form.elements.namedItem('unit'),
        reorder_level: form.elements.namedItem('reorder_level'),
        description: form.elements.namedItem('description')
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
        if (name === 'reorder_level') ids.push('materialReorderHint');
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
            if (value === '' || (Number.isFinite(Number(value)) && Number(value) < 0)) {
                message = 'Enter zero or a positive number.';
            } else if (!/^\d+(?:\.\d{1,2})?$/.test(value)) {
                message = 'Enter zero or a positive number with up to 2 decimal places.';
            } else {
                const number = Number(value);
                if (!Number.isFinite(number) || number < 0) {
                    message = 'Enter zero or a positive number.';
                } else if (number > 9999999999.99) {
                    message = 'Enter a value not greater than 9,999,999,999.99.';
                } else if (selectedUnitNeedsWholeNumber() && !Number.isInteger(number)) {
                    message = 'This unit requires a whole number.';
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
            fields.reorder_level.step = selectedUnitNeedsWholeNumber() ? '1' : '0.01';
        }
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
        Object.keys(fieldErrors).forEach(function (name) { setFieldError(name, ''); });
        if (generalError) generalError.hidden = true;
        updateReorderStep();
    }

    function openModal(showServerError) {
        returnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : openButton;
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
            event.preventDefault();
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
                if (name === 'unit') {
                    updateReorderStep();
                    if (fields.reorder_level.hasAttribute('aria-invalid')) validateField('reorder_level');
                }
                if (field.hasAttribute('aria-invalid')) validateField(name);
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
                submitButton.textContent = 'Saving…';
            }
        });
    }

    updateReorderStep();
    if (modal.dataset.autoOpen === 'true') {
        openModal(true);
    }
})();

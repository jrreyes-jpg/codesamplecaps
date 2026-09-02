(() => {
    const supplierForm = document.querySelector('[data-supplier-form]');
    const supplierTrashForms = document.querySelectorAll('[data-supplier-trash-confirm]');

    supplierTrashForms.forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm('Move this supplier to trash bin?')) {
                event.preventDefault();
            }
        });
    });

    if (!supplierForm) {
        return;
    }

    const storageKey = 'engineer_procurement_supplier_form';
    const clearButton = supplierForm.querySelector('[data-supplier-clear]');
    const phoneField = supplierForm.querySelector('[data-phone-field]');
    const draftFields = Array.from(supplierForm.querySelectorAll('[data-supplier-draft-field]'));
    const formMode = supplierForm.getAttribute('data-supplier-form-mode') || 'create';
    const formSupplierId = supplierForm.getAttribute('data-supplier-form-id') || '0';

    const applyPhoneValidation = () => {
        if (!phoneField) {
            return;
        }

        const normalized = (phoneField.value || '').replace(/\D+/g, '').slice(0, 11);
        phoneField.value = normalized;

        if (normalized === '') {
            phoneField.setCustomValidity('');
            phoneField.removeAttribute('data-validation-state');
            return;
        }

        if (!/^09\d{9}$/.test(normalized)) {
            phoneField.setCustomValidity('Contact number must start with 09 and contain exactly 11 digits.');
            phoneField.setAttribute('data-validation-state', 'invalid');
            return;
        }

        phoneField.setCustomValidity('');
        phoneField.setAttribute('data-validation-state', 'valid');
    };

    const syncFieldState = (field) => {
        if (!field) {
            return;
        }

        if (field === phoneField) {
            applyPhoneValidation();
        } else if (field.value.trim() === '') {
            field.removeAttribute('data-validation-state');
        } else if (field.checkValidity()) {
            field.setAttribute('data-validation-state', 'valid');
        } else {
            field.setAttribute('data-validation-state', 'invalid');
        }
    };

    const saveDraft = () => {
        const payload = {
            mode: formMode,
            supplierId: formSupplierId,
            values: {},
        };

        draftFields.forEach((field) => {
            payload.values[field.name] = field.value;
        });

        window.localStorage.setItem(storageKey, JSON.stringify(payload));
    };

    const loadDraft = () => {
        const currentUrl = new URL(window.location.href);
        if (currentUrl.searchParams.get('supplier_form_reset') === '1') {
            window.localStorage.removeItem(storageKey);
            currentUrl.searchParams.delete('supplier_form_reset');
            window.history.replaceState({}, '', currentUrl.toString());
            return;
        }

        const raw = window.localStorage.getItem(storageKey);
        if (!raw) {
            return;
        }

        try {
            const payload = JSON.parse(raw);
            if (!payload || payload.mode !== formMode || String(payload.supplierId || '0') !== String(formSupplierId)) {
                return;
            }

            draftFields.forEach((field) => {
                const nextValue = payload.values && typeof payload.values[field.name] === 'string'
                    ? payload.values[field.name]
                    : null;
                if (nextValue !== null && field.value.trim() === '') {
                    field.value = nextValue;
                }
            });
        } catch (error) {
            window.localStorage.removeItem(storageKey);
        }
    };

    const clearDraft = () => {
        draftFields.forEach((field) => {
            field.value = '';
            field.removeAttribute('data-validation-state');
            field.setCustomValidity('');
        });
        window.localStorage.removeItem(storageKey);
        supplierForm.reset();
    };

    loadDraft();
    draftFields.forEach((field) => {
        syncFieldState(field);
        field.addEventListener('input', () => {
            syncFieldState(field);
            saveDraft();
        });
        field.addEventListener('blur', () => {
            syncFieldState(field);
        });
    });

    if (phoneField) {
        applyPhoneValidation();
    }

    clearButton?.addEventListener('click', () => {
        clearDraft();
        if (formMode === 'edit') {
            window.location.href = '/codesamplecaps/ENGINEER/dashboards/procurement.php?clear_supplier_form=1#create-supplier';
        }
    });

    supplierForm.addEventListener('submit', () => {
        draftFields.forEach(syncFieldState);
        if (!supplierForm.checkValidity()) {
            supplierForm.reportValidity();
        }
        saveDraft();
    });
})();

// Costing rows para mabilis magdagdag ng materials, labor, at notes.
document.addEventListener('DOMContentLoaded', function () {
    const activeModalKey = 'engineer.siteInspection.activeModal';
    const modalScrollKey = 'engineer.siteInspection.modalScroll';

    const money = new Intl.NumberFormat('en-PH', {
        style: 'currency',
        currency: 'PHP',
    });

    const syncTotal = function (form) {
        let total = 0;
        form.querySelectorAll('.costing-row').forEach(function (row) {
            const quantityField = row.querySelector('input[name="quantity[]"]');
            const unitField = row.querySelector('select[name="unit[]"]');
            const costField = row.querySelector('input[name="unit_cost[]"]');
            if (isValidQuantityText(quantityField?.value.trim() || '', unitField?.value || '')
                && isValidCostText(costField?.value.trim() || '')) {
                total += Number(quantityField.value) * Number(costField.value);
            }
        });

        const totalBox = form.querySelector('[data-costing-total]');
        if (totalBox) {
            totalBox.textContent = money.format(total);
        }
    };

    const storageKeyForForm = function (form) {
        const inspectionId = form.querySelector('input[name="inspection_id"]')?.value || 'unknown';
        return `engineer.siteInspection.costing.${inspectionId}`;
    };

    const saveFormDraft = function (form) {
        const rows = Array.from(form.querySelectorAll('.costing-row')).map(function (row) {
            return {
                item_type: row.querySelector('select[name="item_type[]"]')?.value || 'material',
                inventory_id: row.querySelector('input[name="inventory_id[]"]')?.value || '',
                material_id: row.querySelector('select[name="material_id[]"]')?.value || '',
                item_name: row.querySelector('input[name="item_name[]"]')?.value || '',
                quantity: row.querySelector('input[name="quantity[]"]')?.value || '',
                unit: row.querySelector('select[name="unit[]"]')?.value || 'unit',
                unit_cost: row.querySelector('input[name="unit_cost[]"]')?.value || '',
                notes: row.querySelector('input[name="notes[]"]')?.value || '',
            };
        });

        const payload = {
            engineer_findings: form.querySelector('textarea[name="engineer_findings"]')?.value || '',
            risk_notes: form.querySelector('textarea[name="risk_notes"]')?.value || '',
            client_requests: form.querySelector('textarea[name="client_requests"]')?.value || '',
            rows,
        };

        window.localStorage.setItem(storageKeyForForm(form), JSON.stringify(payload));
    };

    const normalizeText = function (value) {
        return String(value ?? '').trim();
    };

    const normalizeNumber = function (value) {
        const text = normalizeText(value);
        if (text === '') {
            return '';
        }

        const normalizedText = text.startsWith('.') ? `0${text}` : text;
        const number = Number(normalizedText);
        return Number.isFinite(number) ? String(number) : normalizedText;
    };

    const formSnapshot = function (form) {
        return JSON.stringify({
            findings: normalizeText(form.querySelector('textarea[name="engineer_findings"]')?.value),
            riskNotes: normalizeText(form.querySelector('textarea[name="risk_notes"]')?.value),
            clientRequests: normalizeText(form.querySelector('textarea[name="client_requests"]')?.value),
            rows: Array.from(form.querySelectorAll('.costing-row')).map(function (row) {
                return {
                    type: normalizeText(row.querySelector('select[name="item_type[]"]')?.value).toLowerCase(),
                    materialId: normalizeText(row.querySelector('select[name="material_id[]"]')?.value) || '',
                    itemName: normalizeText(row.querySelector('input[name="item_name[]"]')?.value),
                    quantity: normalizeNumber(row.querySelector('input[name="quantity[]"]')?.value),
                    unit: normalizeText(row.querySelector('select[name="unit[]"]')?.value).toLowerCase(),
                    unitCost: normalizeNumber(row.querySelector('input[name="unit_cost[]"]')?.value),
                    notes: normalizeText(row.querySelector('input[name="notes[]"]')?.value),
                };
            }),
        });
    };

    const updateSaveDraftState = function (form) {
        const saveButton = form.querySelector('[data-save-draft]');
        if (!saveButton || form.dataset.isSaving === 'true') {
            return;
        }

        const isDirty = isFormDirty(form);
        saveButton.disabled = !isDirty;
        saveButton.classList.toggle('is-disabled', !isDirty);
        saveButton.setAttribute('aria-disabled', isDirty ? 'false' : 'true');

        if (new URLSearchParams(window.location.search).has('debug_inspection_dirty')) {
            console.debug('Inspection draft snapshots', {
                baseline: form.dataset.savedSnapshot || '',
                current: formSnapshot(form),
            });
        }
    };

    const isFormDirty = function (form) {
        return formSnapshot(form) !== (form.dataset.savedSnapshot || '');
    };

    const fillRow = function (row, data) {
        row.querySelector('select[name="item_type[]"]').value = data.item_type || 'material';
        const materialPicker = row.querySelector('select[name="material_id[]"]');
        if (materialPicker) {
            materialPicker.value = data.material_id || '';
        }
        row.querySelector('input[name="item_name[]"]').value = data.item_name || '';
        row.querySelector('input[name="quantity[]"]').value = data.quantity || '1';
        row.querySelector('select[name="unit[]"]').value = data.unit || 'unit';
        row.querySelector('input[name="unit_cost[]"]').value = data.unit_cost || '';
        row.querySelector('input[name="notes[]"]').value = data.notes || '';
    };

    const setLinkedMaterialState = function (row) {
        const type = row.querySelector('select[name="item_type[]"]')?.value || '';
        const materialField = row.querySelector('[data-material-field]');
        const picker = row.querySelector('[data-material-picker]');
        const itemName = row.querySelector('input[name="item_name[]"]');
        const unit = row.querySelector('select[name="unit[]"]');
        const inventoryId = row.querySelector('input[name="inventory_id[]"]');
        const isMaterial = type === 'material';

        materialField?.toggleAttribute('hidden', !isMaterial);
        if (!isMaterial) {
            if (picker) picker.value = '';
            if (inventoryId) inventoryId.value = '';
            itemName?.removeAttribute('readonly');
            unit?.removeAttribute('data-locked');
            unit?.removeAttribute('aria-disabled');
            return;
        }

        const option = picker?.selectedOptions[0];
        const linkedName = option?.getAttribute('data-name') || '';
        const linkedUnit = option?.getAttribute('data-unit') || '';
        if (linkedName && linkedUnit) {
            itemName.value = linkedName;
            itemName.setAttribute('readonly', 'readonly');
            unit.value = linkedUnit;
            unit.setAttribute('data-locked', 'true');
            unit.setAttribute('aria-disabled', 'true');
        } else {
            itemName?.removeAttribute('readonly');
            unit?.removeAttribute('data-locked');
            unit?.removeAttribute('aria-disabled');
        }
    };

    const rowHasMeaningfulData = function (row) {
        return (row.querySelector('select[name="material_id[]"]')?.value || '') !== ''
            || (row.querySelector('input[name="item_name[]"]')?.value.trim() || '') !== ''
            || !['', '0', '0.00'].includes(row.querySelector('input[name="unit_cost[]"]')?.value.trim() || '')
            || (row.querySelector('input[name="notes[]"]')?.value.trim() || '') !== ''
            || (row.querySelector('input[name="quantity[]"]')?.value || '') !== '1';
    };

    const resetRowForType = function (row) {
        row.querySelector('input[name="inventory_id[]"]').value = '';
        row.querySelector('select[name="material_id[]"]').value = '';
        row.querySelector('input[name="item_name[]"]').value = '';
        row.querySelector('input[name="quantity[]"]').value = '';
        row.querySelector('select[name="unit[]"]').selectedIndex = 0;
        row.querySelector('input[name="unit_cost[]"]').value = '';
        row.querySelector('input[name="notes[]"]').value = '';
        row.querySelectorAll('.costing-field-error').forEach((error) => error.remove());
        row.querySelectorAll('.is-invalid').forEach((field) => field.classList.remove('is-invalid'));
        setLinkedMaterialState(row);
    };

    const restoreFormDraft = function (form) {
        const saved = window.localStorage.getItem(storageKeyForForm(form));
        if (!saved) {
            return;
        }

        let payload = null;
        try {
            payload = JSON.parse(saved);
        } catch (error) {
            window.localStorage.removeItem(storageKeyForForm(form));
            return;
        }
        form.querySelector('textarea[name="engineer_findings"]').value = payload.engineer_findings || '';
        form.querySelector('textarea[name="risk_notes"]').value = payload.risk_notes || '';
        form.querySelector('textarea[name="client_requests"]').value = payload.client_requests || '';

        const rowsBox = form.querySelector('[data-costing-rows]');
        const firstRow = rowsBox?.querySelector('.costing-row');
        if (!rowsBox || !firstRow || !Array.isArray(payload.rows)) {
            return;
        }

        rowsBox.innerHTML = '';
        payload.rows.forEach(function (rowData) {
            const row = firstRow.cloneNode(true);
            fillRow(row, rowData);
            rowsBox.appendChild(row);
            bindRow(form, row);
        });
    };

    const setFieldState = function (field, isInvalid) {
        if (!field) {
            return;
        }

        field.classList.toggle('is-invalid', isInvalid);
    };

    const setFieldError = function (field, message) {
        if (!field) {
            return;
        }

        setFieldState(field, true);

        const holder = field.closest('label') || field.parentElement;
        if (!holder || holder.querySelector('.costing-field-error')) {
            return;
        }

        const error = document.createElement('small');
        error.className = 'costing-field-error';
        error.textContent = message;
        holder.appendChild(error);
    };

    const clearFieldError = function (field) {
        if (!field) {
            return;
        }

        field.classList.remove('is-invalid');
        const holder = field.closest('label') || field.parentElement;
        holder?.querySelector('.costing-field-error')?.remove();
    };

    const showCostingError = function (form, message) {
        const errorBox = form.querySelector('[data-costing-error]');
        if (!errorBox) {
            return;
        }

        errorBox.textContent = message;
        errorBox.hidden = false;
    };

    const clearCostingError = function (form) {
        const errorBox = form.querySelector('[data-costing-error]');
        form.querySelectorAll('.is-invalid-total').forEach(function (field) {
            field.classList.remove('is-invalid-total');
        });

        if (errorBox) {
            errorBox.textContent = '';
            errorBox.hidden = true;
        }
    };

    const normalizeDecimalField = function (field) {
        if (!field) {
            return;
        }

        const value = field.value.trim();
        if (/^\.\d+$/.test(value)) {
            field.value = `0${value}`;
        }
    };

    const isValidCostText = function (value) {
        return /^(?:0|[1-9]\d*)(\.\d{1,2})?$/.test(value) && Number(value) > 0;
    };

    const costErrorMessage = function (value) {
        const number = Number(value);
        if (value !== '' && Number.isFinite(number) && number <= 0) {
            return 'Enter a cost greater than 0.';
        }
        return 'Use a valid cost with up to 2 decimals.';
    };

    const isWholeCountUnit = function (unit) {
        return ['unit', 'pc', 'pcs', 'roll', 'box', 'pack', 'set', 'lot', 'person', 'bundle', 'sheet', 'pair', 'tube', 'trip'].includes(unit);
    };

    const isValidQuantityText = function (value, unit) {
        if (isWholeCountUnit(unit)) {
            return /^[1-9]\d*$/.test(value);
        }

        return /^(?:0|[1-9]\d*)(\.\d+)?$/.test(value) && Number(value) > 0;
    };

    const addCostingRow = function (form, type = 'material') {
        const rowsBox = form.querySelector('[data-costing-rows]');
        const firstRow = rowsBox?.querySelector('.costing-row');
        if (!rowsBox || !firstRow) {
            return null;
        }

        const row = firstRow.cloneNode(true);
        row.querySelectorAll('input').forEach(function (field) {
            field.value = field.name === 'quantity[]' ? '1' : '';
            clearFieldError(field);
        });
        row.querySelectorAll('select').forEach(function (field) {
            field.selectedIndex = 0;
            clearFieldError(field);
        });

        const typeField = row.querySelector('select[name="item_type[]"]');
        if (typeField) {
            typeField.value = type;
        }

        rowsBox.appendChild(row);
        bindRow(form, row);
        syncTotal(form);
        saveFormDraft(form);
        updateSaveDraftState(form);

        return row;
    };

    const validateCosting = function (form, requireFinal) {
        let hasMaterial = false;
        let hasLabor = false;
        let hasAnyRow = false;
        let total = 0;
        let firstInvalid = null;
        let errorMessage = '';
        const findings = form.querySelector('textarea[name="engineer_findings"]');

        clearCostingError(form);
        form.querySelectorAll('.is-invalid').forEach(function (field) {
            field.classList.remove('is-invalid');
        });
        form.querySelectorAll('.costing-field-error').forEach(function (field) {
            field.remove();
        });
        form.querySelectorAll('.is-invalid-total').forEach(function (field) {
            field.classList.remove('is-invalid-total');
        });

        form.querySelectorAll('.costing-row').forEach(function (row) {
            const type = row.querySelector('select[name="item_type[]"]');
            const name = row.querySelector('input[name="item_name[]"]');
            const quantity = row.querySelector('input[name="quantity[]"]');
            const unit = row.querySelector('select[name="unit[]"]');
            const unitCost = row.querySelector('input[name="unit_cost[]"]');
            const nameValue = name?.value.trim() || '';
            const quantityText = quantity?.value.trim() || '';
            const quantityValue = Number(quantityText || 0);
            const unitValue = unit?.value.trim() || '';
            const unitCostText = unitCost?.value.trim() || '';
            const rowHasValue = nameValue !== '' || quantityText !== '' || unitCostText !== '';

            if (!rowHasValue) {
                return;
            }

            hasAnyRow = true;
            hasMaterial = hasMaterial || type?.value === 'material';
            hasLabor = hasLabor || type?.value === 'labor';
            if (isValidQuantityText(quantityText, unitValue) && isValidCostText(unitCostText)) {
                total += quantityValue * Number(unitCostText);
            }

            if (nameValue === '') {
                setFieldError(name, 'Item / Labor name is required.');
                firstInvalid = firstInvalid || name;
            }

            if (!isValidQuantityText(quantityText, unitValue)) {
                setFieldError(quantity, isWholeCountUnit(unitValue)
                    ? `Enter a whole number for ${unitValue || 'this unit'}.`
                    : 'Qty must be greater than 0.');
                firstInvalid = firstInvalid || quantity;
            }

            if (!isValidCostText(unitCostText)) {
                setFieldError(unitCost, costErrorMessage(unitCostText));
                firstInvalid = firstInvalid || unitCost;
            }

            if (unitValue === '') {
                setFieldError(unit, 'Unit is required.');
                firstInvalid = firstInvalid || unit;
            }

        });

        if (!hasAnyRow) {
            firstInvalid = firstInvalid || form.querySelector('input[name="item_name[]"]');
            setFieldError(firstInvalid, 'Add at least one material or labor row.');
        }

        if (requireFinal && findings && findings.value.trim().length < 10) {
            setFieldError(findings, 'Engineer Findings is required before submitting.');
            firstInvalid = firstInvalid || findings;
        }

        if (requireFinal && (!hasMaterial || !hasLabor || total <= 0)) {
            const totalBox = form.querySelector('[data-costing-total]');
            totalBox?.classList.add('is-invalid-total');
            if (!hasMaterial) {
                const newRow = addCostingRow(form, 'material');
                const nameField = newRow?.querySelector('input[name="item_name[]"]');
                setFieldError(nameField, 'Fill this Material item.');
                firstInvalid = firstInvalid || nameField;
                errorMessage = errorMessage || 'Missing Material row. I added one below.';
            } else if (!hasLabor) {
                const newRow = addCostingRow(form, 'labor');
                const nameField = newRow?.querySelector('input[name="item_name[]"]');
                setFieldError(nameField, 'Fill this Labor item.');
                firstInvalid = firstInvalid || nameField;
                errorMessage = errorMessage || 'Missing Labor row. I added one below.';
            } else {
                firstInvalid = firstInvalid || totalBox;
                errorMessage = errorMessage || 'Total cost must be greater than 0.';
            }
        }

        if (firstInvalid) {
            showCostingError(form, errorMessage || 'Please fix the highlighted field.');
            firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
            if (typeof firstInvalid.focus === 'function') {
                firstInvalid.focus();
            }
            return false;
        }

        return true;
    };

    const bindRow = function (form, row) {
        const typeField = row.querySelector('select[name="item_type[]"]');
        let previousType = typeField?.value || 'material';
        setLinkedMaterialState(row);

        typeField?.addEventListener('change', function () {
            const nextType = typeField.value;
            if (rowHasMeaningfulData(row)
                && !window.confirm('Change cost type?\n\nChanging the type will clear the current row details. Continue?')) {
                typeField.value = previousType;
                return;
            }

            previousType = nextType;
            resetRowForType(row);
            syncTotal(form);
            saveFormDraft(form);
            updateSaveDraftState(form);
        });

        row.querySelectorAll('[data-costing-decimal]').forEach(function (field) {
            field.addEventListener('input', function () {
                clearFieldError(field);
                clearCostingError(form);
                syncTotal(form);
                saveFormDraft(form);
                updateSaveDraftState(form);
            });

            field.addEventListener('blur', function () {
                normalizeDecimalField(field);
                const value = field.value.trim();
                const unit = row.querySelector('select[name="unit[]"]')?.value || '';
                const valid = field.name === 'unit_cost[]'
                    ? isValidCostText(value)
                    : isValidQuantityText(value, unit);
                if (!valid) {
                    setFieldError(field, field.name === 'unit_cost[]'
                        ? costErrorMessage(value)
                        : (isWholeCountUnit(unit) ? `Enter a whole number for ${unit}.` : 'Qty must be greater than 0.'));
                }
                syncTotal(form);
            });
        });

        row.querySelector('[data-material-picker]')?.addEventListener('change', function (event) {
            const option = event.target.selectedOptions[0];
            const name = option?.getAttribute('data-name') || '';
            const unit = option?.getAttribute('data-unit') || '';
            const nameField = row.querySelector('input[name="item_name[]"]');
            const unitField = row.querySelector('select[name="unit[]"]');
            if (name && nameField) {
                nameField.value = name;
            }
            if (unit && unitField && Array.from(unitField.options).some((optionItem) => optionItem.value === unit)) {
                unitField.value = unit;
            }
            if (!name) {
                if (nameField) nameField.value = '';
                if (unitField) unitField.selectedIndex = 0;
            }
            setLinkedMaterialState(row);
            saveFormDraft(form);
            updateSaveDraftState(form);
        });

        row.querySelector('select[name="unit[]"]')?.addEventListener('change', function (event) {
            const quantity = row.querySelector('input[name="quantity[]"]');
            const value = quantity?.value.trim() || '';
            if (value !== '' && !isValidQuantityText(value, event.target.value)) {
                setFieldError(quantity, isWholeCountUnit(event.target.value)
                    ? `Enter a whole number for ${event.target.value}.`
                    : 'Qty must be greater than 0.');
            }
            syncTotal(form);
        });

        row.querySelectorAll('input, select').forEach(function (field) {
            field.addEventListener('input', function () {
                clearFieldError(field);
                clearCostingError(form);
                saveFormDraft(form);
                updateSaveDraftState(form);
            });

            field.addEventListener('change', function () {
                if (field.matches('select[name="unit[]"][data-locked]')) {
                    const option = row.querySelector('[data-material-picker]')?.selectedOptions[0];
                    field.value = option?.getAttribute('data-unit') || field.value;
                }
                clearFieldError(field);
                clearCostingError(form);
                saveFormDraft(form);
                updateSaveDraftState(form);
            });
        });

        row.querySelector('[data-remove-costing-row]')?.addEventListener('click', function () {
            if (rowHasMeaningfulData(row)
                && !window.confirm('Remove costing item?\n\nThis will remove the current costing row and its entered details.')) {
                return;
            }

            const rows = form.querySelectorAll('.costing-row');
            if (rows.length <= 1) {
                row.querySelectorAll('input').forEach(function (field) {
                    field.value = field.name === 'quantity[]' ? '1' : '';
                });
                row.querySelectorAll('select').forEach(function (field) {
                    field.selectedIndex = 0;
                });
            } else {
                row.remove();
            }

            syncTotal(form);
            saveFormDraft(form);
            updateSaveDraftState(form);
        });
    };

    document.querySelectorAll('[data-costing-form]').forEach(function (form) {
        form.querySelectorAll('.costing-row').forEach(function (row) {
            bindRow(form, row);
        });

        // Kunin muna ang saved server values bago mag-restore ng unsaved browser draft.
        form.dataset.savedSnapshot = formSnapshot(form);
        restoreFormDraft(form);

        form.querySelector('[data-add-costing-row]')?.addEventListener('click', function () {
            addCostingRow(form);
        });

        form.querySelector('[data-save-draft]')?.addEventListener('click', function (event) {
            if (!isFormDirty(form) || form.dataset.isSaving === 'true') {
                event.preventDefault();
                event.stopPropagation();
            }
        });

        form.querySelectorAll('textarea').forEach(function (field) {
            field.addEventListener('input', function () {
                clearFieldError(field);
                clearCostingError(form);
                saveFormDraft(form);
                updateSaveDraftState(form);
            });
        });

        form.querySelector('[data-clear-costing-form]')?.addEventListener('click', function () {
            if (!window.confirm('Clear all unsaved costing inputs?')) {
                return;
            }

            window.localStorage.removeItem(storageKeyForForm(form));
            form.querySelectorAll('textarea').forEach(function (field) {
                field.value = '';
            });
            form.querySelectorAll('.costing-row').forEach(function (row, index) {
                if (index > 0) {
                    row.remove();
                    return;
                }

                row.querySelectorAll('input').forEach(function (field) {
                    field.value = field.name === 'quantity[]' ? '1' : '';
                });
                row.querySelectorAll('select').forEach(function (field) {
                    field.selectedIndex = 0;
                });
            });
            clearCostingError(form);
            form.querySelectorAll('.costing-field-error, .is-invalid').forEach(function (field) {
                field.classList?.remove('is-invalid');
                if (field.classList?.contains('costing-field-error')) {
                    field.remove();
                }
            });
            syncTotal(form);
            updateSaveDraftState(form);
        });

        form.addEventListener('submit', function (event) {
            if (form.dataset.isSaving === 'true') {
                event.preventDefault();
                return;
            }

            const action = event.submitter?.value || 'save_draft';
            const isFinalSubmit = action === 'submit_to_admin';

            if (!isFinalSubmit && !isFormDirty(form)) {
                event.preventDefault();
                updateSaveDraftState(form);
                return;
            }

            form.querySelectorAll('[data-costing-decimal]').forEach(normalizeDecimalField);

            if (!validateCosting(form, isFinalSubmit)) {
                event.preventDefault();
                return;
            }

            if (isFinalSubmit && !window.confirm('Submit this costing to Admin for review?')) {
                event.preventDefault();
                return;
            }

            if (!isFinalSubmit) {
                const saveButton = form.querySelector('[data-save-draft]');
                if (saveButton) {
                    form.dataset.isSaving = 'true';
                    saveButton.disabled = true;
                    saveButton.classList.remove('is-disabled');
                    saveButton.classList.add('is-loading');
                    saveButton.innerHTML = '<span class="inspection-button-spinner" aria-hidden="true"></span> Saving...';
                }
            }
        });

        syncTotal(form);
        updateSaveDraftState(form);
    });

    document.querySelectorAll('[data-confirm-inspection-transition]').forEach(function (button) {
        button.closest('form')?.addEventListener('submit', function (event) {
            const actionLabel = button.getAttribute('data-confirm-inspection-transition') || 'update this inspection';
            if (!window.confirm(`${actionLabel}?`)) {
                event.preventDefault();
            }
        });
    });

    const closeInspectionModal = function (modal) {
        if (!modal) {
            return;
        }

        modal.hidden = true;
        document.body.classList.remove('inspection-modal-open');
        window.localStorage.removeItem(activeModalKey);
    };

    document.querySelectorAll('[data-inspection-modal-open]').forEach(function (button) {
        button.addEventListener('click', function () {
            const modal = document.getElementById(button.getAttribute('data-inspection-modal-open'));
            if (!modal) {
                return;
            }

            modal.hidden = false;
            document.body.classList.add('inspection-modal-open');
            window.localStorage.setItem(activeModalKey, modal.id);
            modal.querySelector('[data-inspection-modal-close]')?.focus();
        });
    });

    document.querySelectorAll('.inspection-modal').forEach(function (modal) {
        const panel = modal.querySelector('.inspection-modal__panel');
        panel?.addEventListener('scroll', function () {
            window.localStorage.setItem(modalScrollKey, String(panel.scrollTop));
        });

        modal.addEventListener('click', function (event) {
            if (event.target === modal || event.target.closest('[data-inspection-modal-close]')) {
                closeInspectionModal(modal);
            }
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            document.querySelectorAll('.inspection-modal:not([hidden])').forEach(closeInspectionModal);
        }
    });

    const activeModalId = window.localStorage.getItem(activeModalKey);
    if (activeModalId) {
        const modal = document.getElementById(activeModalId);
        const panel = modal?.querySelector('.inspection-modal__panel');
        if (modal) {
            modal.hidden = false;
            document.body.classList.add('inspection-modal-open');
            window.setTimeout(function () {
                if (panel) {
                    panel.scrollTop = Number(window.localStorage.getItem(modalScrollKey) || 0);
                }
            }, 0);
        }
    }

    document.querySelectorAll('[data-inspection-toast]').forEach(function (toast) {
        window.setTimeout(function () {
            toast.remove();
        }, 4000);
    });
});
